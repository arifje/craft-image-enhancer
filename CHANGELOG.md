# Changelog

## 2.2.1 — 2026-10-21

### Fixed

- Recognize selected Assets fields when Craft 5 assigns a different field layout handle.
- Recheck user, plugin and asset permissions before queued editor operations run, while preserving authorized temporary upload repair.
- Cancel late queued operations when an editor cancels before the enqueue response arrives.
- Reject malformed request parameters with HTTP 400 instead of PHP errors or unintended scalar casts.
- Handle malformed Google image and video response collections without PHP errors.

### Tested

- Added provider-response, native Craft 5 queue, browser, upload repair and permission regressions. See `docs/audit-2026-10-20.md` for exact coverage and remaining limitations.
