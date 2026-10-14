# SvgSecurityCheck Changelog

> Tagged releases roll up every change since the previous tag. Versions bundled with
> CMS Builder are marked on their sections. Error codes are never renamed once released,
> so code that switches on `$violation->code` needs no changes between versions.

## [1.0.0] - 2026-10-13

> **Bundled with CMS Builder v3.85**

First release. SvgSecurityCheck checks an uploaded SVG file and rejects it if it could run
script, load an outside resource, or hang a renderer. It never rewrites the file. The
[README](README.md) says what it blocks and what it does not check.

### Added

- **`SvgSecurityCheck::checkFile($path)`, `checkString($svg)` and `rules()`.** Nothing throws
  for a bad file; a missing path is a `file-unreadable` rejection.
- **`Result` with `ok` and `errors`, `Violation` with `code`, `detail`, `template` and
  `message`.** One error per distinct problem, at most 50. `Violation::TEMPLATES` holds
  every message template for translation.
- **One error code per rule**, listed with its fix in [docs/errors.md](docs/errors.md).
  Codes are stable from this release on.
- **Size limits as public static properties** (`SvgSecurityCheck::$maxExpandedElements` and
  the others in [docs/ai-reference.md](docs/ai-reference.md#limits)). The rules themselves
  have no options.

### Requirements

- PHP 8.1+, `ext-xmlreader`, `ext-libxml`. No other dependencies.
