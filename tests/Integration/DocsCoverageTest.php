<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use Itools\SvgSecurityCheck\Result;
use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Violation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every public method, property and constant of the three classes appears in README.md
 * and docs/ai-reference.md, found by reflection so a new member cannot ship undocumented.
 * Every page under docs/ is linked from the README, so none can go unlisted.
 */
class DocsCoverageTest extends TestCase
{
    private const PAGES = ['README.md', 'docs/ai-reference.md'];

    /** @return iterable<string, array{string, string}> page and the text the member must appear as */
    public static function memberProvider(): iterable
    {
        $members = [];
        foreach ((new ReflectionClass(SvgSecurityCheck::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                $members[] = 'SvgSecurityCheck::' . $method->getName() . '(';
            }
        }
        foreach ([Result::class => '$result', Violation::class => '$violation'] as $class => $variable) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $members[] = $variable . '->' . $property->getName();
            }
            foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                $members[] = $reflection->getShortName() . '::' . $constant->getName();
            }
        }
        foreach (self::PAGES as $page) {
            foreach ($members as $member) {
                yield "$page $member" => [$page, $member];
            }
        }
    }

    /** @dataProvider memberProvider */
    public function testMemberIsDocumented(string $page, string $member): void
    {
        $markdown = file_get_contents(__DIR__ . "/../../$page");
        $this->assertStringContainsString($member, $markdown, "$member is missing from $page");
    }

    public function testEveryDocsPageIsLinkedFromTheReadme(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        foreach (glob(__DIR__ . '/../../docs/*.md') as $path) {
            $name = basename($path);
            $this->assertStringContainsString("](docs/$name)", $readme, "README.md does not link to docs/$name");
        }
    }
}
