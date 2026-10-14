#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Times SvgSecurityCheck on generated files of known sizes, on hostile files and on the real
 * files in corpus/, and prints the markdown tables saved in benchmarks/results.md.
 *
 *     php -d opcache.enable_cli=1 -d xdebug.mode=off benchmarks/check-speed.php
 *     php ... benchmarks/check-speed.php --corpus=corpus                            # add the real-file table (run tools/fetch-corpus.php and tools/fetch-commons.php first)
 *     php ... benchmarks/check-speed.php --sanitizer=/path/to/vendor/autoload.php   # add enshrined/svg-sanitize columns for comparison
 *
 * Loads src/ directly, so no composer install is needed. Generated files are written to the
 * system temp dir and deleted at the end. Each time is the fastest of several runs of one
 * checkFile() call. For the generated and hostile files the runs happen in a fresh PHP process
 * per file, and peak memory is how much that process's peak resident set size (VmHWM on Linux)
 * grew during the runs. That counts libxml2's own allocations, which memory_get_peak_usage()
 * does not see. On other systems the memory columns print n/a.
 */

namespace Itools\SvgSecurityCheck\Benchmarks;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use function renderMdTable;

require __DIR__ . '/../src/Violation.php';
require __DIR__ . '/../src/Result.php';
require __DIR__ . '/../src/SvgSecurityCheck.php';
require __DIR__ . '/../tools/shared-md-table.php';

const RUNS       = 7;
const SIZES      = ['1 KB' => 1024, '100 KB' => 102400, '1 MB' => 1048576, '10 MB' => 10485760, '50 MB' => 52428800];
const BUCKETS    = ['under 10 KB' => 10240, '10 KB to 100 KB' => 102400, '100 KB to 1 MB' => 1048576, 'over 1 MB' => PHP_INT_MAX];
const IS_LINUX   = PHP_OS_FAMILY === 'Linux';

//region Options

$options   = getopt('', ['corpus:', 'sanitizer:', 'subprocess', 'file:', 'mode:']);
$corpus    = $options['corpus'] ?? null;
$sanitizer = $options['sanitizer'] ?? null;

// Every child this script starts carries SVGSECURITYCHECK_BENCH_CHILD. A child that is not in
// subprocess mode would run the full benchmark and start children of its own without end.
if (getenv('SVGSECURITYCHECK_BENCH_CHILD') !== false && !isset($options['subprocess'])) {
    fwrite(STDERR, "check-speed.php: refusing to run the full benchmark inside a benchmark child process\n");
    exit(1);
}

if ($sanitizer !== null) {
    require $sanitizer;
}

// Subprocess mode: time one file and print "seconds peakGrowthKb" on one line.
if (isset($options['subprocess'])) {
    $file    = $options['file'];
    $call    = $options['mode'] === 'sanitize' ? fn() => sanitize($file) : fn() => SvgSecurityCheck::checkFile($file);
    $before  = peakKb();
    $seconds = fastest($call);
    printf("%.9f %d\n", $seconds, IS_LINUX ? peakKb() - $before : 0);
    exit;
}
putenv('SVGSECURITYCHECK_BENCH_CHILD=1');   // inherited by every process shell_exec() starts below

//endregion
//region Environment

$opcacheOn = (bool)ini_get('opcache.enable_cli') && extension_loaded('Zend OPcache');
$jitOn     = $opcacheOn && (bool)(opcache_get_status(false)['jit']['enabled'] ?? false);
$xdebugOn  = extension_loaded('xdebug') && ini_get('xdebug.mode') !== 'off';
$cpu       = IS_LINUX && preg_match('/model name\s*:\s*(.+)/', (string)file_get_contents('/proc/cpuinfo'), $m) ? trim($m[1]) : php_uname('m');

printf("PHP %s, libxml %s, %s %s, %s\n", PHP_VERSION, LIBXML_DOTTED_VERSION, PHP_OS_FAMILY, php_uname('r'), $cpu);
printf("opcache %s, JIT %s, xdebug %s\n\n", $opcacheOn ? 'on' : 'OFF', $jitOn ? 'on' : 'off', $xdebugOn ? 'LOADED' : 'off');
if (!$opcacheOn || $xdebugOn) {
    echo "WARNING: run with -d opcache.enable_cli=1 -d xdebug.mode=off for citable numbers.\n\n";
}

//endregion
//region Generated Files

$tempDir = sys_get_temp_dir() . '/svgsecuritycheck-bench-' . bin2hex(random_bytes(8));   // a name nobody can create ahead of us
if (!mkdir($tempDir, 0700)) {
    fwrite(STDERR, "check-speed.php: could not create $tempDir\n");
    exit(1);
}

