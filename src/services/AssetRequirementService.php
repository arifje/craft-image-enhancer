<?php

namespace arjanbrinkman\craftimageenhancer\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\assets\FileSizeConditionRule;
use craft\elements\conditions\assets\HeightConditionRule;
use craft\elements\conditions\assets\WidthConditionRule;
use craft\elements\conditions\ElementCondition;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\fields\Assets as AssetsField;
use yii\base\Component;

class AssetRequirementService extends Component
{
    private const REPAIR_CONTEXT_TTL = 3600;
    private const MAX_REPAIR_DIMENSION = 8192;

    public function inspect(AssetsField $field, Asset $asset, ?ElementInterface $referenceElement = null): array
    {
        $condition = $field->getSelectionCondition();
        if (!$condition instanceof ElementCondition) {
            return $this->inspectionResult($asset, [], true);
        }

        $condition->referenceElement = $referenceElement;
        $violations = [];
        $constraints = [];
        foreach ($condition->getConditionRules() as $rule) {
            if (!$rule instanceof ElementConditionRuleInterface) {
                continue;
            }

            $matches = $rule->matchElement($asset);
            $violation = $this->describeViolation($rule, $asset);
            if ($violation !== null) {
                $violation['failed'] = !$matches;
                $constraints[] = $violation;
            }
            if ($matches) {
                continue;
            }

            if ($violation === null) {
                return $this->inspectionResult($asset, [[
                    'type' => 'unsupported',
                    'label' => $rule->getLabel(),
                    'message' => sprintf('%s does not meet this field requirement.', $rule->getLabel()),
                ]], false);
            }

            $violations[] = $violation;
        }

        if (empty($violations) && !$condition->matchElement($asset)) {
            return $this->inspectionResult($asset, [[
                'type' => 'unsupported',
                'label' => 'Selection condition',
                'message' => 'The image does not meet this field selection condition.',
            ]], false);
        }

        return $this->inspectionResult($asset, $violations, $condition->matchElement($asset), $constraints);
    }

    public function createRepairContext(
        Asset $asset,
        AssetsField $field,
        int $targetFolderId,
        string $originalFilename,
        ?int $referenceElementId,
        ?int $siteId,
        int $userId,
    ): string {
        $token = bin2hex(random_bytes(24));
        $saved = Craft::$app->getCache()->set($this->cacheKey($token), [
            'assetId' => (int) $asset->id,
            'fieldId' => (int) $field->id,
            'targetFolderId' => $targetFolderId,
            'originalFilename' => $originalFilename,
            'referenceElementId' => $referenceElementId,
            'siteId' => $siteId,
            'userId' => $userId,
        ], self::REPAIR_CONTEXT_TTL);
        if (!$saved) {
            throw new \RuntimeException('Could not create an upload repair session.');
        }

        return $token;
    }

    public function getRepairContext(string $token): ?array
    {
        $context = Craft::$app->getCache()->get($this->cacheKey($token));

        return is_array($context) ? $context : null;
    }

    public function deleteRepairContext(string $token): void
    {
        Craft::$app->getCache()->delete($this->cacheKey($token));
    }

    public function getRepairTargetDimensions(string $token, int $assetId, int $userId): ?array
    {
        $context = $this->getAuthorizedRepairContext($token, $userId, $assetId);
        if (!$context) {
            return null;
        }

        $asset = Craft::$app->getAssets()->getAssetById($assetId);
        $field = Craft::$app->getFields()->getFieldById((int) ($context['fieldId'] ?? 0));
        if (!$asset instanceof Asset || !$field instanceof AssetsField) {
            return null;
        }

        $referenceElement = null;
        if (!empty($context['referenceElementId'])) {
            $referenceElement = Craft::$app->getElements()->getElementById(
                (int) $context['referenceElementId'],
                null,
                $context['siteId'] ?? null,
            );
        }

        $inspection = $this->inspect($field, $asset, $referenceElement);
        if (!$inspection['repairable']) {
            return null;
        }

        return [
            'width' => (int) $inspection['targetWidth'],
            'height' => (int) $inspection['targetHeight'],
        ];
    }

    public function getAuthorizedRepairContext(string $token, int $userId, ?int $assetId = null): ?array
    {
        $context = $this->getRepairContext($token);
        $contextAssetId = (int) ($context['assetId'] ?? 0);
        if (
            !$context ||
            !$contextAssetId ||
            ($assetId !== null && $contextAssetId !== $assetId) ||
            (int) ($context['userId'] ?? 0) !== $userId
        ) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $asset = Craft::$app->getAssets()->getAssetById($contextAssetId);
        $targetFolder = Craft::$app->getAssets()->findFolder([
            'id' => (int) ($context['targetFolderId'] ?? 0),
        ]);
        if (
            !$user ||
            (int) $user->id !== $userId ||
            !$asset instanceof Asset ||
            (int) $asset->uploaderId !== $userId ||
            !$targetFolder ||
            !Craft::$app->getUser()->checkPermission('saveAssets:' . $targetFolder->getVolume()->uid)
        ) {
            return null;
        }

        return $context;
    }

    private function inspectionResult(Asset $asset, array $violations, bool $selectable, array $constraints = []): array
    {
        $target = $this->targetDimensions($asset, $constraints ?: $violations);

        return [
            'selectable' => $selectable,
            'repairable' => !$selectable && !empty($violations) && $target['available'],
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
            'fileSize' => (int) $asset->size,
            'fileSizeLabel' => $this->formatBytes((int) $asset->size),
            'targetWidth' => $target['width'],
            'targetHeight' => $target['height'],
            'violations' => $violations,
        ];
    }

