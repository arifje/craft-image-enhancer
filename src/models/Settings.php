<?php

namespace arjanbrinkman\craftimagequalitychecker\models;

use Craft;
use craft\base\Model;

/**
 * Image Quality Checker settings
 */
class Settings extends Model
{
	public const MODEL_LATEST = 'latest';
	public const ENHANCEMENT_DISABLED = 'disabled';
	public const ENHANCEMENT_SAFE = 'safe';
	public const ENHANCEMENT_CREATIVE = 'creative';
	public const ENHANCEMENT_TRIGGER_THRESHOLD = 'threshold';
	public const ENHANCEMENT_TRIGGER_ALWAYS = 'always';
	public const ENHANCEMENT_ACTION_REPLACE = 'replace';
	public const ENHANCEMENT_ACTION_ADD = 'add';
	public const IMAGE_MODEL_GPT_IMAGE_2 = 'gpt-image-2';
	public const IMAGE_MODEL_GPT_IMAGE_1 = 'gpt-image-1';
	public const IMAGE_PROVIDER_OPENAI = 'openai';
	public const IMAGE_PROVIDER_XAI = 'xai';
	public const IMAGE_PROVIDER_GOOGLE = 'google';
	public const IMAGE_PROVIDER_FRONTEND = 'frontend';
	public const XAI_IMAGE_MODEL_GROK_IMAGINE_QUALITY = 'grok-imagine-image-quality';
	public const GOOGLE_IMAGE_MODEL_GEMINI_3_1_FLASH_IMAGE = 'gemini-3.1-flash-image';
	public const GOOGLE_IMAGE_MODEL_GEMINI_3_PRO_IMAGE = 'gemini-3-pro-image';
	public const GOOGLE_IMAGE_MODEL_GEMINI_2_5_FLASH_IMAGE = 'gemini-2.5-flash-image';
	public const FACE_HANDLING_ALLOW_AI = 'allow_ai';
	public const FACE_HANDLING_SAFE_FALLBACK = 'safe_fallback';
	public const ENHANCEMENT_LEVEL_MIN = 1;
	public const ENHANCEMENT_LEVEL_MAX = 10;
	public const LEGACY_CREATIVE_ENHANCEMENT_PROMPT = 'This is a real-world image and may be a photograph, video still, screenshot, frame capture, or otherwise blurry/low-quality source. Apply only conservative, non-destructive technical cleanup. Preserve the exact identity and likeness of every visible person. If a human face is visible, identity preservation overrides sharpness: do not reconstruct, redraw, beautify, age, de-age, stylize, smooth, or change the face. Do not change facial geometry, face shape, eyes, eyebrows, nose, mouth, teeth, smile, expression, skin texture, hairline, hairstyle, facial hair, ears, makeup, or distinctive marks. Do not use outside knowledge, celebrity recognition, or assumptions to make a blurry face look like a known person. If facial details are blurry, uncertain, occluded, motion-blurred, compressed, or low resolution, keep those details soft and uncertain instead of inventing them. Preserve the same crop, zoom level, framing, canvas size, composition, perspective, people, objects, background, text, clothing, hands, and textures. Do not add, remove, replace, reposition, uncrop, extend, or invent anything. Only reduce noise and compression artifacts, apply very mild sharpening/deblurring to already visible edges, and make subtle color/exposure correction if needed. If an improvement would require guessing new details, do not do it. The result should be indistinguishable from the original except for mild technical quality improvements.';
	public const JSON_CREATIVE_ENHANCEMENT_PROMPT = <<<'PROMPT'
{
  "task_type": "conservative_real_world_image_cleanup",
  "source_context": {
    "possible_sources": [
      "photograph",
      "video_still",
      "screenshot",
      "frame_capture",
      "blurry_or_low_quality_source"
    ],
    "known_limitations": [
      "motion_blur",
      "low_resolution",
      "compression_artifacts",
      "noise",
      "uncertain_details"
    ]
  },
  "primary_rule": "Preserve the original image. Do not add, remove, replace, reconstruct, reposition, uncrop, extend, or invent any visual detail.",
  "allowed_adjustments": [
    "reduce_noise",
    "reduce_compression_artifacts",
    "very_mild_sharpening_of_already_visible_edges",
    "subtle_color_balance_correction",
    "subtle_exposure_or_contrast_correction"
  ],
  "forbidden_adjustments": [
    "creative_enhancement",
    "context_aware_infilling",
    "generating_missing_detail",
    "beautification",
    "stylization",
    "upscaling_by_inventing_texture",
    "changing_crop_zoom_framing_canvas_or_composition",
    "changing_people_objects_background_text_clothing_hands_or_textures"
  ],
  "human_faces": {
    "priority": "identity_preservation_over_sharpness",
    "instructions": [
      "Preserve the exact identity and likeness of every visible person.",
      "Do not reconstruct, redraw, beautify, age, de-age, stylize, smooth, or change any face.",
      "Do not use outside knowledge, celebrity recognition, or assumptions to make a blurry face look like a known person.",
      "If facial details are blurry, uncertain, occluded, motion-blurred, compressed, or low resolution, keep those details soft and uncertain instead of inventing them."
    ],
    "forbidden_changes": [
      "face_shape",
      "facial_geometry",
      "eyes",
      "eyebrows",
      "nose",
      "mouth",
      "teeth",
      "smile",
      "expression",
      "skin_texture",
      "hairline",
      "hairstyle",
      "facial_hair",
      "ears",
      "makeup",
      "distinctive_marks"
    ]
  },
  "uncertainty_policy": "If an improvement requires guessing, do not do it. Leave uncertain areas visibly uncertain.",
  "output_goal": "The output must keep the same dimensions, crop, framing, composition, perspective, and visual content. It should be indistinguishable from the original except for mild technical cleanup."
}
PROMPT;
	public const DEFAULT_CREATIVE_ENHANCEMENT_PROMPT = <<<'PROMPT'
Enhance this real-world image, which may be a photograph, video still, screenshot, frame capture, or otherwise blurry or low-quality source. Create an ultra-clear, realistic result with maximum useful clarity while fully preserving the original content. Remove blur, noise, grain, color fading, and compression artifacts where this can be done without guessing, fabricating, or changing the scene.

Restore sharp focus and realistic detail only from details that are already visible in the original image. Refine natural micro-details in skin texture, hair strands, fabric textures, objects, and background surfaces, but do not invent missing detail, reconstruct uncertain areas, or add new texture that was not visibly supported by the source.

Preserve the exact identity, proportions, and natural appearance of every visible person. Hard rule: do not alter facial structure, facial features, expression, hairstyle, hairline, facial hair, skin texture, body shape, pose, clothing, age, or identity in any way. The face must remain the same person as in the original image, only clearer where the original detail supports it. If a face is blurry, occluded, motion-blurred, compressed, or low resolution, keep uncertain facial features uncertain instead of creating a new face. Do not use outside knowledge, celebrity recognition, or assumptions to make a blurry face look like a known person.

Correct color balance to restore natural skin tones and accurate colors while maintaining the original atmosphere. Improve lighting, dynamic range, and contrast subtly to add depth and realism without changing the direction of the light, time of day, mood, or scene context.

Preserve the original crop, dimensions, framing, composition, camera angle, perspective, pose, facial expression, background elements, text, objects, clothing, hands, and overall mood. Do not add, remove, replace, reposition, uncrop, extend, stylize, beautify, smooth into a plastic look, or create artificial sharpening halos. The output should look like the same image, technically cleaned up and clearer, not a creative reinterpretation.
PROMPT;
	public const DEFAULT_FACE_BLUR_DETECTION_PROMPT = <<<'PROMPT'
Detect every visible human face/head area that should be anonymized. Include blurry, motion-blurred, low-resolution, side-view, profile, background, partially occluded, and cropped faces. Do not identify anyone. Return only valid JSON with this exact shape: {"faces":[{"x":0,"y":0,"width":100,"height":100,"confidence":"high"}]}. Coordinates must be normalized integers from 0 to 1000 relative to the full image. The rectangle must tightly cover only the visible face/head oval plus a small margin: forehead, eyes, nose, mouth, cheeks, chin, hairline, ears, and facial hair if present. Exclude neck, shoulders, torso, clothing, hands, microphones, signs, background, and sky. If the person is close to the camera, still return only the head/face area, not the upper body. If uncertain, prefer a smaller head-centered box over a large body box.
PROMPT;

