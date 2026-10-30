# Changelog

## 3.1.0 - 2026-10-30

- Combine successive AI enhancements, custom edits, automatic face blur, shapes, text, image layers and local blurs in one editor session, then save the finished image once.
- Apply AI results to the editor automatically and preserve each result in undo/redo history. Further AI edits use the currently selected base image while layers remain editable.
- Validate preview ownership and the original asset binding both when queueing and when processing another edit. Keep the original unchanged until the final save and clean up accepted previews when closing the session.

## 3.0.0 — 2026-10-21

### Breaking

- Require Craft CMS `^5.11.0` and PHP `^8.2`; Craft 4 sites must stay on 2.x until their application upgrade is complete.
- Remove Craft 4 utility registration and repaired-asset rendering branches.

### Development

- Add explicit PHPStan tooling and a complete Composer check script for static analysis, style and regression tests.
- See README for upgrade order and `docs/craft5-only-2026-10-21.md` for validation evidence and limitations.

## 2.2.1 — 2026-10-21

### Fixed

- Recognize selected Assets fields when Craft 5 assigns a different field layout handle.
- Recheck user, plugin and asset permissions before queued editor operations run, while preserving authorized temporary upload repair.
- Cancel late queued operations when an editor cancels before the enqueue response arrives.
- Reject malformed request parameters with HTTP 400 instead of PHP errors or unintended scalar casts.
- Handle malformed Google image and video response collections without PHP errors.

### Tested

- Added provider-response, native Craft 5 queue, browser, upload repair and permission regressions. See `docs/audit-2026-10-20.md` for exact coverage and remaining limitations.
