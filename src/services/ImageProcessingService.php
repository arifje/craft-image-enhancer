<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\helpers\FileHelper;
use arjanbrinkman\craftimageenhancer\helpers\ImageHelper;
use arjanbrinkman\craftimageenhancer\helpers\ReformatHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;
use craft\base\Component;
use craft\elements\Asset;
use GuzzleHttp\ClientInterface;

/** Shared format processing for the control panel and queued MCP requests. */
class ImageProcessingService extends Component
{
    /** Changes the canvas without stretching the subject. @throws \Throwable */
    public function reformatToTempFile(ClientInterface $client, Settings $settings, Asset $asset, string $localPath, int $width, int $height, string $method, array $providerOptions = [], ?string $prompt = null): string
    {
        $error = ReformatHelper::validateTarget($width, $height, $method);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        $mimeType = (string) $asset->mimeType;
        $tempPath = FileHelper::createTempPathForAsset($asset);
        $canvasPath = null;
        $maskPath = null;
        $aiPath = null;

        try {
            if ($method === ReformatHelper::METHOD_CROP) {
                $focalPoint = $asset->getFocalPoint();
                ReformatHelper::crop(
                    $localPath, $tempPath, $mimeType, $width, $height,
                    (float) ($focalPoint['x'] ?? 0.5), (float) ($focalPoint['y'] ?? 0.5),
                );
            } elseif ($method === ReformatHelper::METHOD_FIT) {
                ReformatHelper::fitWithBlur($localPath, $tempPath, $mimeType, $width, $height);
            } else {
                $canvasPath = FileHelper::createTempPathForAsset($asset);
                $maskPath = FileHelper::createTempPath('png');
                ReformatHelper::writeExtendInput($localPath, $canvasPath, $maskPath, $mimeType, $width, $height);
                $aiPath = ImageEnhancer::getInstance()->aiImageEnhancement->extendToTempFile(
                    $client, $settings, $asset, $canvasPath, $maskPath, $width, $height, $providerOptions, $prompt,
                );
                $error = ImageHelper::validateImageInfo(ImageHelper::getImageInfo($aiPath));
                if ($error !== null) {
                    throw new \RuntimeException('The provider returned an unusable image (' . $error . ').');
                }
                ReformatHelper::compositeExtension($aiPath, $localPath, $tempPath, $mimeType, $width, $height);
            }
        } catch (\Throwable $e) {
            FileHelper::delete($tempPath);
            throw $e;
        } finally {
            FileHelper::delete($canvasPath, $maskPath, $aiPath);
        }

        return $tempPath;
    }
}
