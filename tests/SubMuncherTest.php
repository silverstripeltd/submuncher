<?php

namespace SilverStripe\SubMuncher\Tests;

use IPTools\IP;
use IPTools\Network;
use IPTools\Range;
use Mockery;
use PHPUnit\Framework\TestCase;
use SilverStripe\SubMuncher\SubMuncher;

class SubMuncherTest extends TestCase
{
    public function tearDown()
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConsolidateIPs()
    {
        $sm = new SubMuncher();
        $this->assertEquals(
            ['1.1.1.0/31'],
            $sm->consolidate(['1.1.1.0', '1.1.1.1'], 1)
        );
        $this->assertEquals(
            ['1.1.1.0/31', '1.1.1.2/32'],
            $sm->consolidate(['1.1.1.0', '1.1.1.1', '1.1.1.2'], 2)
        );

        $this->assertEquals(
            ['1::/127'],
            $sm->consolidate(['1::0', '1::1'], 1)
        );
        $this->assertEquals(
            ['1::/127', '1::2/128'],
            $sm->consolidate(['1::0', '1::1', '1::2'], 2)
        );
    }

    public function testConsolidatesToMax()
    {
        $list = ['1.1.1.0', '1.1.1.1', '1.1.1.2', '1.1.1.3', '1.1.1.4', '1.1.1.5'];
        $sm = new SubMuncher();
        for ($i = 5; $i > 0; --$i) {
            $this->assertLessThanOrEqual($i, count($sm->consolidate($list, $i)));
        }
    }

    public function testConsolidateCidrs()
    {
        $sm = new SubMuncher();
        $this->assertEquals(
            ['1.1.1.0/31'],
            $sm->consolidate(['1.1.1.0/32', '1.1.1.1/32'], 1)
        );
        $this->assertEquals(
            ['1::/127'],
            $sm->consolidate(['1::0/128', '1::1/128'], 1)
        );
    }

    public function testPicksSmallerLeak()
    {
        $sm = new SubMuncher();
        $this->assertEquals(
            ['1.1.1.0/32', '1.1.1.4/32', '2.2.2.0/31'],
            $sm->consolidate([
                '1.1.1.0/32',
                '1.1.1.4/32',
                '2.2.2.0/32',
                '2.2.2.1/32',
            ], 3)
        );
    }

    public function testComputesLeakageSize()
    {
        $sm = new SubMuncher();
        $sm->consolidate([
            '1.1.1.0/32',
            '1.1.1.4/32',
        ], 1);
        $this->assertEquals($sm->getLeakTotal(), 6);
    }

    public function testFuzzCidrs()
    {
        $cases = [
            // Just IPs, single reduction.
            ['ipCount' => 256, 'prefixWobble' => 0, 'reduction' => 1],
            ['ipCount' => 65536, 'prefixWobble' => 0, 'reduction' => 1],
            ['ipCount' => 16777216, 'prefixWobble' => 0, 'reduction' => 1],
            ['ipCount' => PHP_INT_MAX, 'prefixWobble' => 0, 'reduction' => 1],
            // Cidrs, 3 reductions.
            ['ipCount' => 256, 'prefixWobble' => 1, 'reduction' => 3],
            ['ipCount' => 65536, 'prefixWobble' => 3, 'reduction' => 3],
            ['ipCount' => 16777216, 'prefixWobble' => 6, 'reduction' => 3],
            ['ipCount' => PHP_INT_MAX, 'prefixWobble' => 12, 'reduction' => 3],
        ];

        foreach ([IP::IP_V4, IP::IP_V6] as $version) {
            foreach ($cases as $case) {
                for ($i = $case['reduction'] + 1; $i < 20; $i += 7) {
                    $this->fuzz($version, $case['ipCount'], $case['prefixWobble'], $i, $case['reduction']);
                }
            }
        }
    }

    /**
     * @param $version
     * @param $ipCount
     * @param $prefixMin
     * @param $prefixMax
     * @param $ruleCount
     * @param $consolidatedCount
     * @param mixed $prefixWobble
     * @param mixed $reduction
     */
    private function fuzz($version, $ipCount, $prefixWobble, $ruleCount, $reduction)
    {
        $consolidatedCount = $ruleCount - $reduction;
        $sm = new SubMuncher();
        $rules = $this->generateRules($version, $ipCount, $prefixWobble, $ruleCount);

        $serialisedRules = $rules;
        array_walk($serialisedRules, function (Network &$item) {
            $item = (string) $item;
        });

        $consolidated = $sm->consolidate($serialisedRules, $consolidatedCount);

        if (count($consolidated) != $consolidatedCount) {
            printf("Failed to consolidate to expected max.\n");
            $this->dumpContext($rules, $consolidated);
            $this->assertCount($consolidatedCount, $consolidated);
        }
        $this->verifyRules($rules, $consolidated);
    }

    /**
     * @param string $version
     * @param int    $ipCount
     * @param int    $prefixMin
     * @param int    $prefixMax
     * @param $ruleCount
     * @param mixed $prefixWobble
     *
     * @return Network[]
     */
    private function generateRules($version, $ipCount, $prefixWobble, $ruleCount)
    {
        if ($version === IP::IP_V4) {
            $prefixMax = 32;
        } else {
            $prefixMax = 128;
        }
        $prefixMin = $prefixMax - $prefixWobble;

        $rules = [];
        $taken = [];
        for ($i = 0; $i < $ruleCount; ++$i) {
            $r = mt_rand(0, $ipCount);
            if (isset($taken[$r])) {
                --$i;

                continue;
            }
            $taken[$r] = true;

            $ip = IP::parseLong($r, $version);
            $n = Network::parse($ip);
            $prefix = mt_rand($prefixMin, $prefixMax);
            $n->setPrefixLength($prefix);
            $rules[] = $n;
        }

        return $rules;
    }

    /**
     * @param Network[] $rules
     * @param string[]  $consolidatedRanges
     */
    private function verifyRules(array $rules, array $consolidated)
    {
        $consolidatedRanges = [];
        foreach ($consolidated as $cons) {
            $consolidatedRanges[] = [
                'cidr' => $cons,
                'range' => Range::parse($cons),
            ];
        }

        foreach ($rules as $rule) {
            $found = false;
            foreach ($consolidatedRanges as $consRange) {
                $contains = $consRange['range']->contains($rule);
                if ($contains) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                printf("Rule missing: %s\n", $rule);
                $this->dumpContext($rules, $consolidated);
            }
            $this->assertTrue($found);
        }
    }

    /**
     * @param Network[] $rules
     * @param string[]  $consolidated
     */
    private function dumpContext(array $rules, array $consolidated)
    {
        printf("Original rules:\n");
        foreach ($rules as $rule) {
            printf("%s\n", $rule);
        }
        printf("Consolidated rules:\n");
        foreach ($consolidated as $cons) {
            printf("%s\n", $cons);
        }
    }
}
