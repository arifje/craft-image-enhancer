<?php

declare(strict_types=1);

// Run in a new PHP process after craft-mcp-enhancements.php.
$site = getenv('IE_MCP_TEST_SITE');
if ($site !== '/tmp/ie-mcp-20261031/site') throw new RuntimeException('Wrong isolated test site.');
require $site . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
if ($app->getDb()->createCommand('SELECT DATABASE()')->queryScalar() !== 'ie_mcp_20261031') throw new RuntimeException('Wrong isolated test database.');

use craft\elements\Entry;
use arjanbrinkman\craftimageenhancer\helpers\FileHelper;

$evidence = json_decode(file_get_contents(dirname($site) . '/acceptance.json'), true, 512, JSON_THROW_ON_ERROR);
$draft = Entry::find()->id($evidence['draftId'])->siteId($evidence['siteId'])->status(null)->drafts(null)->one();
$canonical = Entry::find()->id($evidence['canonicalId'])->siteId($evidence['siteId'])->status(null)->one();
$source = $app->getAssets()->getAssetById($evidence['sourceAssetId']);
$result = $app->getAssets()->getAssetById($evidence['resultAssetId']);
if (!$draft?->getIsDraft() || !$canonical || $canonical->enabled || !$source || !$result || $source->id === $result->id) throw new RuntimeException('Draft or asset state is incorrect.');
$ids = array_map('intval', $draft->getFieldValue($evidence['field'])->ids());
$canonicalIds = array_map('intval', $canonical->getFieldValue($evidence['field'])->ids());
if ($ids !== [(int)$source->id, (int)$result->id] || $canonicalIds !== [(int)$source->id]) throw new RuntimeException('Unexpected draft/canonical relations.');
$sourcePath = FileHelper::copyAssetToTemp($source);
$resultPath = FileHelper::copyAssetToTemp($result);
try {
    $size = getimagesize($resultPath);
    if ($size[0] !== 1200 || $size[1] !== 675 || $result->width !== 1200 || $result->height !== 675) throw new RuntimeException('Result pixels or metadata are incorrect.');
    if (hash_file('sha256', $sourcePath) !== $evidence['sourceSha256']) throw new RuntimeException('Original file changed.');
} finally {
    FileHelper::delete($sourcePath, $resultPath);
}
echo "PASS independent readback: exact encoded pixels, separate asset, original hash, draft-only relation, unpublished canonical.\n";
