<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Unit;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Everything decided before the first element is inspected: reading the file, the
 * bytes before the root tag, processing instructions, the root element, and what
 * libxml reports about well-formedness.
 *
 * Codes: file-unreadable, not-svg, not-utf8, doctype-not-allowed,
 * processing-instruction, comment-not-allowed, cdata-not-allowed,
 * element-in-title-or-desc, root-not-svg, root-namespace-wrong, malformed-xml.
 *
 * libxml's 10 MB text-node limit is not exercised: building the input costs more
 * than it proves. The depth limit is, since 300 nested elements are cheap.
 */
class StructureTest extends SvgSecurityCheckTestCase
{
    //region Reading the File

    public function testMissingFile(): void
    {
        $result = SvgSecurityCheck::checkFile('/no/such/dir/logo.svg');
        $this->assertFalse($result->ok);
        $this->assertSame('file-unreadable', $result->errors[0]->code);
        $this->assertSame('logo.svg', $result->errors[0]->detail);   // basename only: the message may end up in a page
    }

    public function testMissingFileNameIsEscapedAsOneLine(): void
    {
        $detail = SvgSecurityCheck::checkFile("/no/such/dir/a\nb\xE9.svg")->errors[0]->detail;
        $this->assertSame('a\nb\351.svg', $detail);   // a name from $_FILES['logo']['name'] is the visitor's
    }

    public function testDirectory(): void
    {
        $this->assertSame('file-unreadable', SvgSecurityCheck::checkFile(sys_get_temp_dir())->errors[0]->code);
    }

    public function testFileAndStringAgree(): void
    {
        $svg        = $this->svg('<rect width="1" height="1"/><script/>');
        $fromFile   = SvgSecurityCheck::checkFile($this->tempFile($svg));
        $fromString = SvgSecurityCheck::checkString($svg);
        $this->assertSame(['element-not-allowed'], array_column($fromFile->errors, 'code'));
        $this->assertEquals($fromString, $fromFile);
    }

    public function testAcceptedFile(): void
    {
        $this->assertTrue(SvgSecurityCheck::checkFile($this->tempFile($this->svg()))->ok);
    }

    /** libxml2 decodes %XX in a path as if it were a URL; a real file named with %27 must still open. */
    public function testPathWithPercentAndSpace(): void
    {
        $path = $this->tempFile($this->svg()) . ' d%27Ivrea 50%_off.svg';
        file_put_contents($path, $this->svg());
        try {
            $this->assertTrue(SvgSecurityCheck::checkFile($path)->ok);
        } finally {
            unlink($path);
        }
    }

    /**
     * A file deleted or locked between checkFile()'s readability check and XMLReader::open()
     * must come back as file-unreadable, not as an Error from reading an empty reader. No
     * portable way exists to make a real open fail after is_readable() passed, so this calls
     * the private check() with a loader that reports failure.
     */
    public function testOpenFailureReportsFileUnreadable(): void
    {
        $class     = new ReflectionClass(SvgSecurityCheck::class);
        $validator = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($validator, 0);
        $result = $class->getMethod('check')->invoke($validator, $this->svg(), fn() => false, 'logo.svg');
        $this->assertSame('file-unreadable: logo.svg', self::describe($result));
    }

    //endregion
    //region Before the Root Tag

    #[DataProvider('acceptedPrologProvider')]
    public function testPrologAccepted(string $prolog): void
    {
        $this->assertAccepts($prolog . $this->svg());
    }

    public static function acceptedPrologProvider(): array
    {
        $doctype = '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">';
        return [
            'nothing'                            => [''],
            'leading whitespace'                 => ["\n\n \t"],
            'xml declaration'                    => ['<?xml version="1.0"?>'],
            'utf-8 declared, lowercase'          => ['<?xml version="1.0" encoding="utf-8"?>'],
            'utf-8 declared, with standalone'    => ['<?xml version="1.0" encoding="UTF-8" standalone="no"?>'],
            'utf-8 bom'                          => ["\xEF\xBB\xBF"],
            'bom then declaration'               => ["\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?>"],
            'comment'                            => ['<!-- Generator: Adobe Illustrator 27.0, SVG Export Plug-In -->'],
            'doctype with public and system ids' => [$doctype],
            'doctype with [ inside a quoted id'  => ['<!DOCTYPE svg SYSTEM "odd[name].dtd">'],
            'declaration, comment, doctype'      => ["<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<!-- Generator: Adobe Illustrator -->\n$doctype\n"],
        ];
    }

    #[DataProvider('rejectedPrologProvider')]
    public function testPrologRejected(string $file, string $code, string $detail): void
    {
        $this->assertRejects($file, $code, $detail);
    }

