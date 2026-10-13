<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use Itools\SvgSecurityCheck\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the error codes and the reject fixtures in step: every code in
 * Violation::TEMPLATES has a fixture named after it, every fixture names a code that
 * still exists, and docs/errors.md has a table row for every code with its template.
 */
class ErrorCodesTest extends TestCase
{
    private const REJECT_DIR = __DIR__ . '/../Support/fixtures/reject';

    /** Codes no file on disk can trigger, with the reason, so the exemption cannot go stale unnoticed. */
    private const NO_FIXTURE = [
        'file-unreadable' => 'a fixture that exists is readable',
    ];

    public function testEveryCodeHasARejectFixture(): void
    {
        foreach (array_keys(Violation::TEMPLATES) as $code) {
            if (isset(self::NO_FIXTURE[$code])) {
                continue;
            }
            $this->assertFileExists(self::REJECT_DIR . "/$code-1.svg", "no reject fixture for $code");
        }
    }

    public function testEveryRejectFixtureNamesAKnownCode(): void
    {
        foreach (glob(self::REJECT_DIR . '/*.svg') as $path) {
            $name = basename($path);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+-\d+\.svg$/', $name, "$name is not named <code>-<n>.svg");
            $code = preg_replace('/-\d+\.svg$/', '', $name);
            $this->assertArrayHasKey($code, Violation::TEMPLATES, "$name names a code that does not exist");
        }
    }

    public function testEveryCodeHasARowInErrorsDoc(): void
    {
        $page = file_get_contents(__DIR__ . '/../../docs/errors.md');
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertMatchesRegularExpression("/^\\| `$code` +\\| `(.+?)` +\\| /m", $page, "docs/errors.md has no table row for $code");
            preg_match("/^\\| `$code` +\\| `(.+?)` +\\| /m", $page, $row);
            $this->assertSame($template, $row[1], "the docs/errors.md message for $code does not match Violation::TEMPLATES");
        }
    }

    public function testErrorsDocRowsAreInTemplateOrder(): void
    {
        $page = file_get_contents(__DIR__ . '/../../docs/errors.md');
        preg_match_all('/^\\| `([a-z0-9-]+)` +\\| `/m', $page, $rows);
        $this->assertSame(array_keys(Violation::TEMPLATES), $rows[1], 'docs/errors.md rows are not in Violation::TEMPLATES order');
    }

    public function testExemptCodesStillExist(): void
    {
        foreach (array_keys(self::NO_FIXTURE) as $code) {
            $this->assertArrayHasKey($code, Violation::TEMPLATES, "$code is exempt from needing a fixture but is not a code");
        }
    }
}
