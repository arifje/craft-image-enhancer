<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException as RuntimeException;
use arjanbrinkman\craftimageenhancer\helpers\EnhancementPolicy;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\mcp\Access;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;

/** Resolves native draft fields and attaches results without publishing or touching other fields. */
class EnhancementDrafts
{
    /** @return array{entry: Entry, root: Entry, field: Assets, ids: array} */
    public function resolve(int $id, int $siteId, string $handle, ?int $ownerDraftId, User $user): array
    {
        $entry = Entry::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
        if (!$entry instanceof Entry || $entry->getIsRevision() || !$entry->canView($user) || !$entry->canSave($user)) {
            throw new RuntimeException('The requested draft is unavailable or not editable by this user.');
        }
        $root = $entry;
        $seen = [];
        while ($root->getSection() === null) {
            if (count($seen) >= 10 || isset($seen[$root->id])) {
                throw new RuntimeException('Invalid nested ownership chain.');
            }
            $seen[$root->id] = true;
            $owner = $root->getPrimaryOwner();
            if (!$owner instanceof Entry || !$owner->canSave($user) || $owner->siteId !== $entry->siteId
                || !(new Query())->from(Table::ELEMENTS_OWNERS)->where(['elementId' => $root->id, 'ownerId' => $owner->id])->exists()) {
                throw new RuntimeException('The nested entry must be owned by the explicitly requested draft.');
            }
            // A shared canonical block must first be materialized on its draft by Craft.
            if ((new Query())->from(Table::ELEMENTS_OWNERS)->where(['elementId' => $root->id])->andWhere(['not', ['ownerId' => $owner->id]])->exists()) {
                throw new RuntimeException('This nested entry is shared. Edit/materialize it on the draft before enhancing its image.');
            }
            $root = $owner;
        }
        if (!$root->getIsDraft() || $root->getIsRevision() || !$root->canSave($user)
            || ($entry->id !== $root->id && $ownerDraftId !== (int) $root->id)
            || ($entry->id === $root->id && $ownerDraftId !== null && $ownerDraftId !== (int) $root->id)) {
            throw new RuntimeException('Pass a draft element ID; nested entries also require their exact root ownerDraftId. Canonical entries are never a fallback.');
        }
        if (Craft::$app->getIsMultiSite() && !$user->can('editSite:' . $root->getSite()->uid)) {
            throw new RuntimeException('The user may not edit this site.');
        }
        $field = $entry->getFieldLayout()?->getFieldByHandle($handle);
        if (!$field instanceof Assets || !$field->allowUploads || ($field->restrictFiles && $field->allowedKinds && !in_array(Asset::KIND_IMAGE, $field->allowedKinds, true))) {
            throw new RuntimeException('The actual entry layout must contain an upload-enabled Assets field accepting images.');
        }
        $ids = array_map('intval', $entry->getFieldValue($field->handle)->status(null)->trashed(null)->ids());
        return compact('entry', 'root', 'field', 'ids');
    }

    /** Capture field identity, relations and resolved native upload destination before queueing. */
    public function prepare(array $request, User $user): array
    {
        $target = $this->resolve($request['targetDraftId'], $request['siteId'], $request['field'], $request['ownerDraftId'], $user);
        $relations = EnhancementPolicy::relations($target['ids'], -1, $request['replaceAssetId']);
        if ($target['field']->maxRelations && count($relations) > $target['field']->maxRelations) {
            throw new RuntimeException('The field is full. Explicitly select replaceAssetId to replace only that relation.');
        }
        Access::section($target['root']->getSection()->uid);
        $field = $target['field'];
        $volume = $field->_volumeBySourceKey($field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource);
        if (!$volume || !$user->can('saveAssets:' . $volume->uid) || !$user->can('viewAssets:' . $volume->uid)) {
            throw new RuntimeException('The user cannot view and upload assets in the field destination.');
        }
        $folderId = $field->resolveDynamicPathToFolderId($target['entry']);
        self::folder($folderId, $user);
        return [
            'folderId' => $folderId,
            'fieldUid' => $target['field']->uid,
            'fieldId' => $target['field']->id,
            'beforeIds' => $target['ids'],
            'rootDraftId' => (int) $target['root']->id,
            'sectionUid' => $target['root']->getSection()->uid,
        ];
    }

    /** Native volume authorization for an upload destination. */
    public static function folder(int $id, User $user): \craft\models\VolumeFolder
    {
        $folder = Craft::$app->getAssets()->getFolderById($id);
        if (!$folder || !$folder->volumeId || !$user->can('saveAssets:' . $folder->getVolume()->uid) || !$user->can('viewAssets:' . $folder->getVolume()->uid)) {
            throw new RuntimeException('The destination folder is unavailable or the user cannot view and upload assets there.');
        }
        return $folder;
    }

    /** Called inside a transaction after locking the destination element rows. */
    public function attach(array $request, Asset $result, User $user): string
    {
        $target = $this->resolve($request['targetDraftId'], $request['siteId'], $request['field'], $request['ownerDraftId'], $user);
        if ($target['field']->uid !== $request['fieldUid'] || (int) $target['field']->id !== $request['fieldId'] || $target['root']->id !== $request['rootDraftId']) {
            return 'attachment_conflict';
        }
        $expected = EnhancementPolicy::relations($request['beforeIds'], (int) $result->id, $request['replaceAssetId']);
        if ($target['ids'] === $expected) {
            return 'complete';
        }
        if ($target['ids'] !== $request['beforeIds']) {
            return 'attachment_conflict';
        }
        if ($target['field']->resolveDynamicPathToFolderId($target['entry']) !== $request['folderId']) {
            return 'attachment_conflict';
        }
        if ($target['field']->maxRelations && count($expected) > $target['field']->maxRelations) {
            throw new RuntimeException('The target field limit has changed. The generated asset was retained.');
        }
        if (!ImageEnhancer::getInstance()->assetRequirements->inspect($target['field'], $result, $target['entry'])['selectable']) {
            throw new RuntimeException('The generated asset does not meet the field selection rules. It was retained for review.');
        }
        $target['entry']->setFieldValue($target['field']->handle, $expected);
        if (!Craft::$app->getElements()->saveElement($target['entry'], true, false)) {
            throw new RuntimeException('Craft could not save the draft field. The generated asset was retained for review.');
        }
        return 'complete';
    }
}
