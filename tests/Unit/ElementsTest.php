<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which elements get through: the SVG allowlist, the elements left off it on
 * purpose, elements in other XML namespaces, prefixes, and the design-tool
 * namespaces that pass without inspection.
 *
 * Codes: element-not-allowed, namespace-not-allowed, element-prefix-wrong.
 */
class ElementsTest extends SvgSecurityCheckTestCase
{
    //region Allowlist

    #[DataProvider('allowedElementProvider')]
    public function testAllowedElement(string $element): void
    {
        $this->assertAccepts($this->svg("<$element/>"));
    }

    public static function allowedElementProvider(): array
    {
        $cases = [];
        foreach (SvgSecurityCheck::rules()['elements'] as $element) {
            $cases[$element] = [$element];
        }
        return $cases;
    }

    #[DataProvider('deniedElementProvider')]
    public function testDeniedElement(string $element): void
    {
        $this->assertRejects($this->svg("<$element/>"), 'element-not-allowed', $element);
    }

    public static function deniedElementProvider(): array
    {
        $elements = [
            'script', 'foreignObject', 'handler', 'listener',              // run script
            'iframe', 'object', 'embed', 'audio', 'video', 'link', 'meta', // html, loads things
            'font', 'font-face', 'font-face-uri', 'glyph', 'altGlyph',     // svg fonts, removed from browsers
            'tref', 'cursor', 'color-profile',                             // external references
            'marker',                                                      // drawn again at every point of a line
            'Script',                                                      // xml is case-sensitive, so this is not <script> but it is still unknown
            'blink',                                                       // made up: unknown means rejected
        ];
        $cases = [];
        foreach ($elements as $element) {
            $cases[$element] = [$element];
        }
        return $cases;
    }

    public function testRejectedElementReportsOnlyItself(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<script href="data:text/javascript,alert(1)" onload="alert(1)"/>'));
        $this->assertSame(['element-not-allowed'], array_column($result->errors, 'code'));
    }

    public function testChildrenOfARejectedElementAreStillChecked(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<foreignObject><iframe src="data:text/html,x"/></foreignObject>'));
        $this->assertSame(['foreignObject', 'iframe'], array_column($result->errors, 'detail'));
    }

    //endregion
    //region Other Namespaces

    #[DataProvider('foreignNamespaceProvider')]
    public function testForeignNamespaceRejected(string $body, string $namespace): void
    {
        $this->assertRejects($this->svg($body), 'namespace-not-allowed', $namespace);
    }

    public static function foreignNamespaceProvider(): array
    {
        $xhtml = 'http://www.w3.org/1999/xhtml';
        return [
            'xhtml link element'         => ["<h:link xmlns:h=\"$xhtml\" rel=\"stylesheet\" href=\"red-bg.css\"/>", $xhtml],
            'xhtml preconnect'           => ["<h:link xmlns:h=\"$xhtml\" rel=\"preconnect\" href=\"https://evil.example\"/>", $xhtml],
            'xhtml by default namespace' => ["<div xmlns=\"$xhtml\">text</div>", $xhtml],
            'xhtml inside foreignObject' => ["<foreignObject><div xmlns=\"$xhtml\"/></foreignObject>", $xhtml],
            'xinclude'                   => ['<xi:include xmlns:xi="http://www.w3.org/2001/XInclude" href="/etc/passwd" parse="text"/>', 'http://www.w3.org/2001/XInclude'],
            'mathml'                     => ['<m:math xmlns:m="http://www.w3.org/1998/Math/MathML"/>', 'http://www.w3.org/1998/Math/MathML'],
            'xml events'                 => ['<ev:listener xmlns:ev="http://www.w3.org/2001/xml-events" event="load"/>', 'http://www.w3.org/2001/xml-events'],
            'unknown'                    => ['<x:thing xmlns:x="http://example.com/ns"/>', 'http://example.com/ns'],
        ];
    }

    public function testNoNamespaceIsRejectedAsAnUnknownElement(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<g xmlns=""><rect/></g>'));
        $this->assertSame(['element-not-allowed', 'element-not-allowed'], array_column($result->errors, 'code'));
        $this->assertSame(['g', 'rect'], array_column($result->errors, 'detail'));
    }

    public function testUnboundPrefixIsReportedAsWritten(): void
    {
        $this->assertRejects($this->svg('<foo:bar/>'), 'element-not-allowed', 'foo:bar');
    }

    //endregion
    //region Prefixes

    /** An HTML parser ignores xmlns and goes by the name as written, so the prefix has to match what the element is. */
    #[DataProvider('wrongPrefixProvider')]
    public function testWrongPrefixRejected(string $svg, string $detail): void
    {
        $this->assertRejects($svg, 'element-prefix-wrong', $detail);
    }

