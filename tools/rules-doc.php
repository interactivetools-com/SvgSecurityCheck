#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Rewrites the allowlist blocks in docs/ai-reference.md from SvgSecurityCheck::rules(), so the
 * docs cannot drift from the code. Each block sits between
 * <!-- rules:NAME --> and <!-- /rules:NAME --> markers, where NAME is a key of rules();
 * everything else on the page is written by hand.
 *
 *     php tools/rules-doc.php            # rewrite the page
 *     php tools/rules-doc.php --check    # exit 1 naming any page that is out of date (RulesDocTest does the same)
 */

namespace Itools\SvgSecurityCheck\Tools\RulesDoc;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use function renderMdTable;

require_once __DIR__ . '/shared-md-table.php';

const PAGES = ['docs/ai-reference.md'];
const WIDTH = 100;

/** The page with every marked block regenerated. Unmarked text is returned as is. */
function render(string $markdown): string
{
    $rules = SvgSecurityCheck::rules();
    return preg_replace_callback(
        '/(<!-- rules:(\w+) -->)\n.*?\n?(<!-- \/rules:\2 -->)/s',   // <!-- rules:elements --> ... <!-- /rules:elements -->
        fn(array $match) => $match[1] . "\n" . block($match[2], $rules[$match[2]]) . "\n" . $match[3],
        $markdown,
    );
}

function block(string $name, array $list): string
{
    return match ($name) {
        'namespacedAttributes'            => table($list),
        'inertNamespaces'                 => "```text\n" . implode("\n", $list) . "\n```",   // one URL per line
        'imageElements', 'dataImageTypes' => implode(', ', array_map(fn(string $item) => "`$item`", $list)),
        default                           => "```text\n" . wrap($list) . "\n```",
    };
}

/** Space-separated names in source order, wrapped at WIDTH. */
function wrap(array $names): string
{
    $lines = [''];
    foreach ($names as $name) {
        $last = array_key_last($lines);
        if ($lines[$last] !== '' && strlen($lines[$last]) + 1 + strlen($name) > WIDTH) {
            $lines[] = $name;
        } else {
            $lines[$last] .= ($lines[$last] === '' ? '' : ' ') . $name;
        }
    }
    return implode("\n", $lines);
}

/** @param array<string, string[]> $byNamespace */
function table(array $byNamespace): string
{
    $rows = [];
    foreach ($byNamespace as $namespace => $names) {
        $rows[] = ["`$namespace`", implode(', ', array_map(fn(string $name) => "`$name`", $names))];
    }
    return rtrim(renderMdTable(['Namespace', 'Attributes'], $rows), "\n");
}

if (realpath($argv[0] ?? '') === __FILE__) {
    require __DIR__ . '/../vendor/autoload.php';
    $check = in_array('--check', $argv, true);
    $stale = 0;
    foreach (PAGES as $page) {
        $path    = __DIR__ . "/../$page";
        $current = file_get_contents($path);
        $fresh   = render($current);
        if ($fresh === $current) {
            continue;
        }
        $stale++;
        if ($check) {
            echo "$page is out of date: run php tools/rules-doc.php\n";
        } else {
            file_put_contents($path, $fresh);
            echo "$page updated\n";
        }
    }
    exit($check && $stale > 0 ? 1 : 0);
}
