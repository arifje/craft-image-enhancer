# Persistent comparison and editor themes

2026-11-01, Image Enhancer 3.4.0.

AI results still become the current editable base automatically. The comparison now belongs to the working canvas instead of the transient provider preview, so accepting a result no longer hides it. It compares the initial original with the complete current composition, including visible layers. Format changes contain the original inside the new dimensions without stretching it. Done, the comparison toggle, or selecting a tool/layer returns to editing. Comparison can be reopened without another provider request. Its range supports native keyboard controls.

The header's sun button switches between light and dark themes. The preference is stored under `imageEnhancerTheme` in browser local storage. Dark is the default; blocked storage does not prevent switching during the session. Theme colors and comparison overlays are outside the exported canvas. Upload repair retains its existing dedicated UI.

Validation used the general Craft 5 Docker container, PHP 8.4.27, Craft 5.11.5.1 and Chromium. Native browser tests used the isolated site and database `ie_mcp_20261031`, not the shared site's database. The released 3.3.0 JavaScript reproduced the disappearing comparison before testing the fix.

- PHPStan, ECS and all 438 PHP regression checks passed through `composer check`. ECS emits existing dependency deprecation notices under PHP 8.4.
- Node document and session regressions passed, including consecutive AI results, format changes, layer/history preservation, comparison state, theme persistence, and unavailable browser storage.
- All 20 native browser checks passed: delayed comparison visibility, accessible pressed state, Home/End slider controls, theme colors/native controls, unchanged canvas pixels after switching themes, drawing after enhancement, reopening comparison, undo/redo, repeated enhancement with retained layers, narrow-screen layout, close/reopen theme persistence, and switching back to dark. No browser JavaScript errors occurred.
- Desktop dark/light and narrow light screenshots were visually inspected. The browser test waits for Craft's modal and shade animations when closing/reopening.
- AI transport was mocked locally, with all external browser/provider requests blocked. Native HTTP, queue execution, provider response decoding and preview storage ran normally. No paid AI requests were made. This UI change did not repeat the previous release's final asset replacement or native MCP acceptance suite.

The browser scenario is `tests/browser/editor-comparison-theme.cjs`. It requires the guarded isolated site, `ui-fixture.json`, `ui-job.php`, a mocked provider transport and the PHP server on port 8424; it is not a standalone installer. Set `IE_MCP_TEST_SITE=/tmp/ie-mcp-20261031/site` when running it in the container. `IE_BASELINE_DIR` can point to the released JavaScript to reproduce the original failure.

No schema migration or frontend build is needed. Reload the control panel after deploying. This release targets Craft 5.11+; Craft 4 remains on the 2.x line.
