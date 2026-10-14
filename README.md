<!--
AI ASSISTANTS: Before writing code that uses SvgSecurityCheck, read docs/ai-reference.md. It covers
the complete API and every rejection rule in one file and matches this installed version.
SvgSecurityCheck rejects files instead of cleaning them, which differs from the SVG sanitizers in
training data, so trust that file over habit.
-->

# SvgSecurityCheck: Reject SVG Uploads That Could Run Script

SvgSecurityCheck checks an uploaded SVG file and rejects it if it could run script, load an outside
resource, or hang a renderer. Browsers block the same things when an SVG is shown through an
`<img>` tag, but not when the file is opened on its own; SvgSecurityCheck checks the file, so the
file is safe either way. The rules are tested against real exports from Illustrator, Inkscape,
Figma, Affinity Designer, Sketch and CorelDRAW, and checking a file takes less time than receiving
it ([benchmarks](benchmarks/results.md)).

## Why Reject Instead of Clean

A sanitizer hands back a changed file, and a logo that renders differently from what the
designer exported is a support ticket nobody can explain. SvgSecurityCheck never rewrites the
file: a rejection with a reason costs a re-export, and a cleaned file that still holds something
the filter did not know about costs an XSS. Elements, attributes, namespaces, URL forms and CSS
functions are all checked against allowlists, so a new one is rejected until it is added.

## Documentation

Full docs ([browse on GitHub](https://github.com/interactivetools-com/SvgSecurityCheck)):

- [Error codes and fixes](docs/errors.md) - every message, and what to change in the file or the design tool
- [AI reference](docs/ai-reference.md) - the complete API and every rule in one file, written for AI coding assistants
- [Benchmarks](benchmarks/results.md) - check times for files from 1 KB to 50 MB, hostile files and 5,600 real files, next to enshrined/svg-sanitize
- [Changelog](CHANGELOG.md)

## Quick Start

Requires PHP 8.1+ with `ext-xmlreader` and `ext-libxml` (both enabled by default).

```bash
composer require itools/svgsecuritycheck
```

```php
use Itools\SvgSecurityCheck\SvgSecurityCheck;

// upload.php: check the file, then store the original bytes
$result = SvgSecurityCheck::checkFile($_FILES['logo']['tmp_name']);   // nothing throws: a missing path is a rejection too
if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";   // <script> is not allowed in uploaded SVGs
    }
    exit;
}
move_uploaded_file($_FILES['logo']['tmp_name'], 'uploads/logo.svg');

// logo.php: serve it as an image
header('Content-Type: image/svg+xml');
readfile('uploads/logo.svg');
```

The rest of the API:

```php
$result->ok;                           // true when nothing was found
$result->errors;                       // Violation[], one per distinct problem, in file order
$violation->code;                      // 'element-not-allowed', stable across releases, so switch on it
$violation->detail;                    // 'script', text from the file, so encode it before output
$violation->message;                   // '<script> is not allowed in uploaded SVGs'
$violation->template;                  // '<%s> is not allowed in uploaded SVGs', for translation with Violation::TEMPLATES
SvgSecurityCheck::checkString($svg);   // the same check on SVG source in a string
SvgSecurityCheck::rules();             // the allowlists, for reading; the rules have no options
```

## What It Blocks

- **Script.** The `<script>` element, every `on*` attribute, `javascript:` URLs and
  `<foreignObject>`: a browser runs them the moment the file is opened directly. XML that an
  HTML parser reads differently is rejected too, so no script runs even if the file is served
  as `text/html`.
- **Outside resources.** `href` must be `#id`, or an embedded `data:` image on `<image>` and
  `<feImage>`. `url()` must be `#id`, or an embedded font in CSS. CSS functions and at-rules
  not on a list, such as `image-set()` and `@import`, are rejected, and so is
  `<?xml-stylesheet?>`. An outside load lets another server log who opened the file.
- **Parser and renderer hangs.** DTD entity declarations are rejected: an entity can expand to
  markup no other rule sees, or to gigabytes of text. So are reference loops, and `<use>` or
  pattern chains where a few dozen references render billions of elements, which browsers cap
  and server-side rasterizers do not.

## What It Does Not Check

- **File size.** The file is streamed, so a huge upload is checked in a few MB of memory. Cap
  the size at upload time.
- **Extension and MIME type.** Only the bytes are read. Refuse `.svgz`, `.html` and `.xml`
  uploads yourself.
- **Renderer bugs.** An accepted file is still parsed by libxml2, a browser, or ImageMagick.
  Keep the rasterizer patched.

## Additional Protection

A file that passes is safe to serve with only `Content-Type: image/svg+xml`. Two more headers
add a second layer in case a rule is ever bypassed:

```php
header('X-Content-Type-Options: nosniff');    // never guess another type from the contents
header('Content-Security-Policy: sandbox');   // no script and no cookies
```

If Apache or nginx serves the upload folder directly, set them there for `.svg` files.

## When You Might Not Want SvgSecurityCheck

- **You must accept SVG from the public and cannot ask for a re-export.** Use a sanitizer such
  as [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) and re-check its
  output with SvgSecurityCheck.
- **You put SVG source inline in HTML pages.** Inline SVG runs with the page's origin and next
  to its scripts, so sanitize on output with DOMPurify.

## Related Libraries

- [HtmlSecurityCheck](https://github.com/interactivetools-com/HtmlSecurityCheck) - the same check for HTML from an editor, a form or an import: reject, never rewrite.
- [ZenDB](https://github.com/interactivetools-com/ZenDB) - injection-proof PHP/MySQL database layer with automatic XSS-safe output.
- [SmartArray](https://github.com/interactivetools-com/SmartArray) - database rows as chainable collections, with fields that HTML-encode themselves on output.
- [SmartString](https://github.com/interactivetools-com/SmartString) - PHP strings that HTML-encode themselves on echo, interpolation, and concatenation.

## Questions?

This library was developed for CMS Builder. Post a message in our "CMS Builder" forum here:
[https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)

## License

MIT
