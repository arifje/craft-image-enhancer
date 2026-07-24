<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\App;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\models\VolumeFolder;
use craft\web\UploadedFile;

class ImageCreatorService extends Component
{
    private const GENERATION_TTL = 3600;
    private const MAX_SOURCE_FILE_SIZE = 25 * 1024 * 1024;
    private const MAX_GENERATED_FILE_SIZE = 50 * 1024 * 1024;

    public function getClientConfig(): array
    {
        $volumes = $this->getWritableVolumes();
        try {
            $settings = $this->getCardsSettings();
            $templates = $settings ? $this->normalizeTemplates($settings->abyssaleTemplates ?? []) : [];
            $apiKey = $settings ? $this->getCardsApiKey($settings) : '';

            $message = '';
            if (!$settings) {
                $message = 'Install and enable the Craft Cards plugin to use Image Creator.';
            } elseif ($apiKey === '') {
                $message = 'Configure the Abyssale API key in the Craft Cards plugin settings.';
            } elseif ($templates === []) {
                $message = 'Configure at least one Abyssale template in the Craft Cards plugin settings.';
            } elseif ($volumes === []) {
                $message = 'You do not have permission to save assets in any volume.';
            }

            $defaultTemplate = (string) ($settings->abyssaleDefaultTemplate ?? '');
            if (!in_array($defaultTemplate, array_column($templates, 'id'), true)) {
                $defaultTemplate = (string) ($templates[0]['id'] ?? '');
            }
        } catch (\Throwable $e) {
            Craft::warning('ImageEnhancer: Could not load Image Creator configuration: ' . $e->getMessage(), __METHOD__);
            $templates = [];
            $defaultTemplate = '';
            $message = 'Image Creator configuration could not be loaded. Check the Craft Cards plugin settings.';
        }

        return [
            'available' => $message === '',
            'message' => $message,
            'templates' => $templates,
            'defaultTemplate' => $defaultTemplate,
            'volumes' => $volumes,
        ];
    }

    public function getTemplateData(string $templateId): array
    {
        $this->requireConfiguredTemplate($templateId);
        $detail = $this->fetchTemplateDetail($templateId);

        $formats = [];
        foreach (($detail['formats'] ?? []) as $format) {
            if (!is_array($format) || empty($format['id'])) {
                continue;
            }

            $label = (string) ($format['name'] ?? $format['id']);
            if (isset($format['width'], $format['height'])) {
                $label .= sprintf(' (%d x %d)', (int) $format['width'], (int) $format['height']);
            }

            $formats[] = [
                'id' => (string) $format['id'],
                'name' => $label,
            ];
        }

        $textElements = [];
        foreach (($detail['elements'] ?? []) as $key => $element) {
            if (!is_array($element)) {
                continue;
            }

            $name = (string) ($element['name'] ?? (is_string($key) ? $key : ''));
            if ($name !== '' && ($element['type'] ?? null) === 'text') {
                $textElements[] = $name;
            }
        }

        return [
            'formats' => $formats,
            'textElements' => array_values(array_unique($textElements)),
        ];
    }

    public function uploadSource(UploadedFile $uploadedFile, int $userId): array
    {
        if ($uploadedFile->getHasError()) {
            throw new \RuntimeException('The source image could not be uploaded.');
        }
        if ((int) $uploadedFile->size > self::MAX_SOURCE_FILE_SIZE) {
            throw new \RuntimeException('The source image is larger than 25 MB.');
        }

        $settings = $this->requireCardsSettings();
        $uploadPath = $this->resolveConfiguredPath((string) ($settings->tempUploadPath ?? ''));
        $uploadUrl = rtrim((string) App::parseEnv($settings->tempUploadUrl ?? ''), '/');
        if ($uploadPath === '' || !is_dir($uploadPath) || !is_writable($uploadPath) || $uploadUrl === '') {
            throw new \RuntimeException('The Craft Cards temporary upload path and URL are not configured correctly.');
        }

        $tempPath = $uploadedFile->saveAsTempFile();
        if ($tempPath === false) {
            throw new \RuntimeException('The source image could not be stored temporarily.');
        }

        try {
            $imageInfo = @getimagesize($tempPath);
            $mimeType = is_array($imageInfo) ? (string) $imageInfo['mime'] : '';
            $extension = $this->extensionForMimeType($mimeType);
            if ($extension === null || !in_array($extension, ['jpg', 'png'], true)) {
                throw new \RuntimeException('Only JPEG and PNG source images are supported.');
            }

            $filename = sprintf(
                'image-creator-%d-%s-%s.%s',
                $userId,
                date('Ymd-His'),
                bin2hex(random_bytes(6)),
                $extension,
            );
            $targetPath = $uploadPath . DIRECTORY_SEPARATOR . $filename;
            if (!copy($tempPath, $targetPath)) {
                throw new \RuntimeException('The source image could not be copied to the public temporary folder.');
            }

            return [
                'url' => $uploadUrl . '/' . rawurlencode($filename),
                'filename' => $uploadedFile->name,
            ];
        } finally {
            FileHelper::unlink($tempPath);
        }
    }

