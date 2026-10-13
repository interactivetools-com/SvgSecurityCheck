# Browser Research Report

The research SvgSecurityCheck's rules were built from, kept for its source citations and Blink
details. Written 2026-09-12, before the library existed, by seven research agents plus one
fact checker per finding, then hand-verified against Chromium source. It was written to
design a sanitizer; the decision in [design-decisions.md](design-decisions.md) went the
other way (reject, never rewrite), and `SvgSecurityCheck::rules()` is the current allowlist.

---

# SVG in `<img>`: what Chrome allows

Research notes, 2026-09-12. Every claim was fact-checked against the cited source.

## 1. Short answer

- **Does Chrome filter the SVG?** No. Blink parses the whole file, `<script>`,
  `onload=`, `javascript:` and all, into a real Document, then turns off
  capabilities at the page level. Nothing is stripped.
- **What does it turn off?** Script, plugins, and every subresource fetch whose
  URL is not `data:`. SMIL and CSS animation still run. `foreignObject` still
  renders. Interaction is dead because the isolated page has no widget.
- **Is there a test plan?** No written plan exists. The tests are the plan:
  web-platform-tests under `svg/embedded/` and `svg/as-image/`, plus Chromium's
  own `web_tests/svg/as-image/` and `http/tests/security/`.
- **Gmail?** Gmail does not render SVG at all. Inline `<svg>` fails in all four
  Gmail clients, `<img src=x.svg>` fails because Google's image proxy will not
  serve SVG, and `.svg` attachments are allowed but not shown inline. Nothing in
  email will exercise the rules.

## 2. The standard

Two specs, split awkwardly.

