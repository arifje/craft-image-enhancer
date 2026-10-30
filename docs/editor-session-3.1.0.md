# Combined editor session validation

2026-10-30, Image Enhancer 3.1.0.

AI enhancement, custom edits and automatic face blur now add successive base images to the existing editor document. Each result gets its own history identity. Subsequent operations use the selected base, including after undo; local layers stay separate. Only the final **Save image** replaces the original asset. Format changes and video generation still require pending edits to be saved first.

Source previews must belong to the current user and original asset, be complete, and remain readable. Both the controller and queue worker validate this binding. Provider retries retain the selected source. Closing the editor requests cleanup of all accepted previews; existing garbage collection covers abandoned or undeletable previews. Cleanup of an older result cannot remove a newer operation's status.

Validation used the general Craft 5 Docker PHP container, with the existing isolated `image_enhancer_audit_20261020` database/site. The shared site's plugin installation and database were not changed.

- Full `composer check` passed on Craft 5.11.0 and 5.11.5.1: PHPStan, ECS and 408 PHP regression checks on each dependency set.
- Editor document and session JavaScript regressions passed, plus syntax and whitespace checks.
- Native Chromium/Craft 5.11.5.1 testing passed 32 checks covering AI before/after layers, repeated enhancements, source-file hashes, undo followed by another AI operation, unchanged original version until save, invalid source rejection, provider failure recovery, cancellation, automatic face blur, the Custom blur shortcut, keyboard and mouse saves, preview cleanup, preserved asset relation, and flattened reopening. No browser JavaScript errors occurred.
- AI responses and face detection were mocked locally. Native queue jobs, permission checks, preview assets, image processing, HTTP requests and final asset replacement ran normally. No paid provider requests were made.

No schema migration or frontend build is needed. Deploy the release, restart queue workers and reload the control panel. Craft 4 remains on the 2.x release line.
