<?php

declare(strict_types=1);

$qty = 3;
$lineCount = $qty * 2;
$taxRate = 0.1925;
$opacity = 0.5;
$count = $totalCount + 2;
$itemsTotal = 10;

$label = "if ($prix == 10.10) { $centimes = (int) ($prix * 100); }";
$note = 'montant = 19.99 * 1.19';

// $prix = 10.50;
# $solde = $solde / 3;
/* $montant = (float) $x; */

$story = <<<TXT
$prix = 19.99;
$part = $montant / 3;
TXT;

$nowdoc = <<<'ND'
$frais = 2.50;
ND;

$cents = 1999;
$half = intdiv($cents, 2);
echo number_format($cents / 100, 2, '.', ' ');

$htCents = 1000;
$ttcCents = $htCents + intdiv($htCents * 1925, 10000);

class Invoice
{
    public int $amountCents;
}

function getTaxRate(): float
{
    return 0.1925;
}

function formatPrice(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ');
}

$net = bcmul('19.99', '1.1925', 4);

$ignored = $prix * 1.19; // centguard:ignore — cas voulu, documenté
