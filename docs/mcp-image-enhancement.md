# MCP image enhancement

Image Enhancer 3.3 adds `image_enhancement_options`, `image_enhance` and `image_enhancement_status` through Craft MCP Tools' `EVENT_REGISTER_TOOLS` event. Processing uses the same provider integration and format processor as the editor. No site-specific fields or volumes are assumed.

## Configuration and upgrade

- Craft CMS 5.11+, PHP 8.2+ for Image Enhancer. Optional **`arifje/craft-mcp-tools` 1.2+ requires PHP 8.3+**. No changes to MCP Tools are required.
- Run `php craft up` after updating; schema 1.3.0 creates `imageenhancer_mcp_requests`. Restart queue workers. Fresh installations create the same table. Existing images and settings are preserved.
- Enable MCP Tools and its dangerous/content tools. Connect through its authenticated HTTP transport with a user-bound **content/full** token holding the **upload** capability. Stdio's implicit administrator identity is deliberately unsupported.
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

`preserve_scene` defaults to exposure/colour/noise corrections and prohibits invented details in the provider prompt; changed ratios use deterministic fit/padding. AI cannot guarantee photographic fidelity: human review is required. `generative_expand` explicitly authorizes generated surroundings, then composites the original centre back over the output. Reconstruction of people, objects, damage or text is not an offered operation. Embedded image text and metadata are treated as untrusted content.

Sources must be JPEG/PNG, at most 50 MiB, 8192 pixels per edge and 24 megapixels. Outputs retain JPEG/PNG format, have 64–8192 pixel edges, at most 24 megapixels and 25 MiB. Final decoded dimensions are checked before saving. Ratios are limited to 1:4–4:1, or 1:3–3:1 for expansion.

Poll the returned request ID:

```json
{"name":"image_enhancement_status","arguments":{"requestId":"returned-request-uuid"}}
```

Polling only reads. It never generates, queues, saves or attaches. Responses include the queue job ID, stage/progress/timestamps, safe error, separate generation/storage/attachment outcomes, asset ID/dimensions/URL, natural key `{volume,path,filename}`, draft/field/site IDs, review links, warnings and provenance. Asset URLs may be null for private volumes. Progress is checkpoint-based, not provider streaming progress. Provenance lives in the request table; descriptive alt text is copied from the source, never replaced by technical logs.

For nested Matrix entries, pass their element ID as `targetDraftId` and the exact root draft element ID as `ownerDraftId`. Every owner must be editable and the ownership chain must be exclusive to that draft. Shared canonical blocks must first be edited/materialized on the draft using Craft. The tool refuses ambiguous ownership; it never falls back to saving a canonical entry.

## Conflicts, failures and retries

Submission validates the user/token, source, operation, model, exact size, actual entry layout, field limits and native destination before starting work. Workers recheck live permissions/token state before processing, saving and attaching. Attachment locks native element/site rows, reloads the draft, compares ordered relations with the submission snapshot, and saves only that draft through Craft. Unrelated fields are loaded fresh and preserved. A changed target field or destination produces `attachment_conflict`; the new asset is retained for explicit manual attachment. Native field selection conditions are checked on the resulting asset. Attachment errors likewise retain it as `attachment_failed`.

Reuse **identical arguments and the same idempotency key** for submission retries. Keys are scoped to the user and retained without automatic expiry. Changed arguments with an existing key are rejected. Completed requests return the existing asset. Status is restricted to the initiating user and currently accessible source/result and target section.

A `storage_failed` result means generated pixels were retained privately. Fix storage/permissions, then submit the same arguments/key to enqueue a storage-only recovery without another provider request. A stopped worker can recover an existing durable checkpoint when its original queue job is manually retried. If it stopped before that checkpoint, it reports `uncertain` and does not repeat a potentially charged request. A request left in `processing` beyond the job timeout needs an operator to inspect/retry that original queue job; polling does not restart it. Provider failures are not automatically retried. An enqueue failure reports `failed`; fix the queue and deliberately submit a new key only after confirming no work ran.

Known limits: no live AI fidelity guarantee; no autonomous publication; no automatic attachment retry after a human conflict; no automatic provenance expiration; filesystem writes cannot be transactionally rolled back with database changes. Retained private files require operator cleanup after abandoned storage failures. Tests use the native Craft database queue; alternative queue drivers and PostgreSQL have not been validated.

## Validation

Run `composer check` and the JavaScript session/document tests. The native suite `tests/craft-mcp-enhancements.php` refuses to start outside the disposable `/tmp/ie-mcp-20261031/site` application and `ie_mcp_20261031` database. Supply `IE_MCP_TEST_SITE` only after provisioning that isolated Craft 5 site with this plugin and MCP Tools 1.2. It creates its own volumes, generic Assets/Matrix fields, disabled entries, drafts, users and tokens. It uses the real provider serializer/decoder with a network-free Guzzle transport, so no provider is charged. Run `tests/craft-mcp-readback.php` in a new process afterward. See [the release audit](mcp-audit-3.3.0.md) for actual results and acceptance IDs.