    public function generate(array $requestData, int $userId): array
    {
        $templateId = trim((string) ($requestData['template'] ?? ''));
        $this->requireConfiguredTemplate($templateId);
        $templateData = $this->getTemplateData($templateId);
        $allowedFormats = array_column($templateData['formats'], 'id');
        $templateFormat = trim((string) ($requestData['templateFormat'] ?? ''));
        if ($templateFormat !== '' && !in_array($templateFormat, $allowedFormats, true)) {
            throw new \RuntimeException('The selected template format is not available.');
        }

        $payload = [];
        if ($templateFormat !== '') {
            $payload['template_format_name'] = $templateFormat;
        }

        $allowedTextElements = array_flip($templateData['textElements']);
        $elements = $requestData['elements'] ?? [];
        if (is_string($elements)) {
            $elements = json_decode($elements, true) ?: [];
        }
        foreach (is_array($elements) ? $elements : [] as $name => $value) {
            if (!is_string($name) || !isset($allowedTextElements[$name])) {
                continue;
            }

            $value = trim((string) $value);
            $payload['elements'][$name] = $value === ''
                ? ['hidden' => true]
                : ['payload' => mb_substr($value, 0, 5000)];
        }

        $imageUrl = trim((string) ($requestData['imageUrl'] ?? ''));
        if ($imageUrl !== '') {
            $this->requireAllowedSourceUrl($imageUrl);
            $payload['elements']['image'] = ['image_url' => $imageUrl];
        }

        $teaserUrl = trim((string) ($requestData['imageTeaserUrl'] ?? ''));
        if ($teaserUrl !== '') {
            $this->requireAllowedSourceUrl($teaserUrl);
            $payload['elements']['image_teaser'] = ['image_url' => $teaserUrl];
        } else {
            $payload['elements']['image_teaser'] = ['hidden' => true];
        }

        $settings = $this->requireCardsSettings();
        $response = Craft::createGuzzleClient([
            'base_uri' => $this->getCardsApiUrl($settings),
            'headers' => [
                'x-api-key' => $this->getCardsApiKey($settings),
                'Accept' => 'application/json',
            ],
            'timeout' => 90,
            'connect_timeout' => 10,
        ])->post('/banner-builder/' . rawurlencode($templateId) . '/generate', [
            'json' => $payload,
        ]);

        $result = json_decode((string) $response->getBody(), true);
        $generatedUrl = is_array($result) ? trim((string) ($result['file']['url'] ?? '')) : '';
        if (!$this->isSafeRemoteUrl($generatedUrl)) {
            throw new \RuntimeException('The image provider did not return a valid generated image URL.');
        }

        $token = bin2hex(random_bytes(24));
        $templates = $this->normalizeTemplates($settings->abyssaleTemplates ?? []);
        $templateName = $templateId;
        foreach ($templates as $template) {
            if ($template['id'] === $templateId) {
                $templateName = $template['name'];
                break;
            }
        }

        $saved = Craft::$app->getCache()->set($this->generationCacheKey($token), [
            'userId' => $userId,
            'url' => $generatedUrl,
            'templateId' => $templateId,
            'templateName' => $templateName,
            'createdAt' => time(),
        ], self::GENERATION_TTL);
        if (!$saved) {
            throw new \RuntimeException('The generated image could not be prepared for saving.');
        }

        return [
            'generationToken' => $token,
            'url' => $generatedUrl,
        ];
    }

