# MCP image enhancement

Image Enhancer 3.3 adds `image_enhancement_options`, `image_enhance` and `image_enhancement_status` through Craft MCP Tools' `EVENT_REGISTER_TOOLS` event. Processing uses the same provider integration and format processor as the editor. No site-specific fields or volumes are assumed.

## Configuration and upgrade

- Craft CMS 5.11+, PHP 8.2+ for Image Enhancer. Optional **`arifje/craft-mcp-tools` 1.2+ requires PHP 8.3+**. No changes to MCP Tools are required.
- Run `php craft up` after updating; schema 1.3.0 creates `imageenhancer_mcp_requests`. Restart queue workers. Fresh installations create the same table. Existing images and settings are preserved.
- Enable MCP Tools (1.8 or later) and its dangerous/content tools. Connect as a Craft user: through its authenticated HTTP transport with a user-bound **content/full** token holding the **upload** capability, or from inside Craft through MCP Tools' `LocalClient` (as the AI assistant does), which acts as the signed-in user under their own permissions. Stdio's implicit administrator identity is deliberately unsupported. Queued work stores who started it and checks again before the paid call and before attaching: a revoked or expired token, a disabled scope or tool, or a user who lost access stops it.
- The user needs `accessCp`, `craft-image-enhancer:use-ai-tools`, native source view permissions (including peer assets when relevant), destination volume view/upload permissions, and native draft/site permissions. Full MCP scope does not bypass these checks. Section restrictions apply to draft destinations and their status responses.
- Configure image provider credentials and models in Image Enhancer. `image_enhancement_options` lists only configured/enabled providers; it never returns credentials. OpenAI discovery uses the editor's existing 15-minute cache; other catalogs follow the editor settings. If the configured provider is chosen by editors, pass a provider explicitly. Unsupported providers/models are rejected, not substituted.
- Run a Craft queue worker. The job allows 900 seconds and retains existing provider timeouts. Imagick is needed for padding, preservation sizing and expansion; crop can use Craft's image driver.
- Web and queue processes must share the Craft database, mutex backend and writable `storage/image-enhancer/mcp` directory. Retained output is private server-side data, not a public preview URL. Do not remove it while a storage retry is pending.

The editor continues to work without MCP Tools installed. MCP SDK is a development-only dependency for schema checks; it does not become a production dependency of Image Enhancer.

## Calls

Discover first:

```json
{"name":"image_enhancement_options","arguments":{}}
```

Create an unpublished draft using Craft or the site's authorized draft tools. Use the **draft element ID**, not its canonical ID or the `draftId` database record. Substitute real IDs, a layout field handle and a supported model from discovery:

```json
{
  "name": "image_enhance",
  "arguments": {
    "sourceAssetId": 321,
    "idempotencyKey": "article-456-thumbnail-review-1",
    "operation": "preserve_scene",
    "aspectRatio": "16:9",
    "width": 1200,
    "height": 675,
    "instructions": "Correct exposure and colour balance only.",
    "provider": "openai",
    "model": "gpt-image-2",
    "targetDraftId": 456,
    "field": "thumbnail",
    "siteId": 1
  }
}
```

The operation incurs an AI provider charge. The original file remains untouched. A new asset is saved in the Assets field's resolved native upload folder and appended to that draft. To replace only a selected relation, also pass `replaceAssetId`; other assets retain their order. A full field refuses append before queueing. Without `targetDraftId` and `field`, the result is saved alongside the source without attachment.

For a deterministic, free crop use `"operation":"crop"` and omit instructions/provider/model. `pad` preserves the complete source on a blurred background. Both resize proportionally. Upscaling is resampling, not recovered detail. Ratios must be positive integer `A:B`; explicit dimensions must exactly satisfy them. Without dimensions, the processor calculates a bounded exact-ratio canvas.

