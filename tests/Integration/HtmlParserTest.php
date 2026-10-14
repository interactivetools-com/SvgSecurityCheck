<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use Dom\HTMLDocument;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;

/**
 * A file that passes must run no script when it is served as text/html. Each input here
 * passes the check, then goes through PHP's HTML5 parser (Dom\HTMLDocument, PHP 8.4+), and
 * the tree it builds must hold no script element, no on* attribute, no javascript: URL and
 * no element that loads another document.
 *
 * The inputs are the accept fixtures plus files built to sit right next to a rule: CDATA,
 * comments and processing instructions holding markup, metadata elements, and HTML-looking
 * text inside <title> and <desc>.
 *
 * PHP's parser is not Chrome's. Chrome reads <![CDATA[ directly inside <title> or <desc> as a
 * comment that ends at the first >, so markup in it runs; PHP keeps it as text. So this test
 * cannot catch a regression in the CDATA rule. ChromeCrossCheckTest and StructureTest's CDATA
 * cases do.
 */
#[RequiresPhp('>= 8.4')]
class HtmlParserTest extends SvgSecurityCheckTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures/accept';

    /** Elements that run script or load another document when an HTML parser builds them. */
    private const ACTIVE_ELEMENTS = ['script', 'iframe', 'frame', 'object', 'embed'];

    #[DataProvider('passingFileProvider')]
    public function testNoScriptWhenParsedAsHtml(string $svg): void
    {
        $this->assertAccepts($svg);

        $document = HTMLDocument::createFromString($svg, LIBXML_NOERROR);
        $found    = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), self::ACTIVE_ELEMENTS, true)) {
                $found[] = "<$element->localName>";
            }
            foreach ($element->attributes as $attribute) {
                if (stripos($attribute->localName, 'on') === 0) {
                    $found[] = "$attribute->name= on <$element->localName>";
                }
                if (preg_match('/^[\s\x00-\x1F]*javascript:/i', $attribute->value)) {
                    $found[] = "javascript: in $attribute->name on <$element->localName>";
                }
            }
        }
        $this->assertSame([], $found, 'The HTML parser built: ' . implode(', ', $found));
    }

    public static function passingFileProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/*.svg') as $path) {
            $cases[basename($path)] = [file_get_contents($path)];
        }

        $open     = '<svg xmlns="' . self::SVG_NS . '">';
        $payload  = '<img src=x onerror=alert>';   // no (: alert( in <style> is an unlisted CSS function and would reject
        $nearMiss = [
            'cdata in a group'              => "<g><![CDATA[>$payload]]></g>",
            'cdata in style'                => "<style><![CDATA[a{}</style>$payload]]></style>",
            'cdata in a metadata element'   => "<x:meta xmlns:x=\"http://ns.adobe.com/x\"><![CDATA[>$payload]]></x:meta>",
            'comment holding markup'        => "<!-- >$payload -->",
            'xpacket markers'               => '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><?xpacket end="w"?>',
            'escaped markup in title'       => '<title>&lt;img src=x onerror=alert(1)&gt;</title>',
            'cdata in title without >'      => '<title><![CDATA[<img src=x onerror=alert(1)]]></title>',
            'metadata with prefixed script' => '<x:scripted xmlns:x="http://ns.adobe.com/x">alert(1)</x:scripted>',
            'metadata with a style'         => '<x:meta xmlns:x="http://ns.adobe.com/x" style="fill:red"/>',
            'escaped style in desc'         => '<desc>&lt;style/&gt;svg{fill:url(https://example.com/a)}</desc>',
            'xlink bound correctly'         => '<a xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="#r"><rect id="r"/></a>',
        ];
        foreach ($nearMiss as $name => $body) {
            $cases[$name] = [$open . $body . '</svg>'];
        }
        return $cases;
    }
}
