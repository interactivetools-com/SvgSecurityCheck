<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every place a URL can appear in an attribute: href and xlink:href on each kind of
 * element, embedded data: images, SVG nested inside data: images, and url() in
 * attribute values.
 *
 * Codes: href-not-allowed, image-href-not-allowed, embedded-svg-not-allowed,
 * url-not-fragment, reference-expansion-too-large. CSS url() is in CssTest.
 */
class UrlsTest extends SvgSecurityCheckTestCase
{
    private const XLINK = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

    // a 1x1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    //region href: Same-File References

    #[DataProvider('fragmentHrefProvider')]
    public function testFragmentHrefAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body, self::XLINK));
    }

    public static function fragmentHrefProvider(): array
    {
        return [
            'use to a symbol'        => ['<symbol id="icon"><rect width="1" height="1"/></symbol><use href="#icon"/>'],
            'use with xlink:href'    => ['<symbol id="icon"/><use xlink:href="#icon"/>'],
            'a'                      => ['<a href="#top"><rect/></a>'],
            'textPath'               => ['<path id="p" d="M0 0h10"/><text><textPath href="#p">x</textPath></text>'],
            'mpath'                  => ['<path id="m" d="M0 0h10"/><rect><animateMotion dur="1s"><mpath href="#m"/></animateMotion></rect>'],
            'gradient template'      => ['<linearGradient id="a"/><linearGradient id="b" href="#a"/>'],
            'pattern template'       => ['<pattern id="a"/><pattern id="b" xlink:href="#a"/>'],
            'feImage'                => ['<rect id="r"/><filter id="f"><feImage href="#r"/></filter>'],
            'image'                  => ['<rect id="r"/><image href="#r"/>'],
            'animate target'         => ['<rect id="r"/><animate href="#r" attributeName="x" to="1"/>'],
            'surrounding whitespace' => ['<symbol id="icon"/><use href=" #icon "/>'],
        ];
    }

    #[DataProvider('externalHrefProvider')]
    public function testExternalHrefRejected(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body, self::XLINK), 'href-not-allowed', $detail);
    }

    public static function externalHrefProvider(): array
    {
        return [
            'empty'                     => ['<a href=""/>', '(empty)'],
            'only whitespace'           => ['<use href="  "/>', '(empty)'],
            'javascript in a'           => ['<a href="javascript:alert(1)"/>', 'javascript:alert(1)'],
            'javascript in xlink:href'  => ['<a xlink:href="javascript:alert(1)"/>', 'javascript:alert(1)'],
            'upper case scheme'         => ['<a href="JAVASCRIPT:alert(1)"/>', 'JAVASCRIPT:alert(1)'],
            'https in a'                => ['<a href="https://example.com/"/>', 'https://example.com/'],
            'external use'              => ['<use href="https://evil.example/x.svg#a"/>', 'https://evil.example/x.svg#a'],
            'data in use'               => ['<use href="data:image/svg+xml;base64,PHN2Zy8+"/>', 'data:image/svg+xml;base64,PHN2Zy8+'],
            'relative use'              => ['<use href="x.svg#a"/>', 'x.svg#a'],
            'protocol-relative use'     => ['<use href="//evil.example/x.svg#a"/>', '//evil.example/x.svg#a'],
            'external mpath'            => ['<animateMotion><mpath href="https://evil.example/p.svg#m"/></animateMotion>', 'https://evil.example/p.svg#m'],
            'external gradient'         => ['<linearGradient href="https://evil.example/g.svg#g"/>', 'https://evil.example/g.svg#g'],
            'trimmed before reporting'  => ['<use href="  javascript:alert(1)  "/>', 'javascript:alert(1)'],
            'long value cut at 60'      => ['<use href="' . str_repeat('a', 70) . '"/>', str_repeat('a', 60) . '...'],
        ];
    }

    //endregion
    //region href: Embedded Images

    #[DataProvider('embeddedImageProvider')]
    public function testEmbeddedImageAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body, self::XLINK));
    }

    public static function embeddedImageProvider(): array
    {
        $png = self::PNG;
        return [
            'png'                => ["<image href=\"data:image/png;base64,$png\"/>"],
            'jpeg'               => ['<image href="data:image/jpeg;base64,/9j/4AAQ"/>'],
            'jpg'                => ['<image href="data:image/jpg;base64,/9j/4AAQ"/>'],
            'gif'                => ['<image href="data:image/gif;base64,R0lGODlh"/>'],
            'webp'               => ['<image href="data:image/webp;base64,UklGRg=="/>'],
            'upper case'         => ["<image href=\"DATA:IMAGE/PNG;BASE64,$png\"/>"],
            'xlink:href'         => ["<image xlink:href=\"data:image/png;base64,$png\"/>"],
            'feImage'            => ["<filter id=\"f\"><feImage href=\"data:image/png;base64,$png\"/></filter>"],
            'line-wrapped base64' => ['<image href="data:image/png;base64,' . chunk_split($png, 20, "\n") . '"/>'],
        ];
    }

    #[DataProvider('externalImageProvider')]
    public function testExternalImageRejected(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body), 'image-href-not-allowed', $detail);
    }

    public static function externalImageProvider(): array
    {
        return [
            'https'                   => ['<image href="https://evil.example/x.png"/>', 'https://evil.example/x.png'],
            'same-origin absolute'    => ['<image href="/images/x.png"/>', '/images/x.png'],
            'relative'                => ['<image href="x.png"/>', 'x.png'],
            'javascript'              => ['<image href="javascript:alert(1)"/>', 'javascript:alert(1)'],
            'data html'               => ['<image href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"/>', 'data:text/html,<script>alert(1)</script>'],
            'svg not base64'          => ['<image href="data:image/svg+xml;utf8,&lt;svg/&gt;"/>', 'data:image/svg+xml;utf8,<svg/>'],
            'svg url-encoded'         => ['<image href="data:image/svg+xml,%3Csvg%2F%3E"/>', 'data:image/svg+xml,%3Csvg%2F%3E'],
            'png not base64'          => ['<image href="data:image/png,AAAA"/>', 'data:image/png,AAAA'],
            'unlisted image type'     => ['<image href="data:image/bmp;base64,AAAA"/>', 'data:image/bmp;base64,AAAA'],
            'feImage https'           => ['<filter id="f"><feImage href="https://evil.example/x.png"/></filter>', 'https://evil.example/x.png'],
        ];
    }

    //endregion
    //region href: Embedded SVG

    /** Wraps $inner as a data: image inside a new outer SVG. */
    private function embed(string $inner): string
    {
        return $this->svg('<image href="data:image/svg+xml;base64,' . base64_encode($inner) . '"/>');
    }

    public function testEmbeddedSvgAccepted(): void
    {
        $this->assertAccepts($this->embed($this->svg('<rect width="1" height="1"/>')));
        $this->assertAccepts($this->embed($this->embed($this->svg())));
        $this->assertAccepts($this->embed($this->embed($this->embed($this->svg()))));   // three levels is the limit
    }

    public function testEmbeddedSvgNestedTooDeep(): void
    {
        $violation = $this->assertRejects($this->embed($this->embed($this->embed($this->embed($this->svg())))), 'embedded-svg-not-allowed');
        $this->assertStringContainsString('nested more than 3 levels deep', $violation->detail);
    }

    public function testLineWrappedEmbeddedSvg(): void
    {
        $this->assertAccepts($this->svg('<image href="data:image/svg+xml;base64,' . chunk_split(base64_encode($this->svg()), 16, "\n") . '"/>'));
    }

    /** The inner file gets the full check, and its message becomes the outer detail. */
    #[DataProvider('badEmbeddedSvgProvider')]
    public function testBadEmbeddedSvgRejected(string $inner, string $detail): void
    {
        $this->assertRejects($this->embed($inner), 'embedded-svg-not-allowed', $detail);
    }

    public static function badEmbeddedSvgProvider(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg"';
        return [
            'script inside'        => ["$open><script/></svg>", '<script> is not allowed in uploaded SVGs'],
            'event handler inside' => ["$open onload=\"alert(1)\"/>", 'onload= event handler attributes are not allowed'],
            'external image inside' => ["$open><image href=\"https://evil.example/x.png\"/></svg>", 'Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not https://evil.example/x.png'],
            'not an svg at all'    => ['hello', 'This is not an SVG file: it starts with hello'],
        ];
    }

    /** A gzipped SVG (svgz) declared as image/svg+xml: the bytes start with the gzip magic number, not with <. */
    public function testGzippedEmbeddedSvg(): void
    {
        $violation = $this->assertRejects($this->embed(gzencode($this->svg())), 'embedded-svg-not-allowed');
        $this->assertStringStartsWith('This is not an SVG file: it starts with \\037\\213', $violation->detail);
    }

    /** libxml keeps one error buffer per process, and the inner parse clears it; the outer file's diagnostic must survive that. */
    public function testOuterMalformedXmlSurvivesAnEmbeddedSvg(): void
    {
        $image = '<image width="1" height="1" href="data:image/svg+xml;base64,' . base64_encode($this->svg()) . '"/>';
        $this->assertRejects($this->svg("<g/>$image", 'xmlns:a="not a uri"'), 'malformed-xml', "xmlns:a: 'not a uri' is not a valid URI (line 1)");
        $this->assertRejects($this->svg("$image<g xmlns:a=\"not a uri\"/>"), 'malformed-xml', "xmlns:a: 'not a uri' is not a valid URI (line 1)");
        $this->assertRejects($this->svg("<g/>$image", 'xml:id="1bad"'), 'malformed-xml', 'xml:id : attribute value 1bad is not an NCName (line 1)');
    }

    public function testEmbeddedSvgWithBrokenBase64(): void
    {
        $this->assertRejects($this->svg('<image href="data:image/svg+xml;base64,!!!"/>'), 'embedded-svg-not-allowed', 'the data: URL is not valid base64');
    }

    //endregion
    //region url() in Attribute Values

    #[DataProvider('fragmentUrlProvider')]
    public function testFragmentUrlAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body));
    }

    public static function fragmentUrlProvider(): array
    {
        return [
            'fill gradient'      => ['<linearGradient id="grad"/><rect fill="url(#grad)"/>'],
            'filter'             => ['<filter id="f"/><rect filter="url(#f)"/>'],
            'spaces inside'      => ['<rect fill="url( #g )"/>'],
            'newline inside'     => ["<rect fill=\"url(\n#g)\"/>"],
            'single quotes'      => ['<rect fill="url(\'#g\')"/>'],
            'double quotes'      => ['<rect fill="url(&quot;#g&quot;)"/>'],
            'quotes and spaces'  => ['<rect fill="url( &quot;#g&quot; )"/>'],
            'with fallback'      => ['<rect fill="url(#g) red"/>'],
            'mask'               => ['<rect mask="url(#m)"/>'],
            'clip-path'          => ['<rect clip-path="url(#c)"/>'],
            'marker-start'       => ['<path d="M0 0" marker-start="url(#m)"/>'],
            'plain colors'       => ['<rect fill="none" stroke="rgb(1,2,3)"/>'],
        ];
    }

    #[DataProvider('externalUrlProvider')]
    public function testExternalUrlRejected(string $body, string $attribute): void
    {
        $this->assertRejects($this->svg($body), 'url-not-fragment', $attribute);
    }

    public static function externalUrlProvider(): array
    {
        return [
            'https'                       => ['<rect fill="url(https://evil.example/p.svg#a)"/>', 'fill'],
            'upper case'                  => ['<rect fill="URL(https://evil.example/p.svg#a)"/>', 'fill'],
            'quoted'                      => ['<rect fill="url( \'https://evil.example/p.svg#a\' )"/>', 'fill'],
            'newline inside'              => ["<rect fill=\"url(\nhttps://evil.example/p.svg#a)\"/>", 'fill'],
            'data'                        => ['<rect filter="url(data:image/svg+xml;base64,AAAA)"/>', 'filter'],
            'relative'                    => ['<rect stroke="url(p.svg#a)"/>', 'stroke'],
            'clip-path'                   => ['<rect clip-path="url(https://evil.example/c.svg#c)"/>', 'clip-path'],
            'mask'                        => ['<rect mask="url(https://evil.example/m.svg#m)"/>', 'mask'],
            'marker-end'                  => ['<path d="M0 0" marker-end="url(https://evil.example/m.svg#m)"/>', 'marker-end'],
            'any attribute, here animate' => ['<animate attributeName="fill" to="url(https://evil.example/p.svg#a)"/>', 'to'],
        ];
    }

    /** Presentation attributes take CSS escapes, so u\72l( is url( to a browser; any backslash rejects before the url( scan. */
    #[DataProvider('cssEscapedUrlProvider')]
    public function testCssEscapedUrlRejected(string $body): void
    {
        $this->assertRejects($this->svg($body), 'css-not-allowed', '\\');
    }

    public static function cssEscapedUrlProvider(): array
    {
        return [
            'u\72l'            => ['<rect fill="u\72l(https://evil.example/p.svg#a)"/>'],
            'first letter'     => ['<rect clip-path="\75rl(https://evil.example/c.svg#c)"/>'],
            'six-digit hex'    => ['<rect stroke="\000072l(https://evil.example/p.svg#a)"/>'],
            'filter'           => ['<rect filter="u\72l(https://evil.example/f.svg#f)"/>'],
            'mask'             => ['<rect mask="u\72l(https://evil.example/m.svg#m)"/>'],
            'same-file target' => ['<rect fill="u\72l(#g)"/>'],
            'font-family'      => ['<text font-family="\5fae\8f6f">x</text>'],
        ];
    }

    public function testBackslashInDataAndAriaAttributesAccepted(): void
    {
        $this->assertAccepts($this->svg('<rect data-name="C:\Users\dave\logo.ai" aria-label="a\b"/>'));
    }

    public function testEscapedUrlCannotHideAPatternBomb(): void
    {
        $bomb = str_replace('fill="url(#', 'fill="u\72l(#', $this->patternChain(10, 5));
        $this->assertRejects($bomb, 'css-not-allowed', '\\');
    }

    /** The other banned CSS tokens reject in attributes too: Chrome applies mask="image-set(...)" and fetches the image. */
    #[DataProvider('cssTokenInAttributeProvider')]
    public function testCssTokenInAttributeRejected(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body), 'css-not-allowed', $detail);
    }

    public static function cssTokenInAttributeProvider(): array
    {
        return [
            'image-set() in mask'   => ['<rect mask="image-set(\'https://evil.example/m.png\' 1x)"/>', 'image-set('],
            'image() in fill'       => ['<rect fill="image(https://evil.example/p.png)"/>', 'image('],
            'src() in mask'         => ['<rect mask="src(\'https://evil.example/m.png\')"/>', 'src('],
            'expression()'          => ['<rect width="expression(alert(1))"/>', 'expression('],
            'behavior'              => ['<rect fill="behavior:url(#x)"/>', 'behavior:'],
            'behavior, long space'  => ['<rect fill="behavior' . str_repeat(' ', 100) . ':url(#x)"/>', 'behavior' . str_repeat(' ', 52) . '...'],
            'in an animation value' => ['<animate attributeName="mask" to="image-set(\'https://evil.example/m.png\' 1x)"/>', 'image-set('],
        ];
    }

    public function testCssTokensInDataAndAriaAttributesAccepted(): void
    {
        $this->assertAccepts($this->svg('<rect data-src="image-set(x.png 1x)" aria-label="behavior: none"/>'));
    }

    //endregion
    //region Reference Expansion

    /** $fanOut <use> elements per level, $levels deep: the last level renders $fanOut ** $levels copies of the leaf. */
    private function useTree(int $fanOut, int $levels): string
    {
        $body = '<circle id="l0" r="1"/>';
        for ($level = 1; $level <= $levels; $level++) {
            $body .= "<g id=\"l$level\">" . str_repeat('<use href="#l' . ($level - 1) . '"/>', $fanOut) . '</g>';
        }
        return $this->svg("<defs>$body</defs><use href=\"#l$levels\"/>");
    }

    /** $fanOut rects per pattern, each filled with the pattern below it, $levels deep. */
    private function patternChain(int $fanOut, int $levels): string
    {
        $body = '<pattern id="p0" width="1" height="1"><rect width="1" height="1"/></pattern>';
        for ($level = 1; $level <= $levels; $level++) {
            $body .= "<pattern id=\"p$level\" width=\"1\" height=\"1\">" . str_repeat('<rect width="1" height="1" fill="url(#p' . ($level - 1) . ')"/>', $fanOut) . '</pattern>';
        }
        return $this->svg("<defs>$body</defs><rect width=\"1\" height=\"1\" fill=\"url(#p$levels)\"/>");
    }

    #[DataProvider('acceptedReferenceProvider')]
    public function testReferenceExpansionAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body, self::XLINK));
    }

    public static function acceptedReferenceProvider(): array
    {
        return [
            'pattern filled a hundred times' => ['<pattern id="p" width="1" height="1"><rect width="1" height="1"/></pattern>' . str_repeat('<rect fill="url(#p)"/>', 100)],
            'gradient template chain'        => ['<linearGradient id="a"><stop/></linearGradient><linearGradient id="b" href="#a"/><rect fill="url(#b)"/>'],
            'marker inside another marker'   => ['<marker id="m1"><path marker-start="url(#m2)"/></marker><marker id="m2"><circle r="1"/></marker><path marker-start="url(#m1)"/>'],
            'reference in a style attribute' => ['<pattern id="p"/><rect style="fill:url(#p)"/>'],
            'animation of an ancestor'       => ['<g id="g"><animate href="#g" attributeName="opacity" to="0"/></g>'],   // animating does not render the target
            'link to an ancestor'            => ['<g id="top"><a href="#top"><rect/></a></g>'],
            'one use'               => ['<symbol id="s"><circle r="1"/></symbol><use href="#s"/>'],
            'many uses of one'      => ['<symbol id="s"><circle r="1"/></symbol>' . str_repeat('<use href="#s"/>', 500)],
            'nested a few levels'   => ['<circle id="a" r="1"/><g id="b"><use href="#a"/><use href="#a"/></g><g id="c"><use href="#b"/><use href="#b"/></g><use href="#c"/>'],
            'target defined later'  => ['<use href="#s"/><symbol id="s"><circle r="1"/></symbol>'],
            'unknown target'        => ['<use href="#nothing"/>'],
            'encoded target'        => ['<circle id="a" r="1"/><use href="#%61"/>'],
            'bad percent escape'    => ['<use href="#%zz"/>'],
            'xlink:href'            => ['<circle id="a" r="1"/><use xlink:href="#a"/>'],
            'loop in defs, nothing references it' => ['<defs><g id="a"><use href="#a"/></g></defs>'],   // never rendered, so never expanded, same as in a browser
            'bomb in a symbol, never used'        => ['<symbol id="s"><g id="l1">' . str_repeat('<use href="#l0"/>', 100) . '</g><g id="l2">' . str_repeat('<use href="#l1"/>', 100) . '</g><g id="l3">' . str_repeat('<use href="#l2"/>', 100) . '</g></symbol><circle id="l0" r="1"/>'],
        ];
    }

    public function testTenThousandCopiesIsFine(): void
    {
        $this->assertAccepts($this->useTree(10, 4));   // 10^4 leaves, plus the groups
    }

    public function testAHundredThousandCopiesIsTooMany(): void
    {
        $violation = $this->assertRejects($this->useTree(10, 5), 'reference-expansion-too-large');
        $this->assertSame('expand to more than 100,000 elements', $violation->detail);
    }

    public function testBinaryTreeBomb(): void
    {
        $this->assertRejects($this->useTree(2, 40), 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    /** The loop is reported from the first <use> in the file that reaches it. */
    public function testLoop(): void
    {
        $violation = $this->assertRejects(
            $this->svg('<g id="ping"><use href="#pong"/></g><g id="pong"><use href="#ping"/></g><use href="#ping"/>'),
            'reference-expansion-too-large',
        );
        $this->assertSame('form a loop (#pong -> #ping -> #pong)', $violation->detail);
    }

    public function testSelfReference(): void
    {
        $this->assertRejects($this->svg('<defs><g id="a"><use href="#a"/></g></defs><use href="#a"/>'), 'reference-expansion-too-large', 'form a loop (#a -> #a)');
    }

    /** Browsers percent-decode a fragment before the id lookup, so #%6C5 renders id="l5" and has to count as it. */
    public function testEncodedFragmentCannotHideABomb(): void
    {
        $detail = 'expand to more than 100,000 elements';
        $this->assertRejects(str_replace('href="#l', 'href="#%6C', $this->useTree(10, 5)), 'reference-expansion-too-large', $detail);
        $this->assertRejects(str_replace('fill="url(#p', 'fill="url(#%70', $this->patternChain(10, 5)), 'reference-expansion-too-large', $detail);
        $this->assertRejects(str_replace('fill="url(#p', 'style="fill:url(#%70', $this->patternChain(10, 5)), 'reference-expansion-too-large', $detail);
    }

    /**
     * A quoted id runs to its closing quote, and a browser's URL parser drops tabs and
     * trailing spaces, so url('#p)5') renders id="p)5" and #p&#9;5 renders id="p5".
     */
    #[DataProvider('quotedFragmentProvider')]
    public function testQuotedFragmentCannotHideABomb(string $idPrefix, string $urlFormat): void
    {
        $svg = $this->patternChain(10, 5);
        for ($level = 0; $level <= 5; $level++) {
            $svg = str_replace(["id=\"p$level\"", "url(#p$level)"], ["id=\"$idPrefix$level\"", sprintf($urlFormat, $level)], $svg);
        }
        $this->assertRejects($svg, 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    public static function quotedFragmentProvider(): array
    {
        return [
            'single quotes around a )'  => ['p)', "url('#p)%d')"],
            'double quotes around a )'  => ['p)', 'url(&quot;#p)%d&quot;)'],
            'double quotes around a \'' => ["p'", "url(&quot;#p'%d&quot;)"],
            'trailing space'            => ['p', "url('#p%d ')"],
            'tab inside the id'         => ['p', "url('#p&#9;%d')"],
        ];
    }

    public function testTabInsideAnHrefCannotHideABomb(): void
    {
        $this->assertRejects(str_replace('href="#l', 'href="#l&#9;', $this->useTree(10, 5)), 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    public function testEncodedFragmentLoop(): void
    {
        $this->assertRejects($this->svg('<g id="a"><use href="#%62"/></g><g id="b"><use href="#a"/></g><use href="#a"/>'), 'reference-expansion-too-large', 'form a loop (#b -> #a -> #b)');
    }

    public function testBombInDefsReferencedOnce(): void
    {
        $this->assertRejects($this->svg('<defs><circle id="l0" r="1"/><g id="l1">' . str_repeat('<use href="#l0"/>', 400) . '</g><g id="l2">' . str_repeat('<use href="#l1"/>', 400) . '</g></defs><use href="#l2"/>'), 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    public function testPatternChainOfFourLevels(): void
    {
        $this->assertAccepts($this->patternChain(10, 4));   // about 32,000 rects
    }

    public function testPatternBomb(): void
    {
        $this->assertRejects($this->patternChain(10, 5), 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    /** $references <use> elements, each to its own missing id, inside $nestedIds groups that all have an id. */
    private function nestedReferences(int $nestedIds, int $references, string $target = 'missing'): string
    {
        $body = '';
        for ($i = 0; $i < $nestedIds; $i++) {
            $body .= "<g id=\"n$i\">";
        }
        for ($i = 0; $i < $references; $i++) {
            $body .= $target === 'missing' ? "<use href=\"#m$i\"/>" : "<use href=\"#$target\"/>";
        }
        return $this->svg($body . str_repeat('</g>', $nestedIds));
    }

    /** The same target inside the same id is one entry, so repeating a reference costs nothing however deep it sits. */
    public function testRepeatedReferenceInsideNestedIdsStaysSmall(): void
    {
        $svg  = $this->nestedReferences(200, 40000, 'dot');
        $peak = memory_get_peak_usage();
        $this->assertAccepts($svg);
        $this->assertLessThan($peak + 16 * 1024 * 1024, memory_get_peak_usage(), 'checking the file added more than 16 MB');
    }

    public function testDistinctReferencesInsideNestedIdsAreCapped(): void
    {
        $this->assertAccepts($this->nestedReferences(100, 980));   // 98,000 pairs + 980 targets + 100 ids
        $this->assertRejects($this->nestedReferences(100, 990), 'reference-expansion-too-large', 'need more than 100,000 records to track (ids and references)');
    }

    /** Every id is a record, so a file cannot grow the check's memory with ids alone. */
    public function testAHundredThousandIdsIsTooMany(): void
    {
        $ids = fn(int $count) => implode('', array_map(fn(int $i) => "<g id=\"i$i\"/>", range(1, $count)));
        $this->assertAccepts($this->svg($ids(100000)));
        $this->assertRejects($this->svg($ids(100001)), 'reference-expansion-too-large', 'need more than 100,000 records to track (ids and references)');
    }

    /** Each distinct target of a visible element is a record too, even when nothing defines it. */
    public function testAHundredThousandDistinctTargetsIsTooMany(): void
    {
        $uses = fn(int $count) => implode('', array_map(fn(int $i) => "<use href=\"#t$i\"/>", range(1, $count)));
        $this->assertAccepts($this->svg($uses(100000)));
        $this->assertRejects($this->svg($uses(100001)), 'reference-expansion-too-large', 'need more than 100,000 records to track (ids and references)');
    }

    /** Every url() on one element is collected, however many attributes carry one. */
    public function testThousandsOfUrlAttributesOnOneElement(): void
    {
        $pattern = '<pattern id="p" width="1" height="1">' . str_repeat('<rect width="1" height="1"/>', 50) . '</pattern>';
        $fills   = fn(int $count) => implode(' ', array_map(fn(int $i) => "data-$i=\"url(#p)\"", range(1, $count)));
        $this->assertAccepts($this->svg("$pattern<rect {$fills(1900)}/>"));   // 1,900 x 51 = 96,900 elements
        $this->assertRejects($this->svg("$pattern<rect {$fills(2000)}/>"), 'reference-expansion-too-large', 'expand to more than 100,000 elements');
    }

    /** Numeric ids come back from PHP array keys as ints; the expansion walk must still treat them as ids. */
    public function testNumericIds(): void
    {
        $this->assertAccepts($this->svg('<circle id="2" r="1"/><g id="1"><use href="#2"/></g><use href="#1"/>'));
        $this->assertRejects($this->svg('<g id="1"><use href="#2"/></g><g id="2"><use href="#1"/></g><use href="#1"/>'), 'reference-expansion-too-large', 'form a loop (#2 -> #1 -> #2)');
    }

    #[DataProvider('referenceLoopProvider')]
    public function testReferenceLoop(string $body, string $loop): void
    {
        $this->assertRejects($this->svg($body, self::XLINK), 'reference-expansion-too-large', "form a loop ($loop)");
    }

    public static function referenceLoopProvider(): array
    {
        return [
            'marker on itself'          => ['<path id="a" d="M0 0" marker-start="url(#a)"/>', '#a -> #a'],
            'marker containing itself'  => ['<marker id="m"><path marker-start="url(#m)"/></marker><path marker-end="url(#m)"/>', '#m -> #m'],
            'mask on itself'            => ['<mask id="m" mask="url(#m)"/><rect mask="url(#m)"/>', '#m -> #m'],
            'two clip paths'            => ['<clipPath id="a"><rect clip-path="url(#b)"/></clipPath><clipPath id="b"><rect clip-path="url(#a)"/></clipPath><rect clip-path="url(#a)"/>', '#a -> #b -> #a'],
            'filter through feImage'    => ['<filter id="f"><feImage href="#r"/></filter><rect id="r" filter="url(#f)"/>', '#f -> #r -> #f'],
            'gradient templates'        => ['<defs><linearGradient id="a" href="#b"/><linearGradient id="b" xlink:href="#a"/></defs><rect fill="url(#a)"/>', '#a -> #b -> #a'],
            'pattern template itself'   => ['<defs><pattern id="p" href="#p"/></defs><rect fill="url(#p)"/>', '#p -> #p'],
            'through a style attribute' => ['<defs><pattern id="p"><rect style="fill: url(#p)"/></pattern></defs><rect style="fill:url(#p)"/>', '#p -> #p'],
            'use inside a pattern'      => ['<defs><pattern id="p"><use href="#p"/></pattern></defs><rect fill="url(#p)"/>', '#p -> #p'],
            'quoted id with a )'        => ['<defs><pattern id="p)"><rect fill="url(\'#p)\')"/></pattern></defs><rect fill="url(\'#p)\')"/>', '#p) -> #p)'],
        ];
    }

    public function testChainLongerThanTheCallStack(): void
    {
        $body = '<circle id="l0" r="1"/>';
        for ($level = 1; $level <= 2000; $level++) {
            $body .= "<g id=\"l$level\"><use href=\"#l" . ($level - 1) . '"/></g>';
        }
        $this->assertAccepts($this->svg("<defs>$body</defs><use href=\"#l2000\"/>"));   // 4001 elements, one copy each
    }

    //endregion
}
