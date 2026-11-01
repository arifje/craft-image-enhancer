# Changelog

## 3.4.1 - 2026-11-01

- Clarify MCP operation and native Craft queue identifiers while preserving `requestId` and `jobId` aliases.
- Report native queue progress, failures, retries, expired reservations and missing jobs separately from durable image and attachment outcomes, without writes during polling.
- Retain failed processing in Craft's queue manager and reuse its original job for storage recovery, with guards against repeating paid work.
- Compact completed MCP request details after 30 days through Craft GC while retaining idempotency, provenance, output assets and recoverable operations.

## 3.4.0 - 2026-11-01

- Keep the before/after comparison visible after accepting AI results into the editing session. Reopen it at any time to compare the original image with the current composition.
- Add a light/dark theme toggle in the editor header and remember the choice in the browser.
- Preserve editable layers, undo/redo, subsequent enhancements and the single final save while comparing.
- Add native browser coverage for persistent comparison, keyboard controls, continued editing, responsive light-theme layout and theme persistence.

## 3.3.0 - 2026-10-31

- Add optional native Craft MCP Tools for enhancement discovery, queued image processing and read-only status.
- Preserve source assets, validate exact output pixels, and save separate results using shared provider and format processing.
- Support explicitly selected unpublished draft Assets fields and exclusive nested ownership, native upload destinations, relation replacement and human-edit conflict detection.
- Persist idempotency, provenance and generation/storage/attachment outcomes; recover retained images after storage failure without another provider call.
- Require live user/token permissions, disclose AI costs and fidelity limits, and keep provider diagnostics and credentials out of tool responses.
- Add the schema 1.3.0 migration, MCP examples and isolated native integration coverage. Run `php craft up` and restart queue workers after updating.

## 3.2.0 - 2026-10-30

- Combine crop, resize, fit and AI extend with AI enhancement and editable layers in one session, then save the finished image once.
- Keep layers aligned with format changes, include dimensions in undo/redo, and show the current composition in the live format preview.
- Use the current session image for subsequent format operations and accept resized final compositions within the existing image limits.
- Keep one Enhance button per asset card after Craft refreshes or saves an asset, and clean up existing duplicate buttons.

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
