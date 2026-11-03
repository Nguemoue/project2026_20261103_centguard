# centguard

L'argent en float n'est pas de l'argent.  
Float money is not money.

`19.99 * 100` en PHP ne vaut pas `1999`. Ça vaut `1998.9999999999998`, et `(int)` tronque à **1998**. Un centime disparaît. Sur un prix à `19,99` avec la TVA camerounaise à **19,25 %** (`× 1.1925`), le float ne tombe déjà plus juste.

`centguard` est un scanner **sans dépendance** qui lit du PHP et montre la ligne.

```bash
php centguard.php --prove
php centguard.php src/
```

```text
Preuve, sur cet interpréteur PHP :

  19.99 * 100            = 1998.9999999999997726
  (int) (19.99 * 100)    = 1998   ← 1999 attendu, 1 centime perdu
  19.99 * 1.1925         = 23.8380749999999964
                            (TVA Cameroun 19,25 % : 23.838075 exact)
```

```text
centguard — l'argent en float n'est pas de l'argent

examples/buggy.php
  HIGH    cents-from-float   26  $centimes = (int) ($prix * 100);
          (int) ($prix * 100) tronque. 19.99 * 100 vaut 1998.9999999999998, donc 1998.
          → Ne multiplie jamais un float par 100. Le prix doit déjà être en centimes.
```

Code de sortie `1` dès qu'un finding `high` est là. Branchable en CI.

---

## Pourquoi ce bug est réel

IEEE-754 ne représente pas `0.01`. Donc :

| Écriture | Ce que PHP fait vraiment |
| --- | --- |
| `$centimes = (int) ($prix * 100)` avec `$prix = 19.99` | `1998`, pas `1999` |
| `$ttc = $prix * 1.1925` | `23.838074999999996` au lieu de `23.838075` |
| `$part = $montant / 3` | le reste des centimes se perd |
| `round($solde, 2)` | masque le symptôme, pas la cause |
| `(float) $row['montant']` | fige l'erreur dans le type |

La version juste tient les montants en **centimes entiers** dès le début :

```php
$prixCentimes = 1999;
$brutCentimes = $prixCentimes * $qty;
$tvaCentimes = intdiv($brutCentimes * 1925 + 5000, 10000); // 19,25 %
$ttcCentimes = $brutCentimes + $tvaCentimes;

$part = intdiv($ttcCentimes, 3);
$reste = $ttcCentimes % 3; // à redistribuer, 1 centime à la fois
```

`examples/fixed.php` fait exactement ça pour un article à 19,99 × 3. Résultat : **71,51** (7151 centimes), pas un float « à peu près ».

L'autre voie légale, à la frontière (CSV, formulaire, API) : garder la saisie en `string` et passer par `bcmath` avec un scale explicite. Jamais de cast `(float)`.

## Installation

PHP **8.1+**. Rien à `composer require`.

```bash
git clone https://github.com/Nguemoue/project2026_20261103_centguard.git
cd project2026_20261103_centguard
php centguard.php --self-test
php centguard.php --lang=fr chemin/vers/src
```

## Usage

```bash
php centguard.php [options] <fichier-ou-dossier>...
```

| Option | Effet |
| --- | --- |
| `--lang=fr` ou `--lang=en` | Langue des messages. Défaut : `en`, ou `fr` si `LANG` commence par `fr`. |
| `--json` | Sortie JSON pour la CI |
| `--min=high` | Échoue seulement sur `high` (défaut) |
| `--min=medium` | Échoue aussi sur `medium` (`round()`) |
| `--ignore=regle,regle` | Tait des règles |
| `--no-color` | Pas d'ANSI |
| `--prove` | Affiche `19.99 * 100` sur **ce** PHP |
| `--self-test` | Joue les fixtures du dépôt |

Une ligne qui contient le commentaire `centguard:ignore` est sautée.

Dossiers ignorés : `vendor/`, `node_modules/`, `.git/`. Extensions lues : `.php`, `.phtml`, `.inc`.

### Noms reconnus

Le scanner ne devine pas les types : il regarde le **nom**. Il réagit à `prix`, `price`, `montant`, `amount`, `solde`, `balance`, `frais`, `fee`, `tva`, `tax`, `commission`, `wallet`, `paiement`, `facture`, `salaire`, `ht`, `ttc`, `tarif`, et leurs variantes camelCase (`$totalTtc`, `$prixUnitaire`).

Ignorés volontairement :

- `$total` tout seul (trop de faux positifs sur des compteurs)
- un nom qui contient `rate`, `taux`, `percent`, `qty`, `count`, `ratio` (`$taxRate = 0.1925` est un taux, pas un montant)
- un nom qui contient `cent` ou `centime` (`$prixCentimes` est déjà un entier)

Ce n'est pas PHPStan. Une variable `$a = 19.99` ne sera pas vue. Nomme l'argent comme de l'argent.

### Règles

| Règle | Niveau | Exemple |
| --- | --- | --- |
| `assign-float` | high | `$prix = 19.99` |
| `decimal-math` | high | `$ttc = $prix * 1.1925` |
| `float-cast` | high | `(float) $row['montant']` |
| `float-typed` | high | `function pay(float $amount)` |
| `float-return` | high | `function getSolde(): float` |
| `compare-float` | high | `$prix == 10.10` |
| `split-division` | high | `$montant / 3` |
| `cents-from-float` | high | `(int) ($prix * 100)` |
| `float-literal` | high | `$frais = $ok ? 2.5 : 0.0` |
| `round-money` | medium | `round($solde, 2)` |

## Pour partager

> `19.99 * 100` en PHP = `1998.9999999999998`.  
> `(int)` de ça = **1998 centimes**.  
> Ton checkout arrondit au doigt mouillé — surtout avec une TVA à 19,25 %.  
> Scanner sans dépendance : `php centguard.php src/`

## Vérifier

```bash
php centguard.php --self-test
php examples/fixed.php
```

Le self-test exige que `examples/buggy.php` sorte exactement les 10 règles, que `examples/safe.php` et `examples/fixed.php` soient silencieux, et que `(int) (19.99 * 100)` vaille toujours `1998` sur la machine qui lance le test.

## Licence

MIT.