	// ChatGPT
	public string $chatGptApiKey = '';
	public string $chatGptPrompt = 'You are an expert in image quality. Evaluate this image from 1 (very bad) to 100 (excellent), considering sharpness, blur, noise, and motion blur.';
	public string $chatGptResultLanguage = 'Dutch';
	public string $chatGptModel = self::MODEL_LATEST;
	
	// Slack notification
	public bool $slackNotification = true;
	public bool $slackErrorNotification = false;
	public string $slackWebhookUrl = '';
	public string $slackBotToken = ''; // Required for postMessage method
 	public string $slackChannel = '';
	public string $slackErrorChannel = '';
	
	// Email notification
	public bool $emailNotification = false;
	public string $emailNotificationRecipient = '';

	// Debugging
	public bool $debugLogging = false;
	 
	public int $notificationThreshold = 50;
	
	// Enabled volume handles
	public array $allowedAssetFieldHandles = [];

	// Image enhancement
	public string $imageEnhancementMode = self::ENHANCEMENT_DISABLED;
	public string $imageEnhancementTrigger = self::ENHANCEMENT_TRIGGER_THRESHOLD;
	public string $imageEnhancementAction = self::ENHANCEMENT_ACTION_REPLACE;
	public string $imageEnhancementProvider = self::IMAGE_PROVIDER_OPENAI;
	public string $imageEnhancementModel = self::IMAGE_MODEL_GPT_IMAGE_2;
	public string $xAiApiKey = '';
	public string $xAiImageEnhancementModel = self::XAI_IMAGE_MODEL_GROK_IMAGINE_QUALITY;
	public string $googleAiApiKey = '';
	public string $googleImageEnhancementModel = self::GOOGLE_IMAGE_MODEL_GEMINI_3_1_FLASH_IMAGE;
	public string $imageEnhancementFaceHandling = self::FACE_HANDLING_ALLOW_AI;
	public int $creativeEnhancementClarityLevel = 5;
	public int $creativeEnhancementContrastLevel = 5;
	public int $creativeEnhancementColorLevel = 5;
	public int $creativeEnhancementNoiseReductionLevel = 5;
	public bool $retryFailedEnhancementJobs = false;
	public int $failedEnhancementRetryDelay = 60;
	public int $safeEnhancementMaxWidth = 2400;
	public int $safeEnhancementJpegQuality = 90;
	public string $creativeEnhancementPrompt = self::DEFAULT_CREATIVE_ENHANCEMENT_PROMPT;
	public string $faceBlurDetectionPrompt = self::DEFAULT_FACE_BLUR_DETECTION_PROMPT;

	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel', 'slackErrorChannel', 'chatGptResultLanguage', 'slackBotToken', 'chatGptModel', 'imageEnhancementMode', 'imageEnhancementTrigger', 'imageEnhancementAction', 'imageEnhancementProvider', 'imageEnhancementModel', 'xAiApiKey', 'xAiImageEnhancementModel', 'googleAiApiKey', 'googleImageEnhancementModel', 'imageEnhancementFaceHandling', 'creativeEnhancementPrompt', 'faceBlurDetectionPrompt'], 'string'],
			[['slackNotification', 'slackErrorNotification', 'emailNotification', 'retryFailedEnhancementJobs', 'debugLogging'], 'boolean'],
			[['safeEnhancementMaxWidth', 'safeEnhancementJpegQuality'], 'integer'],
			[['failedEnhancementRetryDelay'], 'integer', 'min' => 0, 'max' => 86400],
			[['creativeEnhancementClarityLevel', 'creativeEnhancementContrastLevel', 'creativeEnhancementColorLevel', 'creativeEnhancementNoiseReductionLevel'], 'integer', 'min' => self::ENHANCEMENT_LEVEL_MIN, 'max' => self::ENHANCEMENT_LEVEL_MAX],
			[['allowedAssetFieldHandles'], 'safe'],
		];
	}

	public static function fallbackChatGptModels(): array
	{
		return [
			self::MODEL_LATEST,
			'gpt-5.5',
			'gpt-5.4',
			'gpt-5.2',
			'gpt-5.1',
			'gpt-5',
			'gpt-4.1',
			'gpt-4o',
			'gpt-4o-mini',
			'gpt-4-turbo',
		];
	}

	public static function isSupportedChatGptModel(string $model): bool
	{
		if (!str_starts_with($model, 'gpt-')) {
			return false;
		}

		foreach (['audio', 'realtime', 'search', 'transcribe', 'tts', 'codex', 'image'] as $unsupported) {
			if (str_contains($model, $unsupported)) {
				return false;
			}
		}

		return true;
	}

	public static function imageEnhancementModeOptions(): array
	{
		return [
			['label' => 'Disabled', 'value' => self::ENHANCEMENT_DISABLED],
			['label' => 'Imagick safe optimization', 'value' => self::ENHANCEMENT_SAFE],
			['label' => 'AI enhancement', 'value' => self::ENHANCEMENT_CREATIVE],
		];
	}

	public static function imageEnhancementProviderOptions(): array
	{
		return [
			['label' => 'OpenAI', 'value' => self::IMAGE_PROVIDER_OPENAI],
			['label' => 'Grok Imagine (xAI)', 'value' => self::IMAGE_PROVIDER_XAI],
			['label' => 'Google Nano Banana', 'value' => self::IMAGE_PROVIDER_GOOGLE],
			['label' => 'Choose in frontend', 'value' => self::IMAGE_PROVIDER_FRONTEND],
		];
	}

	public static function imageEnhancementTriggerOptions(): array
	{
		return [
			['label' => 'Only when score is below threshold', 'value' => self::ENHANCEMENT_TRIGGER_THRESHOLD],
			['label' => 'Always enhance and skip quality check', 'value' => self::ENHANCEMENT_TRIGGER_ALWAYS],
		];
	}

	public static function imageEnhancementActionOptions(): array
	{
		return [
			['label' => 'Replace original image', 'value' => self::ENHANCEMENT_ACTION_REPLACE],
			['label' => 'Add enhanced image next to original', 'value' => self::ENHANCEMENT_ACTION_ADD],
		];
	}

	public static function imageEnhancementModelOptions(): array
	{
		return [
			['label' => 'GPT Image 2', 'value' => self::IMAGE_MODEL_GPT_IMAGE_2],
			['label' => 'GPT Image 1', 'value' => self::IMAGE_MODEL_GPT_IMAGE_1],
		];
	}

	public static function xAiImageEnhancementModelOptions(): array
	{
		return [
			['label' => 'Grok Imagine Image Quality', 'value' => self::XAI_IMAGE_MODEL_GROK_IMAGINE_QUALITY],
		];
	}

	public static function googleImageEnhancementModelOptions(): array
	{
		return [
			['label' => 'Gemini 3.1 Flash Image (Nano Banana 2)', 'value' => self::GOOGLE_IMAGE_MODEL_GEMINI_3_1_FLASH_IMAGE],
			['label' => 'Gemini 3 Pro Image (Nano Banana Pro)', 'value' => self::GOOGLE_IMAGE_MODEL_GEMINI_3_PRO_IMAGE],
			['label' => 'Gemini 2.5 Flash Image (Nano Banana)', 'value' => self::GOOGLE_IMAGE_MODEL_GEMINI_2_5_FLASH_IMAGE],
		];
	}

	public static function imageEnhancementFaceHandlingOptions(): array
	{
		return [
			['label' => 'Allow AI enhancement for images with faces', 'value' => self::FACE_HANDLING_ALLOW_AI],
			['label' => 'Use Imagick safe optimization when faces are detected', 'value' => self::FACE_HANDLING_SAFE_FALLBACK],
		];
	}

	public function getEffectiveCreativeEnhancementPrompt(): string
	{
		$prompt = trim($this->creativeEnhancementPrompt);
		if (
			$prompt === '' ||
			$prompt === trim(self::LEGACY_CREATIVE_ENHANCEMENT_PROMPT) ||
			$prompt === trim(self::JSON_CREATIVE_ENHANCEMENT_PROMPT)
		) {
			return self::DEFAULT_CREATIVE_ENHANCEMENT_PROMPT;
		}

		return $this->creativeEnhancementPrompt;
	}

	public function getCreativeEnhancementPromptForRequest(): string
	{
		return trim($this->getEffectiveCreativeEnhancementPrompt()) . "\n\n" . $this->getCreativeEnhancementTuningPrompt();
	}

	public function getEffectiveFaceBlurDetectionPrompt(): string
	{
		$prompt = trim($this->faceBlurDetectionPrompt);

		return $prompt !== '' ? $this->faceBlurDetectionPrompt : self::DEFAULT_FACE_BLUR_DETECTION_PROMPT;
	}

	public function getFaceBlurDetectionPromptForRequest(): string
	{
		return trim($this->getEffectiveFaceBlurDetectionPrompt());
	}

	public function getCreativeEnhancementTuningPrompt(): string
	{
		$levels = $this->getCreativeEnhancementTuningLevels();

		return sprintf(
			"Enhancement tuning levels are mandatory controls, from %d to %d, where 5 is balanced/medium. These levels override generic enhancement wording. Make the visual difference between low, medium, and high settings noticeable while still preserving identity, composition, natural skin texture, and the original scene.\n- Clarity/detail: %d/10. %s\n- Contrast/depth: %d/10. %s\n- Color intensity: %d/10. %s\n- Noise/artifact cleanup: %d/10. %s",
			self::ENHANCEMENT_LEVEL_MIN,
			self::ENHANCEMENT_LEVEL_MAX,
			$levels['clarity'],
			$this->describeClarityLevel($levels['clarity']),
			$levels['contrast'],
			$this->describeContrastLevel($levels['contrast']),
			$levels['color'],
			$this->describeColorLevel($levels['color']),
			$levels['noiseReduction'],
			$this->describeNoiseReductionLevel($levels['noiseReduction'])
		);
	}

	public function getCreativeEnhancementTuningLevels(): array
	{
		return [
			'clarity' => $this->normalizeEnhancementLevel($this->creativeEnhancementClarityLevel),
			'contrast' => $this->normalizeEnhancementLevel($this->creativeEnhancementContrastLevel),
			'color' => $this->normalizeEnhancementLevel($this->creativeEnhancementColorLevel),
			'noiseReduction' => $this->normalizeEnhancementLevel($this->creativeEnhancementNoiseReductionLevel),
		];
	}

	private function normalizeEnhancementLevel(int $level): int
	{
		return max(self::ENHANCEMENT_LEVEL_MIN, min(self::ENHANCEMENT_LEVEL_MAX, $level));
	}

	private function describeClarityLevel(int $level): string
	{
		if ($level <= 2) {
			return 'Keep sharpening and deblurring minimal; preserve a softer original look and do not chase crispness.';
		}
		if ($level <= 4) {
			return 'Apply restrained sharpening and deblurring, with only a modest clarity lift.';
		}
		if ($level === 5) {
			return 'Apply balanced sharpening and deblurring for a natural clear result.';
		}
		if ($level <= 7) {
			return 'Make the image noticeably clearer with stronger edge definition and visible texture detail.';
		}

		return 'Strongly improve sharpness, deblurring, edge definition, and visible texture detail, without halos or invented facial detail.';
	}

	private function describeContrastLevel(int $level): string
	{
		if ($level <= 2) {
			return 'Keep contrast nearly unchanged; preserve a softer, flatter, less punchy look.';
		}
		if ($level <= 4) {
			return 'Use restrained contrast with gentle depth and no dramatic punch.';
		}
		if ($level === 5) {
			return 'Use balanced contrast and dynamic range for a natural result.';
		}
		if ($level <= 7) {
			return 'Add noticeably more depth, contrast, and dimensionality while preserving highlight and shadow detail.';
		}

		return 'Create a visibly punchier, richer, higher-contrast result while avoiding crushed shadows, blown highlights, or changed lighting direction.';
	}

	private function describeColorLevel(int $level): string
	{
		if ($level <= 2) {
			return 'Keep saturation and vibrance very restrained; preserve muted colors and avoid making the image colorful.';
		}
		if ($level <= 4) {
			return 'Use subtle color enrichment with a calm, natural palette.';
		}
		if ($level === 5) {
			return 'Use balanced saturation, vibrance, and color richness.';
		}
		if ($level <= 7) {
			return 'Make colors noticeably richer and more vivid while keeping skin tones believable.';
		}

		return 'Create a clearly vibrant, colorful result with strong saturation and vibrance, while keeping skin tones natural and avoiding artificial color casts.';
	}

	private function describeNoiseReductionLevel(int $level): string
	{
		if ($level <= 2) {
			return 'Use minimal cleanup; retain natural grain, real texture, and some original compression softness.';
		}
		if ($level <= 4) {
			return 'Use light cleanup of noise and artifacts while keeping visible texture intact.';
		}
		if ($level === 5) {
			return 'Use balanced noise, grain, blur residue, and compression artifact cleanup.';
		}
		if ($level <= 7) {
			return 'Clean noise, grain, blur residue, and compression artifacts noticeably while preserving real skin and material texture.';
		}

		return 'Clean noise, grain, blur residue, and compression artifacts aggressively, but do not smooth faces into a plastic look or remove real texture.';
	}
	
}
