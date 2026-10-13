<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Support;

use Itools\SvgSecurityCheck\Result;
use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the Unit and Integration suites.
 *
 * Conventions:
 * - Inputs are built inline with svg(), so each test shows the whole document it checks
 * - assertAccepts() and assertRejects() go through checkString(); checkFile() tests write
 *   the same input to disk with tempFile(), which tearDown() deletes
 * - assertRejects() checks that the code is in the error list, not that it is the only
 *   error, since one bad element often breaks two rules; ResultTest pins exact lists
 * - Failure messages list every violation the validator reported, one per line
 */
abstract class SvgSecurityCheckTestCase extends TestCase
{
    use SharedTestHelpers;

    protected const SVG_NS = 'http://www.w3.org/2000/svg';

    //region Building Inputs

    /**
     * Wrap $body in a root <svg> that declares the SVG namespace.
     *
     *     $this->svg('<rect width="1" height="1"/>');
     *     $this->svg('', 'onload="alert(1)"');
     */
    protected function svg(string $body = '', string $rootAttributes = ''): string
    {
        $attributes = 'xmlns="' . self::SVG_NS . '"' . ($rootAttributes === '' ? '' : " $rootAttributes");
        return "<svg $attributes>$body</svg>";
    }

    /** @var string[] written by tempFile(), deleted in tearDown() */
    private array $tempFiles = [];

    /** Write $contents to a new temp file and return its path, for checkFile() tests. */
    protected function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'svgsecuritycheck-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    //endregion
    //region Assertions

    /** Assert checkString($svg) passes. Returns the Result. */
    protected function assertAccepts(string $svg): Result
    {
        $result = SvgSecurityCheck::checkString($svg);
        $this->assertTrue($result->ok, "Expected the SVG to be accepted, got:\n" . self::describe($result));
        return $result;
    }

    /**
     * Assert checkString($svg) reports a violation with code $code and, when given, exactly
     * $detail. Returns that violation so the test can check its message too.
     */
    protected function assertRejects(string $svg, string $code, ?string $detail = null): Violation
    {
        $result = SvgSecurityCheck::checkString($svg);
        foreach ($result->errors as $violation) {
            if ($violation->code === $code && ($detail === null || $violation->detail === $detail)) {
                $this->addToAssertionCount(1);   // a match is the assertion; without this PHPUnit flags the test as risky
                return $violation;
            }
        }
        $wanted = $detail === null ? $code : "$code with detail '$detail'";
        $this->fail("Expected $wanted, got:\n" . self::describe($result));
    }

    /** One line per violation, "code: detail", or "(no errors)". For failure messages. */
    protected static function describe(Result $result): string
    {
        if ($result->ok) {
            return '(no errors)';
        }
        return implode("\n", array_map(fn(Violation $violation) => "$violation->code: $violation->detail", $result->errors));
    }

    //endregion
}