    public function saveGeneratedAsset(string $token, array $destination, int $userId): Asset
    {
        $context = Craft::$app->getCache()->get($this->generationCacheKey($token));
        if (!is_array($context) || (int) ($context['userId'] ?? 0) !== $userId) {
            throw new \RuntimeException('This generated image has expired. Generate it again before saving.');
        }

        [$folder, $field, $referenceElement] = $this->resolveDestination($destination);
        $this->requireSavePermission($folder);
        $tempPath = tempnam(Craft::$app->getPath()->getTempPath(), 'image-creator-');
        if ($tempPath === false) {
            throw new \RuntimeException('A temporary file could not be created.');
        }

        $asset = null;
        try {
            $this->downloadGeneratedImage((string) $context['url'], $tempPath);
            $imageInfo = @getimagesize($tempPath);
            $mimeType = is_array($imageInfo) ? (string) $imageInfo['mime'] : '';
            $extension = $this->extensionForMimeType($mimeType);
            if ($extension === null) {
                throw new \RuntimeException('The generated file is not a supported image.');
            }

            $filename = AssetsHelper::prepareAssetName(sprintf(
                '%s-%s-%s.%s',
                $context['templateName'] ?: 'generated-image',
                date('Ymd-His'),
                substr($token, 0, 8),
                $extension,
            ));

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($folder->volumeId);
            $asset->uploaderId = $userId;
            $asset->avoidFilenameConflicts = true;
            $asset->title = AssetsHelper::filename2Title(pathinfo($filename, PATHINFO_FILENAME));
            $asset->setScenario(Asset::SCENARIO_CREATE);

            ImageEnhancer::$skipAssetQueue = true;
            try {
                $saved = Craft::$app->getElements()->saveElement($asset);
            } finally {
                ImageEnhancer::$skipAssetQueue = false;
            }
            if (!$saved) {
                throw new \RuntimeException(implode(' ', $asset->getErrorSummary(true)));
            }

            if ($field instanceof AssetsField) {
                $inspection = ImageEnhancer::getInstance()->assetRequirements->inspect(
                    $field,
                    $asset,
                    $referenceElement,
                );
                if (!$inspection['selectable']) {
                    Craft::$app->getElements()->deleteElement($asset);
                    $asset = null;
                    $messages = array_column($inspection['violations'] ?? [], 'message');
                    throw new \RuntimeException(
                        $messages
                            ? 'The generated image does not meet this field: ' . implode(' ', $messages)
                            : 'The generated image does not meet this field selection condition.',
                    );
                }
            }

            Craft::$app->getCache()->delete($this->generationCacheKey($token));

            return $asset;
        } catch (\Throwable $e) {
            if ($asset instanceof Asset && $asset->id) {
                Craft::$app->getElements()->deleteElement($asset);
            }
            throw $e;
        } finally {
            FileHelper::unlink($tempPath);
        }
    }

    private function fetchTemplateDetail(string $templateId): array
    {
        $settings = $this->requireCardsSettings();
        $cacheKey = 'image-enhancer-creator-template-' . hash(
            'sha256',
            $this->getCardsApiUrl($settings) . '|' . $templateId,
        );
        $cached = Craft::$app->getCache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = Craft::createGuzzleClient([
            'base_uri' => $this->getCardsApiUrl($settings),
            'headers' => [
                'x-api-key' => $this->getCardsApiKey($settings),
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
            'connect_timeout' => 10,
        ])->get('/templates/' . rawurlencode($templateId));
        $detail = json_decode((string) $response->getBody(), true);
        if (!is_array($detail)) {
            throw new \RuntimeException('The template provider returned an invalid response.');
        }

        Craft::$app->getCache()->set($cacheKey, $detail, 300);

        return $detail;
    }

    private function resolveDestination(array $destination): array
    {
        $fieldId = (int) ($destination['fieldId'] ?? 0);
        if ($fieldId) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            if (!$field instanceof AssetsField || !$this->isCreatorEnabledForField($field)) {
                throw new \RuntimeException('This asset field is not enabled for Image Creator.');
            }

            $referenceElement = null;
            $elementId = (int) ($destination['elementId'] ?? 0);
            if ($elementId) {
                $siteId = (int) ($destination['siteId'] ?? 0) ?: null;
                $referenceElement = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
            }

            $folderId = $field->resolveDynamicPathToFolderId($referenceElement);
            $folder = $folderId ? Craft::$app->getAssets()->findFolder(['id' => $folderId]) : null;
            if (!$folder instanceof VolumeFolder) {
                throw new \RuntimeException('The asset field upload location could not be resolved.');
            }

            return [$folder, $field, $referenceElement];
        }

        $volumeId = (int) ($destination['volumeId'] ?? 0);
        $volume = $volumeId ? Craft::$app->getVolumes()->getVolumeById($volumeId) : null;
        $folder = $volume ? Craft::$app->getAssets()->getRootFolderByVolumeId((int) $volume->id) : null;
        if (!$folder instanceof VolumeFolder) {
            throw new \RuntimeException('Choose a valid asset volume.');
        }

        return [$folder, null, null];
    }

    private function downloadGeneratedImage(string $url, string $targetPath): void
    {
        if (!$this->isSafeRemoteUrl($url)) {
            throw new \RuntimeException('The generated image URL is invalid.');
        }

        Craft::createGuzzleClient([
            'timeout' => 60,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 3],
        ])->get($url, [
            'sink' => $targetPath,
            'on_headers' => static function($response): void {
                $length = (int) $response->getHeaderLine('Content-Length');
                if ($length > self::MAX_GENERATED_FILE_SIZE) {
                    throw new \RuntimeException('The generated image is larger than 50 MB.');
                }
            },
        ]);

