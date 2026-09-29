<?php

namespace arjanbrinkman\craftimageenhancer;

use Craft;

use arjanbrinkman\craftimageenhancer\models\Settings;
use arjanbrinkman\craftimageenhancer\services\AiImageEnhancementService;
use arjanbrinkman\craftimageenhancer\services\AiVideoGenerationService;
use arjanbrinkman\craftimageenhancer\services\AssetRequirementService;
use arjanbrinkman\craftimageenhancer\services\CleanupService;
use arjanbrinkman\craftimageenhancer\services\OpenAiModelService;
use arjanbrinkman\craftimageenhancer\services\RuntimeSettingsService;
use arjanbrinkman\craftimageenhancer\jobs\AnalyzeImageJob;
use arjanbrinkman\craftimageenhancer\utilities\QualityCheckUtility;
use arjanbrinkman\craftimageenhancer\web\assets\imageenhancer\ImageEnhancerAsset;

use yii\base\Event;

use craft\base\Model;
use craft\base\Plugin;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fields\Assets as AssetsField;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\events\ElementEvent;
use craft\helpers\Json;
use craft\web\View;
use craft\events\TemplateEvent;

/**
 * Image Enhancer plugin
 *
 * @method static ImageEnhancer getInstance()
 * @method Settings getSettings()
 * @property AiImageEnhancementService $aiImageEnhancement
 * @property AiVideoGenerationService $aiVideoGeneration
 * @property AssetRequirementService $assetRequirements
 * @property CleanupService $cleanup
 * @property RuntimeSettingsService $runtimeSettings
 * @property OpenAiModelService $openAiModels
 */
class ImageEnhancer extends Plugin
{
	/**
	 * Permission required to run the paid AI tools (enhance, custom edit, blur faces,
	 * create video) and the upload requirement assistant. Admins hold it implicitly.
	 */
	public const PERMISSION_USE_AI_TOOLS = 'craft-image-enhancer:use-ai-tools';

	public string $schemaVersion = '1.2.0';
	public bool $hasCpSettings = true;

	/**
	 * Legacy suppression flag for the after-save analysis hook.
	 *
	 * Still honoured for backwards compatibility, but it is not nesting-safe (an inner
	 * `finally { $skipAssetQueue = false; }` re-enables the hook for the outer caller).
	 * New code should use {@see suppressAssetQueue()}.
	 */
	public static bool $skipAssetQueue = false;

	/**
	 * Nesting depth of active {@see suppressAssetQueue()} calls.
	 */
	private static int $_assetQueueSuppressionDepth = 0;

	/**
	 * Whether the CP field enhancer has already been registered for this request.
	 */
	private bool $_cpFieldEnhancerRegistered = false;

	public static function config(): array
	{
		return [
			'components' => [
				'openAiModels' => OpenAiModelService::class,
				'aiImageEnhancement' => AiImageEnhancementService::class,
				'aiVideoGeneration' => AiVideoGenerationService::class,
				'assetRequirements' => AssetRequirementService::class,
				'cleanup' => CleanupService::class,
				'runtimeSettings' => RuntimeSettingsService::class,
			],
		];
	}

	/**
	 * Runs $fn while the after-save analysis hook is suppressed. Nesting-safe.
	 *
	 * @template T
	 * @param callable(): T $fn
	 * @return T
	 */
	public static function suppressAssetQueue(callable $fn): mixed
	{
		self::$_assetQueueSuppressionDepth++;
		try {
			return $fn();
		} finally {
			self::$_assetQueueSuppressionDepth--;
		}
	}

	/**
	 * Whether the after-save analysis hook is currently suppressed.
	 */
	public static function isAssetQueueSuppressed(): bool
	{
		return self::$_assetQueueSuppressionDepth > 0 || self::$skipAssetQueue;
	}

	/**
	 * Whether a volume handle is in the analysis allow-list (an empty list selects nothing).
	 *
	 * @param string[] $allowedHandles
	 */
	public static function isVolumeHandleInScope(?string $volumeHandle, array $allowedHandles): bool
	{
		return $volumeHandle !== null && $volumeHandle !== '' && in_array($volumeHandle, $allowedHandles, true);
	}

