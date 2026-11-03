#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * centguard — trouve l'argent PHP stocké en float.
 *
 * 19.99 * 100 === 1998.9999999999998, et (int) de ça vaut 1998.
 * Un centime disparaît. Ce script montre la ligne.
 *
 * Zéro dépendance. PHP >= 8.1.
 */

const CENTGUARD_VERSION = '1.0.0';

const CENTGUARD_SKIP_PARTS = [
    'rate', 'taux', 'percent', 'percentage', 'pct', 'qty', 'count',
    'quantity', 'ratio', 'opacity', 'weight', 'poids', 'latitude',
    'longitude', 'index', 'id', 'ids',
];

const CENTGUARD_MONEY_PARTS = [
    'price', 'prices', 'prix', 'amount', 'amounts', 'montant', 'montants',
    'subtotal', 'subtotals', 'balance', 'balances', 'solde', 'soldes',
    'fee', 'fees', 'frais', 'tax', 'taxes', 'taxe', 'tva', 'commission',
    'commissions', 'cost', 'costs', 'cout', 'couts', 'wallet', 'wallets',
    'payment', 'payments', 'paiement', 'paiements', 'refund', 'refunds',
    'discount', 'discounts', 'remise', 'remises', 'salary', 'salaire',
    'salaires', 'invoice', 'invoices', 'facture', 'factures', 'tarif',
    'tarifs', 'ht', 'ttc',
];

const CENTGUARD_MINOR_PARTS = ['cent', 'cents', 'centime', 'centimes'];

/** @var array<string, array{severity: string, fr: array{0: string, 1: string}, en: array{0: string, 1: string}}> */
const CENTGUARD_RULES = [
    'assign-float' => [
        'severity' => 'high',
        'fr' => [
            'Ce montant est un littéral float. 19.99 n\'existe pas en binaire.',
            'Stocke des centimes entiers (1999) dès la saisie.',
        ],
        'en' => [
            'This amount is a float literal. 19.99 does not exist in binary.',
            'Store integer cents (1999) from the moment the value is captured.',
        ],
    ],
    'decimal-math' => [
        'severity' => 'high',
        'fr' => [
            'Opération float sur un montant. Le résultat peut gagner ou perdre un centime.',
            'Calcule en centimes entiers, ou avec bcmath et un scale explicite.',
        ],
        'en' => [
            'Float arithmetic on money. The result can gain or drop a cent.',
            'Do the math in integer cents, or with bcmath and an explicit scale.',
        ],
    ],
    'float-cast' => [
        'severity' => 'high',
        'fr' => [
            'Cast float sur de l\'argent : l\'erreur d\'arrondi est figée dans le type.',
            'Garde la saisie en string, puis convertis une seule fois vers des centimes.',
        ],
        'en' => [
            'Float cast on money: the rounding error is now part of the type.',
            'Keep the input as a string, then convert once into cents.',
        ],
    ],
    'float-typed' => [
        'severity' => 'high',
        'fr' => [
            'Un type float sur un montant garantit des calculs inexacts.',
            'Préfère int (centimes) ou string (décimal exact).',
        ],
        'en' => [
            'A float type on money guarantees inexact math.',
            'Prefer int (cents) or string (exact decimal).',
        ],
    ],
    'float-return' => [
        'severity' => 'high',
        'fr' => [
            'Cette fonction renvoie un montant en float.',
            'Renvoie des centimes (int) ou une string décimale.',
        ],
        'en' => [
            'This function returns money as a float.',
            'Return cents (int) or a decimal string.',
        ],
    ],
    'compare-float' => [
        'severity' => 'high',
        'fr' => [
            'Comparer un montant à un float (ex. == 10.10) rate pour certaines valeurs.',
            'Compare des entiers, ou bccomp() sur des strings.',
        ],
        'en' => [
            'Comparing money to a float (e.g. == 10.10) misses some values.',
            'Compare integers, or bccomp() on strings.',
        ],
    ],
    'split-division' => [
        'severity' => 'high',
        'fr' => [
            'Diviser un montant float (partage, prorata, TVA) perd le reste.',
            'Passe en centimes, puis intdiv(), et distribue le reste 1 par 1.',
        ],
        'en' => [
            'Dividing a float amount (split bill, prorate, tax) drops the remainder.',
            'Switch to cents, use intdiv(), and hand out the leftover one cent at a time.',
        ],
    ],
    'cents-from-float' => [
        'severity' => 'high',
        'fr' => [
            '(int) ($prix * 100) tronque. 19.99 * 100 vaut 1998.9999999999998, donc 1998.',
            'Ne multiplie jamais un float par 100. Le prix doit déjà être en centimes.',
        ],
        'en' => [
            '(int) ($price * 100) truncates. 19.99 * 100 is 1998.9999999999998, so 1998.',
            'Never multiply a float by 100. The price should already be cents.',
        ],
    ],
    'round-money' => [
        'severity' => 'medium',
        'fr' => [
            'round($montant, 2) masque le bug sans le supprimer.',
            'Arrondis une seule fois, en centimes entiers, à la frontière du système.',
        ],
        'en' => [
            'round($amount, 2) hides the bug without removing it.',
            'Round once, to integer cents, at the system boundary.',
        ],
    ],
    'float-literal' => [
        'severity' => 'high',
        'fr' => [
            'Un littéral float côtoie un montant. Le décimal n\'est déjà plus exact.',
            'Écris le décimal en string ("19.99") ou, mieux, en centimes (1999).',
        ],
        'en' => [
            'A float literal sits next to money. That decimal is already inexact.',
            'Write the decimal as a string ("19.99") or, better, as cents (1999).',
        ],
    ],
];

