#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# Uses the CLI's default model, reasoning effort and scan limits. Additional CLI
# flags can be passed as arguments. Run scans one repo at a time: concurrent scans
# share a sandbox dir in /tmp and kill each other's workers.
# Full pre-release scan:
#   .github/scripts/codex-security-scan.sh --mode deep
# Pick a model:
#   .github/scripts/codex-security-scan.sh --mode deep --model gpt-6-astra
set -euo pipefail
cd "$(dirname "$0")/../.."

# There's no exclude flag, so build the path list here: everything except tests,
# gitignored files (vendor, corpus, caches, .idea) and __* scratch notes. Skipping
# those keeps the scan on shipped code; corpus/ is thousands of third-party SVG
# files, and the scratch notes get quoted back as evidence, which we don't want
# steering the results.
shopt -s dotglob
paths=()
for entry in *; do
    if [[ $entry == .git || $entry == tests || $entry == __* ]] || git check-ignore -q "$entry"; then
        continue
    fi
    paths+=(--path "$entry")
done

# The scan prompt lives here (written to a temp file at runtime) so the repo
# needs no scratch file. It says what the library promises and what it does not
# check on purpose; without that, the scanner reports the documented boundaries
# (no MIME check, unencoded detail, files that render as nothing) as findings
# instead of looking for a file that gets past the rules. It states the intent (what
# an accepted file may do in a browser) and asks for the browser behavior behind a
# gap, not a working demonstration: workers turn attack vocabulary into attack
# files, and OpenAI's content check refuses those workers mid-scan.
prompt_file=$(mktemp)
trap 'rm -f "$prompt_file"' EXIT
cat > "$prompt_file" <<'PROMPT'
This is a whole-library pre-release scan of SvgSecurityCheck, a PHP 8.1+ library
that checks an uploaded SVG file and rejects it when it could run script, load
an outside resource, or hang a renderer. It validates and never rewrites.
tests/, vendor/ and corpus/ are excluded on purpose. Review runtime source,
documentation examples, and maintainer tooling (tools/, .github/) in their
actual contexts; the tools download and tally third-party test files on a
developer machine and never run in a deployed web application. Assess the
current checkout for release readiness. Calibrate severity to the evidence;
not every finding is a release blocker.

What the library promises

An accepted file, shown through an <img> tag or opened as its own document,
stays inside what current Chrome, Firefox and Safari allow an SVG image to
do: it cannot run script, cannot load anything outside itself, and cannot
interact with the page or navigate. The same file is safe in a server-side
rasterizer such as librsvg or resvg. docs/internal/browser-research.md is the
reference for what the browsers allow; docs/ai-reference.md lists every rule
and allowlist and is the specification.

What to look for

A file the library accepts that a browser or rasterizer treats differently
from what the rules assume. The likely places:

- An allowed element, attribute, namespace or URL form that a browser can
  make run script, fetch, or navigate. Name the browser and the behavior.
- Something libxml2 reads one way and a browser another: namespace
  handling, name case, xlink:href against href, entities, CDATA, the byte
  order mark and encoding declaration, control bytes, invalid UTF-8, nested
  data: SVG, and anything that lets the prolog or root checks see a
  different document than the browser does.
- Animation reaching an attribute the rules deny on the element itself.
- A reference chain or nesting that the expansion counter or the depth
  limits do not follow, so a browser tab or rasterizer hangs on an accepted
  file.

For each, give the rule that should have applied, why it did not, the
browser behavior with its source (a spec section or a web-platform test),
and the smallest file that shows the gap with a harmless marker, such as a
rectangle that renders red when the gap is real. A working demonstration is
not wanted and not needed.

Do not report any of these, not as a finding, a note, or a hardening
suggestion. They are documented decisions, and time spent on them is
wasted:

- Violation detail and message are copied from the file, always one line of
  valid UTF-8; the docs say to encode them for the output. The one thing to
  flag is a documentation example that puts them into HTML or JavaScript
  unencoded; the library returning them is correct.
- File name, extension, MIME type and size are not checked; the docs make
  them the upload handler's job.
- A file that passes may render as nothing.
- References made through CSS class selectors are not followed by the
  expansion counter; a documented gap.
- Rejecting more than Chrome's <img> mode would (external links,
  foreignObject, unknown elements) is by design.
- The library's own time and memory: reading is linear in file size, and
  what it keeps is capped by documented limits.

Use prior findings as leads, not proof. Verify the current implementation,
consolidate one root cause reported several ways, and calibrate severity to
what an accepted file can actually do.
PROMPT

codex-security scan . "${paths[@]}" \
    --knowledge-base docs/ai-reference.md \
    --knowledge-base docs/errors.md \
    --knowledge-base docs/internal/browser-research.md \
    --scan-prompt-file "$prompt_file" \
    "$@"
