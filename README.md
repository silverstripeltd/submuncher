# Submuncher

Tool for shortening CIDRs lists.

Inspired by @andrewandante https://github.com/andrewandante/submuncher.

## Promises 

The tool promises the list will be shortened to desired maximum size. It also promises all input addresses will be
included within the output CIDRs.

It does not guarantee output list will not have ("leak") non-input IPs. In other words, it consolidates the list by
merging CIDRs and making them bigger.

Algorithm aims to minimise the leak size.

## Example

```php
$longList = ['1.1.1.0', '1.1.1.1', '1.1.1.2'];
$subMuncher = new SubMuncher();
$shortList = $subMuncher->consolidate($longList, 1);
// $shortList is ['1.1.1.0/30']
echo $subMuncher->getLeakTotal();
// Outputs "1" - 1.1.1.3 was leaked.
```