echo "## Generated files\n\n";
$headers = ['File', 'Check time', 'Throughput', 'Peak memory added'];
if ($sanitizer !== null) {
    $headers = [...$headers, 'svg-sanitize time', 'svg-sanitize memory'];
}
$rows = [];
foreach (SIZES as $label => $bytes) {
    $path = "$tempDir/generated-$bytes.svg";
    file_put_contents($path, generateSvg($bytes));
    $result = SvgSecurityCheck::checkFile($path);
    if (!$result->ok) {
        fwrite(STDERR, "generated $label file was rejected: {$result->errors[0]->message}\n");
        exit(1);
    }
    [$seconds, $peakKb] = measureCheck($path);
    $row = [$label, ms($seconds), sprintf('%.0f MB/s', filesize($path) / 1048576 / $seconds), memoryCell($peakKb)];
    if ($sanitizer !== null) {
        $sanitized = measure($path, 'sanitize', $sanitizer);
        $row[]     = $sanitized === null ? 'failed' : ms($sanitized[0]);
        $row[]     = $sanitized === null ? 'failed' : memoryCell($sanitized[1]);
    }
    $rows[] = $row;
}
echo renderMdTable($headers, $rows), "\n";

//endregion
//region Hostile Files

// Files built to hang or exhaust a renderer. Every one is rejected; the table shows what the rejection costs.
echo "## Hostile files\n\n";
$rows = [];
foreach (hostileFiles() as $label => $svg) {
    $path = "$tempDir/hostile-" . preg_replace('/\W+/', '-', $label) . '.svg';
    file_put_contents($path, $svg);
    $result = SvgSecurityCheck::checkFile($path);
    if ($result->ok) {
        fwrite(STDERR, "hostile file '$label' was accepted\n");
        exit(1);
    }
    [$seconds, $peakKb] = measureCheck($path);
    $rows[] = [$label, humanBytes(filesize($path)), "`{$result->errors[0]->code}`", ms($seconds), memoryCell($peakKb)];
}
echo renderMdTable(['File', 'Size', 'Rejected as', 'Check time', 'Peak memory added'], $rows), "\n";

//endregion
//region Corpus

if ($corpus !== null) {
    $files = corpusFiles($corpus);
    $times = [];   // path => fastest seconds
    $sizes = [];
    foreach ($files as $path) {
        $sizes[$path] = filesize($path);
    }
    $passTimes = [];
    for ($run = 0; $run < 3; $run++) {
        $passStart = hrtime(true);
        foreach ($files as $path) {
            $start = hrtime(true);
            SvgSecurityCheck::checkFile($path);
            $seconds      = (hrtime(true) - $start) / 1e9;
            $times[$path] = min($times[$path] ?? INF, $seconds);
        }
        $passTimes[] = (hrtime(true) - $passStart) / 1e9;
    }
    $totalBytes = array_sum($sizes);
    $passBest   = min($passTimes);
    printf("## Corpus: %s files, %.1f MB, checked in %.2f s (%s files/s, %.0f MB/s)\n\n", number_format(count($files)), $totalBytes / 1048576, $passBest, number_format(count($files) / $passBest), $totalBytes / 1048576 / $passBest);
    $columns = ['Files', 'Median per file', 'Mean per file', 'Slowest file'];
    $rows    = [];
    $lower   = 0;
    foreach (BUCKETS as $label => $upper) {
        $bucket = array_filter($times, fn($path) => $sizes[$path] >= $lower && $sizes[$path] < $upper, ARRAY_FILTER_USE_KEY);
        $lower  = $upper;
        if ($bucket !== []) {
            $rows[] = corpusRow($label, $bucket, $sizes);
        }
    }
    echo renderMdTable(['Size', ...$columns], $rows), "\n";

    // The same numbers per source folder, so real-world icon sets and renderer test suites can be told apart.
    $bySource = [];
    foreach ($times as $path => $seconds) {
        $bySource[explode('/', substr($path, strlen($corpus) + 1))[0]][$path] = $seconds;
    }
    $rows = [];
    foreach ($bySource as $source => $bucket) {
        $rows[] = corpusRow($source, $bucket, $sizes);
    }
    echo renderMdTable(['Source', ...$columns], $rows), "\n";
}

//endregion
//region Cleanup

foreach (glob("$tempDir/*") ?: [] as $file) {
    unlink($file);
}
rmdir($tempDir);

//endregion
//region Helpers

