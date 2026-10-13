<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every file under tests/Support/fixtures/ is a whole SVG checked from disk:
 * accept/ files must pass, reject/ files must report the code in their name
 * (reject/<code>-<n>.svg).
 */
class FixturesTest extends SvgSecurityCheckTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';

    #[DataProvider('acceptFixtureProvider')]
    public function testAcceptFixture(string $path): void
    {
        $result = SvgSecurityCheck::checkFile($path);
        $this->assertTrue($result->ok, "Expected the fixture to be accepted, got:\n" . self::describe($result));
    }

    public static function acceptFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/accept/*.svg') as $path) {
            $cases[basename($path)] = [$path];
        }
        return $cases;
    }

    #[DataProvider('rejectFixtureProvider')]
    public function testRejectFixture(string $path, string $code): void
    {
        $result = SvgSecurityCheck::checkFile($path);
        $this->assertContains($code, array_column($result->errors, 'code'), "Expected $code, got:\n" . self::describe($result));
    }

    public static function rejectFixtureProvider(): array
    {
        $cases = [];
        foreach (glob(self::FIXTURES . '/reject/*.svg') as $path) {
            $cases[basename($path)] = [$path, preg_replace('/-\d+\.svg$/', '', basename($path))];
        }
        return $cases;
    }
}
