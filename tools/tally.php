#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Runs SvgSecurityCheck over every .svg under corpus/ and reports, per source, how many files
 * were accepted and rejected, the count per error code with example paths, and the
 * surprises: rejections in a must-accept set, acceptances in a must-reject set.
 *
 *     php tools/tally.php                        # every source (.svg files, plus svgo's .svg.txt cases)
 *     php tools/tally.php resvg simple-icons     # named sources
 *     php tools/tally.php resvg --code=css-not-allowed   # list every file behind one code, with the message
 *
 * What each source should do comes from its SOURCE.json, written by tools/fetch-corpus.php.
 * corpus/own/ (our own design-tool exports, added by hand) has none and counts as must-accept.
 *
 * A rejection in a must-accept set is a missing allowlist entry or a real edge to document.
 * An acceptance in a must-reject set is a bug until the upstream case turns out not to apply
 * to files in an <img> tag.
 */

require __DIR__ . '/../vendor/autoload.php';

use Itools\SvgSecurityCheck\SvgSecurityCheck;

const CORPUS_DIR = __DIR__ . '/../corpus';
const EXAMPLES   = 3;

$codeFilter = null;
$requested  = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--code=')) {
        $codeFilter = substr($arg, 7);
    } else {
        $requested[] = $arg;
    }
}

if (!is_dir(CORPUS_DIR)) {
    fwrite(STDERR, "No corpus/ folder. Run: php tools/fetch-corpus.php\n");
    exit(1);
}

$sources = array_map('basename', glob(CORPUS_DIR . '/*', GLOB_ONLYDIR));
if ($requested !== []) {
    $sources = array_intersect($sources, $requested);
}

$grandAccepted = 0;
$grandRejected = 0;
$surprises     = [];

foreach ($sources as $name) {
    $dir      = CORPUS_DIR . "/$name";
    $meta     = is_file("$dir/SOURCE.json") ? json_decode(file_get_contents("$dir/SOURCE.json"), true) : ['expect' => 'accept', 'expectByName' => []];
    $accepted = 0;
    $rejected = 0;
    $byCode   = [];   // code => [count, examples[]]
    $listing  = [];   // for --code

    foreach (svgFiles($dir) as $path) {
        $relative = substr($path, strlen($dir) + 1);
        $expect   = expectFor($relative, $meta);
        $result   = str_ends_with($path, '.svg.txt') ? SvgSecurityCheck::checkString(svgoInput(file_get_contents($path))) : SvgSecurityCheck::checkFile($path);
        $codes    = array_unique(array_column($result->errors, 'code'));

        if ($result->ok) {
            $accepted++;
            if ($expect === 'reject') {
                $surprises[] = "$name/$relative accepted (must-reject set)";
            }
        } else {
            $rejected++;
            if ($expect === 'accept') {
                $surprises[] = "$name/$relative rejected: " . implode(', ', $codes) . ' (must-accept set)';
            }
            foreach ($codes as $code) {
                $byCode[$code] ??= [0, []];
                $byCode[$code][0]++;
                if (count($byCode[$code][1]) < EXAMPLES) {
                    $byCode[$code][1][] = $relative;
                }
            }
        }
        if ($codeFilter !== null && in_array($codeFilter, $codes, true)) {
            foreach ($result->errors as $violation) {
                if ($violation->code === $codeFilter) {
                    $listing[] = "  $relative\n      $violation->message";
                }
            }
        }
    }

    $grandAccepted += $accepted;
    $grandRejected += $rejected;
    printf("%-14s accepted %5d   rejected %5d   (%s, %s)\n", $name, $accepted, $rejected, $meta['expect'], $meta['license'] ?? 'own files');
    arsort($byCode);
    foreach ($byCode as $code => [$count, $examples]) {
        printf("  %-30s %5d   %s\n", $code, $count, implode(', ', $examples));
    }
    if ($listing !== []) {
        echo "  --- every file with $codeFilter:\n", implode("\n", $listing), "\n";
    }
}

printf("\n%-14s accepted %5d   rejected %5d\n", 'total', $grandAccepted, $grandRejected);
if ($surprises !== []) {
    echo "\nSurprises (", count($surprises), "):\n";
    foreach ($surprises as $surprise) {
        echo "  $surprise\n";
    }
}

/**
 * Every .svg and .svg.txt under $dir, sorted. Uses scandir(), not RecursiveDirectoryIterator:
 * on a WSL-mounted Windows drive the iterator silently skips files in large folders.
 *
 * @return string[]
 */
function svgFiles(string $dir): array
{
    $paths = [];
    foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
        $path = "$dir/$name";
        $lower = strtolower($name);
        if (is_dir($path)) {
            $paths = [...$paths, ...svgFiles($path)];
        } elseif (str_ends_with($lower, '.svg') || str_ends_with($lower, '.svg.txt')) {
            $paths[] = $path;
        }
    }
    sort($paths);
    return $paths;
}

/** svgo's .svg.txt cases: an optional description, then ===, then the input SVG, then @@@, then the expected output. */
function svgoInput(string $case): string
{
    $afterDescription = str_contains($case, "\n===\n") ? explode("\n===\n", $case, 2)[1] : $case;
    return trim(explode('@@@', $afterDescription)[0]);
}

function expectFor(string $relative, array $meta): string
{
    foreach ($meta['expectByName'] ?? [] as $pattern => $expect) {
        if (preg_match($pattern, $relative)) {
            return $expect;
        }
    }
    return $meta['expect'];
}
