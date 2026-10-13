<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which attributes get through: the allowlist, data-* and aria-*, the xml: and
 * xlink: attributes, xmlns declarations, event handlers, and attributes in the
 * inert design-tool namespaces.
 *
 * Codes: event-handler, attribute-not-allowed, xlink-prefix-wrong. Values (href, style, url()) are
 * covered in UrlsTest and CssTest.
 */
class AttributesTest extends SvgSecurityCheckTestCase
{
    private const XLINK = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

    //region Allowlist

    #[DataProvider('allowedAttributeProvider')]
    public function testAllowedAttribute(string $attribute): void
    {
        $value = $attribute === 'href' ? '#a' : 'x';
        $this->assertAccepts($this->svg("<g $attribute=\"$value\"/>"));
    }

    public static function allowedAttributeProvider(): array
    {
        $cases = [];
        foreach (SvgSecurityCheck::rules()['attributes'] as $attribute) {
            $cases[$attribute] = [$attribute];
        }
        return $cases;
    }

    public function testDataAndAriaAttributes(): void
    {
        $this->assertAccepts($this->svg('<rect data-name="Layer 1" data-x-y="1" aria-label="Logo" aria-hidden="true" role="img"/>'));
    }

    #[DataProvider('namespacedAttributeProvider')]
    public function testNamespacedAttributeAccepted(string $attribute): void
    {
        $this->assertAccepts($this->svg("<g $attribute/>", self::XLINK));
    }

    public static function namespacedAttributeProvider(): array
    {
        return [
            'xml:lang'    => ['xml:lang="en"'],
            'xml:space'   => ['xml:space="preserve"'],
            'xlink:href'  => ['xlink:href="#a"'],
            'xlink:title' => ['xlink:title="Logo"'],
        ];
    }

    #[DataProvider('xmlnsDeclarationProvider')]
    public function testXmlnsDeclarationAccepted(string $declaration): void
    {
        $this->assertAccepts($this->svg("<g $declaration/>"));
    }

    public static function xmlnsDeclarationProvider(): array
    {
        return [
            'xlink'                        => ['xmlns:xlink="http://www.w3.org/1999/xlink"'],
            'unused prefix'                => ['xmlns:foo="http://example.com/"'],
            'prefix that starts with on'   => ['xmlns:onx="http://example.com/"'],
            'prefix that is exactly on'    => ['xmlns:on="http://example.com/"'],
            'repeated default declaration' => ['xmlns="http://www.w3.org/2000/svg"'],
        ];
    }

    /** HTML parsers read xlink:href by its spelling, so the xlink prefix may only mean XLink. */
    #[DataProvider('wrongXlinkPrefixProvider')]
    public function testXlinkPrefixBoundElsewhereRejected(string $body, string $rootAttributes): void
    {
        $this->assertRejects($this->svg($body, $rootAttributes), 'xlink-prefix-wrong', 'http://ns.adobe.com/x');
    }

    public static function wrongXlinkPrefixProvider(): array
    {
        $adobe = 'xmlns:xlink="http://ns.adobe.com/x"';
        return [
            'on the root'           => ['<a xlink:href="javascript:alert(1)"><rect/></a>', $adobe],
            'on the element'        => ["<image $adobe xlink:href=\"https://evil.example/a.png\"/>", ''],
            'on a metadata element' => ["<metadata><x:meta xmlns:x=\"http://ns.adobe.com/x\" $adobe/></metadata>", ''],
        ];
    }

    public function testAttributesInInertNamespacesPassOnSvgElements(): void
    {
        $this->assertAccepts($this->svg(
            '<g inkscape:label="Layer 1" inkscape:groupmode="layer" sodipodi:insensitive="true" i:knockout="Off"/>',
            'xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" xmlns:i="http://ns.adobe.com/AdobeIllustrator/10.0/"',
        ));
    }

    //endregion
    //region Denied

    #[DataProvider('deniedAttributeProvider')]
    public function testDeniedAttribute(string $body, string $attribute): void
    {
        $this->assertRejects($this->svg($body, self::XLINK . ' xmlns:ev="http://www.w3.org/2001/xml-events"'), 'attribute-not-allowed', $attribute);
    }

    public static function deniedAttributeProvider(): array
    {
        return [
            'tabindex'               => ['<rect tabindex="0"/>', 'tabindex'],
            'target'                 => ['<a href="#a" target="_blank"/>', 'target'],
            'crossorigin'            => ['<image href="#a" crossorigin="anonymous"/>', 'crossorigin'],
            'cursor'                 => ['<rect cursor="pointer"/>', 'cursor'],
            'src'                    => ['<image src="x.png"/>', 'src'],
            'xml:base'               => ['<g xml:base="http://example.com/"/>', 'xml:base'],
            'xml:id'                 => ['<use href="#a"/><g xml:id="a"/>', 'xml:id'],   // Batik resolves it as an id, the expansion check does not
            'xlink:show'             => ['<a href="#a" xlink:show="new"/>', 'xlink:show'],
            'xlink:actuate'          => ['<a href="#a" xlink:actuate="onLoad"/>', 'xlink:actuate'],
            'xml events'             => ['<rect ev:event="load"/>', 'ev:event'],
            'unknown'                => ['<rect foo="1"/>', 'foo'],
            'wrong case'             => ['<rect Fill="red"/>', 'Fill'],
            'data with underscore'   => ['<rect data_name="x"/>', 'data_name'],
        ];
    }

    //endregion
    //region Event Handlers

    #[DataProvider('eventHandlerProvider')]
    public function testEventHandlerRejected(string $body, string $rootAttributes, string $attribute): void
    {
        $this->assertRejects($this->svg($body, $rootAttributes), 'event-handler', $attribute);
    }

    public static function eventHandlerProvider(): array
    {
        $inkscape = 'xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"';
        return [
            'onload on the root'       => ['', 'onload="alert(1)"', 'onload'],
            'onclick'                  => ['<rect onclick="alert(1)"/>', '', 'onclick'],
            'onmouseover'              => ['<rect onmouseover="alert(1)"/>', '', 'onmouseover'],
            'onbegin on an animation'  => ['<animate attributeName="x" onbegin="alert(1)"/>', '', 'onbegin'],
            'upper case'               => ['<rect ONLOAD="alert(1)"/>', '', 'ONLOAD'],
            'mixed case'               => ['<rect onLoad="alert(1)"/>', '', 'onLoad'],
            'made-up on* name'         => ['<rect onfoo="alert(1)"/>', '', 'onfoo'],
            'in the xlink namespace'   => ['<rect xlink:onload="alert(1)"/>', self::XLINK, 'xlink:onload'],
            'in an inert namespace'    => ['<rect inkscape:onload="alert(1)"/>', $inkscape, 'inkscape:onload'],
            'on an inert element'      => ['<inkscape:thing onload="alert(1)"/>', $inkscape, 'onload'],
        ];
    }

    //endregion
    //region Several Problems on One Element

    public function testEveryBadAttributeIsReported(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<rect onclick="x" tabindex="0" foo="1" fill="red"/>'));
        $this->assertSame(['event-handler', 'attribute-not-allowed', 'attribute-not-allowed'], array_column($result->errors, 'code'));
        $this->assertSame(['onclick', 'tabindex', 'foo'], array_column($result->errors, 'detail'));
    }

    public function testStyleAttributeIsCheckedAsCss(): void
    {
        $this->assertAccepts($this->svg('<rect style="fill:red;stroke:url(#g)"/>'));
        $this->assertRejects($this->svg('<rect style="behavior:url(#x)"/>'), 'css-not-allowed', 'behavior:');
    }

    //endregion
}
