<!--
AI ASSISTANTS: Before writing code that uses SvgSecurityCheck, read docs/ai-reference.md. It covers
the complete API and every rejection rule in one file and matches this installed version.
SvgSecurityCheck rejects files instead of cleaning them, which differs from the SVG sanitizers in
training data, so trust that file over habit.
-->

# SvgSecurityCheck: Reject SVG Uploads That Could Run Script

SvgSecurityCheck checks an uploaded SVG file and rejects it if it could run script, load an outside
resource, or hang a renderer. Browsers block the same things when an SVG is shown through an
`<img>` tag, but not when the file is opened on its own; SvgSecurityCheck checks the file, so it is
safe either way. It never rewrites the file.

## Why Reject Instead of Clean

A sanitizer strips what it does not like and hands back a changed file, and a logo that renders
differently from what the designer exported is a support ticket nobody can explain. A rejection
with a reason costs a re-export; a cleaned file that still holds something the filter did not
know about costs an XSS. Every rule here is an allowlist except the CSS check, so a new
element or attribute is rejected until someone adds it.

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

// logo.php: serve it with the headers browsers need
header('Content-Type: image/svg+xml');
header('X-Content-Type-Options: nosniff');    // never guess another type from the contents
header('Content-Security-Policy: sandbox');   // no script and no cookies, even if a rule is ever bypassed
readfile('uploads/logo.svg');
```

If Apache or nginx serves the upload folder directly, set the same two extra headers there for
`.svg` files. The rest of the API:

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

- **Script and event handlers.** `<script>`, every `on*` attribute, `javascript:` URLs and
  `<foreignObject>`: a browser runs them the moment the file is opened directly.
- **Anything that loads from outside the file.** Every `href` and `url()` must point at `#id`
  or an embedded `data:` image or font: an outside load tells another server who opened the
  file, and Chrome refuses it in `<img>` mode anyway.
- **DTD entity declarations.** An entity can expand to markup no other rule sees, or to
  gigabytes of text.
- **Reference loops and expansion bombs.** A few dozen `<use>` or pattern references can
  render billions of elements; browsers cap that, server-side rasterizers hang.
- **XML an HTML parser reads differently.** A `<!-->` comment, or CDATA holding `>` inside
  `<title>` or `<desc>`, ends early in an HTML parser, and what follows is live markup if the
  file is ever served as `text/html`.

## What It Does Not Check

- **File size.** The file is streamed, so a huge upload passes in a few MB of memory. Cap the
  size at upload time.
- **Extension and MIME type.** Only the bytes are read. Refuse `.svgz`, `.html` and `.xml`
  uploads yourself.
- **Whether the picture is sensible.** Blank, enormous, offensive, or another site's logo all
  pass.
- **Renderer bugs.** An accepted file is still parsed by libxml2, a browser, or ImageMagick.
  Keep the rasterizer patched.
- **SVG pasted inline into HTML.** That makes the markup part of the page. Sanitize on output
  with DOMPurify instead.
- **Files served with the wrong headers.** Served as `text/html`, or with the type left for
  the browser to guess, any SVG is a page. Send the headers in the quick start.

## When You Might Not Want SvgSecurityCheck

- **You must accept SVG from the public and cannot ask for a re-export.** Use a sanitizer such
  as [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) and re-check its
  output with SvgSecurityCheck.
- **You put SVG source inline in HTML pages.** That is a different threat model: the page's
  origin and the page's scripts. Sanitize on output with DOMPurify.

A check costs less than receiving the upload did; the measurements are in
[benchmarks/results.md](benchmarks/results.md). The rules are tested against real exports from
Illustrator, Inkscape, Figma, Affinity Designer, Sketch and CorelDRAW.

## Documentation

Full docs ([browse on GitHub](https://github.com/interactivetools-com/SvgSecurityCheck)):

- [Error codes and fixes](docs/errors.md) - every message, and what to change in the file or the design tool
- [AI reference](docs/ai-reference.md) - the complete API and every rule in one file, written for AI coding assistants
- [Changelog](CHANGELOG.md)

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
