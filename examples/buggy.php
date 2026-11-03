<?php

declare(strict_types=1);

$prix = 19.99;

$ttc = $prix * 1.1925;

$montant = (float) $commande['montant'];

function encaisser(float $amount): void
{
}

function getSolde(): float
{
    return 0.0;
}

if ($prix == 10.10) {
    echo "match";
}

$part = $montant / 3;

$centimes = (int) ($prix * 100);

$affiche = round($solde, 2);

$frais = $qty > 0 ? 2.5 : 0.0;
