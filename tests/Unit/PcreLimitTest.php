<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Past pcre.backtrack_limit a regex returns false, and every check reads that false as a
 * rejection, never as "no match". No pattern here gets near the limit on real input (a
 * possessive name run costs one pass), so the test lowers the limit to 2, which is read at
 * match time, until each guard fires. The inputs fire on both the JIT and the interpreter.
 *
 * Codes: not-utf8, css-not-allowed, url-not-fragment, reference-expansion-too-large.
 */
class PcreLimitTest extends SvgSecurityCheckTestCase
{
    #[DataProvider('guardProvider')]
    public function testFalseRejects(string $svg, string $code, string $detail): void
    {
        ini_set('pcre.backtrack_limit', '2');
        try {
            $this->assertRejects($svg, $code, $detail);
        } finally {
            ini_restore('pcre.backtrack_limit');
        }
    }

    public static function guardProvider(): array
    {
        $open = '<svg xmlns="' . self::SVG_NS . '">';
        return [
            'encoding declaration' => ['<?xml version="1.0" encoding="UTF-8"?>' . $open . '</svg>', 'not-utf8', 'declared in a form that could not be read'],
            'css in style'         => [$open . '<style>.a{fill:url(#a)}</style></svg>', 'css-not-allowed', 'text that could not be scanned'],
            'url() in attribute'   => [$open . '<rect fill="url(#a)"/></svg>', 'url-not-fragment', 'fill'],
            'reference counting'   => [$open . '<rect fill="url(#a)"/></svg>', 'reference-expansion-too-large', 'could not be counted'],
        ];
    }
}