**HTML Living Standard** is the only normative user-agent rule, and it is one
paragraph in the image processing model
(https://html.spec.whatwg.org/multipage/images.html#images-processing-model):

> User agents must not run executable code (e.g. scripts) embedded in the image
> resource. ... User agents must not allow the resource to act in an interactive
> fashion, but should honour any animation in the resource.

Note the split: no script and no interaction are `must not`; animation is only
`should`. HTML says nothing about external resource loads from inside an SVG
image. A search of the full single-page spec (15,593,584 bytes) finds zero hits
for "secure animated", "secure static", or "svg-integration".

HTML also has an authoring rule on `<img src>`
(https://html.spec.whatwg.org/multipage/embedded-content.html#the-img-element):

> If the src attribute is present, it must contain a valid non-empty URL ...
> referencing a non-interactive, optionally animated, image resource that is
> neither paged nor scripted. ... these definitions preclude SVG files with
> script

That binds the page author, not the browser.

**SVG 2** (W3C Candidate Recommendation, 4 October 2018) is where the processing
modes live: https://www.w3.org/TR/SVG2/conform.html. Three modes:

| Mode | script | external refs | declarative animation | interactivity |
|---|---|---|---|---|
| dynamic interactive | yes | yes | yes | yes |
| secure animated | no | no | yes | no |
| secure static | no | no | no | no |

The referencing-modes section makes secure mode a `must` for SVG's own `<image>`
element, then says of HTML:

> The same processing modes are expected to be used for other cases where SVG is
> used in place of a raster image, such as an HTML 'img' element or in any CSS
> property that takes an `<image>` data type.

"Expected", not "must". So no spec normatively requires Chrome's `<img>`
behaviour. It is inferred from HTML's must-not-script plus SVG 2's expectation.

Two details that matter for the allowlists:

- **Fragment and data: URLs are not "external".** SVG 2 defines external
  references as network access "except for: same-document URL references ...
  [and] data URL references". So `xlink:href="#gradient1"`, `url(#filter1)` and
  `<image href="data:image/png;base64,...">` are all inside secure animated
  mode. Rejecting them would be stricter than the spec.
- **Interaction off does not mean markup removed.** "any user input events that
  would be targetted at the document ... must have no effect". The `<a>` element
  and `begin="click"` stay in the file, they just do nothing.

**foreignObject** is not forbidden. SVG 2:

> if an SVG document is being used in secure animated mode due to being
> referenced by an HTML 'img' or SVG 'image' element, then any content within a
> 'foreignObject' element must have scripts, interactivity, and external file
> references disabled, but should have declarative animation enabled.

**CSS.** CSS Images Level 4 (Working Draft, 30 Sep 2025) normatively requires
SVG referenced from an `<image>` value to use secure static mode, or secure
animated mode if the UA supports animated images
(https://www.w3.org/TR/css-images-4/#image-file-formats). CSS Images Level 3 has
no such text. CSS Basic User Interface Level 4 imposes the same rule on `cursor`.

## 3. How Chrome implements it

**Filter or render-time restriction? Render-time, entirely.**

`SVGImage::DataChanged` (`third_party/blink/renderer/core/svg/graphics/svg_image.cc`)
builds an `IsolatedSVGDocumentHost` with `ProcessingMode::kAnimated` and
`NullUrl()` as the base URL. That class
(`third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc`)
does the work:

```cpp
page = Page::CreateNonOrdinary(chrome_client, agent_group_scheduler, ...);
Settings& settings = page->GetSettings();
settings.SetScriptEnabled(false);
settings.SetPluginsEnabled(false);
...
frame->ForceSynchronousDocumentInstall(AtomicString("image/svg+xml"), *data, base_url);
```

Its header comment: "Encapsulation of an (SVG)Document that is
isolated/independent from other documents. Does not run scripts. Used by
SVGImage."

Those two setters are the **only** Settings turned off. `CopySettingsFrom()`
then inherits font families, font sizes, image animation policy,
prefers-reduced-motion, preferred color scheme, forced colors and
accept-languages from a normal page. Images, CSS, fonts, filters, masks,
gradients, text and layout all work exactly as in a document.

**Script: two independent gates.**

- `ScriptLoader::PrepareScript()` returns at spec step 16 ("If scripting is
  disabled for el, then return"), so a `<script>` element stays in the DOM, is
  never fetched, never runs.
- `JSEventHandlerForContentAttribute::Create()` returns `nullptr` when
  `CanExecuteScripts()` is false. An `onload=` attribute stays as attribute text
  but compiles to no listener at all.
- `SVGImage::ServiceAnimations()` asserts with a `ScriptForbiddenScope`.

**Resource loading: one line, scheme-only.** In
`third_party/blink/renderer/core/loader/base_fetch_context.cc`,
`BaseFetchContext::CanRequestInternal` around line 305:

```cpp
// SVG images/resource documents have unique security rules that prevent all
// subresource requests except for data urls.
if (IsIsolatedSVGChromeClient() && !url.ProtocolIsData()) {
  return ResourceRequestBlockedReason::kOrigin;
}
```

The test looks only at the chrome client and the URL scheme. Never the resource
type, never the initiating element. So `<image href>`, external `<use href>`,
`<feImage href>`, CSS `url()` for fonts, backgrounds, filters, masks and
cursors, `@import`, `<link rel=stylesheet>`, and anything an `<img>`, `<object>`
or `<video>` inside `<foreignObject>` tries to fetch all land in the same
function. Same-origin http is blocked. `blob:` is blocked. Cached bytes are
blocked, because the check runs before the memory cache is consulted (that was
the original 2014 bug, crbug 380885).

A second barrier sits behind it: the isolated frame's
`LocalFrameClient::GetURLLoaderFactory()` returns a factory whose only handler
is `NOTREACHED()`. A request that got past the fetch check would crash the
renderer, not reach the network.

Blocked loads **fail silently**. The SVG branch returns before
`PrintAccessDeniedMessage`, and `EmptyChromeClient::AddMessageToConsole` has an
empty body, so DevTools shows nothing.

**Relative URLs never resolve.** The base URL is `NullUrl()`, so `href="pic.png"`
produces an invalid KURL and no request is created.

**`<use>` is now fragment-only.** `RemoveDataUrlInSvgUse` is `status: "stable"`
in `runtime_enabled_features.json5`, and a check right after the isolated-SVG
block rejects `data:` when the initiator is `kUse`. Chrome 120 shipped this
(https://developer.chrome.com/blog/migrate-way-from-data-urls-in-svg-use). So
inside an `<img>` SVG, `<use>` can only reference `#id`.

**foreignObject renders.** HTML inside it is laid out. Blink only flags it:
`SVGImage::HasSingleSecurityOrigin()` walks the flat tree and returns false on
the first `SVGForeignObjectElement`, which taints any canvas the image is drawn
into. `<iframe>`, `<object>`, `<embed>` and media inside it are dead ends:
`EmptyLocalFrameClient::CreateFrame()`, `CreateFencedFrame()`, `CreatePlugin()`
and `CreateWebMediaPlayer()` all return `nullptr`.

**Animation runs.** `<img>` gets `kAnimated`. Only `kStatic` (used by
`SVGResourceDocumentContent`, the path for CSS `url()` and external `<use>`
targets) sets `kImageAnimationPolicyNoAnimation`. The animation is driven by
`SVGImageChromeClient`'s own timer at `base::Hertz(60)`, not the host page's
animation frame, "Because a single SVGImage can be shared by multiple pages".
SMIL DOM events are off: `SMILTimeContainer` is constructed with
`should_dispatch_events_(!SVGImage::IsInSVGImage(&owner))`, pinned by
`TEST_F(SVGImageTest, DisablesSMILEvents)`.

**Interactivity.** There is no single switch. The Page is non-ordinary, uses
`EmptyChromeClient` and `EmptyLocalFrameClient`, has no widget, and
`EmptyLocalFrameClient::BeginNavigation()` has an empty body. So link
activation, `javascript:` hrefs, `:hover`, `:focus` and `cursor` never come into
play. Blink's web test `svg/as-image/svg-canvas-link-not-colored.html` shows
neither `:link` nor `:visited` matches.

**Origin.** The document is installed with a null URL and null policy container,
which routes to an opaque origin. `<link rel=preconnect>` and `rel=dns-prefetch`
do nothing, because `LocalFrame::PrescientNetworking()` returns `nullptr` with
no `WebLocalFrameImpl` behind the frame (regression test:
`web_tests/svg/as-image/preconnect-in-svg.html`, crbug 1069289). No DNS or TCP
side channel.

**XSLT is a live edge.** `ProcessingInstruction::CheckStyleSheet` blocks
*external* `xml-stylesheet` in an image context, but a same-document sheet
(`href="#id"`) is still processed and can replace the document. Blink has a use
counter `kXSLPIInSVGImage` and a test `SVGImageSimTest, SVGWithXSLT`. Put
`<?xml-stylesheet?>` on your deny list.

## 4. Firefox and WebKit differences

| Behaviour | Chrome (Blink) | Firefox (Gecko) | Safari (WebKit) |
|---|---|---|---|
| Where enforced | `BaseFetchContext::CanRequestInternal` | `nsDataDocumentContentPolicy::ShouldLoad` | `CachedResourceLoader::canRequest` |
| Script off by | `SetScriptEnabled(false)` | no docshell + `ScriptLoader::SetEnabled(false)` | `setScriptEnabled(false)` |
| `data:` subresources | allowed | allowed | allowed |
| `blob:` subresources | blocked | **allowed** (`URI_LOADABLE_BY_SUBSUMERS`) | blocked |
| Relative URLs | null base URL, never resolve | real document URI kept, resolve then get blocked | empty URL, never resolve |
| `foreignObject` renders | yes | yes | yes |
| iframe/embed inside it | no (`nullptr` clients) | no (`nsObjectLoadingContent`) | no |
| Canvas tainted by foreignObject | yes | unverified | yes |
| `:visited` | never matches | `Gecko_VisitedStylesEnabled` returns false | (WebKit FIXME names the leak) |
| `<use href="data:...">` | removed in Chrome 120 | removed in Firefox 122 | never supported |
| Extra backstop | loader factory `NOTREACHED()` | - | full sandbox flags asserted |

The rule landed in Blink in June 2014 (commit `ee281f7cac9d`, "Enforce SVG image
security rules"), explicitly to match Gecko: "With this patch we now match
Gecko's behavior on both testcases." Gecko's side is Mozilla bug 628747.

## 5. Tests

There is no written test plan and no design doc; the model exists as code comments in three
Blink files plus the SVG 2 conformance chapter. The tests are the plan. The corpus tools
download these; the names are what to search for.

web-platform-tests: `svg/embedded/image-embedding-nested-http-url.sub.html` (network
`<image href>` must not paint), `image-embedding-nested-data-url.html` and `-png`,
`-nesteder-`, `-from-canvas` (nested `data:` must paint),
`image-embedding-nested-external-data-url-png.html` (the rule is about the subresource
URL, not the outer one), `svg/as-image/external-resource-inline-sheet.html` (inline
`<style>` background must load in `<object>` and not in `<img>`, from Mozilla bug 1982344),
`html/semantics/embedded-content/the-img-element/svg-img-with-external-stylesheet.html`,
`svg/embedded/image-embedding-svg-nested-svg-in-foreignobject.html` (`foreignObject`
renders in `<img>` everywhere).

Chromium `third_party/blink/web_tests/`: `http/tests/security/svg-image-with-cached-remote-image.html`
(cached bytes must not be reused, crbug 380885), `http/tests/security/svg-image-with-css-import.html`
(crbug 382296), `svg/as-image/data-font-in-css.html` (`data:font/ttf` does load),
`svg/as-image/svg-canvas-not-tainted.html` and `svg-canvas-xhtml-tainted.html` (any
`foreignObject` taints), `svg/as-image/svg-canvas-link-not-colored.html` (neither `:link`
nor `:visited` matches), `svg/as-image/preconnect-in-svg.html`,
`http/tests/svg/use-contenttype-blocked.html` and `use-no-contenttype-blocked.html`.

Gecko `layout/reftests/svg/as-image/reftest.list` is the clearest written statement of the
iframe and embed rule, and pairs each external-resource test with a `data:` twin.

## 6. Email clients

Nothing in email renders SVG the way a browser does, so email never exercises the rules.
Gmail renders no SVG in any form: inline `<svg>` is dropped, its image proxy will not serve
SVG (Google told MediaWiki in 2016 there were no plans to, phabricator T127794), and `.svg`
attachments are accepted but not shown. Outlook for Web and the new Outlook for Windows
stopped rendering inline SVG in 2025, citing XSS (message center MC1130385). Google's own
guidance for hosting user SVG is isolation headers (`Content-Security-Policy: sandbox`,
`nosniff`, `Content-Disposition: attachment`), not sanitizing.

## 7. How the existing libraries compare

| Library | Fits Chrome's `<img>` model? | Gaps |
|---|---|---|
| **DOMPurify** | no | Allows `http`/`https` in `href` and `xlink:href` by default (`IS_ALLOWED_URI`). Does not parse CSS at all: `style` is in `DEFAULT_URI_SAFE_ATTRIBUTES`, so `_isValidAttribute` returns true before any URL check, and `<style>` text is never inspected. Its own README: "DOMPurify per default allows CSS". Strips `use`, `foreignObject`, `animate`, `set`, `script` by default (not in the allow-list), but keeps `animateColor`, `animateMotion`, `animateTransform`, `mpath`. Built for DOM insertion, not files; its CVE history is mostly mXSS from re-parsing sanitized output in a different context (CVE-2024-47875, CVE-2024-45801, CVE-2026-65914). |
| **enshrined/svg-sanitize** (PHP) | no, out of the box | `isHrefSafeValue` returns true for `http://` and `https://`, and `removeRemoteReferences` defaults to `false`, so external references survive unless you turn the flag on. `data:` is limited to five raster prefixes, stricter than Chrome. `<use>` is fragment-only with a depth cap. No `feImage`, `feDropShadow`, `animate`, `set`, `foreignObject`. Two CVEs, both CDATA (CVE-2022-23638 real, fixed 0.15.0; CVE-2023-28426 later withdrawn as a false positive). Current fix rewrites every CDATA node into an escaped text node. |
| **MediaWiki** (rejector, not sanitizer) | closest match | `UploadVerification.php` `checkSvgScriptCallback`: href must be `#` or `data:`, except `<a>` which may be `http(s)`; `data:` href must match `image/(gif|jpeg|jpg|a?png|webp|avif)`; rejects `set`/`animate` with `attributeName` starting `on`, `set/@attributeName` containing `href`, `set/@to` matching `(http|https|data|script):`. `SvgCssChecker.php` tokenizes CSS. Also carries a large allow-list of Illustrator, Inkscape, sodipodi, XMP, Photoshop and Dublin Core namespaces, which answers the design-tool question. Rejects the file instead of rewriting it. |
| **Cloudflare svg-hush** (Rust) | best mechanism | Types every attribute (`Url`, `UrlFunc`, `StyleSheet`, `Keyword`, `Number`, `Text`, `AnyAscii`) and lets `animate`/`set` target only inert types. Keeps `animate`, `set`, `animateTransform`, `animateMotion`. Drops all `data:` URLs unless you supply an `image_filter` callback, so embedded rasters and fonts are lost by default. |
| **librsvg** (for server-side thumbnails) | no | "ignores animations, scripts, and events" but does resolve referenced images (https://gnome.pages.gitlab.gnome.org/librsvg/devel-docs/security.html). Static mode with network, not Chrome's `<img>`. |

## 8. Watch item and a future test

- **Chromium issue 40094872**, "Top-level navigation to SVG documents isn't restricted like
  `<img src>` embedding of same image" (needs a Google sign-in to read). If Chrome ever
  restricts a directly opened SVG the way it restricts `<img>`, the rules stay as they are:
  Firefox, Safari and server-side rasterizers would not have changed. Check once a year.
  `tools/img-vs-direct/index.html` shows the current behaviour: the same SVG through
  `<img>`, as a CSS background, and opened directly. Last checked 2026-09-14 in Chrome:
  blocked in `<img>` and as a background, runs when opened directly.
- **Not built: a round-trip render test.** Render an accepted file once through `<img>` and
  once as a top-level document, and diff the two images. Any difference is exactly the
  attack surface Chrome's render-time blocks hide. Needs a headless browser, so it would be
  a manual or CI tool, not a PHPUnit test.

## 9. Sources

**Specs**
- https://html.spec.whatwg.org/multipage/images.html#images-processing-model
- https://html.spec.whatwg.org/multipage/embedded-content.html#the-img-element
- https://html.spec.whatwg.org/
- https://www.w3.org/TR/SVG2/conform.html
- https://www.w3.org/TR/SVG2/conform.html#referencing-modes
- https://www.w3.org/TR/SVG2/conform.html#secure-animated-mode
- https://www.w3.org/TR/SVG2/conform.html#features
- https://svgwg.org/svg2-draft/conform.html
- https://www.w3.org/TR/svg-integration/
- https://svgwg.org/specs/integration/
- https://www.w3.org/TR/css-images-4/#image-file-formats
- https://drafts.csswg.org/css-images-4/
- https://fetch.spec.whatwg.org/

**Chromium / Blink source**
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc
- https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/svg_image.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/svg_image_chrome_client.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/loader/base_fetch_context.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/loader/empty_clients.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/animation/smil_time_container.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/svg_use_element.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/bindings/core/v8/js_event_handler_for_content_attribute.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/frame/local_frame.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/platform/loader/fetch/resource_fetcher.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/platform/runtime_enabled_features.json5
- https://github.com/chromium/chromium/commit/ee281f7cac9d
- https://github.com/chromium/chromium/commit/5580670229
- https://github.com/chromium/chromium/commit/4f3ebd119d
- https://developer.chrome.com/blog/migrate-way-from-data-urls-in-svg-use

**Chromium web_tests**
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/svg-canvas-link-not-colored.html
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/data-font-in-css-crash.html
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/preconnect-in-svg.html
- https://github.com/chromium/chromium/tree/main/third_party/blink/web_tests/svg/as-image/resources

**web-platform-tests**
- https://github.com/web-platform-tests/wpt/tree/master/svg/embedded
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/embedded/image-embedding-nested-http-url.sub.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/embedded/image-embedding-nested-data-url.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/as-image/external-resource-inline-sheet.html
- https://github.com/web-platform-tests/wpt/blob/master/svg/as-image/external-resource-inline-sheet.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/html/semantics/embedded-content/the-img-element/svg-img-with-external-stylesheet.html

**Gecko and WebKit**
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/dom/base/nsDataDocumentContentPolicy.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/dom/base/Document.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/netwerk/build/components.conf
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/image/SVGDocumentWrapper.cpp
- https://searchfox.org/mozilla-central/source/image/SVGDocumentWrapper.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/layout/reftests/svg/as-image/reftest.list
- https://bugzilla.mozilla.org/show_bug.cgi?id=628747
- https://bugzilla.mozilla.org/show_bug.cgi?id=629682
- https://bugzilla.mozilla.org/show_bug.cgi?id=1190881
- https://bugzilla.mozilla.org/show_bug.cgi?id=1901414
- https://raw.githubusercontent.com/WebKit/WebKit/main/Source/WebCore/svg/graphics/SVGImage.cpp
- https://raw.githubusercontent.com/WebKit/WebKit/main/Source/WebCore/loader/cache/CachedResourceLoader.cpp
- https://bugs.webkit.org/show_bug.cgi?id=180301

**Sanitizers**
- https://github.com/cure53/DOMPurify/blob/main/src/tags.ts
- https://raw.githubusercontent.com/cure53/DOMPurify/main/src/tags.ts
- https://raw.githubusercontent.com/cure53/DOMPurify/main/src/purify.ts
- https://github.com/cure53/DOMPurify/blob/main/src/regexp.ts
- https://github.com/darylldoyle/svg-sanitizer/blob/1.0.0/src/Sanitizer.php
- https://raw.githubusercontent.com/darylldoyle/svg-sanitizer/1.0.0/src/Sanitizer.php
- https://github.com/advisories/GHSA-fqx8-v33p-4qcc
- https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/Upload/UploadVerification.php
- https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/Upload/SvgCssChecker.php
- https://raw.githubusercontent.com/cloudflare/svg-hush/main/src/lib.rs
- https://gnome.pages.gitlab.gnome.org/librsvg/devel-docs/security.html

**Email**
- https://raw.githubusercontent.com/hteumeuleu/caniemail/main/_features/html-svg.md
- https://raw.githubusercontent.com/hteumeuleu/caniemail/main/_features/image-svg.md
- https://www.caniemail.com/features/html-svg/
- https://knowledge.workspace.google.com/admin/gmail/advanced/set-up-an-image-url-proxy-allowlist
- https://phabricator.wikimedia.org/T127794
- https://support.google.com/mail/answer/6590
- https://support.google.com/drive/answer/37603
- https://securelist.com/svg-phishing/116256/
- https://mc.merill.net/message/MC1130385
- https://raw.githubusercontent.com/ampproject/amphtml/main/validator/validator-svg.protoascii
- https://raw.githubusercontent.com/ampproject/amphtml/main/docs/spec/email/amp-email-html.md
- https://raw.githubusercontent.com/googlearchive/caja/master/README.md
- https://raw.githubusercontent.com/google/closure-library/master/closure/goog/html/sanitizer/tagwhitelist.js
- https://web.dev/articles/securely-hosting-user-data

**Issue trackers (not readable without sign-in, cited from commits)**
- https://github.com/whatwg/html/issues/10641
- https://github.com/w3c/svgwg/issues/358
