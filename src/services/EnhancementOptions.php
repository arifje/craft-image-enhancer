<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException;
use arjanbrinkman\craftimageenhancer\helpers\EnhancementPolicy;
use arjanbrinkman\craftimageenhancer\helpers\ReformatHelper;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\models\Settings;

/** Uses the same configured providers and model catalog as the editor. */
class EnhancementOptions
{
    /** Credential-free discovery; a key's presence only determines provider availability. */
    public function all(): array
    {
        $plugin = ImageEnhancer::getInstance();
        $settings = $plugin->getSettings();
        $providers = [];
        foreach ([Settings::IMAGE_PROVIDER_OPENAI, Settings::IMAGE_PROVIDER_XAI, Settings::IMAGE_PROVIDER_GOOGLE] as $provider) {
            if ($settings->imageEnhancementProvider !== Settings::IMAGE_PROVIDER_FRONTEND && $settings->imageEnhancementProvider !== $provider) {
                continue;
            }
            $options = ['provider' => $provider];
            if ($plugin->aiImageEnhancement->getConfiguredApiKey($settings, $options) === '') {
                continue;
            }
            $models = match ($provider) {
                Settings::IMAGE_PROVIDER_XAI => Settings::xAiImageEnhancementModelOptions(),
                Settings::IMAGE_PROVIDER_GOOGLE => Settings::googleImageEnhancementModelOptions(),
                default => array_merge($plugin->getImageEnhancementModelOptions(), Settings::imageEnhancementModelOptions()),
            };
            $default = $plugin->aiImageEnhancement->getProviderModel($settings, $options);
            $providers[] = [
                'provider' => $provider,
                'defaultModel' => $default,
                'models' => array_values(array_unique([...array_column($models, 'value'), $default])),
                'operations' => ['preserve_scene', 'enhance', 'generative_expand'],
                'incursAiCosts' => true,
            ];
        }
        return [
            'providers' => $providers,
            'defaultProvider' => $settings->imageEnhancementProvider === Settings::IMAGE_PROVIDER_FRONTEND ? null : $settings->imageEnhancementProvider,
            'operations' => [
                ['operation' => 'preserve_scene', 'available' => class_exists(\Imagick::class) && $providers !== [], 'incursAiCosts' => true, 'description' => 'AI corrections, followed by deterministic fit with blurred padding if the ratio changes. AI fidelity needs human review; no reconstruction is authorized.'],
                ['operation' => 'enhance', 'available' => $providers !== [], 'incursAiCosts' => true, 'description' => 'The Enhance button of the control panel: the site\'s own enhancement prompt and tuning levels make the picture clearer (sharper, less noise and blur, better colour and light) without changing what is in it. Keeps the picture\'s own ratio; to change the ratio use crop or generative_expand, before or after. AI fidelity needs human review.'],
                ['operation' => 'crop', 'available' => true, 'incursAiCosts' => false, 'description' => 'Deterministic crop around the asset focal point and proportional resize.'],
                ['operation' => 'pad', 'available' => class_exists(\Imagick::class), 'incursAiCosts' => false, 'description' => 'Preserve all source content and fit on a blurred copy of the image.'],
                ['operation' => 'generative_expand', 'available' => class_exists(\Imagick::class) && $providers !== [], 'incursAiCosts' => true, 'description' => 'Explicitly generate outside the source. Composite the original centre back over the AI output.'],
            ],
            'aspectRatios' => EnhancementPolicy::RATIOS,
            'customRatios' => 'Positive integer A:B; exact dimensions must satisfy A:B. Local/enhancement ratios 1:4 to 4:1, expansion 1:3 to 3:1.',
            'capabilities' => ['crop' => true, 'padding' => class_exists(\Imagick::class), 'generativeExpansion' => class_exists(\Imagick::class) && $providers !== [], 'upscale' => 'Proportional Lanczos resampling; no claim of recovered factual detail.'],
            'constraints' => ['sourceMimeTypes' => ['image/jpeg', 'image/png'], 'maxSourceBytes' => 52428800, 'maxOutputBytes' => 26214400, 'maxPixels' => ReformatHelper::MAX_PIXELS, 'minOutputEdge' => ReformatHelper::MIN_EDGE, 'maxEdge' => ReformatHelper::MAX_EDGE, 'maxInstructionsLength' => 2000],
            'destinations' => ['sourceFolder' => 'A separate asset alongside the source when no target is supplied.', 'draftField' => 'Actual layout Assets field upload destination. Supply targetDraftId (element ID), siteId and field; nested entries also need ownerDraftId. Default append; replaceAssetId replaces only that selected relation.'],
            'requires' => ['A Craft user: a user-bound HTTP MCP token with content/full scope and upload capability, or a call made inside Craft (MCP Tools 1.8+ LocalClient, such as the AI assistant)', 'Use AI image tools and native source, volume, site and draft permissions', 'Craft queue worker', 'Imagick for padding, preserve-scene sizing and expansion'],
        ];
    }

    /** Reject unsupported or disabled overrides rather than silently changing providers. */
    public function provider(string $operation, ?string $provider, ?string $model): array
    {
        if (in_array($operation, ['crop', 'pad'], true)) {
            if ($provider !== null || $model !== null) {
                throw new EnhancementException('Crop and pad are local operations; omit provider and model.');
            }
            return [];
        }
        $catalog = $this->all();
        $provider ??= $catalog['defaultProvider'];
        foreach ($catalog['providers'] as $option) {
            if ($option['provider'] !== $provider) {
                continue;
            }
            $model ??= $option['defaultModel'];
            if (!in_array($model, $option['models'], true)) {
                break;
            }
            return ['provider' => $provider, 'model' => $model];
        }
        throw new EnhancementException('Choose an enabled provider and supported model from image_enhancement_options. No provider was substituted.');
    }
}
