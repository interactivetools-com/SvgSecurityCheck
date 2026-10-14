# Benchmarks

`check-speed.php` times `checkFile()` on generated files of known sizes, on a set of
hostile files built to hang a renderer or slow the check itself, and on the real files in
`corpus/`, and prints the results as Markdown tables. [results.md](results.md) holds the
latest run on each PHP version tested, with a note on the machine and the corpus, and the
main README links it.

Run it through `run.sh`, which turns opcache on and xdebug off and caps the memory and
process count of everything it starts:

```bash
benchmarks/run.sh                                                 # generated and hostile files
benchmarks/run.sh --corpus=corpus                                 # add the real files (php tools/fetch-corpus.php and tools/fetch-commons.php first)
benchmarks/run.sh --sanitizer=/path/to/vendor/autoload.php        # add enshrined/svg-sanitize columns for comparison
PHP=/opt/plesk/php/8.5/bin/php benchmarks/run.sh                  # a PHP binary that is not on the PATH
```

The generated and hostile files are timed in a fresh PHP process each, fastest of seven calls
after one warm-up call, and that process also reports how much its peak memory grew during
the calls. That growth includes libxml2's own allocations, which `memory_get_peak_usage()`
cannot see. The corpus is timed in the main process, three passes, fastest time per file.

`run.sh` and the memory columns need Linux: the script reads thread counts with `ps -o nlwp`
and the memory figures come from `/proc`. On another system, run
`php -d opcache.enable_cli=1 -d xdebug.mode=off benchmarks/check-speed.php` with the same
arguments; the memory columns then print n/a.

The sanitizer comparison needs
[`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer) installed somewhere
outside this repository, for example with `composer require enshrined/svg-sanitize` in an
empty folder. The package is GPL licensed: running it for a timing comparison on your own machine is a
use the license allows, but it is never a dependency here and none of its code ships with
this MIT library. Point `--sanitizer` at that install's `vendor/autoload.php`.
