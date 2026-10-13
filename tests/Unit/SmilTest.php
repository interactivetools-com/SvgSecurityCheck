<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SMIL animation: which attributes an animation may target. An animation can rewrite
 * an attribute at runtime, so without this rule <set attributeName="href"
 * to="javascript:..."> would get past the attribute checks. The values it sets get
 * the same checks as any attribute value.
 *
 * Code: animation-target-not-allowed.
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
            'viewBox'                 => ['<animate attributeName="viewBox" values="0 0 10 10;0 0 20 20" dur="1s"/>'],
            'animateTransform'        => ['<rect><animateTransform attributeName="transform" type="rotate" from="0 5 5" to="360 5 5" dur="1s"/></rect>'],
            'animateMotion with path' => ['<rect><animateMotion path="M0,0 L10,10" dur="1s"/></rect>'],
            'animateMotion with mpath' => ['<path id="p" d="M0 0h10"/><rect><animateMotion dur="1s"><mpath href="#p"/></animateMotion></rect>'],
            'animate the path data'   => ['<path d="M0 0h10"><animate attributeName="d" to="M0 0h20" dur="1s"/></path>'],
            'bare url on fill'        => ['<rect><set attributeName="fill" to="https://example.com/a.png"/></rect>'],   // Chrome loads nothing: fill needs url()
            'javascript on fill'      => ['<rect><set attributeName="fill" to="javascript:alert"/></rect>'],            // and runs nothing: only href navigates
        ];
    }

    /** Values get the general rules: url() must be #id and CSS functions must be listed. */
    #[DataProvider('rejectedValueProvider')]
    public function testValueGetsTheGeneralRules(string $body, string $code, string $detail): void
    {
        $this->assertRejects($this->svg($body), $code, $detail);
    }

    public static function rejectedValueProvider(): array
    {
        return [
            'url() to another file' => ['<rect><set attributeName="fill" to="url(https://evil.example/p.svg#a)"/></rect>', 'url-not-fragment', 'to'],
            'image-set()'           => ['<rect><set attributeName="mask" to="image-set(\'https://evil.example/a.png\' 1x)"/></rect>', 'css-not-allowed', 'image-set('],
            'in a values list'      => ['<rect><animate attributeName="fill" values="red;url(//evil.example/p.svg#a)"/></rect>', 'url-not-fragment', 'values'],
        ];
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
            'id'                      => ['<rect id="a"><set attributeName="id" to="b"/></rect>', 'id'],
            'data-*'                  => ['<rect data-name="a"><set attributeName="data-name" to="b"/></rect>', 'data-name'],
            'aria-*'                  => ['<rect><set attributeName="aria-label" to="b"/></rect>', 'aria-label'],
            'not on the allowlist'    => ['<rect><set attributeName="mask-image" to="none"/></rect>', 'mask-image'],
            'case matters'            => ['<rect><set attributeName="Fill" to="red"/></rect>', 'Fill'],
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
}
