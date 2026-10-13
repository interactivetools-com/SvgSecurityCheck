# Benchmarks

`check-speed.php` times `checkFile()` on generated files of known sizes, on a set of
hostile files built to hang a renderer, and on the real files in `corpus/`, and prints the
results as Markdown tables. [results.md](results.md) is the output of the latest run, which
the main README links.

Run it through `run.sh`, which turns opcache on and xdebug off and caps the memory and
process count of everything it starts:

```bash
benchmarks/run.sh                                                 # generated and hostile files
benchmarks/run.sh --corpus=corpus                                 # add the real files (php tools/fetch-corpus.php first)
benchmarks/run.sh --sanitizer=/path/to/vendor/autoload.php        # add enshrined/svg-sanitize columns for scale
PHP=/opt/plesk/php/8.5/bin/php benchmarks/run.sh                  # a PHP binary that is not on the PATH
```

The generated and hostile files are timed in a fresh PHP process each, which also reports
how much its peak memory grew during the timed calls. That growth includes libxml2's own
allocations, which `memory_get_peak_usage()` cannot see. The corpus is timed in the main
process, three passes, fastest time per file.

The sanitizer comparison needs `enshrined/svg-sanitize` installed somewhere outside this
repository. It is GPL licensed: running it for a timing comparison on your own machine is a
use the license allows, but it is never a dependency here and none of its code ships with
this MIT library. Point `--sanitizer` at that install's `vendor/autoload.php`.