function centguard_tokens(string $name): array
{
    $split = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
    $split = strtolower($split);

    return preg_split('/[^a-z0-9]+/', $split, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function centguard_is_minor(string $name): bool
{
    foreach (centguard_tokens($name) as $part) {
        if (in_array($part, CENTGUARD_MINOR_PARTS, true)) {
            return true;
        }
    }

    return false;
}

function centguard_is_money(string $name): bool
{
    if (centguard_is_minor($name)) {
        return false;
    }
    $parts = centguard_tokens($name);
    foreach ($parts as $part) {
        if (in_array($part, CENTGUARD_SKIP_PARTS, true)) {
            return false;
        }
    }
    foreach ($parts as $part) {
        if (in_array($part, CENTGUARD_MONEY_PARTS, true)) {
            return true;
        }
    }

    return false;
}

function centguard_mask(string $src): string
{
    $len = strlen($src);
    $out = '';
    $i = 0;

    while ($i < $len) {
        $c = $src[$i];
        $n = $i + 1 < $len ? $src[$i + 1] : '';

        if ($c === '/' && $n === '/') {
            while ($i < $len && $src[$i] !== "\n") {
                $out .= ' ';
                $i++;
            }
            continue;
        }

        if ($c === '#') {
            while ($i < $len && $src[$i] !== "\n") {
                $out .= ' ';
                $i++;
            }
            continue;
        }

        if ($c === '/' && $n === '*') {
            $out .= '  ';
            $i += 2;
            while ($i < $len) {
                if ($src[$i] === "\n") {
                    $out .= "\n";
                    $i++;
                    continue;
                }
                if ($src[$i] === '*' && ($i + 1) < $len && $src[$i + 1] === '/') {
                    $out .= '  ';
                    $i += 2;
                    break;
                }
                $out .= ' ';
                $i++;
            }
            continue;
        }

        if ($c === '<' && substr($src, $i, 3) === '<<<') {
            $tail = substr($src, $i);
            if (preg_match('/^<<<[ \t]*[\'"]?([A-Za-z_][A-Za-z0-9_]*)[\'"]?/', $tail, $hm) === 1) {
                $ident = $hm[1];
                $consumed = strlen($hm[0]);
                for ($k = 0; $k < $consumed; $k++) {
                    $out .= ($src[$i] === "\n") ? "\n" : ' ';
                    $i++;
                }
                $closer = '/^[ \t]*' . preg_quote($ident, '/') . '\b/';
                while ($i < $len) {
                    if ($src[$i] === "\n") {
                        $out .= "\n";
                        $i++;
                        if (preg_match($closer, substr($src, $i), $cm) === 1) {
                            $blank = strlen($cm[0]);
                            for ($k = 0; $k < $blank; $k++) {
                                $out .= ' ';
                                $i++;
                            }
                            break;
                        }
                        continue;
                    }
                    $out .= ' ';
                    $i++;
                }
                continue;
            }
        }

        if ($c === "'" || $c === '"') {
            $quote = $c;
            $j = $i + 1;
            $buf = '';
            $closed = false;
            while ($j < $len) {
                if ($src[$j] === '\\') {
                    $escapeNext = $quote === '"'
                        || ($j + 1 < $len && ($src[$j + 1] === '\\' || $src[$j + 1] === "'"));
                    if ($escapeNext) {
                        $buf .= $src[$j];
                        $j++;
                        if ($j < $len) {
                            $buf .= $src[$j];
                            $j++;
                        }
                        continue;
                    }
                }
                if ($src[$j] === $quote) {
                    $closed = true;
                    break;
                }
                $buf .= $src[$j];
                $j++;
            }
            $end = $closed ? $j + 1 : $j;
            $keep = $closed && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $buf) === 1;
            if ($keep) {
                $out .= substr($src, $i, $end - $i);
            } else {
                for ($k = $i; $k < $end; $k++) {
                    $out .= $src[$k] === "\n" ? "\n" : ' ';
                }
            }
            $i = $end;
            continue;
        }

        $out .= $c;
        $i++;
    }

    return $out;
}

function centguard_money_context(string $line): bool
{
    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $vars) === false) {
        return false;
    }
    foreach ($vars[1] as $name) {
        if (centguard_is_money($name)) {
            return true;
        }
    }
    if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)/', $line, $fns)) {
        foreach ($fns[1] as $name) {
            if (centguard_is_money($name)) {
                return true;
            }
        }
    }
    if (preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)/', $line, $props)) {
        foreach ($props[1] as $name) {
            if (centguard_is_money($name)) {
                return true;
            }
        }
    }
    if (preg_match_all("/['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]/", $line, $keys)) {
        foreach ($keys[1] as $name) {
            if (centguard_is_money($name)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return list<array{line: int, rule: string, snippet: string}>
 */
function centguard_scan_source(string $source): array
{
    $rawLines = preg_split("/\n/", $source) ?: [];
    $masked = centguard_mask($source);
    $maskLines = preg_split("/\n/", $masked) ?: [];
    if (count($rawLines) !== count($maskLines)) {
        $maskLines = $rawLines;
    }

    $findings = [];
    foreach ($maskLines as $idx => $line) {
        $raw = $rawLines[$idx] ?? $line;
        if (str_contains($raw, 'centguard:ignore')) {
            continue;
        }
        $hit = centguard_scan_line($line);
        if ($hit === null) {
            continue;
        }
        $snippet = trim($raw);
        if (strlen($snippet) > 160) {
            $snippet = substr($snippet, 0, 157) . '...';
        }
        $findings[] = [
            'line' => $idx + 1,
            'rule' => $hit,
            'snippet' => $snippet,
        ];
    }

    return $findings;
}

function centguard_scan_line(string $line): ?string
{
    $money = centguard_money_context($line);
    $fired = [];

    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*-?\d+\.\d+\s*(?:[;,\)]|$)/', $line, $m)) {
        foreach ($m[1] as $name) {
            if (centguard_is_money($name)) {
                $fired[] = 'assign-float';
                break;
            }
        }
    }
    if (preg_match_all("/['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]\\s*=>\\s*-?\\d+\\.\\d+/", $line, $m)) {
        foreach ($m[1] as $name) {
            if (centguard_is_money($name)) {
                $fired[] = 'assign-float';
                break;
            }
        }
    }

    if ($money && preg_match('/(?:^|[^+\-*\/<>=!])[+\-*\/]\s*-?\d+\.\d+|\d+\.\d+\s*[+\-*\/]/', $line) === 1) {
        $fired[] = 'decimal-math';
    }

    if ($money && (preg_match('/\(\s*(?:float|double)\s*\)/', $line) === 1 || preg_match('/\bfloatval\s*\(/', $line) === 1)) {
        $fired[] = 'float-cast';
    }

    if (preg_match_all('/\bfloat\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $line, $m)) {
        foreach ($m[1] as $name) {
            if (centguard_is_money($name)) {
                $fired[] = 'float-typed';
                break;
            }
        }
    }

    if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(.*\)\s*:\s*\??float\b/', $line, $m) === 1) {
        if (centguard_is_money($m[1])) {
            $fired[] = 'float-return';
        }
    }

    if ($money && preg_match('/(?:==|!=|===|!==|<=|>=|<|>)\s*-?\d+\.\d+|-?\d+\.\d+\s*(?:==|!=|===|!==|<=|>=|<|>)/', $line) === 1) {
        $fired[] = 'compare-float';
    }

    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\/\s*(\$?[A-Za-z_][A-Za-z0-9_]*|\d+)\b/', $line, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (!centguard_is_money($hit[1])) {
                continue;
            }
            if (preg_match('/^\d+\.\d+$/', $hit[2]) === 1) {
                continue;
            }
            $fired[] = 'split-division';
            break;
        }
    }

    if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\*\s*1000?\b/', $line, $m)) {
        foreach ($m[1] as $name) {
            if (centguard_is_money($name)) {
                $fired[] = 'cents-from-float';
                break;
            }
        }
    }

    if ($money && preg_match('/\bround\s*\(/', $line) === 1 && !in_array('cents-from-float', $fired, true)) {
        $fired[] = 'round-money';
    }

    if ($fired === [] && $money && preg_match('/\d+\.\d+/', $line) === 1) {
        $fired[] = 'float-literal';
    }

    if ($fired === []) {
        return null;
    }

    $rank = [
        'cents-from-float' => 0,
        'float-cast' => 1,
        'assign-float' => 2,
        'decimal-math' => 3,
        'compare-float' => 4,
        'split-division' => 5,
        'float-typed' => 6,
        'float-return' => 7,
        'float-literal' => 8,
        'round-money' => 9,
    ];
    usort($fired, static fn (string $a, string $b): int => ($rank[$a] ?? 99) <=> ($rank[$b] ?? 99));

    return $fired[0];
}

