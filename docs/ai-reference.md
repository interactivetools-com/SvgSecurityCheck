# SvgSecurityCheck AI Reference

This is a consolidated reference for AI coding assistants: the complete API and every
rejection rule in one file, covering SvgSecurityCheck 1.0. For human-friendly docs, see the
[README](https://github.com/interactivetools-com/SvgSecurityCheck).

Contents:

- [What SvgSecurityCheck Is](#what-svgsecuritycheck-is)
- [API](#api) - checkFile(), checkString(), rules(), Result, Violation
- [Usage Pattern](#usage-pattern)
- [Error Codes](#error-codes)
- [Rules: Structure](#rules-structure)
- [Rules: Elements and Namespaces](#rules-elements-and-namespaces)
- [Rules: Attributes](#rules-attributes)
- [Rules: URLs](#rules-urls) - href, url(), embedded images
- [Rules: CSS](#rules-css)
- [Rules: Animation](#rules-animation)
- [Rules: Reference Loops and Expansion](#rules-reference-loops-and-expansion)
- [Allowlists](#allowlists)
- [Limits](#limits)
- [How the Rules Compare to Chrome](#how-the-rules-compare-to-chrome)
- [Constraints and Gotchas](#constraints-and-gotchas)

---

## What SvgSecurityCheck Is

SvgSecurityCheck checks an uploaded SVG file and rejects it if it could run script, load anything
from outside itself, or hang a renderer, **with a list of reasons**. Chrome blocks the same
things when an SVG is shown through an `<img>` tag, but not when the file is opened on its
own; SvgSecurityCheck checks the file, so it is safe either way. The file is **never modified**:
there is no cleaned output, and the caller stores the original bytes or refuses the upload.

Every rule is an allowlist, except CSS, which is scanned for a short list of tokens.
Elements, attributes, XML namespaces, URL forms and animation targets not on a list are
rejected. The rules have no options: every install checks the same way. The size limits
are public static properties, see [Limits](#limits).

```php
use Itools\SvgSecurityCheck\SvgSecurityCheck;

$result = SvgSecurityCheck::checkFile($_FILES['logo']['tmp_name']);
$result->ok;       // true when the file passed
$result->errors;   // Violation[] - empty when ok, otherwise one per distinct problem
```

Requirements: PHP 8.1+, `ext-xmlreader`, `ext-libxml`. No other dependencies.

## API

Three final classes in the `Itools\SvgSecurityCheck` namespace. Nothing throws for a bad file:
a hostile or broken upload is expected input and comes back as a rejected `Result`.

```php
SvgSecurityCheck::checkFile(string $path): Result     // streams the file; memory does not grow with file size
SvgSecurityCheck::checkString(string $svg): Result    // same check on SVG source in a string
SvgSecurityCheck::rules(): array                      // the allowlists, for documentation and debugging
```

`checkFile()` and `checkString()` give the same result for the same bytes. `checkFile()` on
a path that is not a readable file returns a result with one `file-unreadable` error; it never
throws. Neither method looks at the file name, extension, or MIME type.

`rules()` returns an array with the keys `elements`, `attributes`, `namespacedAttributes`
(keyed by namespace URI), `inertNamespaces`, `inertProcessingInstructions`, `imageElements`,
and `dataImageTypes`. See [Allowlists](#allowlists). The lists are constants; changing the
returned array changes nothing.

### Result

```php
final class Result
{
    public readonly bool  $ok;       // true when $errors is empty
    public readonly array $errors;   // Violation[], in file order, at most 50
}
```

`errors` holds one `Violation` per distinct problem (deduplicated by code and detail), in the
order found, capped at 50. Prolog problems (`not-svg`, `not-utf8`, `doctype-not-allowed`,
`file-unreadable`) stop the check, so they arrive alone. `malformed-xml`, when present, is
always the last entry: nothing after a parse error is checked.

### Violation

```php
$violation->code;       // 'element-not-allowed' - stable across releases
$violation->detail;     // 'script' - plain text taken from the file, at most 60 characters plus '...'
$violation->template;   // '<%s> is not allowed in uploaded SVGs'
$violation->message;    // '<script> is not allowed in uploaded SVGs'
Violation::TEMPLATES;   // every message template keyed by code, one %s each
```

All four properties are readonly strings.

`detail` and `message` contain text from the uploaded file. HTML-encode them before output.

For translation, `template` goes through the translation function and `detail` goes back in
with `sprintf()`. The template holds literal `<` and `>`, so the whole result is encoded:

```php
echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));
```

`Violation::TEMPLATES` lists every template by code so a translation system can register
them all up front.

## Usage Pattern

```php
use Itools\SvgSecurityCheck\SvgSecurityCheck;

$upload = $_FILES['logo'];
$result = SvgSecurityCheck::checkFile($upload['tmp_name']);

if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo '<p>', htmlspecialchars($violation->message), '</p>';
    }
    exit;
}
move_uploaded_file($upload['tmp_name'], "/var/www/uploads/logo.svg");   // the original bytes, unchanged
```

SVG sanitizers return a cleaned string to store. SvgSecurityCheck does not; store the original.

```php
// WRONG - checkString() returns a Result, not a cleaned SVG
$clean = SvgSecurityCheck::checkString($svg);
file_put_contents($path, $clean);

// RIGHT - check, then store the original bytes only when ok
if (SvgSecurityCheck::checkString($svg)->ok) {
    file_put_contents($path, $svg);
}
```

Switch on `code`, not on `message`, when the application reacts to a specific rule. Codes
never change once released; message wording can.

## Error Codes

Every code, its template, and what `detail` holds. Templates are `Violation::TEMPLATES`.

| Code                            | Template                                                                                | `detail`                                                                                                                                                        |
|---------------------------------|-----------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `file-unreadable`               | `Cannot read file %s`                                                                   | the file's basename                                                                                                                                             |
| `not-svg`                       | `This is not an SVG file: it starts with %s`                                            | the first 20 bytes, control and non-ASCII bytes escaped, or `nothing (the file is empty)`                                                                       |
| `not-utf8`                      | `SVG files must be UTF-8, this one is %s`                                               | `UTF-16 or UTF-32` or `declared as ISO-8859-1` (the declared encoding)                                                                                          |
| `malformed-xml`                 | `The SVG is not well-formed XML: %s`                                                    | libxml2's message (may contain a newline) plus ` (line N)`, or `no root element within the first 64 KB`                                                         |
| `doctype-not-allowed`           | `The DOCTYPE declaration is not allowed because %s`                                     | `it contains an internal DTD subset (entity declarations)`                                                                                                      |
| `processing-instruction`        | `Processing instructions like <?%s?> are not allowed`                                   | the instruction's target, such as `xml-stylesheet`; for an `xpacket` marker holding `>`, the target and its text                                                |
| `comment-not-allowed`           | `A comment starting with <!--%s is not allowed, HTML parsers close it there`            | `>` or `->`                                                                                                                                                     |
| `cdata-not-allowed`             | `A CDATA section containing > is not allowed inside <%s>, HTML parsers end it there`    | `title` or `desc`                                                                                                                                               |
| `root-not-svg`                  | `The root element must be <svg>, not <%s>`                                              | the root element's name as written                                                                                                                              |
| `root-namespace-wrong`          | `The root <svg> element must declare xmlns="http://www.w3.org/2000/svg", but it has %s` | `none` or `xmlns="..."` with the namespace found                                                                                                                |
| `element-not-allowed`           | `<%s> is not allowed in uploaded SVGs`                                                  | the element's local name, or its name as written when it has no namespace                                                                                       |
| `namespace-not-allowed`         | `Elements from the XML namespace %s are not allowed`                                    | the namespace URI                                                                                                                                               |
| `element-prefix-wrong`          | `<%s> has the wrong prefix: SVG elements take none, metadata elements need one`         | the element name as written, such as `svg:rect` or `p`                                                                                                          |
| `event-handler`                 | `%s= event handler attributes are not allowed`                                          | the attribute name as written, such as `onload` or `xlink:onload`                                                                                               |
| `attribute-not-allowed`         | `The %s attribute is not allowed`                                                       | the attribute name as written, such as `tabindex` or `xml:base`                                                                                                 |
| `href-not-allowed`              | `href must reference an element in the same file (#id), not %s`                         | the value, or `(empty)`                                                                                                                                         |
| `image-href-not-allowed`        | `Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not %s`   | the value, or `(empty)`                                                                                                                                         |
| `embedded-svg-not-allowed`      | `An embedded SVG image was rejected: %s`                                                | the inner file's message, `the data: URL is not valid base64`, or `SVG images nested more than 3 levels deep`                                                   |
| `url-not-fragment`              | `url() in the %s attribute must reference an element in the same file (#id)`            | the attribute name                                                                                                                                              |
| `reference-expansion-too-large` | `The references in this file %s`                                                        | `form a loop (#a -> #b -> #a)`, `expand to more than 100,000 elements`, or `need more than 100,000 records to track (ids and references)`                       |
| `css-not-allowed`               | `CSS containing %s is not allowed`                                                      | the banned token as matched (`@import`, `\`, `url(https://example.com/a.css`) or `more than 1,000,000 bytes`                                                    |
| `animation-target-not-allowed`  | `Animating the %s attribute is not allowed`                                             | the `attributeName` value                                                                                                                                       |
| `animation-value-not-allowed`   | `The %s animation attribute contains a URL or scheme`                                   | `from`, `to`, `by`, or `values`                                                                                                                                 |

Values longer than 60 characters are cut at 60 and end with `...`.

## Rules: Structure

Checked on the first 64 KB before parsing, then by the parser. Each of the first four ends
the check. The first three arrive as the only error; `malformed-xml` ends the list after
anything found earlier in the file.

- **`not-utf8`**: a UTF-16 or UTF-32 byte order mark or null byte at the start, or an XML
  declaration whose `encoding` is anything but `utf-8` (case-insensitive). A UTF-8 byte order
  mark is fine and is skipped.
- **`not-svg`**: after the byte order mark and leading whitespace, the first byte is not `<`.
  An empty or whitespace-only file reports `nothing (the file is empty)`.
- **`doctype-not-allowed`**: a `<!DOCTYPE` with an internal subset (`[`). A DOCTYPE with only
  a public or system id is accepted; nothing is fetched (`LIBXML_NONET`, `LOADDTD` off,
  `SUBST_ENTITIES` off), so an entity that such a DTD would define reports `malformed-xml`
  (`Entity 'foo' not defined`). The five built-in entities and numeric character references
  are fine.
- **`malformed-xml`**: the root tag does not start within 64 KB, or libxml2 reports any
  error or warning. Includes an undefined namespace prefix and invalid UTF-8 bytes. libxml2's
  own limits apply: element depth 256, a single text node 10 MB.
- **`processing-instruction`**: any `<?target ...?>` anywhere, including `<?xml-stylesheet?>`,
  except the targets in `inertProcessingInstructions` (Adobe's `xpacket` XMP markers). An
  `xpacket` marker whose text holds `>` rejects too: HTML parsers end the instruction at the
  first `>`. The `<?xml ...?>` declaration itself is not a processing instruction and is fine.
- **`comment-not-allowed`**: a comment whose content starts with `>` or `->` (written
  `<!-->` or `<!--->`). All other comments are fine.
- **`cdata-not-allowed`**: a `<![CDATA[` section inside `<title>` or `<desc>` whose text
  contains `>`. HTML parsers use HTML rules inside those two elements and end the CDATA at
  the first `>`. CDATA anywhere else is fine, and so is `&gt;`. The detail is the element name.
- **`root-not-svg`**: the root element's local name is not `svg`. Stops the check.
- **`root-namespace-wrong`**: the root `<svg>` is not in `http://www.w3.org/2000/svg`. Stops
  the check. A prefixed root (`<svg:svg xmlns:svg="...">`) gets `element-prefix-wrong`, see
  [Rules: Elements and Namespaces](#rules-elements-and-namespaces).

## Rules: Elements and Namespaces

An element whose local name is `script` (case-insensitive) rejects with `element-not-allowed`
in any namespace and with any prefix, so script is rejected everywhere. Every other element,
by its namespace:

- **SVG namespace** (`http://www.w3.org/2000/svg`): the local name must be in the
  [elements allowlist](#allowlists), else `element-not-allowed`. Names are case-sensitive.
  The rejected element's attributes are not checked; its children are.
- **No namespace** (an unbound prefix, or inside `xmlns=""`): `element-not-allowed` with the
  name as written.
- **Inert namespace** (see the list): the element and all its attributes pass without
  inspection, except that an `on*` attribute still rejects.
- **Any other namespace**: `namespace-not-allowed` with the URI. This covers XHTML, MathML,
  XInclude, XML Events, and anything unknown.

Then the prefix must match: an SVG element is written without one (`<rect>`) and an
inert-namespace element with one (`<rdf:RDF>`), else `element-prefix-wrong` with the name as
written. HTML parsers ignore `xmlns` and read an element by the name as written, so
`<svg:svg>` is not SVG to them and `<p xmlns="http://ns.adobe.com/x">` is an HTML `<p>`.
Design tools always write it this way.

Not allowed on purpose, so never add them to a file to "fix" a rejection: `script`,
`foreignObject`, `handler`, `listener`, `iframe`, `embed`, `font` (and the other SVG font
elements), `tref`, `cursor`, `animateColor`, `discard`. A nested `<svg>` is fine.

## Rules: Attributes

For every attribute on an element in the SVG namespace, in this order:

1. `xmlns` and `xmlns:*` declarations always pass.
2. A name whose local part starts with `on` (case-insensitive, any namespace, any element
   including inert ones) rejects with `event-handler`. No allowed attribute starts with `on`.
3. An attribute in an inert namespace passes.
4. An unprefixed name must be in the [attributes allowlist](#allowlists) or start with
   `data-` or `aria-`. A prefixed name must be one of `xml:lang`, `xml:space`, `xlink:href`,
   `xlink:title`. Anything else rejects with `attribute-not-allowed`. Names are
   case-sensitive.
5. The value is then checked: `href` and `xlink:href` per [URLs](#rules-urls), `style` per
   [CSS](#rules-css), every other value for `url(` per [URLs](#rules-urls), and on animation
   elements per [Animation](#rules-animation).

Not allowed on purpose: `tabindex`, `target`, `crossorigin`, `cursor`, `xml:base`,
`xlink:show`, `xlink:actuate`, every `on*`.

## Rules: URLs

**`href` and `xlink:href`.** The value is trimmed. It must start with `#` (a same-file
reference). On `<image>` and `<feImage>` only, it may instead be an embedded image:
`data:image/png;base64,`, `data:image/jpeg;base64,`, `data:image/jpg;base64,`,
`data:image/gif;base64,`, `data:image/webp;base64,` or `data:image/svg+xml;base64,` followed
by the data (type matched case-insensitively; `;base64,` is required). Anything else rejects:
`image-href-not-allowed` on those two elements, `href-not-allowed` everywhere else, including
`<a href="https://...">` and `href=""`. Nothing checks that the `#id` exists.

**Embedded SVG.** A `data:image/svg+xml;base64,` value is decoded (strict base64) and checked
as its own file with every rule here. Each problem found inside reports as
`embedded-svg-not-allowed` with the inner message as the detail. SVG inside SVG is followed
three levels deep; a fourth level rejects.

**`url()` in attribute values.** Every allowed attribute except `href` and `style` is scanned
for `url(` followed by optional whitespace and an optional quote and then anything but `#`
(case-insensitive). A match rejects with `url-not-fragment` and the attribute name.
`fill="url(#gradient)"` and `fill="url( '#gradient' )"` pass; `fill="url(image.png)"` and
`fill="url(https://...)"` reject. The eight banned CSS tokens (rule 1 under Rules: CSS below)
reject in these values too, with `css-not-allowed` and the token as the detail:
presentation attributes are CSS values, so `fill="u\72l(...)"` is `url(...)` to a browser
and `mask="image-set('https://...' 1x)"` loads that image. `data-*` and `aria-*` values
are not CSS and are exempt.

## Rules: CSS

Applies to the text of every `<style>` element (including CDATA sections and text inside
child elements) and to every `style` attribute; rule 1 also runs on every other attribute
value except `href`, `data-*` and `aria-*`. Two regular expressions, case-insensitive:

1. Any of these tokens rejects with `css-not-allowed` and the token as the detail: a
   backslash `\`, `@import`, `image(`, `image-set(`, `src(`, `expression(`,
   `-moz-binding`, `behavior:` (whitespace before the colon allowed).
2. `url(` whose target, after optional whitespace and quote, does not start with `#`,
   `data:font/`, or `data:;base64,` rejects with the `url(` and up to 40 characters after it
   as the detail.
3. More than 1,000,000 bytes of text in one `<style>` element rejects with
   `more than 1,000,000 bytes` as the detail. The text is held whole until the closing tag,
   so the limit keeps memory at 1 MB; the largest `<style>` in the corpus is 64 KB.

So `fill: url(#p)` passes, `@font-face { src: url(data:font/woff2;base64,...) }` passes
anywhere in the CSS, and `background: url(https://...)`, `@import`, and any escape sequence
reject. Comments are not stripped: a banned token inside `/* */` still rejects. Selectors,
properties, `:hover`, `:visited`, `@media`, `@keyframes` and `!important` are not checked.

## Rules: Animation

On `<animate>`, `<set>`, `<animateTransform>` and `<animateMotion>`:

- **`attributeName`** (trimmed, and with any `prefix:` removed, so `xlink:href` and
  `q:href` are both `href`) must be in the [attributes allowlist](#allowlists) and not
  `href`, `style`, `class` or `id`, else `animation-target-not-allowed`. Those four change
  URLs, CSS and references after the check. `data-*`, `aria-*` and `on*` are not in the
  allowlist, so they cannot be animated either. Names are case-sensitive. The detail is the
  name as written.
- **`from`, `to`, `by`, `values`** reject with `animation-value-not-allowed` when any
  `;`-separated item starts (after whitespace) with a URL scheme, `letter` then letters,
  digits, `+`, `.` or `-`, then `:`, or with `//`, which browsers read as a URL on the
  page's own scheme. Tab, CR and LF inside an item are ignored first, as browsers ignore
  them in a URL, so `java&#x09;script:` is `javascript:`. A literal newline never gets
  that far: XML turns it into a space.
  `values="0;1"` and `to="red"` pass; `to="javascript:x"`, `values="a;https://x"` and
  `by="//x"` reject.

`<mpath>` is allowed; its `href` follows the href rule.

## Rules: Reference Loops and Expansion

After the whole file is read (and only when it parsed cleanly), every same-file reference
that renders its target is followed: `url(#id)` in any attribute including `style`, and
`href` on `<use>`, `<pattern>`, `<linearGradient>`, `<radialGradient>`, `<filter>` and
`<feImage>`. `<a href="#id">` and the animation elements are not followed. A percent-encoded
fragment is decoded first (`#%61` is `#a`), as browsers do before the id lookup.

- A loop (`#a` references `#b` which references `#a`, or `#a` references itself) rejects
  with `reference-expansion-too-large` and `form a loop (#a -> #b -> #a)`.
- The elements the references would render are counted. More than 100,000 rejects with
  `expand to more than 100,000 elements`. Ten nested patterns of ten rects each is enough.
- Every `id` is a record, so is every distinct target of an element that renders on its
  own, and each reference is recorded once more per element with an `id` around it; the
  same target inside the same `id` is one record. More than 100,000 records rejects with
  `need more than 100,000 records to track (ids and references)`, so memory stays at a few
  MB whatever the file does.
- Content inside `<defs>`, `<symbol>`, `<pattern>`, `<mask>`, `<marker>`, `<clipPath>` and
  `<filter>` counts only when something references it. A loop inside a `<symbol>` that
  nothing uses is accepted.
- References made through CSS selectors in `<style>` (`.a { fill: url(#p) }`) are not
  followed. This is the known gap in the rule.

## Allowlists

The lists as `rules()` returns them. Anything not here is rejected.

**`elements`** (SVG namespace):

<!-- rules:elements -->
```text
svg g defs symbol use title desc metadata switch a view style path rect circle ellipse line polyline
polygon text tspan textPath image linearGradient radialGradient stop pattern clipPath mask marker
filter feBlend feColorMatrix feComponentTransfer feComposite feConvolveMatrix feDiffuseLighting
feDisplacementMap feDistantLight feDropShadow feFlood feFuncA feFuncB feFuncG feFuncR feGaussianBlur
feImage feMerge feMergeNode feMorphology feOffset fePointLight feSpecularLighting feSpotLight feTile
feTurbulence animate set animateTransform animateMotion mpath
```
<!-- /rules:elements -->

**`attributes`** (unprefixed; plus any `data-*` and `aria-*`):

<!-- rules:attributes -->
```text
id class style lang role requiredExtensions requiredFeatures systemLanguage
externalResourcesRequired version baseProfile viewBox preserveAspectRatio zoomAndPan
contentStyleType x y width height cx cy r rx ry x1 y1 x2 y2 fx fy fr d points pathLength href
transform transform-origin transform-box dx dy rotate textLength lengthAdjust startOffset method
spacing side gradientUnits gradientTransform spreadMethod offset patternUnits patternContentUnits
patternTransform clipPathUnits maskUnits maskContentUnits markerUnits markerWidth markerHeight refX
refY orient filterUnits primitiveUnits filterRes in in2 result mode type values tableValues slope
intercept amplitude exponent k1 k2 k3 k4 operator radius stdDeviation edgeMode kernelMatrix order
divisor bias targetX targetY kernelUnitLength preserveAlpha surfaceScale diffuseConstant
specularConstant specularExponent z azimuth elevation pointsAtX pointsAtY pointsAtZ
limitingConeAngle xChannelSelector yChannelSelector scale baseFrequency numOctaves seed stitchTiles
attributeName attributeType begin dur end min max restart repeatCount repeatDur calcMode keyTimes
keySplines from to by additive accumulate path keyPoints origin alignment-baseline baseline-shift
clip clip-path clip-rule color color-interpolation color-interpolation-filters color-rendering
direction display dominant-baseline enable-background fill fill-opacity fill-rule filter flood-color
flood-opacity font font-family font-kerning font-size font-size-adjust font-stretch font-style
font-variant font-weight glyph-orientation-horizontal glyph-orientation-vertical image-rendering
isolation kerning letter-spacing lighting-color marker marker-end marker-mid marker-start mask
mask-type mix-blend-mode opacity overflow paint-order pointer-events shape-rendering stop-color
stop-opacity stroke stroke-dasharray stroke-dashoffset stroke-linecap stroke-linejoin
stroke-miterlimit stroke-opacity stroke-width text-anchor text-decoration text-rendering
unicode-bidi vector-effect visibility white-space word-spacing writing-mode
```
<!-- /rules:attributes -->

**`namespacedAttributes`**, keyed by namespace URI:

<!-- rules:namespacedAttributes -->
| Namespace                              | Attributes      |
|----------------------------------------|-----------------|
| `http://www.w3.org/XML/1998/namespace` | `lang`, `space` |
| `http://www.w3.org/1999/xlink`         | `href`, `title` |
<!-- /rules:namespacedAttributes -->

**`inertNamespaces`**: elements and attributes in these pass without inspection (except
`on*`, a `script` element, and an element written without a prefix). Matched by the start of
the URI, so every namespace under `http://ns.adobe.com/` or `http://purl.org/dc/` counts.

<!-- rules:inertNamespaces -->
```text
http://www.inkscape.org/namespaces/inkscape
http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd
http://www.w3.org/1999/02/22-rdf-syntax-ns#
http://www.w3.org/2000/01/rdf-schema#
http://purl.org/dc/
http://creativecommons.org/ns#
http://web.resource.org/cc/
http://ns.adobe.com/
adobe:ns:meta/
http://www.serif.com/
http://www.bohemiancoding.com/sketch/ns
http://schemas.microsoft.com/visio/2003/SVGExtensions/
http://www.w3.org/2001/XMLSchema-instance
```
<!-- /rules:inertNamespaces -->

**`inertProcessingInstructions`**: `<?target ...?>` lines that pass, by target. `xpacket`
is the pair of markers Adobe tools put around their XMP metadata block; nothing reads them.
Every other target rejects with `processing-instruction`.

<!-- rules:inertProcessingInstructions -->
```text
xpacket
```
<!-- /rules:inertProcessingInstructions -->

**`imageElements`**: <!-- rules:imageElements -->
`image`, `feImage`
<!-- /rules:imageElements -->

**`dataImageTypes`**: <!-- rules:dataImageTypes -->
`png`, `jpeg`, `jpg`, `gif`, `webp`, `svg+xml`
<!-- /rules:dataImageTypes -->

## Limits

The SvgSecurityCheck limits are public static properties, set before the check
(`SvgSecurityCheck::$maxExpandedElements = 250000;`). The memory a hostile file can cost rises
with them. The rules themselves have no options.

| Limit                                | Value         | Source                 |
|--------------------------------------|---------------|------------------------|
| Bytes searched for the root tag      | 64 KB         | `$prologLimit`         |
| Errors reported per file             | 50            | `$maxErrors`           |
| Embedded SVG nesting                 | 3 levels      | `$maxEmbedDepth`       |
| Elements rendered through references | 100,000       | `$maxExpandedElements` |
| Ids and references recorded          | 100,000       | `$maxReferenceEntries` |
| Text in one `<style>` element        | 1 MB          | `$maxStyleLength`      |
| Value length in `detail`             | 60 characters | `$maxDetailLength`     |
| Element nesting depth                | 256           | libxml2 default        |
| Single text node                     | 10 MB         | libxml2 default        |
| File size                            | none          | the file is streamed   |

## How the Rules Compare to Chrome

The yardstick is Chrome's `<img>` mode: the browser parses everything, then disables script,
interaction and every fetch that is not a `data:` URL at render time. The rules are
**stricter** wherever that protection is render-time only and disappears when the file is
opened directly or rasterized on a server:

| Chrome in `<img>`                            | Rule here                             |
|----------------------------------------------|---------------------------------------|
| `<script>` parsed, never runs                | `element-not-allowed`                 |
| `on*` attributes ignored                     | `event-handler`                       |
| `javascript:` and `https:` hrefs inert       | `href-not-allowed`                    |
| external `url()` silently not loaded         | `url-not-fragment`, `css-not-allowed` |
| `<?xml-stylesheet href="#id"?>` processed    | `processing-instruction`              |
| `<foreignObject>` rendered without script    | `element-not-allowed`                 |
| DTD entities expanded                        | `doctype-not-allowed`                 |
| reference loops broken by the renderer       | `reference-expansion-too-large`       |
| SVG inside `<image>` rendered in secure mode | checked with every rule               |

**Looser than Chrome, or not mirrored:** `:hover`, `:visited`, `:focus` in CSS and
`begin="click"` in SMIL are accepted (Chrome makes them do nothing in `<img>`; opened directly
they work; nothing loads or runs either way). `animateColor` and `discard` are rejected as
unknown elements although Chrome would tolerate them.

## Constraints and Gotchas

- **No cleaned output.** The result is accept or reject. Store the original bytes or refuse.
- **No line numbers.** `Violation` has `code` and `detail` only. The one exception is the
  libxml2 message inside a `malformed-xml` detail, which names a line.
- **Same problem once.** Five `<script>` elements report one error; five different `on*`
  attributes report five.
- **A small malformed file reports only `malformed-xml`.** libxml2 parses in chunks and
  discards the chunk containing the error, so errors in the same chunk are not seen. A large
  file reports the errors found before the broken chunk, then `malformed-xml` last.
- **50 errors and the check stops.** Nothing is read past the fiftieth, including a parse
  error later in the file.
- **`detail` and `message` are unencoded text from the file.** Always one line of valid
  UTF-8 (control characters and bytes that are not UTF-8 are escaped, as `\n` or `\351`),
  but they can hold `</script>`, quotes and backticks. HTML-encode for a page. A JSON response with
  `Content-Type: application/json` needs nothing extra. Inside a `<script>` block:

  ```php
  $json = json_encode($violation->message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ```
- **Element and attribute names are case-sensitive**; `on*` detection, URL schemes, data
  image types and CSS tokens are case-insensitive.
- **`href` is trimmed before checking**, so `href="  #a"` passes; `href=""` rejects with
  `(empty)`.
- **Not checked:** whether a `#id` target exists, the file extension, the MIME type, file
  size, CSS selectors and properties, and references made through CSS classes.
- **Serving an accepted file:** it is safe to open directly with no special headers, and no
  script runs even if it is served as `text/html`: the prefix, comment, CDATA and
  processing-instruction rules close the places where an HTML parser reads the file
  differently from XML. `X-Content-Type-Options: nosniff` and
  `Content-Security-Policy: sandbox` add a second layer in case a rule is ever bypassed. Refuse `.svgz` and HTML uploads at the extension
  check; this library does not look at either.
- **Not for inline SVG.** An accepted file is safe to serve as its own document or through
  `<img>`. Pasting SVG source into an HTML page is a different threat model (the page's
  origin, the page's scripts) and is not what these rules were built for.
- **Not configurable.** There is no way to allow an extra element or attribute. Open an issue
  with the file that was rejected. The size limits are the one exception, see
  [Limits](#limits).
