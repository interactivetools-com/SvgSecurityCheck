# Documentation Style Guide

The shared writing standards for all InteractiveTools libraries are in the team's
[internal docs repo](https://github.com/itools-internal/docs/tree/main/open-source)
under open-source/ (private, team access only). This file holds SvgSecurityCheck-specific
additions only.

- **Reader assumption:** a working PHP programmer who handles file uploads and has never
  looked inside an SVG file. Explain what an element does the first time a page uses it, in
  half a sentence.
- **"SVG" is capitalized in prose and takes "an"**: an SVG file, an SVG upload. Lowercase
  only inside code (`image/svg+xml`, `.svg`).
- **Elements in code font with angle brackets, attributes bare:** `<script>`, `<use>`,
  `<foreignObject>`; `href`, `onload`, `style`. The event handler family is written `on*`.
  HTML elements the same way (`<img>`, `<iframe>`).
- **Error codes always in backticks:** `element-not-allowed`. Codes are the API:
  applications switch on them, so a page never paraphrases one.
- **"Rejected" and "accepted"** are the two outcomes of a check, and a rule "allows" or
  "rejects" a thing. "Sanitize", "clean", "strip" and "remove" name what the library does
  not do; use them only when contrasting with sanitizers.
- **The yardstick is "Chrome's `<img>` mode"**, written that way every time. Where a rule
  is stricter or looser than Chrome, say so in those words; the table in
  [design-decisions.md](design-decisions.md) is the source for which cases are which.
  "Browsers" alone means Chrome, Firefox and Safari agree; when they differ, name the
  engine.
- **A same-file reference is written `#id`:** "href must be a same-file reference
  (`#id`)". Not "fragment", "anchor" or "local reference" in the public pages;
  `url-not-fragment` keeps its name as a code.
- **`docs/errors.md` rows carry the fix only.** The message cell is the template from
  `Violation::TEMPLATES`, byte for byte (a test checks it), and the third column says what
  to change, with the design-tool menu path where one exists. What happened is what the
  message already says; do not restate it.
- **Public pages carry no counts.** No error-code totals, corpus tallies, test-suite
  counts, or dated figures in the README or errors.md. Measurements go in
  `benchmarks/results.md`.
- **Design tools are named:** Illustrator, Figma, Inkscape, Affinity Designer, Sketch.
  "Design tool" is the generic. CMS Builder may be named as a user of the library; nothing
  about its internals.