/**
 * @return list<string>
 */
function centguard_collect(string $path): array
{
    if (is_file($path)) {
        return [$path];
    }
    if (!is_dir($path)) {
        return [];
    }

    $out = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $file->getPathname());
        if (preg_match('#/(vendor|node_modules|\.git)/#', $full) === 1) {
            continue;
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        if (!in_array($ext, ['php', 'phtml', 'inc'], true)) {
            continue;
        }
        $out[] = $file->getPathname();
    }
    sort($out);

    return $out;
}

/**
 * @param list<string> $paths
 * @return list<array{file: string, line: int, severity: string, rule: string, snippet: string}>
 */
function centguard_scan_paths(array $paths): array
{
    $findings = [];
    foreach ($paths as $path) {
        foreach (centguard_collect($path) as $file) {
            $size = filesize($file);
            if ($size === false || $size > 1_000_000) {
                continue;
            }
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            foreach (centguard_scan_source($source) as $hit) {
                $rule = CENTGUARD_RULES[$hit['rule']];
                $findings[] = [
                    'file' => $file,
                    'line' => $hit['line'],
                    'severity' => $rule['severity'],
                    'rule' => $hit['rule'],
                    'snippet' => $hit['snippet'],
                ];
            }
        }
    }

    return $findings;
}

