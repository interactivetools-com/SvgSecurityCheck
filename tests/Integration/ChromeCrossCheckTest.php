<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck\Tests\Integration;

use FilesystemIterator;
use Itools\SvgSecurityCheck\SvgSecurityCheck;
use Itools\SvgSecurityCheck\Tests\Support\SvgSecurityCheckTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A second opinion from a real browser: every file the check accepts is parsed by Chrome twice,
 * as SVG and as text/html. Neither tree may hold a script element, an on* attribute, a
 * javascript: URL or an element that loads another document, and the HTML tree may hold no URL
 * or CSS the SVG tree lacks: that would be something an HTML parser reads that the check never
 * saw. This is the test that fails the day a rule misses a way Blink's HTML parser reads XML.
 *
 * The reject fixtures for the parser-difference rules go through the same page as canaries, and
 * each must be flagged, so a page that stopped finding anything fails too.
 *
 * One headless Chrome run does it all: the files (the accept fixtures, HtmlParserTest's near
 * misses, and the corpus when it is present) go into tests/Support/chrome-cross-check.html as
 * JSON, the page parses each with DOMParser, and the dumped DOM carries the findings back.
 *
 * Chrome is found through CHROME_PATH, the usual names on PATH, or the install locations. In CI
 * the test runs on the Linux jobs only, and fails there without Chrome except on ARM, where Google
 * ships none. Elsewhere it skips when Chrome is missing.
 */
final class ChromeCrossCheckTest extends SvgSecurityCheckTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';
    private const CORPUS   = __DIR__ . '/../../corpus';
    private const HARNESS  = __DIR__ . '/../Support/chrome-cross-check.html';

    // one reject fixture per rule that closes a parser difference; each holds a file the HTML parser reads differently
    private const CANARIES = [
        'cdata-not-allowed-1.svg',
        'comment-not-allowed-1.svg',
        'element-in-title-or-desc-1.svg',
        'element-prefix-wrong-1.svg',
        'element-prefix-wrong-2.svg',
        'processing-instruction-2.svg',
        'xlink-prefix-wrong-1.svg',
    ];

    private const CHROME_NAMES = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'];

    // where Chrome installs when it is not on PATH: macOS, Windows, and Windows as seen from WSL
    private const CHROME_PATHS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
        '/mnt/c/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    ];

    public function testChromeFindsNothingTheCheckMissed(): void
    {
        if (getenv('CI') && PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('runs on the Linux CI jobs only: Chrome parses the same on every platform');
        }
        $chrome = self::chrome();
        if ($chrome === null) {
            $message           = 'Chrome not found: install it or set CHROME_PATH to run the browser cross-check';
            $platformHasChrome = PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'aarch64';   // Google ships no Chrome for Linux on ARM
            if (getenv('CI') && $platformHasChrome) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }

        $accepted = self::acceptedFiles();
        $canaries = [];
        foreach (self::CANARIES as $file) {
            $canaries["canary $file"] = file_get_contents(self::FIXTURES . "/reject/$file");
        }
        $result = $this->runInChrome($chrome, $accepted + $canaries);

        $this->assertSame(count($accepted) + count($canaries), $result['checked']);
        $missed = array_diff_key($canaries, $result['found']);
        $this->assertSame([], array_keys($missed), 'the page no longer flags these known parser differences');
        $this->assertSame([], array_diff_key($result['found'], $canaries), 'Chrome found something in files the check accepted');
    }

    /**
     * Every accept fixture, HtmlParserTest near miss and corpus file the check accepts, keyed by a name for the report
     *
     * @return array<string, string>
     */
    private static function acceptedFiles(): array
    {
        $files = [];
        foreach (HtmlParserTest::passingFileProvider() as $name => [$svg]) {
            $files[$name] = $svg;
        }
        if (is_dir(self::CORPUS)) {
            $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::CORPUS, FilesystemIterator::SKIP_DOTS));
            foreach ($paths as $path => $info) {
                if ($info->isFile() && str_ends_with($path, '.svg')) {
                    $files[substr($path, strlen(self::CORPUS) + 1)] = file_get_contents($path);
                }
            }
        }
        return array_filter($files, fn(string $svg) => SvgSecurityCheck::checkString($svg)->ok);
    }

    /**
     * Run the harness page on the files in one headless Chrome, with a fresh profile so it never
     * attaches to a browser that is already open
     *
     * @param array<string, string> $files
     * @return array{checked: int, found: array<string, string[]>}
     */
    private function runInChrome(string $chrome, array $files): array
    {
        $dir = sys_get_temp_dir() . '/svgcheck-chrome-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $page = "$dir/cross-check.html";
        file_put_contents($page, str_replace('__CASES__', self::json($files), file_get_contents(self::HARNESS)));
        try {
            [$dom, , $exit] = $this->runCommand([
                $chrome, '--headless', '--disable-gpu', '--no-sandbox', '--no-first-run',
                '--user-data-dir=' . self::pathForChrome($chrome, "$dir/profile"),
                '--dump-dom', self::pathForChrome($chrome, $page),
            ]);
        } finally {
            self::removeDir($dir);
        }
        $this->assertSame(0, $exit, 'Chrome did not exit cleanly');
        $this->assertSame(1, preg_match('~<pre id="out">([^<]*)</pre>~', $dom, $match), 'no result in the dumped DOM');
        $result = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($result, 'the harness page did not run: ' . $match[1]);
        return $result;
    }

    /**
     * The files as JSON with no < > or & in it, so a </script> inside a file cannot end the script block
     */
    private static function json(array $files): string
    {
        return json_encode($files, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }

    /**
     * The Chrome binary, or null when none is found
     */
    private static function chrome(): ?string
    {
        $candidates = [getenv('CHROME_PATH') ?: ''];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            foreach (self::CHROME_NAMES as $name) {
                $candidates[] = "$dir/$name";
            }
        }
        foreach ([...$candidates, ...self::CHROME_PATHS] as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * The path as Chrome will read it: a Windows path when a Windows Chrome runs from WSL
     */
    private static function pathForChrome(string $chrome, string $path): string
    {
        if (PHP_OS_FAMILY === 'Linux' && str_ends_with($chrome, '.exe')) {
            return trim((string)shell_exec('wslpath -w ' . escapeshellarg($path)));
        }
        return $path;
    }

    /**
     * Delete the page and the Chrome profile, which is a few MB per run
     */
    private static function removeDir(string $dir): void
    {
        $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path => $info) {
            $info->isDir() && !$info->isLink() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
