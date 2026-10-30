# Format changes in the editor session

2026-10-30, Image Enhancer 3.2.0.

Crop, resize, fit and AI extend now produce temporary base images in the same document as AI enhancement and editable layers. Subsequent operations use the selected session base. The original asset changes only on the final **Save image**. Canvas dimensions, layer geometry and base image are restored together by undo/redo. Cropped-out layers remain editable; they are not deleted. Shapes retain their proportions and rotations when resampling introduces a one-pixel rounding difference between axes.

The live format preview includes layers. The queue supplies the layer transform calculated from the actual source dimensions and focal point. Final uploads can have new dimensions within the existing format limits; MIME, upload size, pixel bounds, stale-version detection, replacement permission and the asset lock remain enforced. There is no schema migration or frontend build step.

Craft's native element refresh preserves buttons but removes their wrappers and custom card attributes. The field scanner now reconciles actual buttons rather than relying on a readiness marker. It restores the wrapper, refreshes asset metadata and removes existing duplicate controls within each card.

Validation used the general Craft 5 Docker container with isolated site/database `image_enhancer_audit_20261020`. The shared site's database and installed dependencies were not modified.

- `composer check` passed with Craft 5.11.0 and 5.11.5.1: PHPStan, ECS and 417 PHP regression checks per dependency set.
- Node document/session regressions passed, including changed dimensions, text/blur scaling, hidden/locked layers, cropped-out layers and undo/redo source selection. JavaScript syntax and whitespace checks passed.
- Native Chromium on Craft 5.11.5.1 passed 46 format-session checks: crop → AI enhancement → added shapes/blur/imported image → fit → AI extend → one save → reopen. Source hashes verified that AI read the cropped result. Actual image-layer positions/sizes, canceled panels/jobs, expired source rejection, unchanged original version, new saved dimensions and preserved asset relations were checked. The fixture was restored to its starting dimensions through the normal editor save flow.
- The existing combined AI editor browser scenario passed all 32 checks, including failure recovery, cancellation, automatic face blur, repeated enhancements, cleanup and keyboard/mouse saves.
- The button regression reproduced duplicates with 3.1.0, then passed 15 checks with the fix: four native refreshes, wrapper restoration, existing duplicate cleanup, recovery from a stale readiness marker, one functioning editor launcher, and two actual saves through Craft's asset slideout. No browser JavaScript errors occurred. The regression is saved in `tests/browser/asset-button-refresh.cjs`.
- AI/provider responses were mocked locally; native HTTP requests, queue jobs, image processing, preview assets and final replacement ran normally. No paid provider requests were made.

Deploy the release, restart long-running queue workers and reload the control panel to receive the new JavaScript. Craft 4 remains on the 2.x release line.