    public function testNoRootDetailNamesTheLimitSet(): void
    {
        $default = SvgSecurityCheck::$prologLimit;
        SvgSecurityCheck::$prologLimit = 128 * 1024;
        try {
            $this->assertRejects('<!-- never closed', 'malformed-xml', 'no root element within the first 128 KB');
        } finally {
            SvgSecurityCheck::$prologLimit = $default;
        }
    }

    public static function rejectedPrologProvider(): array
    {
        $svg    = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $subset = 'it contains an internal DTD subset (entity declarations)';
        $noRoot = 'no root element within the first 64 KB';
        $svgDtd = '"-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"';
        $laughs = '<!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]>';
        return [
            'empty file'                    => ['', 'not-svg', 'nothing (the file is empty)'],
            'whitespace only'               => ["  \n\t", 'not-svg', 'nothing (the file is empty)'],
            'plain text'                    => ['Hello, this is a text file.', 'not-svg', 'Hello, this is a tex'],
            'text before the root tag'      => ["hello $svg", 'not-svg', 'hello <svg xmlns="ht'],
            'png bytes'                     => ["\x89PNG\r\n\x1a\n", 'not-svg', '\211PNG\r\n\032\n'],
            'utf-16 le with bom'            => ["\xFF\xFE<\0s\0v\0g\0", 'not-utf8', 'UTF-16 or UTF-32'],
            'utf-16 be with bom'            => ["\xFE\xFF\0<\0s\0v\0g", 'not-utf8', 'UTF-16 or UTF-32'],
            'utf-16 le without bom'         => ["<\0s\0v\0g\0", 'not-utf8', 'UTF-16 or UTF-32'],
            'utf-16 be without bom'         => ["\0<\0s\0v\0g", 'not-utf8', 'UTF-16 or UTF-32'],
            'utf-32 le'                     => ["<\0\0\0s\0\0\0", 'not-utf8', 'UTF-16 or UTF-32'],
            'utf-32 be'                     => ["\0\0\0<\0\0\0s", 'not-utf8', 'UTF-16 or UTF-32'],
            'latin-1 declared'              => ['<?xml version="1.0" encoding="ISO-8859-1"?>' . $svg, 'not-utf8', 'declared as ISO-8859-1'],
            'utf-16 declared'               => ['<?xml version="1.0" encoding="UTF-16"?>' . $svg, 'not-utf8', 'declared as UTF-16'],
            'internal subset'               => ['<!DOCTYPE svg [<!ENTITY x "y">]>' . $svg, 'doctype-not-allowed', $subset],
            'internal subset after the ids' => ["<!DOCTYPE svg PUBLIC $svgDtd [ <!ENTITY ns_svg \"http://www.w3.org/2000/svg\"> ]>$svg", 'doctype-not-allowed', $subset],
            'billion laughs'                => [$laughs . $svg, 'doctype-not-allowed', $subset],
            'root tag after 64 KB'          => ['<!-- ' . str_repeat('x', 70000) . ' -->' . $svg, 'malformed-xml', $noRoot],
            'comment never closed'          => ['<!-- never closed', 'malformed-xml', $noRoot],
        ];
    }

    //endregion
    //region Processing Instructions

    #[DataProvider('processingInstructionProvider')]
    public function testProcessingInstructionRejected(string $file, string $target): void
    {
        $this->assertRejects($file, 'processing-instruction', $target);
    }

    public static function processingInstructionProvider(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg">';
        return [
            'external stylesheet'       => ['<?xml-stylesheet type="text/css" href="theme.css"?>' . $open . '</svg>', 'xml-stylesheet'],
            'same-document xslt'        => ['<?xml-stylesheet type="text/xsl" href="#s"?>' . $open . '</svg>', 'xml-stylesheet'],   // Chrome applies this one even in <img>
            'after the xml declaration' => ['<?xml version="1.0"?><?xml-stylesheet href="#s"?>' . $open . '</svg>', 'xml-stylesheet'],
            'inside the document'       => [$open . '<?php echo 1; ?></svg>', 'php'],
            'after the root element'    => [$open . '</svg><?done?>', 'done'],
            'target case matters'       => ['<?XPACKET begin=""?>' . $open . '</svg>', 'XPACKET'],
            'xpacket holding >'         => ['<?xpacket a="><img src=x onerror=alert(1)>"?>' . $open . '</svg>', 'xpacket a="><img src=x onerror=alert(1)>"'],   // an HTML parser ends it at the first >
        ];
    }

    /** Adobe's XMP markers, as Illustrator writes them around the file and inside <metadata>. */
    public function testXmpMarkersAccepted(): void
    {
        $begin = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>';
        $end   = '<?xpacket end="w"?>';
        $this->assertAccepts($begin . $this->svg() . $end);
        $this->assertAccepts($this->svg("<metadata>$begin<x:xmpmeta xmlns:x=\"adobe:ns:meta/\"/>$end</metadata>"));
    }

    //endregion
    //region Comments