    private function describeViolation(ElementConditionRuleInterface $rule, Asset $asset): ?array
    {
        if ($rule instanceof WidthConditionRule) {
            return $this->dimensionViolation('width', 'Width', (int) $asset->width, $rule);
        }

        if ($rule instanceof HeightConditionRule) {
            return $this->dimensionViolation('height', 'Height', (int) $asset->height, $rule);
        }

        if ($rule instanceof FileSizeConditionRule) {
            $expected = $this->operatorDescription($rule->operator, $rule->value, $rule->maxValue, $rule->unit);

            return [
                'type' => 'fileSize',
                'label' => 'File size',
                'operator' => $rule->operator,
                'value' => (float) $rule->value,
                'maxValue' => (float) $rule->maxValue,
                'unit' => $rule->unit,
                'current' => (int) $asset->size,
                'currentLabel' => $this->formatBytes((int) $asset->size),
                'expectedLabel' => $expected,
                'message' => sprintf('File size is %s; it must be %s.', $this->formatBytes((int) $asset->size), $expected),
            ];
        }

        return null;
    }

    private function dimensionViolation(string $type, string $label, int $current, WidthConditionRule|HeightConditionRule $rule): array
    {
        $expected = $this->operatorDescription($rule->operator, $rule->value, $rule->maxValue, 'px');

        return [
            'type' => $type,
            'label' => $label,
            'operator' => $rule->operator,
            'value' => (float) $rule->value,
            'maxValue' => (float) $rule->maxValue,
            'unit' => 'px',
            'current' => $current,
            'currentLabel' => $current . ' px',
            'expectedLabel' => $expected,
            'message' => sprintf('%s is %d px; it must be %s.', $label, $current, $expected),
        ];
    }

    private function targetDimensions(Asset $asset, array $violations): array
    {
        $width = max(1, (int) $asset->width);
        $height = max(1, (int) $asset->height);
        $minimumScale = 0.0;
        $maxScale = self::MAX_REPAIR_DIMENSION / max($width, $height);

        foreach ($violations as $violation) {
            $type = $violation['type'] ?? '';
            $operator = $violation['operator'] ?? '';
            $value = (float) ($violation['value'] ?? 0);
            $maxValue = (float) ($violation['maxValue'] ?? 0);

            if ($type === 'width' || $type === 'height') {
                $current = $type === 'width' ? $width : $height;
                [$minimum, $maximum] = $this->numericBounds($operator, $value, $maxValue);
                if ($minimum !== null) {
                    $minimumScale = max($minimumScale, $minimum / $current);
                }
                if ($maximum !== null) {
                    $maxScale = min($maxScale, $maximum / $current);
                }
                if ($minimum === null && $maximum === null) {
                    return ['available' => false, 'width' => null, 'height' => null];
                }
                continue;
            }

            if ($type === 'fileSize') {
                $bytes = $this->fileSizeBytes($value, (string) ($violation['unit'] ?? 'B'));
                $currentSize = max(1, (int) $asset->size);
                $failed = (bool) ($violation['failed'] ?? false);
                if (in_array($operator, ['>', '>=', '='], true)) {
                    $minimumScale = max($minimumScale, sqrt(($bytes * ($failed ? 1.15 : 1.0)) / $currentSize));
                } elseif (in_array($operator, ['<', '<='], true)) {
                    $maxScale = min($maxScale, sqrt(($bytes * ($failed ? 0.85 : 1.0)) / $currentSize));
                } else {
                    return ['available' => false, 'width' => null, 'height' => null];
                }
                continue;
            }

            return ['available' => false, 'width' => null, 'height' => null];
        }

        if ($maxScale <= 0 || $minimumScale > $maxScale + 0.0001) {
            return ['available' => false, 'width' => null, 'height' => null];
        }
        $scale = max($minimumScale, min(1.0, $maxScale));

        return [
            'available' => true,
            'width' => max(1, (int) ceil($width * $scale)),
            'height' => max(1, (int) ceil($height * $scale)),
        ];
    }

    private function numericBounds(string $operator, float $value, float $maxValue): array
    {
        return match ($operator) {
            '>' => [floor($value) + 1, null],
            '>=' => [ceil($value), null],
            '<' => [null, max(1, ceil($value) - 1)],
            '<=' => [null, floor($value)],
            '=' => [ceil($value), floor($value)],
            'between' => [ceil($value), floor($maxValue)],
            default => [null, null],
        };
    }

    private function operatorDescription(string $operator, string|float $value, string|float $maxValue, string $unit): string
    {
        $valueLabel = trim((string) $value . ' ' . $unit);
        $maxValueLabel = trim((string) $maxValue . ' ' . $unit);

        return match ($operator) {
            '>' => 'greater than ' . $valueLabel,
            '>=' => 'at least ' . $valueLabel,
            '<' => 'less than ' . $valueLabel,
            '<=' => 'at most ' . $valueLabel,
            '=' => 'exactly ' . $valueLabel,
            'between' => sprintf('between %s and %s', $valueLabel, $maxValueLabel),
            default => sprintf('compatible with the configured %s rule', strtolower($unit === 'px' ? 'dimension' : 'file size')),
        };
    }

    private function fileSizeBytes(float $value, string $unit): int
    {
        $multiplier = match ($unit) {
            'KB' => 1000,
            'MB' => 1000000,
            'GB' => 1000000000,
            default => 1,
        };

        return (int) round($value * $multiplier);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1000) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 'B';
        foreach ($units as $nextUnit) {
            $value /= 1000;
            $unit = $nextUnit;
            if ($value < 1000) {
                break;
            }
        }

        return number_format($value, $value >= 10 ? 1 : 2) . ' ' . $unit;
    }

    private function cacheKey(string $token): string
    {
        return 'image-enhancer:upload-repair:' . $token;
    }
}
