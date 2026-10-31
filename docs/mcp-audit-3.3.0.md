# MCP enhancement release audit — 2026-10-31

Release: **3.3.0**. Tested in `general-craft-5-test-container-php-1`, PHP 8.4.27, Craft 5.11.5.1, MySQL 8, Imagick, Craft MCP Tools 1.2.0. The application and plugin copies were isolated under `/tmp/ie-mcp-20261031`; all fixtures used the separate `ie_mcp_20261031` database. The shared Craft site's content/configuration and production were not changed.

## Passed

- `composer check`: PHPStan level 4, the repository ECS standard and **438 PHP regression checks**. ECS's bundled older Symfony code emits PHP 8.4 deprecation notices, but the check passes with no style violations.
- `node tests/editor-document.js` and `node tests/editor-session.js`: history, layers, format transforms, dimensions, session source selection and cleanup.
- `IE_MCP_TEST_SITE=/tmp/ie-mcp-20261031/site php tests/craft-mcp-enhancements.php`: **61 native checks**. Includes registration and real SDK schemas, exact image pixels and source preservation, native dynamic destination folders, draft-only saves, exclusive nested ownership, shared-block rejection, field conflicts, unrelated edits, invalid MIME/provider/model/ratio inputs, local crop/pad, mocked AI corrections/expansion, idempotency, queue/provider/storage/attachment failures, scoped/read-only/upload tokens, native non-admin permissions, cross-user status denial and token revocation.
- Revoking the user's plugin permission directly in the database during a provider response stops storage even when permissions were cached earlier in that worker. After authorization is restored, the same-key retry reuses the retained pixels without another provider request.
- Fresh native plugin installation created schema 1.3.0 and its unique request/idempotency indexes.
- Independent PHP process readback verified the persisted draft/asset relationship, original SHA-256, exact encoded dimensions and unpublished canonical state. See `tests/craft-mcp-readback.php`.
- A second Composer vendor tree with **MCP Tools absent** booted Image Enhancer successfully and performed native crop/fit operations. The MCP SDK remained a development dependency only.
- `git diff --check` passed.

## Development acceptance result

The suite explicitly created a new test draft. It called `image_enhance` with `preserve_scene`, a configured OpenAI model, exact 1200×675 pixels and that draft's Assets field, executed the native queue job and read the result back.

| Item | Value |
| --- | --- |
| Source asset | 133 |
| Disabled canonical entry | 134 |
| Unpublished test draft element | 148 |
| Result asset | 149 |
| Assets field | `thumbnailc8cef930` |
| Site | 1 |
| Actual result pixels | 1200×675 |
| Original SHA-256 | `c1649488b5f08280f242797070e9e2d04b2dd87963f04d6904da4d762edfa0e5` |

The original relation remains on the canonical entry. The draft contains the original plus the separate result. No content was published.

## Limits of this verification

AI transport was mocked: requests and responses passed through the actual provider serializer/decoder, but **no live provider requests or charges occurred**. This establishes orchestration and pixel/attachment behavior, not real-model fidelity or credentials. Native tests called the registered tool methods with real MCP token records and HTTP call contexts; they did not test a remote client's transport handshake. PostgreSQL, alternative queue drivers, multiple workers racing on separate nodes, and every third-party field condition were not tested. Craft 4 is not supported by the 3.x branch. This release did not change editor JavaScript or CSS; browser UI checks were not repeated.

Configuration, example calls, retries and operational limits are documented in [MCP image enhancement](mcp-image-enhancement.md). Run `php craft up` and restart queue workers when deploying. No change to Craft MCP Tools is required.
