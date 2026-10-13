<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\Result;
use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use Itools\SvgSecurityCheck\Violation;

/**
 * The shape of what comes back: Result, Violation and its templates, how the error
 * list is deduplicated, ordered and capped, and what rules() returns.
 */
use PHPUnit\Framework\Attributes\DataProvider;

class ResultTest extends SvgSecurityCheckTestCase
{
    //region Result

    public function testOkMeansNoErrors(): void
    {
        $result = new Result([]);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function testAnyErrorMeansNotOk(): void
    {
        $result = new Result([new Violation('element-not-allowed', 'script')]);
        $this->assertFalse($result->ok);
        $this->assertCount(1, $result->errors);
    }

    public function testAcceptedSvgGivesAnEmptyList(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<rect width="1" height="1"/>'));
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    //endregion
    //region Violation

    public function testFields(): void
    {
        $violation = new Violation('element-not-allowed', 'foreignObject');
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('foreignObject', $violation->detail);
        $this->assertSame('<%s> is not allowed in uploaded SVGs', $violation->template);
        $this->assertSame('<foreignObject> is not allowed in uploaded SVGs', $violation->message);
    }

    /** Every template takes exactly one %s and nothing else printf would interpret, so sprintf(t($template), $detail) always works. */
    public function testEveryTemplateHasOnePlaceholder(): void
    {
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertSame(1, substr_count($template, '%s'), "$code has one %s");
            $this->assertDoesNotMatchRegularExpression('/%(?!s)/', $template, "$code has no other % directive");
        }
    }

    public function testMessageIsTemplateWithDetail(): void
    {
        $violation = $this->assertRejects($this->svg('<use href="https://evil.example/x.svg#a"/>'), 'href-not-allowed');
        $this->assertSame(sprintf($violation->template, $violation->detail), $violation->message);
    }

    /** The recipe from the class docblock: translate the template, then put the encoded detail back in. */
    public function testTranslationRecipe(): void
    {
        $translations = [Violation::TEMPLATES['element-not-allowed'] => '<%s> ist in hochgeladenen SVGs nicht erlaubt'];
        $violation    = $this->assertRejects($this->svg('<script/>'), 'element-not-allowed');
        $this->assertSame('<script> ist in hochgeladenen SVGs nicht erlaubt', sprintf($translations[$violation->template], htmlspecialchars($violation->detail)));
    }

    /** detail and message carry the file's text as-is; callers HTML-encode before output. */
    public function testDetailIsNotEncoded(): void
    {
        $violation = $this->assertRejects('a<b>c', 'not-svg');
        $this->assertSame('a<b>c', $violation->detail);
        $this->assertSame('This is not an SVG file: it starts with a<b>c', $violation->message);
    }

    /** Whatever the file held, a detail is one line of valid UTF-8, so a log line or an error page can show it as is. */
    #[DataProvider('controlCharacterProvider')]
    public function testDetailIsOneLineOfValidUtf8(string $svg, string $code, string $detail): void
    {
        $this->assertRejects($svg, $code, $detail);
    }

    public static function controlCharacterProvider(): array
    {
        $svg = 'xmlns="http://www.w3.org/2000/svg"';
        return [
            'newline in an href'            => ["<svg $svg><use href=\"a&#10;b\"/></svg>", 'href-not-allowed', 'a\nb'],
            'newline inside a CSS token'    => ["<svg $svg><style>behavior\n:x</style></svg>", 'css-not-allowed', 'behavior\n:'],
            'tab and CR in a CSS url'       => ["<svg $svg><style>a{fill:url(https://x\t\ry)}</style></svg>", 'css-not-allowed', 'url(https://x\t\ny'],   // XML turns CR into LF
            'invalid UTF-8 in the encoding' => ["<?xml version=\"1.0\" encoding=\"caf\xE9\"?><svg $svg/>", 'not-utf8', 'declared as caf\351'],
        ];
    }

    /** Every detail taken from the file is cut at 60 characters, whichever rule reports it; fixed words around it stay. */
    #[DataProvider('longDetailProvider')]
    public function testDetailFromTheFileIsCutAtSixty(string $svg, string $code, string $endsWith): void
    {
        $result = SvgSecurityCheck::checkString($svg);
        foreach ($result->errors as $violation) {
            if ($violation->code === $code) {
                $this->assertStringNotContainsString(str_repeat('a', 61), $violation->detail, $violation->detail);
                $this->assertStringEndsWith($endsWith, $violation->detail);
                return;
            }
        }
        $this->fail("no $code in: " . implode(' | ', array_column($result->errors, 'message')));
    }

    public static function longDetailProvider(): array
    {
        $long = str_repeat('a', 80);
        $svg  = 'xmlns="http://www.w3.org/2000/svg"';
        return [
            'element name'       => ["<svg $svg><$long/></svg>", 'element-not-allowed', '...'],
            'unbound prefix'     => ["<svg $svg><$long:g/></svg>", 'element-not-allowed', '...'],
            'namespace URI'      => ["<svg $svg><x:g xmlns:x=\"http://x.example/$long\"/></svg>", 'namespace-not-allowed', '...'],
            'attribute name'     => ["<svg $svg><rect $long=\"1\"/></svg>", 'attribute-not-allowed', '...'],
            'event handler'      => ["<svg $svg><rect on$long=\"1\"/></svg>", 'event-handler', '...'],
            'url() attribute'    => ["<svg $svg><rect data-$long=\"url(https://x.example/)\"/></svg>", 'url-not-fragment', '...'],
            'animation target'   => ["<svg $svg><set attributeName=\"on$long\"/></svg>", 'animation-target-not-allowed', '...'],
            'root element'       => ["<$long $svg/>", 'root-not-svg', '...'],
            'root namespace'     => ["<svg xmlns=\"http://x.example/$long\"/>", 'root-namespace-wrong', '..."'],
            'processing instr.'  => ["<svg $svg><?$long x?></svg>", 'processing-instruction', '...'],
            'encoding name'      => ["<?xml version=\"1.0\" encoding=\"$long\"?><svg $svg/>", 'not-utf8', '...'],
            'libxml message'     => ["<svg $svg xmlns:a=\"not a uri $long\"/>", 'malformed-xml', '... (line 1)'],
            'reference loop'     => ["<svg $svg><g id=\"$long\"><use href=\"#b$long\"/></g><g id=\"b$long\"><use href=\"#$long\"/></g><use href=\"#$long\"/></svg>", 'reference-expansion-too-large', '...)'],
        ];
    }

    //endregion
    //region The Error List

    public function testTheSameProblemIsReportedOnce(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<script/><script/><g><script/></g>'));
        $this->assertCount(1, $result->errors);
    }

    public function testDistinctProblemsInFileOrder(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<script/><foreignObject/><rect onclick="x"/><script/>'));
        $this->assertSame(['element-not-allowed', 'element-not-allowed', 'event-handler'], array_column($result->errors, 'code'));
        $this->assertSame(['script', 'foreignObject', 'onclick'], array_column($result->errors, 'detail'));
    }

    public function testAtMostFiftyErrors(): void
    {
        $body = '';
        for ($i = 1; $i <= 60; $i++) {
            $body .= "<bad$i/>";
        }
        $result = SvgSecurityCheck::checkString($this->svg($body));
        $this->assertCount(50, $result->errors);
        $this->assertSame('bad1', $result->errors[0]->detail);
        $this->assertSame('bad50', $result->errors[49]->detail);
    }

    /** The cap holds however the errors arrive: many on one element, or from inside an embedded SVG. */
    public function testAtMostFiftyErrorsFromOneElement(): void
    {
        $attributes = '';
        for ($i = 1; $i <= 60; $i++) {
            $attributes .= " bad$i=\"1\"";
        }
        $this->assertCount(50, SvgSecurityCheck::checkString($this->svg("<rect$attributes/>"))->errors);
    }

    public function testAtMostFiftyErrorsWithAnEmbeddedSvg(): void
    {
        $inner = $this->svg('<a1/><a2/><a3/><a4/><a5/>');
        $body  = '';
        for ($i = 1; $i <= 48; $i++) {
            $body .= "<bad$i/>";
        }
        $body .= '<image href="data:image/svg+xml;base64,' . base64_encode($inner) . '"/>';
        $this->assertCount(50, SvgSecurityCheck::checkString($this->svg($body))->errors);
    }

    /** The caps are public static properties, so an application can change them; nothing else reads them. */
    public function testLimitsCanBeOverridden(): void
    {
        $maxErrors       = SvgSecurityCheck::$maxErrors;
        $maxDetailLength = SvgSecurityCheck::$maxDetailLength;
        $maxStyleLength  = SvgSecurityCheck::$maxStyleLength;
        try {
            SvgSecurityCheck::$maxErrors       = 5;
            SvgSecurityCheck::$maxDetailLength = 10;
            SvgSecurityCheck::$maxStyleLength  = 10;
            $result = SvgSecurityCheck::checkString($this->svg('<bad1/><bad2/><bad3/><bad4/><bad5/><bad6/><' . str_repeat('a', 20) . '/>'));
            $this->assertCount(5, $result->errors);
            $this->assertSame('aaaaaaaaaa...', SvgSecurityCheck::checkString($this->svg('<' . str_repeat('a', 20) . '/>'))->errors[0]->detail);
            $this->assertSame('more than 10 bytes', SvgSecurityCheck::checkString($this->svg('<style>.a{fill:red}</style>'))->errors[0]->detail);
        } finally {
            SvgSecurityCheck::$maxErrors       = $maxErrors;
            SvgSecurityCheck::$maxDetailLength = $maxDetailLength;
            SvgSecurityCheck::$maxStyleLength  = $maxStyleLength;
        }
    }

    public function testErrorsAreViolationsWithKnownCodes(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<script/><rect onclick="x" style="@import x"/>'));
        foreach ($result->errors as $violation) {
            $this->assertInstanceOf(Violation::class, $violation);
            $this->assertArrayHasKey($violation->code, Violation::TEMPLATES);
        }
    }

    //endregion
    //region rules()

    public function testRulesLists(): void
    {
        $rules = SvgSecurityCheck::rules();
        $this->assertSame(['elements', 'attributes', 'namespacedAttributes', 'inertNamespaces', 'inertProcessingInstructions', 'imageElements', 'dataImageTypes', 'cssFunctions', 'cssAtRules'], array_keys($rules));
        $this->assertContains('svg', $rules['elements']);
        $this->assertNotContains('script', $rules['elements']);
        $this->assertNotContains('foreignObject', $rules['elements']);
        $this->assertSame([], preg_grep('/^on/i', $rules['attributes']));
        $this->assertSame(['lang', 'space'], $rules['namespacedAttributes']['http://www.w3.org/XML/1998/namespace']);
        $this->assertSame(['href', 'title'], $rules['namespacedAttributes']['http://www.w3.org/1999/xlink']);
        $this->assertSame(['image', 'feImage'], $rules['imageElements']);
        $this->assertSame(['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg+xml'], $rules['dataImageTypes']);
    }

    //endregion
}