function centguard_default_lang(): string
{
    $env = getenv('CENTGUARD_LANG') ?: getenv('LC_ALL') ?: getenv('LANG') ?: '';
    $env = strtolower((string) $env);

    return str_starts_with($env, 'fr') ? 'fr' : 'en';
}

/**
 * @param list<array{file: string, line: int, severity: string, rule: string, snippet: string}> $findings
 */
function centguard_render_text(array $findings, string $lang, bool $color, string $min): int
{
    $high = 0;
    $medium = 0;
    foreach ($findings as $f) {
        if ($f['severity'] === 'high') {
            $high++;
        } else {
            $medium++;
        }
    }

    $title = $lang === 'fr'
        ? 'centguard — l\'argent en float n\'est pas de l\'argent'
        : 'centguard — float money is not money';
    fwrite(STDOUT, $title . "\n");

    if ($findings === []) {
        $ok = $lang === 'fr' ? 'Aucun montant float détecté.' : 'No float-money findings.';
        fwrite(STDOUT, $ok . "\n");

        return 0;
    }

    $current = null;
    foreach ($findings as $f) {
        if ($f['file'] !== $current) {
            $current = $f['file'];
            fwrite(STDOUT, "\n" . $current . "\n");
        }
        $label = strtoupper($f['severity']);
        $padded = sprintf('%-6s', $label);
        if ($color) {
            $padded = $f['severity'] === 'high' ? "\033[31m{$padded}\033[0m" : "\033[33m{$padded}\033[0m";
        }
        $copy = CENTGUARD_RULES[$f['rule']][$lang];
        fwrite(STDOUT, sprintf("  %s  %-16s %4d  %s\n", $padded, $f['rule'], $f['line'], $f['snippet']));
        fwrite(STDOUT, '          ' . $copy[0] . "\n");
        fwrite(STDOUT, '          → ' . $copy[1] . "\n");
    }

    $files = count(array_unique(array_column($findings, 'file')));
    if ($lang === 'fr') {
        $line = sprintf("\n%d high, %d medium · %d fichier%s\n", $high, $medium, $files, $files > 1 ? 's' : '');
    } else {
        $line = sprintf("\n%d high, %d medium · %d file%s\n", $high, $medium, $files, $files > 1 ? 's' : '');
    }
    fwrite(STDOUT, $line);

    $fail = $min === 'medium' ? ($high + $medium) > 0 : $high > 0;

    return $fail ? 1 : 0;
}