    #[DataProvider('acceptedCommentProvider')]
    public function testCommentAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body));
    }

    public static function acceptedCommentProvider(): array
    {
        return [
            'plain'                  => ['<!-- Generator: Adobe Illustrator -->'],
            'empty'                  => ['<!---->'],
            'markup inside'          => ['<!-- <img src=x onerror=alert(1)> stays a comment in both parsers -->'],
            'dash then text'         => ['<!-- -x -->'],
            'greater-than inside'    => ['<!-- a > b -->'],
        ];
    }

    /** To an HTML parser <!--> and <!---> are whole comments, so anything after them would be live markup if the file were served as text/html. */
    #[DataProvider('rejectedCommentProvider')]
    public function testCommentRejected(string $file, string $detail): void
    {
        $this->assertRejects($file, 'comment-not-allowed', $detail);
    }

    public static function rejectedCommentProvider(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg">';
        return [
            'empty comment quirk'   => [$open . '<!--> <img src=x onerror=alert(1)> <!--></svg>', '>'],
            'dash quirk'            => [$open . '<!---> <img src=x onerror=alert(1)> <!--></svg>', '->'],
            'before the root'       => ['<!--> <img src=x onerror=alert(1)> --><svg xmlns="http://www.w3.org/2000/svg"/>', '>'],
        ];
    }

    //endregion
    //region CDATA in Title and Desc

    /**
     * <title> and <desc> switch an HTML parser back to HTML rules, where <![CDATA[ is a bogus
     * comment that ends at the first >. Everything from there to ]]> would be live markup if
     * the file were served as text/html. Everywhere else CDATA is honored by both parsers.
     */
    #[DataProvider('acceptedCdataProvider')]
    public function testCdataAccepted(string $body): void
    {
        $this->assertAccepts($this->svg($body));
    }

    public static function acceptedCdataProvider(): array
    {
        return [
            'title without >'          => ['<title><![CDATA[Logo & wordmark]]></title>'],
            'desc without >'           => ['<desc><![CDATA[a < b]]></desc>'],
            'title with > as entity'   => ['<title>&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title>'],
            'style with >'             => ['<style><![CDATA[ .x{content:"</style><script>alert</script>"} ]]></style>'],
            'text with >'              => ['<text><![CDATA[a > b]]></text>'],
            'sibling after title'      => ['<title><![CDATA[x]]></title><text><![CDATA[a > b]]></text>'],
            'empty title element'      => ['<title/><text><![CDATA[a > b]]></text>'],
        ];
    }

    #[DataProvider('rejectedCdataProvider')]
    public function testCdataRejected(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body), 'cdata-not-allowed', $detail);
    }

    public static function rejectedCdataProvider(): array
    {
        return [
            'title'                    => ['<title><![CDATA[</title><script>alert(1)</script>]]></title>', 'title'],
            'desc'                     => ['<desc><![CDATA[</desc><script>alert(1)</script>]]></desc>', 'desc'],
            'bare > is enough'         => ['<title><![CDATA[a > b]]></title>', 'title'],
            'second CDATA section'     => ['<title><![CDATA[safe]]><![CDATA[</title><script>alert(1)</script>]]></title>', 'title'],
            'nested title in a group'  => ['<g><g><title><![CDATA[a > b]]></title></g></g>', 'title'],
        ];
    }

    //endregion
    //region Elements in Title and Desc

    /**
     * An HTML parser reads elements inside <title> and <desc> as HTML. There <style/> is not
     * empty, so the text after it becomes CSS this check never saw, if the file were served
     * as text/html. So the two hold text only.
     */
    #[DataProvider('elementInTitleOrDescProvider')]
    public function testElementInTitleOrDescRejected(string $body, string $detail): void
    {
        $this->assertRejects($this->svg($body), 'element-in-title-or-desc', $detail);
    }

    public static function elementInTitleOrDescProvider(): array
    {
        return [
            'self-closed style in desc'  => ['<desc><style/>svg{fill:red}</desc>', 'style'],
            'self-closed style in title' => ['<title><style/>svg{fill:red}</title>', 'style'],
            'link in desc'               => ['<desc><a href="#a">text</a></desc>', 'a'],
            'deeper inside'              => ['<title><tspan><tspan/></tspan></title>', 'tspan'],
            'metadata element'           => ['<desc><x:m xmlns:x="http://ns.adobe.com/x"/></desc>', 'x:m'],
        ];
    }

    public function testTextInTitleAndDescAccepted(): void
    {
        $this->assertAccepts($this->svg('<title>Logo &amp; wordmark</title><desc>&lt;style/&gt; is text here</desc><g><title>Layer</title></g>'));
    }

    //endregion
    //region The Root Element

    #[DataProvider('acceptedRootProvider')]
    public function testRootAccepted(string $file): void
    {
        $this->assertAccepts($file);
    }

    public static function acceptedRootProvider(): array
    {
        return [
            'extra namespace declarations' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"/>'],
            'nested svg'                   => ['<svg xmlns="http://www.w3.org/2000/svg"><svg x="10" width="5" height="5"/></svg>'],
            'illustrator root attributes'  => ['<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 100 100" style="enable-background:new 0 0 100 100;" xml:space="preserve"/>'],
        ];
    }

    /** Nothing past a wrong root is reported: the file is not an SVG, so its contents are not the news. */
    #[DataProvider('rejectedRootProvider')]
    public function testRootRejected(string $file, string $code, string $detail): void
    {
        $result = SvgSecurityCheck::checkString($file);
        $this->assertSame([$code], array_column($result->errors, 'code'));
        $this->assertSame($detail, $result->errors[0]->detail);
    }

    public static function rejectedRootProvider(): array
    {
        return [
            'xhtml'                      => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><script/></body></html>', 'root-not-svg', 'html'],
            'html doctype'               => ['<!DOCTYPE html><html></html>', 'root-not-svg', 'html'],
            'rss'                        => ['<rss version="2.0"><channel/></rss>', 'root-not-svg', 'rss'],
            'svg without a namespace'    => ['<svg><script/></svg>', 'root-namespace-wrong', 'none'],
            'svg in the wrong namespace' => ['<svg xmlns="http://www.w3.org/1999/xhtml"/>', 'root-namespace-wrong', 'xmlns="http://www.w3.org/1999/xhtml"'],
            'prefixed, wrong namespace'  => ['<x:svg xmlns:x="http://example.com/"/>', 'root-namespace-wrong', 'xmlns="http://example.com/"'],
        ];
    }

    //endregion
    //region Well-Formedness

    #[DataProvider('malformedProvider')]
    public function testMalformedXml(string $file, string $libxmlMessage): void
    {
        $violation = $this->assertRejects($file, 'malformed-xml');
        $this->assertStringContainsString($libxmlMessage, $violation->detail);
        $this->assertMatchesRegularExpression('/ \(line \d+\)$/', $violation->detail);
    }

    public static function malformedProvider(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg">';
        return [
            'tag never closed'                  => [$open . '<rect width="1" height="1"></svg>', 'Opening and ending tag mismatch'],
            'tags crossed'                      => [$open . '<g><a></g></a></svg>', 'Opening and ending tag mismatch'],
            'attribute without quotes'          => [$open . '<rect width=1/></svg>', 'AttValue: " or \' expected'],
            'duplicate attribute'               => [$open . '<rect id="a" id="b"/></svg>', 'Attribute id redefined'],
            'undefined entity'                  => [$open . '<text>&nbsp;</text></svg>', "Entity 'nbsp' not defined"],
            'unescaped ampersand'               => [$open . '<text>Tom & Jerry</text></svg>', 'xmlParseEntityRef: no name'],
            'unbound prefix'                    => [$open . '<foo:bar/></svg>', 'Namespace prefix foo on bar is not defined'],
            'two root elements'                 => [$open . '</svg>' . $open . '</svg>', 'Extra content at the end of the document'],
            'text after the root'               => [$open . '</svg>trailing', 'Extra content at the end of the document'],
            'truncated file'                    => [$open . '<rect/></s', "expected '>'"],
            'nesting deeper than libxml allows' => [$open . str_repeat('<g>', 300) . str_repeat('</g>', 300) . '</svg>', 'Excessive depth'],
        ];
    }

    public function testBuiltInEntitiesAndCharacterReferencesAreFine(): void
    {
        $this->assertAccepts($this->svg('<text>&amp; &lt; &gt; &quot; &apos; &#169; &#xA9; Tom &amp; Jerry</text>'));
    }

    /**
     * libxml parses in chunks of a few hundred bytes and drops the chunk a well-formedness
     * error is in, so problems found earlier in the file are reported and anything in or
     * after the broken chunk is not. The comment pads the file so the two land in
     * different chunks.
     */
    public function testMalformedXmlEndsTheList(): void
    {
        $padding = '<!--' . str_repeat('x', 5000) . '-->';
        $result  = SvgSecurityCheck::checkString($this->svg("<script/>$padding") . '<foreignObject/>');
        $this->assertSame(['element-not-allowed', 'malformed-xml'], array_column($result->errors, 'code'));
        $this->assertSame('script', $result->errors[0]->detail);
    }

    /** In a small file the error shares a chunk with everything else, so malformed-xml is the only report. */
    public function testSmallMalformedFileReportsOnlyTheXmlError(): void
    {
        $result = SvgSecurityCheck::checkString($this->svg('<script/>') . '<foreignObject/>');
        $this->assertSame(['malformed-xml'], array_column($result->errors, 'code'));
    }

    //endregion
}