        $fileSize = filesize($targetPath);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > self::MAX_GENERATED_FILE_SIZE) {
            throw new \RuntimeException('The generated image could not be downloaded or is too large.');
        }
    }

    private function requireAllowedSourceUrl(string $url): void
    {
        $settings = $this->requireCardsSettings();
        $baseUrl = rtrim((string) App::parseEnv($settings->tempUploadUrl ?? ''), '/');
        $source = parse_url($url);
        $base = parse_url($baseUrl);
        if (
            !is_array($source) ||
            !is_array($base) ||
            !in_array(strtolower((string) ($source['scheme'] ?? '')), ['http', 'https'], true) ||
            strcasecmp((string) ($source['scheme'] ?? ''), (string) ($base['scheme'] ?? '')) !== 0 ||
            strcasecmp((string) ($source['host'] ?? ''), (string) ($base['host'] ?? '')) !== 0 ||
            (int) ($source['port'] ?? 0) !== (int) ($base['port'] ?? 0) ||
            !str_starts_with((string) ($source['path'] ?? ''), rtrim((string) ($base['path'] ?? ''), '/') . '/')
        ) {
            throw new \RuntimeException('The source image URL is not from the configured temporary upload folder.');
        }
    }

    private function requireConfiguredTemplate(string $templateId): void
    {
        if ($templateId === '') {
            throw new \RuntimeException('Choose an image template.');
        }

        $settings = $this->requireCardsSettings();
        $ids = array_column($this->normalizeTemplates($settings->abyssaleTemplates ?? []), 'id');
        if (!in_array($templateId, $ids, true)) {
            throw new \RuntimeException('The selected image template is not configured.');
        }
    }

    private function normalizeTemplates(array $templates): array
    {
        $normalized = [];
        foreach ($templates as $key => $template) {
            if (is_array($template)) {
                $id = trim((string) ($template['id'] ?? ''));
                $name = trim((string) ($template['name'] ?? $id));
            } elseif (is_string($key) && is_string($template)) {
                $id = trim($key);
                $name = trim($template);
            } else {
                continue;
            }

            if ($id === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
            ];
        }

        return $normalized;
    }

    private function getWritableVolumes(): array
    {
        $volumes = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (!Craft::$app->getUser()->checkPermission('saveAssets:' . $volume->uid)) {
                continue;
            }

            $volumes[] = [
                'id' => (int) $volume->id,
                'name' => (string) $volume->name,
                'handle' => (string) $volume->handle,
            ];
        }

        return $volumes;
    }

    private function isCreatorEnabledForField(AssetsField $field): bool
    {
        $handles = ImageEnhancer::getInstance()->getSettings()->cpEnhancerAssetFieldHandles;

        return $handles === [] || in_array($field->handle, $handles, true);
    }

    private function requireSavePermission(VolumeFolder $folder): void
    {
        if (!Craft::$app->getUser()->checkPermission('saveAssets:' . $folder->getVolume()->uid)) {
            throw new \RuntimeException('You do not have permission to save assets in this volume.');
        }
    }

    private function getCardsSettings(): ?object
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('_cards');
        if (!$plugin || !method_exists($plugin, 'getSettings')) {
            return null;
        }

        return $plugin->getSettings();
    }

    private function requireCardsSettings(): object
    {
        $settings = $this->getCardsSettings();
        if (!$settings) {
            throw new \RuntimeException('The Craft Cards plugin is not installed or enabled.');
        }
        if ($this->getCardsApiKey($settings) === '') {
            throw new \RuntimeException('The Abyssale API key is not configured in the Craft Cards plugin.');
        }

        return $settings;
    }

    private function getCardsApiKey(object $settings): string
    {
        return method_exists($settings, 'getApiKey')
            ? trim((string) $settings->getApiKey())
            : trim((string) App::parseEnv($settings->abyssaleApiKey ?? ''));
    }

    private function getCardsApiUrl(object $settings): string
    {
        $url = method_exists($settings, 'getApiUrl')
            ? (string) $settings->getApiUrl()
            : (string) App::parseEnv($settings->abyssaleApiUrl ?? '');

        return rtrim($url !== '' ? $url : 'https://api.abyssale.com', '/');
    }

    private function resolveConfiguredPath(string $path): string
    {
        $path = (string) App::parseEnv($path);
        if ($path === '') {
            return '';
        }

        return (string) Craft::getAlias($path);
    }

    private function isSafeRemoteUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || $host === 'localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return true;
    }

    private function extensionForMimeType(string $mimeType): ?string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function generationCacheKey(string $token): string
    {
        return 'image-enhancer-creator-generation:' . $token;
    }
}
