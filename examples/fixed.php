<?php

declare(strict_types=1);

/**
 * Le même ticket, en centimes. 19,99 € = 1999. TVA Cameroun = 19,25 % = 1925 / 10000.
 */

$prixCentimes = 1999;
$qty = 3;
$brutCentimes = $prixCentimes * $qty;
$tvaCentimes = intdiv($brutCentimes * 1925 + 5000, 10000);
$ttcCentimes = $brutCentimes + $tvaCentimes;

$parts = 3;
$base = intdiv($ttcCentimes, $parts);
$reste = $ttcCentimes % $parts;

echo number_format($ttcCentimes / 100, 2, ',', ' ') . PHP_EOL;
echo "part={$base} centimes, reste={$reste}" . PHP_EOL;
