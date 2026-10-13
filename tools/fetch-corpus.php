#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Downloads the SVG test sets listed in SOURCES into corpus/ (gitignored) for tools/tally.php.
 *
 *     php tools/fetch-corpus.php              # every source not downloaded yet
 *     php tools/fetch-corpus.php resvg gecko  # named sources, downloaded again
 *     GITHUB_TOKEN=ghp_... php tools/fetch-corpus.php
 *
 * Each source is one or more folders in one git repo, on GitHub unless the entry names a
 * GitLab host. The file list comes from the repo's tree API (GitHub: one call per folder, 60
 * calls an hour without a token, 5000 with one; GitLab: 800 calls a minute), the files from
 * raw.githubusercontent.com or GitLab's raw file endpoint, eight at a time. The repo's license
 * text is saved as LICENSE next to the files, and SOURCE.json records where the files came
 * from, which commit, and what the tally should expect of them.
 *
 * Nothing under corpus/ is ever copied into tests/: some of these sets are GPL or LGPL, and
 * while running the check against them here is a use those licenses allow, shipping them in
 * an MIT repo is not. Each source's LICENSE is saved next to its files so the terms travel
 * with them. Design-tool exports go in corpus/own/: tools/fetch-commons.php fills
 * corpus/own/commons/.
 */

const CORPUS_DIR = __DIR__ . '/../corpus';
const PARALLEL   = 8;

/*
 * expect: what the tally should find. accept = real-world or feature files that must pass,
 * reject = attack files that must fail, browser = browser behavior tests (mixed on purpose),
 * mixed = both kinds in one folder. expectByName overrides expect per file name pattern.
 * keep = file extensions to download; match = only paths matching this regex.
 * path may be a list of folders; each is then saved under its own last path segment.
 * gitlab = the GitLab host (default is GitHub); license = SPDX id when the API has none.
 * fixup = a change applied to every file after download, recorded in SOURCE.json:
 *   xmlns = add xmlns="http://www.w3.org/2000/svg" (and xmlns:xlink) to a root <svg> without it,
 *   for fixture sets written for a renderer that does not require the namespace; without it
 *   every such file rejects as root-namespace-wrong and the payload is never reached.
 */
const SOURCES = [
    'resvg'        => ['repo' => 'linebender/resvg-test-suite', 'path' => 'tests', 'expect' => 'accept', 'keep' => ['svg']],
    'simple-icons' => ['repo' => 'simple-icons/simple-icons', 'path' => 'icons', 'expect' => 'accept', 'keep' => ['svg']],
    'svgo'         => ['repo' => 'svg/svgo', 'path' => 'test/svgo', 'expect' => 'mixed', 'keep' => ['svg', 'txt']],
    'svgo-plugins' => ['repo' => 'svg/svgo', 'path' => 'test/plugins', 'expect' => 'reject', 'keep' => ['txt'], 'match' => '/removeScripts/'],
    'svg-hush'     => ['repo' => 'cloudflare/svg-hush', 'path' => 'tests', 'expect' => 'reject', 'keep' => ['xml', 'svg']],
    'borewit'      => ['repo' => 'Borewit/svg-sanitizer', 'path' => 'src/test/resources', 'expect' => 'mixed', 'keep' => ['svg']],
    'dompurify'    => ['repo' => 'cure53/DOMPurify', 'path' => 'test/fixtures', 'expect' => 'reject', 'keep' => ['mjs', 'svg']],
    'payloads'     => ['repo' => 'swisskyrepo/PayloadsAllTheThings', 'path' => 'XSS Injection', 'expect' => 'reject', 'keep' => ['svg', 'md']],
    'enshrined'    => ['repo' => 'darylldoyle/svg-sanitizer', 'path' => 'tests/data', 'expect' => 'mixed', 'keep' => ['svg'],
                       'expectByName' => ['/Clean\.svg$/' => 'accept', '/Test\.svg$/' => 'reject']],
    'mediawiki'    => ['repo' => 'wikimedia/mediawiki', 'path' => 'tests/phpunit/data/upload', 'expect' => 'reject', 'keep' => ['svg']],
    'chromium'     => ['repo' => 'chromium/chromium', 'path' => 'third_party/blink/web_tests/svg/as-image', 'expect' => 'browser', 'keep' => ['svg', 'html']],
    'gecko'        => ['repo' => 'mozilla/gecko-dev', 'path' => 'layout/reftests/svg/as-image', 'expect' => 'browser', 'keep' => ['svg', 'html']],
    'webkit'       => ['repo' => 'WebKit/WebKit', 'path' => 'LayoutTests/svg/as-image', 'expect' => 'browser', 'keep' => ['svg', 'html']],
    'wpt-as-image' => ['repo' => 'web-platform-tests/wpt', 'path' => 'svg/as-image', 'expect' => 'browser', 'keep' => ['svg', 'html']],
    'wpt-embedded' => ['repo' => 'web-platform-tests/wpt', 'path' => 'svg/embedded', 'expect' => 'browser', 'keep' => ['svg', 'html']],
    // files that crashed or hung a real rasterizer without any script: reference loops, expansion bombs, XInclude, deep nesting
    'librsvg'      => ['gitlab' => 'gitlab.gnome.org', 'repo' => 'GNOME/librsvg', 'license' => 'LGPL-2.1', 'expect' => 'mixed', 'keep' => ['svg'], 'fixup' => 'xmlns',
                       'path' => ['rsvg/tests/fixtures/crash', 'rsvg/tests/fixtures/render-crash', 'rsvg/tests/fixtures/errors', 'rsvg/tests/fixtures/loading']],
];

