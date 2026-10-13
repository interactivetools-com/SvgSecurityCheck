<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SMIL animation: which attributes an animation may target, and what from, to, by
 * and values may contain. An animation can rewrite any attribute at runtime, so
 * without these rules <set attributeName="href" to="javascript:..."> would get past
 * the attribute checks.
 *
 * Codes: animation-target-not-allowed, animation-value-not-allowed.
 */
class SmilTest extends SvgSecurityCheckTestCase
{
    //region Accepted Animations

    #[DataProvider('acceptedAnimationProvider')]
    public function testAcceptedAnimation(string $body): void
    {
        $this->assertAccepts($this->svg($body));
    }

    public static function acceptedAnimationProvider(): array
    {
        return [
            'animate a number'        => ['<circle r="1"><animate attributeName="cx" from="0" to="10" dur="1s" repeatCount="indefinite"/></circle>'],
            'values list'             => ['<circle r="1"><animate attributeName="r" values="1;5;1" keyTimes="0;0.5;1" dur="2s"/></circle>'],
            'color values'            => ['<rect><animate attributeName="fill" from="#fff" to="rgb(0,0,0)" dur="1s"/></rect>'],
            'fragment url value'      => ['<rect><animate attributeName="fill" to="url(#g)" dur="1s"/></rect>'],
            'set'                     => ['<rect><set attributeName="fill" to="red" begin="1s"/></rect>'],
            'animateTransform'        => ['<rect><animateTransform attributeName="transform" type="rotate" from="0 5 5" to="360 5 5" dur="1s"/></rect>'],
            'animateMotion with path' => ['<rect><animateMotion path="M0,0 L10,10" dur="1s"/></rect>'],
            'animateMotion with mpath' => ['<path id="p" d="M0 0h10"/><rect><animateMotion dur="1s"><mpath href="#p"/></animateMotion></rect>'],
            'animate the path data'   => ['<path d="M0 0h10"><animate attributeName="d" to="M0 0h20" dur="1s"/></path>'],
            'time-like value'         => ['<text><animate attributeName="x" values="12:00;13:00" dur="1s"/></text>'],
            'exponent number'         => ['<rect><animate attributeName="width" by="1e3" dur="1s"/></rect>'],
            'spaces around items'     => ['<rect><animate attributeName="width" values="0 ; 1 ; 2" dur="1s"/></rect>'],
            'space is not dropped'    => ['<rect><animate attributeName="fill" to="java script:x" dur="1s"/></rect>'],
            'one leading slash'       => ['<rect><animate attributeName="fill" to="/x" dur="1s"/></rect>'],
            'literal newline'         => ["<rect><animate attributeName=\"fill\" to=\"java\nscript:x\" dur=\"1s\"/></rect>"],   // XML turns it into a space before anyone sees it
        ];
    }

    /** from/to on a shape mean nothing, so only the general url() rule applies to them there. */
    public function testValueAttributesOnANonAnimationElementAreNotChecked(): void
    {
        $this->assertAccepts($this->svg('<rect from="javascript:alert(1)"/>'));
        $this->assertRejects($this->svg('<rect to="url(https://evil.example/p.svg#a)"/>'), 'url-not-fragment', 'to');
    }

    //endregion
    //region Targets

    #[DataProvider('deniedTargetProvider')]
    public function testDeniedTarget(string $body, string $target): void
    {
        $this->assertRejects($this->svg($body), 'animation-target-not-allowed', $target);
    }

    public static function deniedTargetProvider(): array
    {
        return [
            'href'                    => ['<use href="#a"><set attributeName="href" to="#b"/></use>', 'href'],
            'xlink:href'              => ['<use href="#a"><set attributeName="xlink:href" to="#b"/></use>', 'xlink:href'],
            'style'                   => ['<rect><animate attributeName="style" to="fill:red"/></rect>', 'style'],
            'class'                   => ['<rect><set attributeName="class" to="b"/></rect>', 'class'],
            'onload'                  => ['<rect><animate attributeName="onload" to="alert(1)"/></rect>', 'onload'],
            'onclick'                 => ['<rect><set attributeName="onclick" to="alert(1)"/></rect>', 'onclick'],
            'padded with whitespace'  => ['<rect><set attributeName=" href " to="#b"/></rect>', 'href'],
            'on animateTransform'     => ['<rect><animateTransform attributeName="href" to="#b"/></rect>', 'href'],
            'on animateMotion'        => ['<rect><animateMotion attributeName="style" to="x"/></rect>', 'style'],
        ];
    }