`enhance` is the control panel's Enhance button for agents. The provider gets the site's own enhancement prompt (the default creative enhancement prompt, or the one set in Image Enhancer's settings) and the tuning levels for clarity, contrast, colour and noise, exactly as the editor sends them. `instructions` are optional and are added after that prompt as extra direction; they never replace it. The picture keeps its own ratio: dimensions or a ratio that differ from the picture's by more than 1 % are refused before anything is queued, and a provider picture whose ratio differs from the source by more than 4 % fails instead of being cut (a smaller difference is trimmed at the edges). To get an enhanced picture in another ratio, run `crop` or `generative_expand` first and `enhance` on that result, or `enhance` first and `crop` after it. `generative_expand` generates only the new surroundings and puts the original centre back unchanged, so it does not make the original part clearer by itself. Status and provenance report `enhancementPrompt: site-default`. Imagick is not needed for `enhance`.

`preserve_scene` defaults to exposure/colour/noise corrections and prohibits invented details in the provider prompt; changed ratios use deterministic fit/padding. AI cannot guarantee photographic fidelity: human review is required. `generative_expand` explicitly authorizes generated surroundings, then composites the original centre back over the output. Reconstruction of people, objects, damage or text is not an offered operation. Embedded image text and metadata are treated as untrusted content.

Sources must be JPEG/PNG, at most 50 MiB, 8192 pixels per edge and 24 megapixels. Outputs retain JPEG/PNG format, have 64–8192 pixel edges, at most 24 megapixels and 25 MiB. Final decoded dimensions are checked before saving. Ratios are limited to 1:4–4:1, or 1:3–3:1 for expansion.

Poll the returned operation ID:

```json
{"name":"image_enhancement_status","arguments":{"operationId":"returned-operation-uuid"}}
```

Polling only reads. It never generates, queues, saves, attaches, expires reservations or updates operation rows. Responses distinguish these identifiers and states:

| Field | Meaning |
| --- | --- |
| `operationId` | Durable Image Enhancer operation UUID. Use it for MCP status. |
| `queueJobId` | Actual numeric Craft queue ID, or null when enqueue failed. Use it in Craft's queue manager. |
| `requestId` | Compatibility alias for `operationId`; existing named and positional status calls continue to work. |
| `jobId` | Compatibility alias for `queueJobId`. In Image Enhancer this has always been the native queue ID, never the operation UUID. |
| `queue.status` | Native metadata: `waiting`, `delayed`, `reserved`, `failed`, `missing`, `not_queued`, or `unavailable`. |
| `queue.progress` | Craft's current progress as a fraction from 0 to 1, or null without an observable row. |
| `queue.attempt` | Craft's attempt counter, or null. A manual Craft retry resets it; this is not lifetime attempt history. |
| `queue.reservationExpired` | The native heartbeat is older than its TTR. Polling reports this without moving the job back to waiting. |
| `executionStatus` | `queued`, `running`, `failed`, `interrupted`, `finished`, `not_queued`, or `unknown`; separate from the processing checkpoint. |
| `processingStage` / `processingProgress` | Last durable image checkpoint and its coarse progress. A checkpoint is not evidence that a worker is still running. |
| `attachmentStatus` | `not_requested`, `pending`, `complete`, `conflict`, or `failed`. `pending` describes the unfinished attachment, not a live worker. |
| `stage` / `progress` | Compatibility fields. Stage retains terminal outcomes, reflects native retry/waiting state, and reports `queue_failed`, `interrupted` or `unknown` instead of stale active checkpoints. Progress uses native queue progress when available, otherwise the retained checkpoint. |

`image_enhancement_status` accepts either `operationId` or legacy `requestId`. If both are supplied, they must match; at least one is required. Do not pass `queueJobId` to the status tool. For example, a queued response may include:

```json
{
  "operationId": "f61fc7b3-46b7-4280-88e1-cf3db1c260cf",
  "requestId": "f61fc7b3-46b7-4280-88e1-cf3db1c260cf",
  "queueJobId": 812,
  "jobId": 812,
  "executionStatus": "queued",
  "processingStage": "queued",
  "attachmentStatus": "pending",
  "queue": {"status":"waiting","progress":0,"attempt":0,"reservationExpired":false,"error":null}
}
```

Craft removes successful queue rows, and administrators can also release or purge unfinished jobs. Therefore `queue.status: missing` never proves success: a durable completion/result is required. A removed job with an unfinished checkpoint reports `executionStatus: interrupted`. A durable `complete` or attachment outcome can report `finished` alongside a missing row. Unavailable queue metadata reports `unknown` for unfinished work; no alternate scheduler is used. Raw queue error text and serialized jobs are not exposed. Operators inspect diagnostics in Craft's queue manager/logs.

Responses also retain safe errors, separate generation/storage/attachment outcomes, asset ID/dimensions/URL, natural key `{volume,path,filename}`, draft/field/site IDs, review links, warnings and provenance. Asset URLs may be null for private volumes. Progress is checkpoint-based, not provider streaming progress. Provenance lives in the operation record; descriptive alt text is copied from the source, never replaced by technical logs.

For nested Matrix entries, pass their element ID as `targetDraftId` and the exact root draft element ID as `ownerDraftId`. Every owner must be editable and the ownership chain must be exclusive to that draft. Shared canonical blocks must first be edited/materialized on the draft using Craft. The tool refuses ambiguous ownership; it never falls back to saving a canonical entry.

## Conflicts, failures and retries

Submission validates the user/token, source, operation, model, exact size, actual entry layout, field limits and native destination before starting work. Workers recheck live permissions/token state before processing, saving and attaching. Attachment locks native element/site rows, reloads the draft, compares ordered relations with the submission snapshot, and saves only that draft through Craft. Unrelated fields are loaded fresh and preserved. A changed target field or destination produces `attachment_conflict`; the new asset is retained for explicit manual attachment. Native field selection conditions are checked on the resulting asset. Attachment errors likewise retain it as `attachment_failed`.

Reuse **identical arguments and the same idempotency key** for submission retries. Keys are scoped to the user and retained without automatic expiry. Changed arguments with an existing key are rejected. Completed requests return the existing asset. Status is restricted to the initiating user and currently accessible source/result and target section.

A `storage_failed` result means generated pixels were retained privately. Fix storage/permissions, then submit the same arguments/key to enqueue a storage-only recovery without another provider request. Storage recovery reuses the original failed Craft queue job where it still exists; it creates a new native job only when that row is absent. It does not schedule a second job while a native job is waiting/reserved or the queue is unavailable. A stopped worker can recover an existing durable checkpoint when its original queue job is manually retried. If it stopped before that checkpoint, it reports `uncertain` and does not repeat a potentially charged request. An expired reservation or unfinished operation with a missing row reports `interrupted`; ask an operator to inspect the original queue job before retrying. Polling does not restart it. Provider/storage exceptions mark the native Craft job failed as well as retaining a safe durable outcome. A manual retry of a durable provider failure or uncertain outcome does not repeat the provider call. Provider failures are not automatically retried. An enqueue failure reports `failed`; fix the queue and deliberately submit a new key only after confirming no work ran.

## Storage and retention

The existing `imageenhancer_mcp_requests` table is an operation/result ledger, not a second queue. No new scheduler, attempt counter, reservation, retry timer or queue table is added. Its retained fields have specific purposes:

- Operation/user IDs and idempotency/request hashes enforce ownership and identical-submission deduplication.
- The normalized request holds destination/scope authorization, the original source version, relation snapshot and provider instructions needed for execution or safe recovery.
- Status, safe error and legacy progress record durable image/attachment outcomes after Craft removes the queue row. Runtime queue progress, attempts and reservation state are read from Craft and are not copied into this ledger.
- The native queue ID links to Craft. The result asset ID and request provenance identify the existing output, dimensions and origin without changing its alt text.

During Craft garbage collection, up to 100 terminal operation payloads per run are compacted after **30 days since the last outcome update**. Instructions, initiating token ID, relation snapshot and recovery-only folder/root details are removed; only provider/model identifiers remain from provider tuning. Leftover private result/partial files for these terminal operations are deleted. `detailsExpired` reports compaction; the outcome timestamp is preserved. This is asynchronous retention, so GC must run regularly.

Minimal ownership/idempotency hashes, source/result provenance and final outcomes remain indefinitely to preserve the existing promise that an old idempotency key cannot trigger another paid operation. Saved output assets are never deleted by this cleanup. Active/unknown queue jobs and nonterminal recoverable operations, including `storage_failed`, are exempt: automatic expiry could destroy in-flight work or the only retained generated pixels. Operators must resolve or explicitly abandon those recoveries under their site's retention policy. This deliberately bounds completed detailed payloads rather than silently expiring deduplication keys or recoverable work. No destructive migration or table rewrite is required.

Known limits: no live AI fidelity guarantee; no autonomous publication; no automatic attachment retry after a human conflict; filesystem writes cannot be transactionally rolled back with database changes. Native database-backed Craft queue metadata (including Craft's proxy-queue wrapper) is supported; a fully substituted queue implementation reports `unavailable` rather than guessing. Alternative backends and PostgreSQL have not been validated.

## Validation

Run `composer check` and the JavaScript session/document tests. The native suite `tests/craft-mcp-enhancements.php` refuses to start outside the disposable `/tmp/ie-mcp-20261031/site` application and `ie_mcp_20261031` database. Supply `IE_MCP_TEST_SITE` only after provisioning that isolated Craft 5 site with this plugin and MCP Tools 1.2. It creates its own volumes, generic Assets/Matrix fields, disabled entries, drafts, users and tokens. It uses the real provider serializer/decoder with a network-free Guzzle transport, so no provider is charged. Run `tests/craft-mcp-readback.php` in a new process afterward. See [the release audit](mcp-audit-3.3.0.md) for actual results and acceptance IDs.