$requested = array_slice($argv, 1);
foreach ($requested as $name) {
    if (!isset(SOURCES[$name])) {
        fwrite(STDERR, "Unknown source '$name'. Known: " . implode(', ', array_keys(SOURCES)) . "\n");
        exit(1);
    }
}

foreach (SOURCES as $name => $source) {
    $dir = CORPUS_DIR . "/$name";
    if ($requested === [] && is_file("$dir/SOURCE.json")) {
        echo str_pad($name, 14), "already downloaded, skipping (name it to refresh)\n";
        continue;
    }
    if ($requested !== [] && !in_array($name, $requested, true)) {
        continue;
    }
    fetchSource($name, $source, $dir);
}
echo "Done. Run: php tools/tally.php\n";

function fetchSource(string $name, array $source, string $dir): void
{
    echo str_pad($name, 14), "listing $source[repo] ... ";
    $listing = isset($source['gitlab']) ? gitlabListing($source) : githubListing($source);
    $files   = $listing['files'];   // relative path => download url
    echo count($files), " files\n";

    if (is_dir($dir)) {
        deleteDirectory($dir);
    }
    mkdir($dir, 0755, true);
    file_put_contents("$dir/LICENSE", $listing['licenseText']);

    $urls = [];
    foreach ($files as $relative => $url) {
        $urls["$dir/$relative"] = $url;
    }
    $failed = downloadAll($urls);
    if (($source['fixup'] ?? null) === 'xmlns') {
        foreach (array_keys($urls) as $path) {
            if (is_file($path)) {
                file_put_contents($path, addSvgNamespace(file_get_contents($path)));
            }
        }
    }

    file_put_contents("$dir/SOURCE.json", json_encode([
        'repo'         => (isset($source['gitlab']) ? "$source[gitlab]/" : '') . $source['repo'],
        'path'         => $source['path'],
        'license'      => $listing['license'],
        'expect'       => $source['expect'],
        'expectByName' => $source['expectByName'] ?? [],
        'fixup'        => $source['fixup'] ?? null,
        'tree'         => $listing['version'],   // GitHub: the folder's git tree at download time; GitLab: the last commit touching the folders
        'fetched'      => date('c'),
        'files'        => count($files) - $failed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    echo str_pad('', 14), "saved ", count($files) - $failed, " files", $failed ? ", $failed failed" : '', " (license: $listing[license])\n";
}

/** Which files to download and from where, plus license and version, for one source. */
function githubListing(array $source): array
{
    [$owner, $repo] = explode('/', $source['repo']);
    $paths = (array)$source['path'];
    $files = [];
    $shas  = [];
    foreach ($paths as $path) {
        $tree = githubApi("https://api.github.com/repos/$owner/$repo/git/trees/HEAD:" . rawurlencode($path) . '?recursive=1');
        if (!empty($tree['truncated'])) {
            echo "warning: listing truncated by GitHub, some files will be missing\n";
        }
        $shas[]  = $tree['sha'];
        $baseUrl = "https://raw.githubusercontent.com/$owner/$repo/HEAD/" . str_replace('%2F', '/', rawurlencode($path)) . '/';
        foreach ($tree['tree'] as $entry) {
            if ($entry['type'] === 'blob' && wanted($entry['path'], $source)) {
                $files[savedPath($path, $entry['path'], $paths)] = $baseUrl . str_replace('%2F', '/', rawurlencode($entry['path']));
            }
        }
    }
    $license = githubApi("https://api.github.com/repos/$owner/$repo/license", allow404: true);   // 404: no single license file (WebKit)
    return [
        'files'       => $files,
        'version'     => implode(',', $shas),
        'license'     => $license['license']['spdx_id'] ?? 'unknown',
        'licenseText' => $license === null ? "No single license file in the repo; see https://github.com/$owner/$repo\n" : base64_decode($license['content']),
    ];
}

function gitlabListing(array $source): array
{
    $project = "https://$source[gitlab]/api/v4/projects/" . rawurlencode($source['repo']);
    $paths   = (array)$source['path'];
    $files   = [];
    foreach ($paths as $path) {
        $tree = json_decode(httpGet("$project/repository/tree?path=" . rawurlencode($path) . '&recursive=true&per_page=100'), true, 512, JSON_THROW_ON_ERROR);
        if (count($tree) === 100) {
            echo "warning: folder has 100 entries or more, the listing may be cut short\n";
        }
        foreach ($tree as $entry) {
            if ($entry['type'] === 'blob' && wanted($entry['path'], $source)) {
                $relative = substr($entry['path'], strlen($path) + 1);
                $files[savedPath($path, $relative, $paths)] = "$project/repository/files/" . rawurlencode($entry['path']) . '/raw?ref=HEAD';
            }
        }
    }
    $lastCommit = json_decode(httpGet("$project/repository/commits?per_page=1&path=" . rawurlencode(commonFolder($paths))), true, 512, JSON_THROW_ON_ERROR);
    return [
        'files'       => $files,
        'version'     => $lastCommit[0]['id'],
        'license'     => $source['license'],
        'licenseText' => httpGet("$project/repository/files/COPYING.LIB/raw?ref=HEAD", allow404: true) ?? httpGet("$project/repository/files/LICENSE/raw?ref=HEAD"),
    ];
}

/** Files to download: by extension and the source's pattern, and never a name that could write outside its corpus folder. */
function wanted(string $path, array $source): bool
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, $source['keep'], true)
        && (!isset($source['match']) || preg_match($source['match'], $path))
        && !preg_match('~\\\\|(^|/)\.\.(/|$)~', $path);   // a backslash (git allows it in a name, Windows reads it as a separator) or a .. segment
}

/** Where a file goes under corpus/<name>/: as listed, or under the folder's last segment when the source has several folders. */
function savedPath(string $folder, string $relative, array $folders): string
{
    return count($folders) > 1 ? basename($folder) . "/$relative" : $relative;
}

function commonFolder(array $paths): string
{
    $common = explode('/', $paths[0]);
    foreach ($paths as $path) {
        $parts = explode('/', $path);
        while ($common !== [] && array_slice($parts, 0, count($common)) !== $common) {
            array_pop($common);
        }
    }
    return implode('/', $common);
}

/** Adds xmlns (and xmlns:xlink when xlink: is used) to a root <svg> tag that declares neither. */
function addSvgNamespace(string $svg): string
{
    if (!preg_match('/<svg[\s>\/]/', $svg, $match, PREG_OFFSET_CAPTURE)) {
        return $svg;
    }
    $tagStart = $match[0][1];
    $tagEnd   = strpos($svg, '>', $tagStart);
    $tag      = substr($svg, $tagStart, $tagEnd - $tagStart);
    $add      = '';
    if (!preg_match('/\sxmlns\s*=/', $tag)) {
        $add .= ' xmlns="http://www.w3.org/2000/svg"';
    }
    if (str_contains($svg, 'xlink:') && !preg_match('/\sxmlns:xlink\s*=/', $tag)) {
        $add .= ' xmlns:xlink="http://www.w3.org/1999/xlink"';
    }
    return $add === '' ? $svg : substr_replace($svg, "<svg$add", $tagStart, 4);
}

function githubApi(string $url, bool $allow404 = false): ?array
{
    $body = httpGet($url, $allow404);
    return $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

/** One GET, with the GitHub token when the URL is GitHub's API. Exits on any failure other than an allowed 404. */
function httpGet(string $url, bool $allow404 = false): ?string
{
    $headers = ['User-Agent: svgsecuritycheck-fetch-corpus', 'Accept: application/json'];
    $token   = getenv('GITHUB_TOKEN');
    if ($token && str_starts_with($url, 'https://api.github.com/')) {
        $headers[] = "Authorization: Bearer $token";
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_HEADER => true]);
    $response   = curl_exec($curl);
    $status     = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    if ($status === 404 && $allow404) {
        return null;
    }
    if ($response === false || $status !== 200) {
        $body      = is_string($response) ? substr($response, $headerSize) : '';
        $remaining = preg_match('/x-ratelimit-remaining:\s*(\d+)/i', (string)$response, $match) ? "$match[1] API calls left this hour" : 'rate limit unknown';
        fwrite(STDERR, "\nRequest failed (HTTP $status, $remaining): $url\n" . substr($body, 0, 300) . "\n");
        exit(1);
    }
    return substr($response, $headerSize);
}

/** Downloads every url to its path key, PARALLEL at a time. Returns how many failed. */
function downloadAll(array $urls): int
{
    $multi   = curl_multi_init();
    $queue   = $urls;
    $active  = [];   // curl handle id => [path, handle]
    $done    = 0;
    $failed  = 0;
    $total   = count($urls);
    $addNext = static function () use (&$queue, &$active, $multi): void {
        $path = array_key_first($queue);
        $url  = $queue[$path];
        unset($queue[$path]);
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'svgsecuritycheck-fetch-corpus']);
        curl_multi_add_handle($multi, $handle);
        $active[(int)$handle] = [$path, $handle];
    };

    while ($queue !== [] && count($active) < PARALLEL) {
        $addNext();
    }
    while ($active !== []) {
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($multi)) {
            $handle          = $info['handle'];
            [$path, ]        = $active[(int)$handle];
            $ok              = $info['result'] === CURLE_OK && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
            if ($ok) {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                file_put_contents($path, curl_multi_getcontent($handle));
            } else {
                $failed++;
                fwrite(STDERR, "\nfailed: " . curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) . "\n");
            }
            $done++;
            curl_multi_remove_handle($multi, $handle);
            unset($active[(int)$handle]);
            if ($queue !== []) {
                $addNext();
            }
            if ($done % 100 === 0 || $done === $total) {
                echo "\r", str_pad('', 14), "downloaded $done / $total";
            }
        }
        if ($active !== []) {
            curl_multi_select($multi, 1.0);
        }
    }
    curl_multi_close($multi);
    echo "\n";
    return $failed;
}

function deleteDirectory(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
