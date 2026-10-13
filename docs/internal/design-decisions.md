# SvgSecurityCheck Design Decisions

Settled decisions with their rationale, all made in 2026-09 while the library was built.
Check here before proposing a feature, a rule change, or a rename. Only the road-not-taken
half is recorded; current behavior is in the source, the docblocks, the changelog, and the
tests.

Contents:

- [Reject, Never Rewrite](#reject-never-rewrite)
- [The Target: Chrome's `<img>` Mode, Stricter Where It Matters](#the-target-chromes-img-mode-stricter-where-it-matters)
- [Allowlists, Not Denylists](#allowlists-not-denylists)
- [Parser: XMLReader Streaming](#parser-xmlreader-streaming)
- [No Line Numbers](#no-line-numbers)
- [A List of Errors, Not the First One](#a-list-of-errors-not-the-first-one)
- [Three Classes, No Options](#three-classes-no-options)
- [Messages Report the Finding, Not the Fix](#messages-report-the-finding-not-the-fix)
- [DOCTYPE: Only the Internal Subset Rejects](#doctype-only-the-internal-subset-rejects)
- [External Links in `<a>` Reject](#external-links-in-a-reject)
- [CSS by Regex, Not a Tokenizer](#css-by-regex-not-a-tokenizer)
- [No Regex Limit CI Step](#no-regex-limit-ci-step)
- [`url()` Is Checked in Every Attribute](#url-is-checked-in-every-attribute)
- [Embedded SVG Images Get the Full Check](#embedded-svg-images-get-the-full-check)
- [Reference Loops and Expansion Bombs](#reference-loops-and-expansion-bombs)
- [Design-Tool Namespaces Are Inert](#design-tool-namespaces-are-inert)
- [Out of Scope: `.svgz`, HTML Uploads, Editor Output](#out-of-scope-svgz-html-uploads-editor-output)
- [The Corpus Stays Out of the Repo](#the-corpus-stays-out-of-the-repo)
- [Naming](#naming)

## Reject, Never Rewrite

The library reports what is wrong and leaves the file alone, unlike enshrined/svg-sanitize
and DOMPurify.

1. The uploader has the source in a design tool and can re-export. Strict rules cost them a
   re-export; loose rules cost the site an XSS.
2. A logo that renders differently from what was uploaded is a support ticket nobody can
   explain.
3. A sanitizer's output has to be re-checked to know it is safe. Accept or reject has no
   output to get wrong.

The mirror image holds for input the author never sees, such as WYSIWYG editor output:
filter it and report what was removed. Not this library's job.

## The Target: Chrome's `<img>` Mode, Stricter Where It Matters

Browsers do not filter an SVG shown through `<img>`. They parse everything, then turn off
script, plugins, interaction and every fetch that is not a `data:` URL, at render time
(SVG 2 "secure animated mode"). Fragment references and `data:` images are allowed because
the spec says they are not external; animation is allowed because the spec says it should
run. Where Chrome only neutralizes something, the rules reject it, because the protection
is gone when the file is opened directly, saved and reopened, or rasterized on a server:

| Chrome in `<img>`                          | Same file opened directly       | Rule                                       |
|--------------------------------------------|---------------------------------|--------------------------------------------|
| `<script>` parsed, never runs              | runs                            | `element-not-allowed`                      |
| `on*` attributes compile to nothing        | fire                            | `event-handler`                            |
| `javascript:` href is inert                | navigates                       | `href-not-allowed`                         |
| `<a href="https://...">` is inert          | clickable                       | `href-not-allowed`                         |
| external `url()` silently blocked          | loads, phones home              | `url-not-fragment`, `css-not-allowed`      |
| `<?xml-stylesheet href="#id"?>` processed  | processed                       | `processing-instruction`                   |
| `<foreignObject>` renders, no script       | scripts inside run              | `element-not-allowed`                      |
| DTD entities expand                        | expand                          | `doctype-not-allowed`                      |
| reference loops broken by the renderer     | hang or crash other renderers   | `reference-expansion-too-large`            |
| `<!-->` is a comment to the XML parser     | an HTML parser closes it early  | `comment-not-allowed`                      |
| `<![CDATA[` in `<title>` is text           | an HTML parser ends it at `>`   | `cdata-not-allowed`                        |

Looser than Chrome, or not mirrored, where there is no attack:

- `:hover`, `:visited`, `:focus` in CSS and `begin="click"` in SMIL are accepted. Chrome
  makes them do nothing in `<img>`; opened directly they work. Nothing loads or runs.
- `animateColor` and `discard` are rejected as unknown elements. Dead in every current
  browser; no design tool exports them.
- `foreignObject` is rejected although Chrome renders it. Keeping it would mean allowing
  and checking most of HTML inside it.

## Allowlists, Not Denylists

A denylist fails the first time a browser adds a feature. An allowlist fails the other way:
a new harmless feature rejects until someone adds it, which costs a re-export and a
one-line change, not an XSS. The corpus tally is how the lists were grown until real icons
and design-tool exports passed.

## Parser: XMLReader Streaming

`XMLReader` with `LIBXML_NONET`, `LOADDTD` off, `SUBST_ENTITIES` off.

1. Constant memory whatever the file size.
2. DOCTYPE and processing instructions come out before the root element. A byte-level check
   on the first 64 KB runs first for what the parser hides: the encoding declaration, a
   UTF-16 or UTF-32 BOM, a DOCTYPE with an internal subset, a root tag that never arrives.
3. libxml2's defaults are free denial-of-service limits: depth 256, one text node 10 MB.
   `XML_PARSE_HUGE` is never passed; switching parsers would silently drop these caps.
4. Same parser family as the browsers' own tests and the sanitizers.

Rejected: `DOMDocument` (parses the whole file before the first check runs), the `ext/xml`
SAX API (callbacks make the walk harder to read for no gain), a hand-written tokenizer (a
second XML parser is a second set of parsing differences to get wrong).

Consequence: libxml2 parses in chunks of a few hundred bytes and a well-formedness error
discards its chunk, so a small malformed file reports only `malformed-xml`. The tests pin
both the small and the large case.

## No Line Numbers

`XMLReader` only exposes a line number through `expand()`, which parses the subtree into a
DOM and gives up streaming. Code plus detail identifies the problem for someone with the
file open, and the uploader re-exports rather than editing line 12.

## A List of Errors, Not the First One

An early draft stopped at the first violation. It changed because a real file usually has
several problems of the same kind (one `onload` per element, one external image per icon),
and reporting all of them lets the uploader fix the file once. Dedupe by code and detail so
five `<script>` elements are one error; cap at 50 so a hostile file cannot make the result
large; `malformed-xml` ends the list because nothing after a parse error is trustworthy.
No exceptions for a failed file: a hostile upload is expected input, so `file-unreadable`
is a rejection like any other and the caller has one code path.

## Three Classes, No Options

`SvgSecurityCheck`, `Result`, `Violation`. The first drafts had one class and an array-shaped
result; typed readonly properties won because the IDE and static analysis check them.
Nothing else gets a class, and there are no dependencies beyond ext-xmlreader and
ext-libxml: security code benefits from being short enough to read in one sitting.

No options: every install checks the same way, the docs say exactly what passes, and there
is no "we turned that check off" ticket. The size limits are public static properties, the
one exception. Add an option when a real caller needs one, and record here why.

## Messages Report the Finding, Not the Fix

`Violation::$message` names what was found and stops. The application knows its users and
their design tools; the library does not. `Violation::TEMPLATES` lets an application
translate or replace messages and add hints. `docs/errors.md` holds the fix per code.

Open: putting the fix hint into the message itself is deferred to a later pass (2026-09-14).
Reopen this section then.

## DOCTYPE: Only the Internal Subset Rejects

A DOCTYPE with an internal subset (`[ ... ]`) rejects; any other DOCTYPE passes. External
DTD references fetch nothing (`LOADDTD` off, `LIBXML_NONET`), so an entity such a DTD would
define comes back as `malformed-xml`, not as an expansion.

Known false reject: Illustrator's "Preserve Illustrator Editing Capabilities" export puts
its private data in entity declarations. Large files, no benefit for a web image, one
checkbox to fix.

Rejected: the MediaWiki rule, which accepts only the five W3C SVG DTD public ids. The
internal subset is the only part that can do anything, and the corpus has real files with
odd but harmless DOCTYPEs.

## External Links in `<a>` Reject

Same rule as every other href. Chrome makes the link inert in `<img>`; opened directly it is
a clickable link to anywhere from a file that renders as the site's own logo. MediaWiki
allows it. The corpus found external links in test files and almost none in real icons or
exports. Revisit with evidence from real uploads.

## CSS by Regex, Not a Tokenizer

MediaWiki runs a real CSS tokenizer. The regex works because the first thing it bans is the
backslash: with no escape syntax, every token reads as written and there is no way to spell
`url(` that a regex sees differently from a browser. Presentation attributes take the same
escapes, so the ban covers every attribute value except `data-*` and `aria-*`, which no
browser reads as CSS. The corpus has no backslash in any of them.

- Comments are allowed but not stripped first: `content: "/*"` inside a string can fake a
  comment opener and hide a token after it.
- The font carve-out applies anywhere in the CSS, not only inside `@font-face`. Finding the
  block would need the tokenizer this decision avoids, and a font data URL outside
  `@font-face` loads nothing.
- `@charset` is not banned. A browser reads it only at the start of a stylesheet loaded as
  its own file, and an upload cannot load one, since `@import` and `<?xml-stylesheet?>` are
  refused. In a `<style>` element or a `style` attribute it is ignored. No current browser
  decodes UTF-7, the trick it was banned for.

## No Regex Limit CI Step

The shared standard asks for a megabyte test and a `pcre.jit=0` CI step when one regex
attempt can walk a large slice of the file. None here can: every pattern that runs over
file content is a literal alternation, a bounded repeat, or a possessive name run, so a long
input costs one pass and never reaches `pcre.backtrack_limit`. Measured 2026-10-13 with the
JIT off: a 900 KB name in `<style>` and a 4.5 MB name in an attribute check in under 35 ms,
and the suite passes with `-d pcre.jit=0`.

- Every regex over file content still reads `false` as a rejection, never as "no match", so
  a future pattern that can reach the limit fails closed. PcreLimitTest lowers the limit
  to 2 to reach each of those guards.
- A new pattern with a repeated group or a lazy repeat over content brings the megabyte test
  and the CI step with it, in the same change.

## `url()` Is Checked in Every Attribute

The first draft checked `url(` only in the nine attributes that take a paint server or a
reference. Now every attribute value. Cost is one regex per attribute; it closes every
attribute that turns out to accept a URL later, animation values included.

## Embedded SVG Images Get the Full Check

Browsers render an SVG inside `<image>` in secure mode, so they do not need this. Server-side
rasterizers (ImageMagick, librsvg, the thumbnailers built on them) give it no protection, and
an uploaded file is likely to meet one. Cost is one base64 decode and a recursive call, three
levels deep.

## Reference Loops and Expansion Bombs

Browsers break reference loops and cap nesting; Inkscape, ImageMagick and librsvg have hung
or crashed on such files. The check follows every same-file reference that renders its
target, counts the rendered elements, and rejects a loop or a total above 100,000, with one
code for both since the fix is the same.

- `<a href="#id">` and the animation elements are not references: linking to and animating
  an ancestor are both common and neither renders the target.
- Content inside `defs`, `symbol`, `pattern`, `mask`, `marker`, `clipPath` and `filter`
  counts only when something references it, as in a renderer. A loop in an unused
  `<symbol>` is accepted.
- References made through CSS selectors are not followed. `.st0 { fill: url(#gradient) }`
  is in nearly every Illustrator export, and following it would need selector matching to
  know which elements are inside which pattern. Known gap: a renderer's own cycle guard
  covers the loop case; the fan-out case needs class selectors written for the purpose.
- The count is iterative, not recursive: a chain can be longer than the PHP call stack
  (Xdebug stops at 512 frames) and a 2,000-deep chain of single references is legal.

## Design-Tool Namespaces Are Inert

Browsers ignore the Inkscape, sodipodi, RDF, Dublin Core, Creative Commons, Adobe, Serif,
Sketch, Visio and XML Schema vocabularies, and every design tool emits them, so they pass
without inspection except `on*`. Any other namespace rejects: XHTML, XML Events, MathML,
XInclude and the unknown ones are where the historical attacks were.

## Out of Scope: `.svgz`, HTML Uploads, Editor Output

- `.svgz`: browsers only render it with `Content-Encoding: gzip`, which upload folders do
  not send. Refuse the extension at upload time.
- HTML files: a served `.html` upload is a same-origin document with the whole browser
  attack surface. Refuse the extension.
- WYSIWYG editor output: filter, do not reject, because the author never saw the HTML.

The deciding question was whether the person can fix what they submitted.

## The Corpus Stays Out of the Repo

The allowlists were calibrated against public test suites and icon sets downloaded by
`tools/fetch-corpus.php` into a gitignored folder and scored by `tools/tally.php`. Nothing
from the corpus is copied into `tests/`: some sets are GPL or LGPL, and the rest would add
thousands of files to every clone. Running a check against a file on a developer's machine
is a use those licenses allow; copying the file into an MIT repo is not, and we do not do
it. The fetch tool keeps each source's LICENSE next to its files, and the committed fixtures
are our own, one per error code plus one per accepted feature, written from the ideas the
corpus surfaced.

## Naming

`SvgSecurityCheck`, `itools/svgsecuritycheck`, `Itools\SvgSecurityCheck`, following the
sibling libraries: CamelCase folder, repo and namespace, lowercase Packagist name with no
hyphen. "Security" covers everything the rules refuse, which is more than script: outside
resource loads and renderer hangs too. "Check" says what comes back: pass or fail with
reasons, never a cleaned copy.

Rejected: `SvgValidator` (to PHP developers "validator" means markup conformance or form
input rules); `SVGSecurityCheck` (PSR treats acronyms as words, as in `XmlReader`);
`SafeSvg`, `SvgGuard`, `SvgRules` (hide that it rejects files).

Error codes are lowercase, hyphenated, one per rule, and never renamed once released,
because applications map them to their own messages.