/**
 * An SVG of roughly the requested size that passes the check and looks like a design-tool
 * export: gradients in <defs>, a <style> block, groups with transforms, paths with fills,
 * strokes and class attributes, and fills that reference the gradients.
 */
function generateSvg(int $targetBytes): string
{
    mt_srand(42);
    $head = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1024 1024" width="1024" height="1024">' . "\n"
        . '<style>.a{fill:#3b82f6}.b{stroke:#1e293b;stroke-width:1.5}.c{opacity:.8}</style>' . "\n<defs>\n";
    for ($i = 0; $i < 10; $i++) {
        $head .= sprintf('<linearGradient id="g%d" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#%06x"/><stop offset="1" stop-color="#%06x"/></linearGradient>' . "\n", $i, mt_rand(0, 0xFFFFFF), mt_rand(0, 0xFFFFFF));
    }
    $head .= "</defs>\n";
    $tail  = "</svg>\n";

    $body = '';
    $n    = 0;
    while (strlen($head) + strlen($body) + strlen($tail) < $targetBytes) {
        if ($n % 50 === 0) {
            $body .= $n === 0 ? '' : "</g>\n";
            $body .= sprintf('<g transform="translate(%d %d) scale(0.5)">' . "\n", mt_rand(0, 512), mt_rand(0, 512));
        }
        $d = sprintf('M%d %d', mt_rand(0, 1024), mt_rand(0, 1024));
        for ($p = 0; $p < 12; $p++) {
            $d .= sprintf(' C%d %d %d %d %d %d', mt_rand(0, 1024), mt_rand(0, 1024), mt_rand(0, 1024), mt_rand(0, 1024), mt_rand(0, 1024), mt_rand(0, 1024));
        }
        $fill  = $n % 7 === 0 ? sprintf('url(#g%d)', $n % 10) : sprintf('#%06x', mt_rand(0, 0xFFFFFF));
        $class = ['a', 'b', 'c', 'a b'][$n % 4];
        $body .= sprintf('<path class="%s" fill="%s" stroke="#%06x" stroke-width="%.1f" d="%s Z"/>' . "\n", $class, $fill, mt_rand(0, 0xFFFFFF), mt_rand(5, 30) / 10, $d);
        $n++;
    }
    if ($n > 0) {
        $body .= "</g>\n";
    }
    return $head . $body . $tail;
}

/** @return array<string, string> label => SVG text, each built to hang or exhaust a renderer */
function hostileFiles(): array
{
    $open = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';

    // seven layers of ten <use> elements, each layer rendering the one below: 10 million rects from 5 KB
    $bomb = "$open<defs><g id=\"l0\">" . str_repeat('<rect width="1" height="1"/>', 10) . '</g>';
    for ($layer = 1; $layer <= 6; $layer++) {
        $bomb .= sprintf('<g id="l%d">%s</g>', $layer, str_repeat(sprintf('<use href="#l%d"/>', $layer - 1), 10));
    }
    $bomb .= '</defs><use href="#l6"/></svg>';

    // a billion laughs DOCTYPE: nine entity layers that expand to 30 GB of text
    $laughs = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "' . str_repeat('lol', 10) . '">';
    for ($layer = 1; $layer <= 9; $layer++) {
        $laughs .= sprintf('<!ENTITY %s "%s">', chr(ord('a') + $layer), str_repeat('&' . chr(ord('a') + $layer - 1) . ';', 10));
    }
    $laughs .= "]>$open<text>&j;</text></svg>";

    // 500 references to different ids inside 250 nested groups with ids: 125,000 records for the expansion check, from 14 KB
    $flood = $open;
    for ($i = 0; $i < 250; $i++) {
        $flood .= "<g id=\"n$i\">";
    }
    for ($i = 0; $i < 500; $i++) {
        $flood .= "<use href=\"#m$i\"/>";
    }
    $flood .= str_repeat('</g>', 250) . '</svg>';

    // 100,000 different violations in one file, well past the 50 the check reports
    $violations = $open;
    for ($i = 0; $i < 100000; $i++) {
        $violations .= "<a href=\"https://example.com/$i\"><rect width=\"1\" height=\"1\"/></a>";
    }
    $violations .= '</svg>';

    return [
        'reference bomb'            => $bomb,
        'reference loop'            => "$open<g id=\"a\"><use href=\"#b\"/></g><g id=\"b\"><use href=\"#a\"/></g><use href=\"#a\"/></svg>",
        'reference flood'           => $flood,
        'entity-expansion DOCTYPE'  => $laughs,
        '100,000 levels of nesting' => $open . str_repeat('<g>', 100000) . str_repeat('</g>', 100000) . '</svg>',
        '100,000 violations'        => $violations,
    ];
}