	public function init(): void
	{
		parent::init();

		// Component-type and permission registration must run in every request context.
		$this->registerPermissions();
		$this->registerGarbageCollection();
		$this->attachEventHandlers();
		$this->registerUtilities();

		// Tabs (settings page)
		$this->_registerSettings();

		// Identity/session and view work must wait until Craft has fully bootstrapped.
		Craft::$app->onInit(function() {
			$request = Craft::$app->getRequest();
			if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
				return;
			}

			Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event) {
				if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
					return;
				}

				$this->registerCpFieldEnhancer();
			});
		});
	}

	protected function createSettingsModel(): ?Model
	{
		return Craft::createObject(Settings::class);
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('craft-image-enhancer/_settings.twig', [
			'plugin' => $this,
			'settings' => $this->getSettings(),
			'chatGptModelOptions' => $this->getChatGptModelOptions(),
			'imageEnhancementModeOptions' => Settings::imageEnhancementModeOptions(),
			'imageEnhancementTriggerOptions' => Settings::imageEnhancementTriggerOptions(),
			'imageEnhancementActionOptions' => Settings::imageEnhancementActionOptions(),
			'imageEnhancementProviderOptions' => Settings::imageEnhancementProviderOptions(),
			'imageEnhancementModelOptions' => $this->getImageEnhancementModelOptions(),
			'xAiImageEnhancementModelOptions' => Settings::xAiImageEnhancementModelOptions(),
			'googleImageEnhancementModelOptions' => Settings::googleImageEnhancementModelOptions(),
			'imageEnhancementFaceHandlingOptions' => Settings::imageEnhancementFaceHandlingOptions(),
			'assetFieldOptions' => $this->getAssetFieldOptions(),
		]);
	}

	public function getChatGptModelOptions(): array
	{
		$models = Settings::fallbackChatGptModels();

		foreach ($this->openAiModels->getModels($this->getSettings()) as $model) {
			if (Settings::isSupportedChatGptModel($model)) {
				$models[] = $model;
			}
		}

		$models = array_values(array_unique($models));

		return array_map(static fn(string $model): array => [
			'label' => $model === Settings::MODEL_LATEST ? 'Latest available model' : $model,
			'value' => $model,
		], $models);
	}

	/**
	 * @param bool $allowRemoteLookup Pass false on page renders so a cold cache falls back to
	 * the curated model list instead of making a live OpenAI request.
	 */
	public function getImageEnhancementModelOptions(bool $allowRemoteLookup = true): array
	{
		return $this->openAiModels->getImageModelOptions($this->getSettings(), $allowRemoteLookup);
	}

	private function _registerSettings(): void
	{
		// Settings Template
		Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, function (
			TemplateEvent $e
		) {
			if (
				$e->template == "settings/plugins/_settings.twig" &&
				($e->variables['plugin']->handle ?? null) === $this->handle
			) {
				// Add the tabs
				$e->variables["tabs"] = [
					["label" => "ChatGPT", "url" => "#settings-tab-chatgpt"],
					["label" => "Notifications", "url" => "#settings-tab-notifications"],									
					["label" => "Enhancement", "url" => "#settings-tab-enhancement"],
					["label" => "Volumes", "url" => "#settings-tab-volumes"],
				];
			}
		});
	}

	private function registerUtilities(): void
	{
		// Craft 5 renamed EVENT_REGISTER_UTILITY_TYPES to EVENT_REGISTER_UTILITIES.
		$eventName = defined(Utilities::class . '::EVENT_REGISTER_UTILITIES')
			? constant(Utilities::class . '::EVENT_REGISTER_UTILITIES')
			: constant(Utilities::class . '::EVENT_REGISTER_UTILITY_TYPES');

		Event::on(
			Utilities::class,
			$eventName,
			static function(RegisterComponentTypesEvent $event) {
				$event->types[] = QualityCheckUtility::class;
			}
		);
	}

	private function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			static function(RegisterUserPermissionsEvent $event) {
				$event->permissions[] = [
					'heading' => 'Image Enhancer',
					'permissions' => [
						self::PERMISSION_USE_AI_TOOLS => [
							'label' => 'Use AI image tools (enhance, custom edit, blur faces, create video, upload repair)',
						],
					],
				];
			}
		);
	}

	private function registerGarbageCollection(): void
	{
		Event::on(Gc::class, Gc::EVENT_RUN, function() {
			try {
				$this->cleanup->purgeStale();
			} catch (\Throwable $e) {
				Craft::warning('ImageEnhancer: Stale data cleanup failed (' . get_class($e) . ').', __METHOD__);
			}
		});
	}

	private function registerCpFieldEnhancer(): void
	{
		if ($this->_cpFieldEnhancerRegistered) {
			return;
		}

		$user = Craft::$app->getUser()->getIdentity();
		if (!$user || !$user->can(self::PERMISSION_USE_AI_TOOLS)) {
			return;
		}

		$this->_cpFieldEnhancerRegistered = true;

		$settings = $this->getSettings();
		$videoService = $this->aiVideoGeneration;
		$config = [
			'craftMajorVersion' => (int) explode('.', Craft::$app->getVersion())[0],
			'uploadRequirementAssistantEnabled' => $settings->enableUploadRequirementAssistant,
			'providerChoiceEnabled' => $settings->imageEnhancementProvider === Settings::IMAGE_PROVIDER_FRONTEND,
			'imageEnhancementProvider' => $settings->imageEnhancementProvider,
			'imageEnhancementModel' => $settings->imageEnhancementModel,
			'xAiImageEnhancementModel' => $settings->xAiImageEnhancementModel,
			'googleImageEnhancementModel' => $settings->googleImageEnhancementModel,
			'allowedFieldHandles' => $settings->cpEnhancerAssetFieldHandles,
			'providerOptions' => Settings::imageEnhancementProviderOptions(),
			'modelOptions' => [
				// Cache-only: a page render must never wait on the OpenAI model endpoint.
				Settings::IMAGE_PROVIDER_OPENAI => $this->getImageEnhancementModelOptions(false),
				Settings::IMAGE_PROVIDER_XAI => Settings::xAiImageEnhancementModelOptions(),
				Settings::IMAGE_PROVIDER_GOOGLE => Settings::googleImageEnhancementModelOptions(),
			],
			'videoProviderOptions' => $videoService->getAvailableProviderOptions($settings),
			'videoModelOptions' => AiVideoGenerationService::modelOptions(),
			'routes' => [
				'uploadAssistant' => 'craft-image-enhancer/upload-assistant/upload',
				'uploadLocalRepair' => 'craft-image-enhancer/upload-assistant/local-repair',
				'uploadFinalize' => 'craft-image-enhancer/upload-assistant/finalize',
				'uploadDiscard' => 'craft-image-enhancer/upload-assistant/discard',
				'assetInfo' => 'craft-image-enhancer/article-image/asset-info',
				'enhance' => 'craft-image-enhancer/article-image/enhance',
				'createVideo' => 'craft-image-enhancer/article-image/create-video',
				'blurFaces' => 'craft-image-enhancer/article-image/blur-faces',
				'status' => 'craft-image-enhancer/article-image/status',
				'cancel' => 'craft-image-enhancer/article-image/cancel',
				'reset' => 'craft-image-enhancer/article-image/reset',
				'keep' => 'craft-image-enhancer/article-image/keep',
				'discard' => 'craft-image-enhancer/article-image/discard',
			],
		];

		Craft::$app->getView()->registerAssetBundle(ImageEnhancerAsset::class);
		Craft::$app->getView()->registerJs('window.ImageEnhancerCp = ' . Json::htmlEncode($config) . ';', View::POS_HEAD);
	}

	private function getAssetFieldOptions(): array
	{
		$options = [];
		foreach (Craft::$app->getFields()->getAllFields() as $field) {
			if (!$field instanceof AssetsField) {
				continue;
			}

			$options[] = [
				'label' => $field->name . ' (' . $field->handle . ')',
				'value' => $field->handle,
			];
		}

		return $options;
	}
	
	private function attachEventHandlers(): void
	{
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
			$element = $event->element;

			if (!$element instanceof Asset || !$event->isNew || self::isAssetQueueSuppressed()) {
				return;
			}

			$this->queueAssetAnalysis($element, $this->getRequestEntryId());
		});
	}

	/**
	 * Whether an asset is covered by the analysis allow-list (same rule AnalyzeImageJob applies).
	 */
	public function isAssetInAnalysisScope(Asset $asset): bool
	{
		if ($asset->kind !== Asset::KIND_IMAGE) {
			return false;
		}

		try {
			$volumeHandle = $asset->getVolume()->handle;
		} catch (\Throwable $e) {
			return false;
		}

		return self::isVolumeHandleInScope($volumeHandle, $this->getSettings()->allowedAssetFieldHandles);
	}

	public function queueAssetAnalysis(Asset $asset, ?int $entryId = null): void
	{
		$settings = $this->getSettings();
		if (
			!$this->runtimeSettings->isQualityCheckEnabled() ||
			$settings->imageEnhancementMode === Settings::ENHANCEMENT_DISABLED ||
			!$this->isAssetInAnalysisScope($asset)
		) {
			return;
		}

		Craft::$app->getQueue()->push(new AnalyzeImageJob([
			'assetId' => $asset->id,
			'entryId' => $entryId ?? $this->getRelatedEntryIdForAsset((int) $asset->id),
		]));
	}

	private function getRequestEntryId(): ?int
	{
		$request = Craft::$app->getRequest();

		if (!method_exists($request, 'getBodyParam')) {
			return null;
		}

		$elementId = $request->getBodyParam('elementId');

		if (!$elementId) {
			return null;
		}

		$siteId = $request->getBodyParam('siteId') ?: null;
		$element = Craft::$app->elements->getElementById((int) $elementId, null, $siteId);

		if (!$element instanceof Entry) {
			return null;
		}

		return $this->getNotificationEntryId($element);
	}

	private function getRelatedEntryIdForAsset(int $assetId): ?int
	{
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $assetId])
			->scalar();

		if (!$sourceId) {
			return null;
		}

		$element = Craft::$app->elements->getElementById((int) $sourceId, null, '*');

		if ($element instanceof Entry) {
			return $this->getNotificationEntryId($element);
		}

		$ownerId = $element->ownerId ?? null;

		return $ownerId ? (int) $ownerId : null;
	}

	private function getNotificationEntryId(Entry $entry): int
	{
		$ownerId = $entry->ownerId ?? null;

		if ($ownerId) {
			return (int) $ownerId;
		}

		$canonicalId = $entry->canonicalId ?? null;

		if ($canonicalId && (int) $canonicalId !== (int) $entry->id) {
			return (int) $canonicalId;
		}

		return (int) $entry->id;
	}
	
}