    /** Any prefix can be bound to the XLink namespace, so the target is matched by the name after the prefix. */
    #[DataProvider('prefixedTargetProvider')]
    public function testPrefixedTargetIsMatchedByLocalName(string $body, string $target): void
    {
        $this->assertRejects($this->svg($body, 'xmlns:q="http://www.w3.org/1999/xlink"'), 'animation-target-not-allowed', $target);
    }

    public static function prefixedTargetProvider(): array
    {
        return [
            'q:href'                    => ['<image q:href="#a"><set attributeName="q:href" to="//evil.example/p.png"/></image>', 'q:href'],
            'q:style'                   => ['<rect><set attributeName="q:style" to="fill:red"/></rect>', 'q:style'],
            'q:onclick'                 => ['<rect><set attributeName="q:onclick" to="alert(1)"/></rect>', 'q:onclick'],
            'prefix declared on <set>'  => ['<image href="#a"><set xmlns:p="http://www.w3.org/1999/xlink" attributeName="p:href" to="#b"/></image>', 'p:href'],
            'prefix as char reference'  => ['<image href="#a"><set attributeName="&#x71;:href" to="#b"/></image>', 'q:href'],
            'undeclared prefix'         => ['<image href="#a"><set attributeName="zz:href" to="#b"/></image>', 'zz:href'],
        ];
    }

    public function testPrefixedHarmlessTargetIsAccepted(): void
    {
        $this->assertAccepts($this->svg('<rect><animate attributeName="q:fill" to="red"/></rect>', 'xmlns:q="http://www.w3.org/1999/xlink"'));
    }

    //endregion
    //region Values

    #[DataProvider('deniedValueProvider')]
    public function testDeniedValue(string $body, string $attribute): void
    {
        $this->assertRejects($this->svg($body), 'animation-value-not-allowed', $attribute);
    }

    public static function deniedValueProvider(): array
    {
        return [
            'to javascript'           => ['<rect><set attributeName="fill" to="javascript:alert(1)"/></rect>', 'to'],
            'to, upper case, padded'  => ['<rect><set attributeName="fill" to=" JAVASCRIPT:alert(1)"/></rect>', 'to'],
            'from https'              => ['<rect><animate attributeName="fill" from="https://evil.example/" to="red"/></rect>', 'from'],
            'by data'                 => ['<rect><animate attributeName="fill" by="data:text/html,x"/></rect>', 'by'],
            'values, later item'      => ['<rect><animate attributeName="fill" values="red; javascript:alert(1)"/></rect>', 'values'],
            'values, last item'       => ['<rect><animate attributeName="fill" values="red;blue;data:x"/></rect>', 'values'],
            'tab inside the scheme'   => ['<rect><set attributeName="fill" to="java&#x09;script:alert(1)"/></rect>', 'to'],
            'newline inside, as ref'  => ['<rect><set attributeName="fill" to="java&#x0A;script:alert(1)"/></rect>', 'to'],
            'protocol-relative'       => ['<rect><animate attributeName="fill" by="//evil.example/x"/></rect>', 'by'],
            'protocol-relative later' => ['<rect><animate attributeName="fill" values="red; //evil.example/x"/></rect>', 'values'],
        ];
    }

    public function testHrefRewriteReportsBothProblems(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<a href="#a"><set attributeName="href" to="javascript:alert(1)"/></a>'));
        $this->assertSame(['animation-target-not-allowed', 'animation-value-not-allowed'], array_column($result->errors, 'code'));
    }

    //endregion
}
