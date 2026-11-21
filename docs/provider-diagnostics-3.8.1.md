# Provider failure diagnostics

Validated on 2026-11-21 for Image Enhancer 3.8.1.

The HTTP category remains, with bounded structured provider reason/code/type/parameter and selected request-ID headers added. The durable error and native queue exception include operation stage, provider/model, target dimensions and available input dimensions, MIME type and byte count. The first error survives manual retries of terminal failures. No new provider requests are made by status reads or those retries.

Only selected JSON error fields are exposed. Known request credentials, explicit instructions, URLs, common token formats, local paths, markup and long encoded data are removed or bounded. Raw request/response dumps and Guzzle exception text are excluded. Non-seekable, malformed, HTML and oversized responses fall back to the HTTP category. Seekable response cursor positions are restored. Previously discarded response details cannot be recovered from historical failed jobs.

Validation used the general Craft 5 Docker container with PHP 8.4.27, Craft 5.12.2 and the isolated `ie_mcp_20261031` database. Its old schema was upgraded through Craft migrations; the shared site's schema and installed source were not changed. Test source and dependencies were copied into temporary directories.

- PHPStan, ECS and the complete Composer regression suite passed, including 29 provider diagnostic checks covering wrapped exceptions, provider shapes, redaction, malformed/oversized responses, bounded output and stable repeated reads.
- All 99 native MCP checks passed. A mocked HTTP 400 passed through Guzzle's actual HTTP-error middleware, the real provider service and Craft's queue. Both persisted MCP status and `getJobDetails()` contained the reason, error code, parameter, request ID and context. Credential/URL redaction and preservation of the original error on retry were verified without another provider call.
- Independent fresh-process readback verified image dimensions, original file hash, separate output asset and draft-only attachment. Node editor document/session regressions and whitespace checks passed.
- No paid provider calls or publication occurred. This validates the diagnostic path with a simulated rejection; it does not establish the cause of the specific historical HTTP 400 in the screenshot. The queue-detail API was checked directly; no new visual UI layout was introduced.

No migration is required for this plugin update. Restart long-running queue workers after deployment. The 29 diagnostic checks and the native suite remain in `tests/mcp-safe-errors.php` and `tests/craft-mcp-enhancements.php`.
