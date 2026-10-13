<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the doc examples true. The PHP examples cannot be run from the page, so their
 * output comments are mirrored by hand below; editing one of those examples means editing
 * its mirror.
 */
class DocsExamplesTest extends TestCase
{
    /** README quick start: the message for a script element */
    public function testScriptMessage(): void
    {
        $result = SvgSecurityCheck::checkString('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertSame('<script> is not allowed in uploaded SVGs', $result->errors[0]->message);
    }

    /** README, the rest of the API: the four Violation fields */
    public function testViolationFields(): void
    {
        $violation = SvgSecurityCheck::checkString('<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>')->errors[0];
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('script', $violation->detail);
        $this->assertSame('<%s> is not allowed in uploaded SVGs', $violation->template);
        $this->assertSame('<script> is not allowed in uploaded SVGs', $violation->message);
    }

    /** README, nothing throws: a missing path is a rejection; docs/errors.md quotes the message */
    public function testMissingFileMessage(): void
    {
        $this->assertSame('Cannot read file php3F.tmp', SvgSecurityCheck::checkFile('/tmp/php3F.tmp')->errors[0]->message);
    }
}