/**
 * @param list<array{file: string, line: int, severity: string, rule: string, snippet: string}> $findings
 */
function centguard_render_json(array $findings, string $lang, string $min): int
{
    $high = 0;
    $medium = 0;
    $out = [];
    foreach ($findings as $f) {
        if ($f['severity'] === 'high') {
            $high++;
        } else {
            $medium++;
        }
        $copy = CENTGUARD_RULES[$f['rule']][$lang];
        $out[] = [
            'file' => $f['file'],
            'line' => $f['line'],
            'severity' => $f['severity'],
            'rule' => $f['rule'],
            'message' => $copy[0],
            'hint' => $copy[1],
            'snippet' => $f['snippet'],
        ];
    }
    $payload = [
        'tool' => 'centguard',
        'version' => CENTGUARD_VERSION,
        'findings' => $out,
        'summary' => [
            'high' => $high,
            'medium' => $medium,
            'files' => count(array_unique(array_column($findings, 'file'))),
        ],
    ];
    fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    $fail = $min === 'medium' ? ($high + $medium) > 0 : $high > 0;

    return $fail ? 1 : 0;
}

function centguard_prove(string $lang): int
{
    $raw = 19.99 * 100;
    $cast = (int) $raw;
    $product = 19.99 * 1.1925;

    if ($lang === 'fr') {
        fwrite(STDOUT, "Preuve, sur cet interpréteur PHP :\n\n");
        fwrite(STDOUT, '  19.99 * 100            = ' . sprintf('%.16F', $raw) . "\n");
        fwrite(STDOUT, '  (int) (19.99 * 100)    = ' . $cast . "   ← 1999 attendu, 1 centime perdu\n");
        fwrite(STDOUT, '  19.99 * 1.1925         = ' . sprintf('%.16F', $product) . "\n");
        fwrite(STDOUT, "                            (TVA Cameroun 19,25 % : 23.838075 exact)\n");
    } else {
        fwrite(STDOUT, "Proof, on this PHP build:\n\n");
        fwrite(STDOUT, '  19.99 * 100            = ' . sprintf('%.16F', $raw) . "\n");
        fwrite(STDOUT, '  (int) (19.99 * 100)    = ' . $cast . "   ← expected 1999, one cent gone\n");
        fwrite(STDOUT, '  19.99 * 1.1925         = ' . sprintf('%.16F', $product) . "\n");
        fwrite(STDOUT, "                            (Cameroon VAT 19.25%: exact value is 23.838075)\n");
    }

    return $cast === 1998 ? 0 : 1;
}