    public static function wrongPrefixProvider(): array
    {
        $svgNs = self::SVG_NS;
        return [
            'prefixed root'               => ["<svg:svg xmlns:svg=\"$svgNs\"><svg:g><![CDATA[><img src=x onerror=alert(1)>]]></svg:g></svg:svg>", 'svg:svg'],
            'prefixed svg element'        => ["<svg xmlns=\"$svgNs\"><s:rect xmlns:s=\"$svgNs\"/></svg>", 's:rect'],
            'metadata element, no prefix' => ["<svg xmlns=\"$svgNs\"><p xmlns=\"http://ns.adobe.com/x\">text</p></svg>", 'p'],
        ];
    }

    /** Script is rejected in every namespace, even where nothing would run it. */
    #[DataProvider('scriptAnywhereProvider')]
    public function testScriptRejectedInAnyNamespace(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body), 'element-not-allowed', $detail);
    }

    public static function scriptAnywhereProvider(): array
    {
        return [
            'metadata namespace, no prefix' => ['<script xmlns="http://ns.adobe.com/x">alert(1)</script>', 'script'],
            'metadata namespace, prefixed'  => ['<x:script xmlns:x="http://ns.adobe.com/x"/>', 'x:script'],
            'svg namespace, prefixed'       => ['<s:script xmlns:s="http://www.w3.org/2000/svg"/>', 's:script'],
        ];
    }

    //endregion
    //region Inert Namespaces

    /** Every listed namespace, as an element with unknown attributes and a child, passes untouched. */
    #[DataProvider('inertNamespaceProvider')]
    public function testInertNamespaceAccepted(string $namespace): void
    {
        $this->assertAccepts($this->svg("<x:thing xmlns:x=\"$namespace\" x:mode=\"1\" anything=\"2\"><x:child>text</x:child></x:thing>"));
    }

    public static function inertNamespaceProvider(): array
    {
        $cases = [];
        foreach (SvgSecurityCheck::rules()['inertNamespaces'] as $namespace) {
            $cases[$namespace] = [$namespace];
        }
        // matched by prefix: the real namespaces have paths after these roots
        $cases['adobe illustrator'] = ['http://ns.adobe.com/AdobeIllustrator/10.0/'];
        $cases['adobe xmp']         = ['http://ns.adobe.com/xap/1.0/'];
        $cases['dublin core']       = ['http://purl.org/dc/elements/1.1/'];
        return $cases;
    }

    public function testInkscapeMetadataBlock(): void
    {
        $this->assertAccepts($this->svg(<<<'SVG'
            <sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"
                id="namedview1" pagecolor="#ffffff" bordercolor="#666666" inkscape:zoom="1.5" inkscape:current-layer="layer1"/>
            <metadata id="metadata1">
                <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:cc="http://creativecommons.org/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/">
                    <cc:Work rdf:about="">
                        <dc:format>image/svg+xml</dc:format>
                        <dc:type rdf:resource="http://purl.org/dc/dcmitype/StillImage"/>
                        <dc:title>Logo</dc:title>
                    </cc:Work>
                </rdf:RDF>
            </metadata>
            SVG));
    }

    public function testIllustratorPgfBlock(): void
    {
        $this->assertAccepts($this->svg('<i:pgf xmlns:i="http://ns.adobe.com/AdobeIllustrator/10.0/" id="adobe_illustrator_pgf">eJzt/QmA</i:pgf>'));
    }

    public function testEventHandlerOnAnInertElementIsStillRejected(): void
    {
        $this->assertRejects(
            $this->svg('<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" onload="alert(1)"/>'),
            'event-handler',
            'onload',
        );
    }

    /** Served as text/html a metadata element is an unknown SVG element and Chrome applies its style, so its values are checked. */
    #[DataProvider('inertAttributeValueProvider')]
    public function testValuesOnAnInertElementAreChecked(string $attributes, string $code, string $detail): void
    {
        $this->assertRejects($this->svg("<x:thing xmlns:x=\"http://ns.adobe.com/x\" $attributes/>"), $code, $detail);
    }

    public static function inertAttributeValueProvider(): array
    {
        return [
            'style with an outside url' => ['style="background:url(https://evil.example/a.png)"', 'css-not-allowed', 'url(https://evil.example/a.png'],
            'unlisted css function'     => ['style="mask-image:image-set(\'a.png\' 1x)"', 'css-not-allowed', 'image-set('],
            'fill with an outside url'  => ['fill="url(https://evil.example/p.svg#a)"', 'url-not-fragment', 'fill'],
            'href to another file'      => ['href="https://evil.example/"', 'href-not-allowed', 'https://evil.example/'],
        ];
    }

    public function testInertElementAttributeNamesAreNotChecked(): void
    {
        $this->assertAccepts($this->svg('<x:thing xmlns:x="http://ns.adobe.com/x" pagecolor="#ffffff" showgrid="false" style="fill:red"/>'));
    }

    public function testSvgElementsInsideAnInertElementAreStillChecked(): void
    {
        $this->assertRejects(
            $this->svg('<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"><script/></sodipodi:namedview>'),
            'element-not-allowed',
            'script',
        );
    }

    //endregion
}
