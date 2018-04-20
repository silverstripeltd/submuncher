<?php

namespace SilverStripe\SubMuncher;

use Exception;
use IPTools\Network;
use IPTools\Range;

class SubMuncher
{
    /**
     * @var string
     */
    private $leakTotal;

    public function consolidate(array $rules, $max)
    {
        $this->leakTotal = '0';

        $ip = [];
        $ipv6 = [];
        foreach ($rules as $rule) {
            $n = Network::parse($rule);
            $ver = $n->getFirstIP()->getVersion();
            switch ($ver) {
                case 'IPv4':
                    $ip[] = $n;

                    break;
                case 'IPv6':
                    $ipv6[] = $n;

                    break;
                default:
                    throw new Exception(sprintf('Unknown protocol %s', $ver));
            }
        }

        return array_merge(
            $this->consolidateIpVersion($ip, $max),
            $this->consolidateIpVersion($ipv6, $max)
        );
    }

    /**
     * @return string
     */
    public function getLeakTotal()
    {
        return $this->leakTotal;
    }

    /**
     * @param Network[] $rules
     * @param $max
     *
     * @return string[]
     */
    private function consolidateIpVersion(array $rules, $max)
    {
        if (empty($rules)) {
            return [];
        }

        while (count($rules) > $max) {
            usort($rules, [$this, 'compareNetworks']);
            $rules = $this->consolidateOnePair($rules);
        }

        array_walk($rules, function (Network &$item) {
            $item = (string) $item;
        });

        return $rules;
    }

    /**
     * @param Network $a
     * @param Network $b
     *
     * @return int
     */
    private function compareNetworks(Network $a, Network $b)
    {
        $aFirst = $a->getFirstIP()->toLong();
        $bFirst = $b->getFirstIP()->toLong();

        if (strnatcmp($aFirst, $bFirst) < 0) {
            return -1;
        }
        if (strnatcmp($aFirst, $bFirst) > 0) {
            return 1;
        }

        $aLast = $a->getLastIP()->toLong();
        $bLast = $b->getLastIP()->toLong();

        return strnatcmp($aLast, $bLast);
    }

    /**
     * @param Network[] $rules
     *
     * @return Network[] rules with size decreased by 1
     */
    private function consolidateOnePair(array $rules)
    {
        $pair = $this->findPairWithSmallestLeak($rules);

        $rules[$pair['left']] = $pair['span'];
        unset($rules[$pair['right']]);

        $this->leakTotal = bcadd($this->leakTotal, $pair['leak']);

        return array_values($rules);
    }

    /**
     * @param Network[] $rules
     *
     * @return array a hash with keys 'left', 'span', 'leaks' representing the smallest leak
     */
    private function findPairWithSmallestLeak(array $rules)
    {
        $spans = [];
        $ruleCount = count($rules);
        for ($left = 0, $right = 1; $right < $ruleCount; $left++, $right++) {
            $span = $this->spanNetwork($rules[$left], $rules[$right]);
            $leak = $this->calculateLeak($rules[$left], $rules[$right], $span);

            $spans[] = [
                'left' => $left,
                'right' => $right,
                'span' => $span,
                'leak' => $leak,
            ];
        }

        usort($spans, function ($a, $b) {
            return strnatcmp($a['leak'], $b['leak']);
        });

        return $spans[0];
    }

    /**
     * @param Network $left
     * @param Network $right
     *
     * @return Network
     */
    private function spanNetwork(Network $left, Network $right)
    {
        // Networks are sorted, left is guaranteed to begin before or on the same address as right...
        $newBegin = $left->getFirstIP();

        // ...but the end can be either way.
        $leftEnd = $left->getLastIP();
        $rightEnd = $right->getLastIP();
        $newEnd = strnatcmp($leftEnd->toLong(), $rightEnd->toLong()) >= 0 ? $leftEnd : $rightEnd;

        // Build rule that contains both networks.
        $span = (new Range($newBegin, $newEnd))->getSpanNetwork();

        return $span;
    }

    /**'
     * @param Network $left
     * @param Network $right
     * @param Network $new
     * @return string
     */
    private function calculateLeak(Network $left, Network $right, Network $new)
    {
        $overlap = bcsub($left->getLastIP()->toLong(), $right->getFirstIP()->toLong());
        if ($overlap[0] === '-') {
            $overlap = '0';
        }

        $oldSize = bcadd($left->getBlockSize(), $right->getBlockSize());
        $oldSize = bcsub($oldSize, $overlap);

        $newSize = $new->getBlockSize();

        return bcsub($newSize, $oldSize);
    }
}
