<?php

namespace arjanbrinkman\craftimageenhancer;

use Craft;

use arjanbrinkman\craftimageenhancer\models\Settings;
use arjanbrinkman\craftimageenhancer\services\AiImageEnhancementService;
use arjanbrinkman\craftimageenhancer\services\AssetRequirementService;
use arjanbrinkman\craftimageenhancer\services\ImageCreatorService;
use arjanbrinkman\craftimageenhancer\services\ImageQualityService;
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
use craft\fields\Assets as AssetsField;
use craft\services\Elements;
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
 * @property AssetRequirementService $assetRequirements
 * @property ImageCreatorService $imageCreator
 * @property RuntimeSettingsService $runtimeSettings
 */
class ImageEnhancer extends Plugin
{
	public string $schemaVersion = '1.2.0';
	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;
	public static bool $skipAssetQueue = false;

	public static function config(): array
	{
		return [
			'components' => [
				'imageQualityService' => ImageQualityService::class,
				'aiImageEnhancement' => AiImageEnhancementService::class,
				'assetRequirements' => AssetRequirementService::class,
				'imageCreator' => ImageCreatorService::class,
				'runtimeSettings' => RuntimeSettingsService::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		// Register HUD message if flash exists
		if (Craft::$app->getRequest()->getIsCpRequest() && Craft::$app->getSession()->hasFlash('imageEnhancerModalWarning')) {
			$flash = Craft::$app->getSession()->getFlash('imageEnhancerModalWarning');
			Craft::$app->getView()->registerAssetBundle(ImageEnhancerAsset::class);
			Craft::$app->getView()->registerJs("window.imageEnhancerModalMessage = " . $flash . ";", \yii\web\View::POS_HEAD);
		}

		$this->registerCpFieldEnhancer();
		$this->attachEventHandlers();
		$this->registerUtilities();

		// Tabs (settings page)
		$this->_registerSettings();
		
		Craft::$app->onInit(function() {
			// Reserved for deferred code (element queries, etc.)
		});
	}

	protected function createSettingsModel(): ?Model
	{
		return Craft::createObject(Settings::class);
	}

	public function getCpNavItem(): ?array
	{
		$item = parent::getCpNavItem();
		if ($item !== null) {
			$item['label'] = 'Image Creator';
		}

		return $item;
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->view->renderTemplate('craft-image-enhancer/_settings.twig', [
			'plugin' => $this,
			'settings' => $this->getSettings(),
			'chatGptModelOptions' => $this->getChatGptModelOptions(),
			'imageEnhancementModeOptions' => Settings::imageEnhancementModeOptions(),
			'imageEnhancementTriggerOptions' => Settings::imageEnhancementTriggerOptions(),
			'imageEnhancementActionOptions' => Settings::imageEnhancementActionOptions(),
			'imageEnhancementProviderOptions' => Settings::imageEnhancementProviderOptions(),
			'imageEnhancementModelOptions' => Settings::imageEnhancementModelOptions(),
			'xAiImageEnhancementModelOptions' => Settings::xAiImageEnhancementModelOptions(),
			'googleImageEnhancementModelOptions' => Settings::googleImageEnhancementModelOptions(),
			'imageEnhancementFaceHandlingOptions' => Settings::imageEnhancementFaceHandlingOptions(),
			'assetFieldOptions' => $this->getAssetFieldOptions(),
		]);
	}

	public function getChatGptModelOptions(): array
	{
		$models = Settings::fallbackChatGptModels();
		$apiKey = $this->getSettings()->getResolvedChatGptApiKey();

		if ($apiKey) {
			try {
				$response = Craft::createGuzzleClient()->get('https://api.openai.com/v1/models', [
					'headers' => [
						'Authorization' => 'Bearer ' . $apiKey,
					],
				]);
				$data = json_decode((string) $response->getBody(), true);

				foreach ($data['data'] ?? [] as $model) {
					$id = $model['id'] ?? null;
					if ($id && Settings::isSupportedChatGptModel($id)) {
						$models[] = $id;
					}
				}
			} catch (\Throwable $e) {
				Craft::warning('ImageEnhancer: Could not fetch OpenAI models: ' . $e->getMessage(), __METHOD__);
			}
		}

		$models = array_values(array_unique($models));

		return array_map(static fn(string $model): array => [
			'label' => $model === Settings::MODEL_LATEST ? 'Latest available model' : $model,
			'value' => $model,
		], $models);
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
		$eventName = defined(Utilities::class . '::EVENT_REGISTER_UTILITIES')
			? Utilities::EVENT_REGISTER_UTILITIES
			: Utilities::EVENT_REGISTER_UTILITY_TYPES;

		Event::on(
			Utilities::class,
			$eventName,
			static function(RegisterComponentTypesEvent $event) {
				$event->types[] = QualityCheckUtility::class;
			}
		);
	}

	private function registerCpFieldEnhancer(): void
	{
		$request = Craft::$app->getRequest();
		if (!$request->getIsCpRequest()) {
			return;
		}

		if (method_exists($request, 'getIsActionRequest') && $request->getIsActionRequest()) {
			return;
		}

		$user = Craft::$app->getUser()->getIdentity();
		if (!$user) {
			return;
		}

		$settings = $this->getSettings();
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
				Settings::IMAGE_PROVIDER_OPENAI => Settings::imageEnhancementModelOptions(),
				Settings::IMAGE_PROVIDER_XAI => Settings::xAiImageEnhancementModelOptions(),
				Settings::IMAGE_PROVIDER_GOOGLE => Settings::googleImageEnhancementModelOptions(),
			],
			'imageCreator' => array_merge(
				$this->imageCreator->getClientConfig(),
				[
					'routes' => [
						'templateData' => 'craft-image-enhancer/image-creator/template-data',
						'upload' => 'craft-image-enhancer/image-creator/upload',
						'generate' => 'craft-image-enhancer/image-creator/generate',
						'save' => 'craft-image-enhancer/image-creator/save',
					],
				],
			),
			'routes' => [
				'uploadAssistant' => 'craft-image-enhancer/upload-assistant/upload',
				'uploadLocalRepair' => 'craft-image-enhancer/upload-assistant/local-repair',
				'uploadFinalize' => 'craft-image-enhancer/upload-assistant/finalize',
				'uploadDiscard' => 'craft-image-enhancer/upload-assistant/discard',
				'assetInfo' => 'craft-image-enhancer/article-image/asset-info',
				'enhance' => 'craft-image-enhancer/article-image/enhance',
				'status' => 'craft-image-enhancer/article-image/status',
				'cancel' => 'craft-image-enhancer/article-image/cancel',
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
		
			if (!$element instanceof Asset || $element->kind !== 'image' || !$event->isNew) {
				return;
			}

			if (self::$skipAssetQueue) {
				return;
			}
			
			/*$user = Craft::$app->getUser()->getIdentity();		
			Craft::info("ImageEnhancer event, user id: " . $user->id);
			if($user->id != 1) {
				return;
			}*/
			
			$this->queueAssetAnalysis($element, $this->getRequestEntryId());
		});
	}

	public function queueAssetAnalysis(Asset $asset, ?int $entryId = null): void
	{
		if (!$this->runtimeSettings->isQualityCheckEnabled()) {
			return;
		}

		Craft::$app->queue->push(new AnalyzeImageJob([
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
