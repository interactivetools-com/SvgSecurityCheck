# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-10-13, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
5,636 files `tools/fetch-corpus.php` and `tools/fetch-commons.php` download; `own` is the
Wikimedia Commons set. The sanitizer columns are enshrined/svg-sanitize 1.0.0 installed in a
folder outside the repository.

## PHP 8.5

```text
PHP 8.5.11, libxml 2.9.7, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off

## Generated files

| File   | Check time | Throughput | Peak memory added | svg-sanitize time | svg-sanitize memory |
|--------|------------|------------|-------------------|-------------------|---------------------|
| 1 KB   | 0.087 ms   | 19 MB/s    | 0 bytes           | 0.21 ms           | 564 KB              |
| 100 KB | 1.05 ms    | 93 MB/s    | 0 bytes           | 1.92 ms           | 1.2 MB              |
| 1 MB   | 9.82 ms    | 102 MB/s   | 0 bytes           | 18.0 ms           | 9.4 MB              |
| 10 MB  | 99.2 ms    | 101 MB/s   | 0 bytes           | 209.7 ms          | 94.2 MB             |
| 50 MB  | 490.1 ms   | 102 MB/s   | 0 bytes           | 1130.0 ms         | 443.5 MB            |

## Hostile files

| File                      | Size      | Rejected as                     | Check time | Peak memory added |
|---------------------------|-----------|---------------------------------|------------|-------------------|
| reference bomb            | 1 KB      | `reference-expansion-too-large` | 0.11 ms    | 0 bytes           |
| reference loop            | 165 bytes | `reference-expansion-too-large` | 0.034 ms   | 0 bytes           |
| reference flood           | 13 KB     | `reference-expansion-too-large` | 6.91 ms    | 6.8 MB            |
| entity-expansion DOCTYPE  | 583 bytes | `doctype-not-allowed`           | 0.005 ms   | 0 bytes           |
| 100,000 levels of nesting | 684 KB    | `malformed-xml`                 | 0.100 ms   | 864 KB            |
| 100,000 violations        | 6.5 MB    | `href-not-allowed`              | 0.19 ms    | 0 bytes           |

## Corpus: 5,636 files, 51.7 MB, checked in 0.51 s (11,088 files/s, 102 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                                    |
|-----------------|-------|-----------------|---------------|-------------------------------------------------|
| under 10 KB     | 5,502 | 0.024 ms        | 0.032 ms      | 0.57 ms (useDosTest.svg, 8 KB)                  |
| 10 KB to 100 KB | 76    | 0.13 ms         | 0.24 ms       | 1.05 ms (Plutchik's_Emotional_Wheel.svg, 43 KB) |
| 100 KB to 1 MB  | 32    | 1.89 ms         | 3.22 ms       | 27.6 ms (useDosCleanTwo.svg, 506 KB)            |
| over 1 MB       | 26    | 3.51 ms         | 7.82 ms       | 108.8 ms (useDosTestTwo.svg, 1.5 MB)            |

| Source       | Files | Median per file | Mean per file | Slowest file                                                    |
|--------------|-------|-----------------|---------------|-----------------------------------------------------------------|
| borewit      | 39    | 0.036 ms        | 0.037 ms      | 0.11 ms (friendly.svg, 2 KB)                                    |
| chromium     | 48    | 0.040 ms        | 0.053 ms      | 0.15 ms (image-preserveAspectRatio-all.svg, 3 KB)               |
| enshrined    | 35    | 0.046 ms        | 3.97 ms       | 108.8 ms (useDosTestTwo.svg, 1.5 MB)                            |
| gecko        | 63    | 0.030 ms        | 0.032 ms      | 0.091 ms (img-and-image-1-ref.svg, 2 KB)                        |
| librsvg      | 84    | 0.034 ms        | 0.097 ms      | 2.84 ms (bug1100-fuzz-layer-nesting-depth.svg, 100 KB)          |
| mediawiki    | 6     | 0.075 ms        | 0.058 ms      | 0.090 ms (inkscape-only-selected.svg, 3 KB)                     |
| own          | 157   | 0.21 ms         | 1.17 ms       | 11.7 ms (US_Veterans_Health_Administration_logo.svg, 805 KB)    |
| payloads     | 8     | 0.032 ms        | 0.034 ms      | 0.057 ms (SVG_XSS_red_lightning.svg, 895 bytes)                 |
| resvg        | 1,679 | 0.043 ms        | 0.044 ms      | 0.14 ms (embedded-16bit-png.svg, 22 KB)                         |
| simple-icons | 3,460 | 0.022 ms        | 0.024 ms      | 0.15 ms (elsevier.svg, 53 KB)                                   |
| svgo         | 3     | 0.012 ms        | 0.015 ms      | 0.026 ms (invalid.svg, 11 bytes)                                |
| webkit       | 33    | 0.062 ms        | 0.062 ms      | 0.18 ms (image-with-nested-data-uri-images.svg, 5 KB)           |
| wpt-as-image | 2     | 0.029 ms        | 0.029 ms      | 0.030 ms (external-resource-inline-sheet-helper.svg, 187 bytes) |
| wpt-embedded | 19    | 0.048 ms        | 0.26 ms       | 2.74 ms (image-large-bitmap.svg, 1.4 MB)                        |

```