function centguard_root(): string
{
    return dirname(__FILE__);
}

function centguard_self_test(): int
{
    $failures = [];

    $sample = "<?php\n\$a = 'prix';\n\$b = \"le prix = 19.99\";\n// \$prix = 1.5;\n";
    $masked = centguard_mask($sample);
    if (substr_count($masked, "\n") !== substr_count($sample, "\n")) {
        $failures[] = 'mask dropped or added lines';
    }
    if (!str_contains($masked, "'prix'")) {
        $failures[] = 'mask ate a simple identifier string';
    }
    if (str_contains($masked, '19.99')) {
        $failures[] = 'mask kept a float hidden in a string';
    }

    $heredoc = "<?php\n\$story = <<<TXT\n\$prix = 19.99;\nTXT;\n\$ok = 1;\n";
    $hmask = centguard_mask($heredoc);
    if (substr_count($heredoc, "\n") !== substr_count($hmask, "\n")) {
        $failures[] = 'heredoc mask changed line count';
    }
    if (centguard_scan_source($heredoc) !== []) {
        $failures[] = 'heredoc body was scanned as code';
    }

    $cast = (int) (19.99 * 100);
    if ($cast !== 1998) {
        $failures[] = 'expected (int)(19.99*100) === 1998, got ' . $cast;
    }

    $selfSource = file_get_contents(__FILE__);
    if ($selfSource !== false && centguard_scan_source($selfSource) !== []) {
        $own = array_column(centguard_scan_source($selfSource), 'rule');
        $failures[] = 'scanner flags itself: ' . json_encode($own);
    }

    $root = centguard_root();
    $cases = [
        'examples/buggy.php' => [
            'assign-float',
            'decimal-math',
            'float-cast',
            'float-typed',
            'float-return',
            'compare-float',
            'split-division',
            'cents-from-float',
            'round-money',
            'float-literal',
        ],
        'examples/safe.php' => [],
        'examples/fixed.php' => [],
    ];

    foreach ($cases as $rel => $expected) {
        $file = $root . '/' . $rel;
        $source = file_get_contents($file);
        if ($source === false) {
            $failures[] = 'missing ' . $rel;
            continue;
        }
        $rules = array_column(centguard_scan_source($source), 'rule');
        sort($rules);
        $want = $expected;
        sort($want);
        if ($rules !== $want) {
            $failures[] = $rel . ' rules ' . json_encode($rules) . ' != ' . json_encode($want);
        }
    }

    if ($failures === []) {
        fwrite(STDOUT, "centguard self-test: ok\n");

        return 0;
    }

    fwrite(STDERR, "centguard self-test: FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }

    return 1;
}

function centguard_help(string $lang): void
{
    if ($lang === 'fr') {
        $text = <<<TXT
centguard %s — traque les montants PHP stockés en float.

Usage :
  php centguard.php [options] <fichier-ou-dossier>...
  php centguard.php --prove
  php centguard.php --self-test

Options :
  --json           sortie JSON (CI, jq)
  --lang=fr|en     langue des messages (défaut : en, ou fr si LANG=fr)
  --min=high       code de sortie 1 dès qu'il y a un high (défaut)
  --min=medium     code de sortie 1 aussi pour les medium
  --ignore=a,b     règles à taire
  --no-color       pas de couleur
  --prove          affiche 19.99 * 100 sur CE php
  --self-test      vérifie le scanner (fixtures incluses)

Le nom de la variable doit ressembler à de l'argent
(\$prix, \$montant, \$solde, \$totalTtc, \$frais…).
\$total tout seul est ignoré, trop de faux positifs.
\$prixCentimes est traité comme des entiers.

Une ligne avec le commentaire centguard:ignore est sautée.

TXT;
    } else {
        $text = <<<TXT
centguard %s — finds PHP money stored as float.

Usage:
  php centguard.php [options] <file-or-directory>...
  php centguard.php --prove
  php centguard.php --self-test

Options:
  --json           JSON output (CI, jq)
  --lang=fr|en     message language (default: en, or fr when LANG=fr)
  --min=high       exit 1 when any high finding exists (default)
  --min=medium     exit 1 on medium findings too
  --ignore=a,b     comma-separated rule ids to skip
  --no-color       disable ANSI colors
  --prove          print 19.99 * 100 on THIS php
  --self-test      run the built-in fixture checks

Variable names must look like money
(\$price, \$amount, \$balance, \$totalTtc, \$fee…).
A bare \$total is ignored on purpose.
\$priceCents is treated as integer minor units.

A line carrying the comment centguard:ignore is skipped.

TXT;
    }
    fwrite(STDOUT, sprintf($text, CENTGUARD_VERSION));
}

/**
 * @param list<string> $argv
 */
function centguard_main(array $argv): int
{
    $lang = centguard_default_lang();
    $json = false;
    $min = 'high';
    $color = stream_isatty(STDOUT);
    $ignore = [];
    $paths = [];
    $prove = false;
    $self = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            centguard_help($lang);
            return 0;
        }
        if ($arg === '--json') {
            $json = true;
            continue;
        }
        if ($arg === '--no-color') {
            $color = false;
            continue;
        }
        if ($arg === '--prove') {
            $prove = true;
            continue;
        }
        if ($arg === '--self-test') {
            $self = true;
            continue;
        }
        if (str_starts_with($arg, '--lang=')) {
            $lang = substr($arg, 7) === 'fr' ? 'fr' : 'en';
            continue;
        }
        if (str_starts_with($arg, '--min=')) {
            $value = substr($arg, 6);
            if ($value !== 'high' && $value !== 'medium') {
                fwrite(STDERR, "Unknown --min={$value}. Use high or medium.\n");
                return 2;
            }
            $min = $value;
            continue;
        }
        if (str_starts_with($arg, '--ignore=')) {
            foreach (explode(',', substr($arg, 9)) as $rule) {
                $rule = trim($rule);
                if ($rule !== '') {
                    $ignore[] = $rule;
                }
            }
            continue;
        }
        if (str_starts_with($arg, '-')) {
            fwrite(STDERR, "Unknown option {$arg}\n");
            centguard_help($lang);
            return 2;
        }
        $paths[] = $arg;
    }

    if ($self) {
        return centguard_self_test();
    }
    if ($prove && $paths === []) {
        return centguard_prove($lang);
    }
    if ($paths === []) {
        centguard_help($lang);
        return 2;
    }

    $findings = centguard_scan_paths($paths);
    if ($ignore !== []) {
        $findings = array_values(array_filter(
            $findings,
            static fn (array $f): bool => !in_array($f['rule'], $ignore, true)
        ));
    }

    if ($prove) {
        centguard_prove($lang);
        fwrite(STDOUT, "\n");
    }

    return $json
        ? centguard_render_json($findings, $lang, $min)
        : centguard_render_text($findings, $lang, $color, $min);
}

if (PHP_SAPI === 'cli' && isset($argv) && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(centguard_main($argv));
}
