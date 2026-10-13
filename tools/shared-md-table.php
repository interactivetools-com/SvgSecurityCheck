<?php
declare(strict_types=1);

/**
 * Markdown table rendering. Source: programming/shared-md-table.php in the
 * docs repo. Make any changes there, then copy the file to every repo that
 * uses it. Runs under php -n: no composer, no mbstring.
 */

/**
 * Renders a markdown table with every column padded to its widest cell, so a
 * committed file reads as a table in a plain editor and not just after a
 * renderer gets hold of it.
 *
 *     echo renderMdTable(['php', 'tier'], [['8.1', 'instruction'], ['8.5', 'frameless']]);
 *
 *     | php | tier        |
 *     |-----|-------------|
 *     | 8.1 | instruction |
 *     | 8.5 | frameless   |
 *
 * Rows may be lists or keyed arrays; only the order matters, and a short row is
 * padded with empty cells rather than throwing off the columns after it.
 *
 * @param  string[]   $headers
 * @param  array<int, array<int|string, string|int|float>> $rows
 */
function renderMdTable(array $headers, array $rows): string
{
    $headers = array_values($headers);
    $matrix  = [];
    foreach ($rows as $row) {
        $cells    = array_map(mdCell(...), array_values($row));
        $matrix[] = array_pad($cells, count($headers), '');
    }

    $widths = array_map(charWidth(...), $headers);
    foreach ($matrix as $cells) {
        foreach ($cells as $i => $text) {
            $widths[$i] = max($widths[$i] ?? 0, charWidth($text));
        }
    }

    $line = static function (array $cells) use ($widths): string {
        $out = '|';
        foreach ($cells as $i => $text) {
            $out .= ' ' . $text . str_repeat(' ', $widths[$i] - charWidth($text)) . ' |';
        }
        return $out . "\n";
    };

    $out = $line($headers);
    $out .= '|' . implode('|', array_map(static fn(int $w): string => str_repeat('-', $w + 2), $widths)) . "|\n";
    foreach ($matrix as $cells) {
        $out .= $line($cells);
    }
    return $out;
}

/** Character count without mbstring: bytes minus UTF-8 continuation bytes */
function charWidth(string $s): int
{
    return strlen($s) - preg_match_all('/[\x80-\xBF]/', $s);
}

/** One table cell as text, with pipes escaped so a value can never split a column. */
function mdCell(string|int|float $value): string
{
    return str_replace('|', '\|', (string)$value);
}