/** Fastest of RUNS timings of one call, in seconds. */
function fastest(callable $call): float
{
    $call();   // warm up: file cache, class loading
    $best = INF;
    for ($i = 0; $i < RUNS; $i++) {
        $start = hrtime(true);
        $call();
        $best = min($best, (hrtime(true) - $start) / 1e9);
    }
    return $best;
}

/** This process's peak resident set size in KB (VmHWM), 0 where /proc is not available. */
function peakKb(): int
{
    preg_match('/VmHWM:\s+(\d+) kB/', (string)@file_get_contents('/proc/self/status'), $match);
    return (int)($match[1] ?? 0);
}

/**
 * Runs this script in subprocess mode on one file: a fresh PHP process with the same opcache
 * and memory_limit settings times the check (or the sanitizer) and reports how much its peak
 * memory grew. Returns [seconds, peak growth in KB or null off Linux], or null when the process
 * failed, which the sanitizer does when a file needs more than memory_limit.
 *
 * @return array{float, ?int}|null
 */
function measure(string $path, string $mode, ?string $sanitizer): ?array
{
    $command = escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off -d opcache.enable_cli=' . (int)ini_get('opcache.enable_cli')
        . ' -d memory_limit=' . escapeshellarg((string)ini_get('memory_limit')) . ' ' . escapeshellarg(__FILE__)
        . ' --subprocess --mode=' . $mode . ' --file=' . escapeshellarg($path)
        . ($sanitizer !== null ? ' --sanitizer=' . escapeshellarg($sanitizer) : '') . ' 2>&1';
    $output = trim((string)shell_exec($command));
    if (!preg_match('/^([\d.]+) (-?\d+)$/', $output, $match)) {   // "seconds peakGrowthKb"
        fwrite(STDERR, "$mode subprocess failed for " . basename($path) . ": " . strtok($output, "\n") . "\n");
        return null;
    }
    return [(float)$match[1], IS_LINUX ? (int)$match[2] : null];
}

/** measure() for the check, which is not expected to fail. @return array{float, ?int} */
function measureCheck(string $path): array
{
    $measured = measure($path, 'check', null);
    if ($measured === null) {
        exit(1);
    }
    return $measured;
}

function sanitize(string $path): string
{
    return (string)(new \enshrined\svgSanitize\Sanitizer())->sanitize((string)file_get_contents($path));
}

function memoryCell(?int $peakGrowthKb): string
{
    return $peakGrowthKb === null ? 'n/a' : humanBytes(max(0, $peakGrowthKb) * 1024);
}

/** Every .svg under the corpus folder, sorted. @return string[] */
function corpusFiles(string $corpusDir): array
{
    $files = [];
    foreach (scandir($corpusDir) ?: [] as $source) {
        if ($source[0] === '.' || !is_dir("$corpusDir/$source")) {
            continue;
        }
        $stack = ["$corpusDir/$source"];
        while ($stack !== []) {
            $dir = array_pop($stack);
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry[0] === '.') {
                    continue;
                }
                $path = "$dir/$entry";
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (str_ends_with($entry, '.svg')) {
                    $files[] = $path;
                }
            }
        }
    }
    sort($files);
    return $files;
}

/**
 * One corpus table row: label, file count, median, mean, and the slowest file with its size.
 *
 * @param array<string, float> $bucket path => fastest seconds
 * @param array<string, int>   $sizes  path => bytes
 * @return string[]
 */
function corpusRow(string $label, array $bucket, array $sizes): array
{
    $slowest = array_search(max($bucket), $bucket, true);
    return [$label, number_format(count($bucket)), ms(median($bucket)), ms(array_sum($bucket) / count($bucket)), sprintf('%s (%s, %s)', ms($bucket[$slowest]), basename($slowest), humanBytes($sizes[$slowest]))];
}

/** @param float[] $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    return ($count % 2) ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
}

function ms(float $seconds): string
{
    $ms = $seconds * 1000;
    return $ms < 0.1 ? sprintf('%.3f ms', $ms) : ($ms < 10 ? sprintf('%.2f ms', $ms) : sprintf('%.1f ms', $ms));
}

function humanBytes(int|float $bytes): string
{
    return match (true) {
        $bytes >= 1048576 => sprintf('%.1f MB', $bytes / 1048576),
        $bytes >= 1024    => sprintf('%.0f KB', $bytes / 1024),
        default           => sprintf('%d bytes', $bytes),
    };
}

//endregion
