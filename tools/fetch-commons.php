#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Downloads the design-tool exports listed in tools/commons-manifest.json from Wikimedia
 * Commons into corpus/own/commons/ (gitignored), one file a second. The README's "N of M
 * design-tool exports are accepted" comes from running tools/tally.php over this folder.
 *
 *     php tools/fetch-commons.php             # download whatever the manifest lists and corpus/ lacks
 *     php tools/fetch-commons.php --relist    # rebuild the manifest from Commons first, then download
 *
 * The manifest holds, per file, the Commons page, the download URL, the license and the size.
 * --relist asks Commons for the newest 30 SVG files in each "Created with <tool>" category
 * (the uploader's tag, so a few files are filed under the wrong tool), skips files over 3 MB
 * and names too long for a filesystem, and rewrites the manifest. Commit the manifest with
 * the README number it produced.
 *
 * Commons answers 429 to more than about one request a second, so this script sleeps a
 * second between downloads and waits out a 429. Expect three minutes for a full download.
 */

const MANIFEST   = __DIR__ . '/commons-manifest.json';
const CORPUS_DIR = __DIR__ . '/../corpus/own/commons';
const API        = 'https://commons.wikimedia.org/w/api.php';
const USER_AGENT = 'SvgSecurityCheck-corpus/1.0 (https://github.com/interactivetools-com/SvgSecurityCheck)';
const PER_TOOL   = 30;
const MAX_BYTES  = 3000000;
const TOOLS      = ['adobe-illustrator' => 'Adobe Illustrator', 'affinity-designer' => 'Affinity Designer', 'coreldraw' => 'CorelDRAW', 'figma' => 'Figma', 'inkscape' => 'Inkscape', 'sketch' => 'Sketch'];

if (in_array('--relist', $argv, true)) {
    $files = [];
    foreach (TOOLS as $folder => $tool) {
        $listed = listNewest($tool);
        echo str_pad($folder, 18), count($listed), " files\n";
        $files += $listed;
    }
    ksort($files);
    file_put_contents(MANIFEST, json_encode([
        'source'  => 'Wikimedia Commons',
        'how'     => 'per tool, the ' . PER_TOOL . ' newest SVG files in the deep category "Created with <tool>", files over ' . MAX_BYTES . ' bytes skipped; php tools/fetch-commons.php --relist',
        'license' => 'per file below; Commons files are CC0, CC BY, CC BY-SA or public domain',
        'listed'  => date('c'),
        'files'   => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");   // non-ASCII file names as \uXXXX, so the manifest stays ASCII
    echo "manifest written: ", count($files), " files\n";
}

$manifest = json_decode(file_get_contents(MANIFEST), true, 512, JSON_THROW_ON_ERROR);
$fetched  = 0;
$skipped  = 0;
foreach ($manifest['files'] as $relative => $file) {
    $path = CORPUS_DIR . "/$relative";
    if (is_file($path) && filesize($path) === $file['bytes']) {
        $skipped++;
        continue;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, httpGet($file['url']));
    $fetched++;
    echo "\rdownloaded $fetched";
    sleep(1);
}
echo $fetched ? "\n" : '', "$fetched downloaded, $skipped already present. Run: php tools/tally.php own\n";

/**
 * The newest PER_TOOL SVG files tagged as made with $tool, keyed by "<folder>/<file name>".
 *
 * @return array<string, array{page: string, url: string, license: string, bytes: int}>
 */
function listNewest(string $tool): array
{
    $folder = array_search($tool, TOOLS, true);
    $query  = http_build_query([
        'action'              => 'query',
        'generator'           => 'search',
        'gsrnamespace'        => 6,   // File:
        'gsrlimit'            => PER_TOOL,
        'gsrsort'             => 'create_timestamp_desc',
        'gsrsearch'           => "filemime:image/svg+xml deepcategory:\"Created with $tool\"",
        'prop'                => 'imageinfo',
        'iiprop'              => 'url|size|extmetadata',
        'iiextmetadatafilter' => 'LicenseShortName',
        'format'              => 'json',
    ]);
    $result = json_decode(httpGet(API . "?$query"), true, 512, JSON_THROW_ON_ERROR);
    $files  = [];
    foreach ($result['query']['pages'] ?? [] as $page) {
        $info = $page['imageinfo'][0];
        $url  = explode('?', $info['url'])[0];   // Commons appends tracking parameters
        $name = rawurldecode(basename($url));
        if ($info['size'] > MAX_BYTES || strlen($name) > 200) {
            continue;
        }
        $files["$folder/$name"] = [
            'page'    => 'https://commons.wikimedia.org/wiki/' . str_replace(' ', '_', $page['title']),
            'url'     => $url,
            'license' => $info['extmetadata']['LicenseShortName']['value'] ?? 'unknown',
            'bytes'   => $info['size'],
        ];
    }
    return $files;
}

/** One GET. Waits and retries on 429; exits on any other failure. */
function httpGet(string $url): string
{
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => USER_AGENT, CURLOPT_TIMEOUT => 60]);
        $body   = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 200 && is_string($body)) {
            return $body;
        }
        if ($status !== 429) {
            fwrite(STDERR, "\nRequest failed (HTTP $status): $url\n");
            exit(1);
        }
        sleep(10 * $attempt);   // Commons asks bots to slow down; 10, 20, 30 ... seconds
    }
    fwrite(STDERR, "\nStill rate limited after 5 attempts: $url\n");
    exit(1);
}