## PHP 8.1

```text
PHP 8.1.34, libxml 2.9.7, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off

## Generated files

| File   | Check time | Throughput | Peak memory added | svg-sanitize time | svg-sanitize memory |
|--------|------------|------------|-------------------|-------------------|---------------------|
| 1 KB   | 0.10 ms    | 16 MB/s    | 0 bytes           | 0.21 ms           | 564 KB              |
| 100 KB | 1.40 ms    | 70 MB/s    | 0 bytes           | 2.02 ms           | 1.3 MB              |
| 1 MB   | 13.4 ms    | 74 MB/s    | 0 bytes           | 18.9 ms           | 10.3 MB             |
| 10 MB  | 134.7 ms   | 74 MB/s    | 0 bytes           | 214.3 ms          | 92.5 MB             |
| 50 MB  | 676.2 ms   | 74 MB/s    | 0 bytes           | 1086.0 ms         | 446.5 MB            |

## Hostile files

| File                      | Size      | Rejected as                     | Check time | Peak memory added |
|---------------------------|-----------|---------------------------------|------------|-------------------|
| reference bomb            | 1 KB      | `reference-expansion-too-large` | 0.12 ms    | 0 bytes           |
| reference loop            | 165 bytes | `reference-expansion-too-large` | 0.031 ms   | 0 bytes           |
| reference flood           | 13 KB     | `reference-expansion-too-large` | 7.12 ms    | 6.6 MB            |
| entity-expansion DOCTYPE  | 583 bytes | `doctype-not-allowed`           | 0.004 ms   | 0 bytes           |
| 100,000 levels of nesting | 684 KB    | `malformed-xml`                 | 0.10 ms    | 0 bytes           |
| 100,000 violations        | 6.5 MB    | `href-not-allowed`              | 0.21 ms    | 0 bytes           |

## Corpus: 5,636 files, 51.7 MB, checked in 0.53 s (10,555 files/s, 97 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                                    |
|-----------------|-------|-----------------|---------------|-------------------------------------------------|
| under 10 KB     | 5,502 | 0.024 ms        | 0.032 ms      | 0.62 ms (useDosTest.svg, 8 KB)                  |
| 10 KB to 100 KB | 76    | 0.13 ms         | 0.27 ms       | 1.14 ms (Plutchik's_Emotional_Wheel.svg, 43 KB) |
| 100 KB to 1 MB  | 32    | 2.09 ms         | 3.49 ms       | 27.8 ms (useDosCleanTwo.svg, 506 KB)            |
| over 1 MB       | 26    | 3.64 ms         | 8.15 ms       | 113.5 ms (useDosTestTwo.svg, 1.5 MB)            |

| Source       | Files | Median per file | Mean per file | Slowest file                                                    |
|--------------|-------|-----------------|---------------|-----------------------------------------------------------------|
| borewit      | 39    | 0.035 ms        | 0.037 ms      | 0.11 ms (friendly.svg, 2 KB)                                    |
| chromium     | 48    | 0.038 ms        | 0.055 ms      | 0.17 ms (image-preserveAspectRatio-all.svg, 3 KB)               |
| enshrined    | 35    | 0.045 ms        | 4.11 ms       | 113.5 ms (useDosTestTwo.svg, 1.5 MB)                            |
| gecko        | 63    | 0.030 ms        | 0.032 ms      | 0.092 ms (img-and-image-1-ref.svg, 2 KB)                        |
| librsvg      | 84    | 0.034 ms        | 0.11 ms       | 3.34 ms (bug1100-fuzz-layer-nesting-depth.svg, 100 KB)          |
| mediawiki    | 6     | 0.075 ms        | 0.059 ms      | 0.090 ms (inkscape-only-selected.svg, 3 KB)                     |
| own          | 157   | 0.22 ms         | 1.25 ms       | 12.7 ms (US_Veterans_Health_Administration_logo.svg, 805 KB)    |
| payloads     | 8     | 0.031 ms        | 0.033 ms      | 0.058 ms (SVG_XSS_red_lightning.svg, 895 bytes)                 |
| resvg        | 1,679 | 0.045 ms        | 0.046 ms      | 0.15 ms (optimizeSpeed-on-SVG.svg, 5 KB)                        |
| simple-icons | 3,460 | 0.022 ms        | 0.023 ms      | 0.15 ms (elsevier.svg, 53 KB)                                   |
| svgo         | 3     | 0.013 ms        | 0.015 ms      | 0.026 ms (invalid.svg, 11 bytes)                                |
| webkit       | 33    | 0.063 ms        | 0.064 ms      | 0.18 ms (image-with-nested-data-uri-images.svg, 5 KB)           |
| wpt-as-image | 2     | 0.028 ms        | 0.028 ms      | 0.028 ms (external-resource-inline-sheet-helper.svg, 187 bytes) |
| wpt-embedded | 19    | 0.047 ms        | 0.26 ms       | 2.74 ms (image-large-bitmap.svg, 1.4 MB)                        |

```
