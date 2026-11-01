# MCP queue/status consistency audit

Validated on 2026-11-01 for Image Enhancer 3.4.1, based on 3.4.0. This release requires no schema migration.

## Implementation

- `operationId` names the existing request UUID. `queueJobId` names the actual Craft queue row. Existing `requestId` and numeric `jobId` remain compatibility aliases. Status accepts either operationId or requestId and rejects contradictory aliases.
- Craft's configured queue remains the only execution engine. Native progress and failures are now reported there. A failed storage operation reuses its original failed queue job when present. Existing serialized jobs retain their requestId property and remain loadable.
- Status reads the configured Craft queue connection/table, without calling mutating `getJobInfo()` or treating `STATUS_DONE` on an absent row as success. It reports queue state/progress/attempt, expired reservations, effective execution status, durable processing checkpoint and attachment outcome separately. It neither retries jobs nor updates the database.
- Exceptions reach Craft's error handler so processing failures remain failed in the queue manager. Durable failure/uncertain checkpoints prevent manual retries from repeating paid requests. Storage recovery keeps its retained pixels. Attachment conflicts/failures retain their assets and require explicit human attachment as before.
- No migration or column removal. Legacy progress remains a coarse checkpoint for compatibility; runtime progress/reservations/attempts stay in Craft. GC compacts terminal request details after 30 days, up to 100 per run, without deleting result assets, changing outcome timestamps or expiring deduplication hashes. Minimal idempotency/provenance records intentionally remain indefinitely. Active/unknown and recoverable storage operations are exempt so cleanup cannot destroy in-flight work.

## Actual checks

Used `general-craft-5-test-container-php-1`, PHP 8.4.27, Craft 5.11.5.1 and its native database queue, with the guarded isolated site `/tmp/ie-mcp-20261031/site` and database `ie_mcp_20261031`. The shared application's database was not used.

- `composer check`: PHPStan and ECS passed, plus 455 PHP checks (including 17 new pure queue-status cases). Existing ECS dependency deprecation notices occur on PHP 8.4.
- Native MCP suite: 88 checks passed. Coverage includes both ID aliases, actual queue descriptions, waiting/delayed/reserved/expired/failed/retried/released jobs, unavailable queue metadata, native progress during processing, complete results after queue removal, failure after purging, whole-row equality across status polls, no duplicate provider call after retry, original-job storage recovery, ownership/permissions, draft isolation, human-edit conflicts, and detail retention with old recoverable storage preserved.
- Independent fresh-process readback passed: encoded output dimensions, original file hash, separate asset, draft-only relation and unpublished canonical entry.
- Node document/session regressions and `git diff --check` passed.
- Provider responses used a local mock transport. No paid provider calls or publication occurred.

## Limits and data impact

Status snapshots can observe a queue transition between reads; polling again converges without repairing state. A missing queue row with an unfinished checkpoint cannot distinguish manual release, purging or interruption, and is reported as interrupted rather than completed. Fully replaced queue implementations report unavailable; alternate backends and PostgreSQL were not validated. Native Craft proxy queues retain the same metadata table but were not exercised separately.

GC only removes old terminal instructions, token references, relation snapshots, recovery details and leftover private terminal files. It does not remove operation rows or output assets. In-flight/recoverable details have no automatic expiry; an operator must resolve or explicitly abandon them under the site's retention policy. Automatic whole-ledger expiry would break existing idempotency guarantees.

When eventually deployed, restart long-running queue workers to load the updated execution code and reconnect MCP clients to refresh the optional operationId argument. Existing requestId clients and serialized jobs remain compatible.
