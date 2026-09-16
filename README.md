# Image Enhancer

Checks newly uploaded image assets for quality issues such as blur, noise, motion blur, and poor sharpness. The plugin sends the image to OpenAI for analysis and can notify users when the returned quality score is below the configured threshold. Enhanced replacement images and one-off custom edits can be generated with OpenAI, Grok Imagine, or Google Nano Banana. Control-panel editors can also turn an image into a downloadable video without changing the asset.

## Requirements

This plugin requires Craft CMS 4 or 5, and PHP 8.2 or later.

You need an OpenAI API key to analyze images. AI enhancement also requires an API key for the selected enhancement provider.

## Configuration

Configure the plugin from the Craft control panel plugin settings.

### General

- **Run quality check on upload**: Admins can turn upload analysis on or off from **Utilities → Image Enhancer**. This runtime toggle is stored in the database instead of project config, so it can be changed directly on production without a code deploy.
- **Runtime prompt overrides**: Admins can override the AI enhancement prompt and face blur detection prompt from **Utilities → Image Enhancer**. Leave an override empty to use the plugin settings/default prompt. These overrides are stored in the database and take effect immediately on production.

### ChatGPT

- **ChatGPT API Key**: Your OpenAI API key, entered directly or selected from an environment variable.
- **ChatGPT Prompt**: The prompt used to evaluate each image.
- **ChatGPT Model**: Choose a fixed OpenAI model, or select **Latest available model**.
- **Language of the ChatGPT result**: The language used for the returned reason.

When **Latest available model** is selected, the plugin fetches the available OpenAI models for the configured API key and uses the newest supported GPT model it can find. When a fixed model is selected, the plugin sends that exact model string to OpenAI.

### Notifications

- **Threshold**: Notifications are sent when the returned score is below this value.
- **Slack**: Enable Slack notifications and configure a Slack bot token and channel.
- **Email**: Enable email notifications to send the result to the author, with an optional CC recipient.
- **Debug logging**: Writes `ImageEnhancer DEBUG` lines to Craft's `web.log` while queue jobs run.
- **Test notifications**: Send a Slack or email test notification directly from the settings page.

Only the OpenAI API key is required to run the analysis. Slack and email can be configured independently.
When enhancement is enabled, Slack notifications include a compact article, author, action, and image link summary.
Slack notifications can be sent through a webhook URL or through a bot token and channel. If a webhook URL is configured, it is used first.

### Enhancement

Enhancement runs only when an image score is below the notification threshold.

- **Disabled**: Analyze and notify only.
- **Imagick safe optimization**: Creates a locally enhanced version. This uses Imagick to improve clarity, sharpen the image, optionally upscale smaller images to the configured max width, strip metadata, and rewrite JPEG/PNG output without changing scene context.
- **AI enhancement**: Creates a provider-generated edit using OpenAI, Grok Imagine, or Google Nano Banana. The AI face handling setting controls whether AI enhancement is allowed for images with visible faces, or whether those images fall back to Imagick safe optimization.
- **AI image provider**: Choose the provider used for AI enhancement. OpenAI uses the ChatGPT API key from the ChatGPT tab. Grok Imagine and Google Nano Banana use their own API key fields. **Choose in frontend** lets editors choose the provider and model in the frontend enhancement component.
- **AI tuning levels**: Use simple 1-10 settings for clarity/detail, contrast/depth, color intensity, and noise/artifact cleanup. The selected levels are added to the image prompt so editors can choose a more colorful/contrasty result or a softer, more restrained result.
- **AI enhancement prompt**: Controls the default prompt used by all AI enhancement providers. This is stored in project config and can be overridden at runtime from the Utility screen.
- **Custom edits**: Editors can enter a one-off instruction such as `Flip the image horizontally` or `Remove all persons`. A custom edit replaces the standard conservative enhancement prompt for that request, then follows the same queued preview and approval workflow. The prompt is limited to 4,000 characters.
- **Create Video**: Editors can animate the current image with optional motion instructions. They choose Google Gemini Omni Flash or an available Grok Imagine video model before queueing a 720p MP4, and the browser remembers that video provider/model choice. The result is offered as a protected download and never creates, relates, or replaces a Craft asset.
- **Face blur detection prompt**: Controls the default prompt used by the frontend **Blur faces** action to detect face/head boxes. The API only returns boxes; Imagick applies the anonymization locally. This is stored in project config and can be overridden at runtime from the Utility screen.
- **Enhancement trigger**: Choose whether enhancement runs only when the quality score is below the threshold, or always runs immediately and skips the quality check.
- **Enhanced image handling**: Choose whether the enhanced file replaces the original asset, or is added next to the original asset for manual review.

Imagick safe optimization requires the PHP Imagick extension. AI enhancement requires an API key for the selected provider. Face detection for the optional safe fallback uses the configured ChatGPT/OpenAI model before deciding whether generative image editing is safe to run. If safe fallback is enabled and no OpenAI key is available for face detection, the plugin uses Imagick safe optimization. The default AI prompt is tuned for clearer results while forbidding identity changes, facial reconstruction, and invented detail; saved settings that are empty or still use a previous default are upgraded to this prompt automatically.
AI-enhanced replacements are cropped back to the original asset dimensions so the original field ratio is retained without white padding.

### Enhancement Provider Setup

#### OpenAI

1. Create an API key in the OpenAI platform dashboard.
2. Enter it in **Settings → ChatGPT → ChatGPT API Key**.
3. In **Enhancement**, set **AI image provider** to **OpenAI**.
4. Choose an OpenAI image model, for example `gpt-image-2.5-sunburst` or `gpt-image-2.5-flare`.

The same OpenAI key is used for quality analysis, face detection fallback, and OpenAI image enhancement.

OpenAI model options are loaded from the [Models API](https://developers.openai.com/api/reference/resources/models/methods/list) using the saved API key and cached in Craft for **15 minutes**. The settings page and control-panel editor share this list, and frontend model validation uses the same options. New `gpt-image-*` models appear automatically when returned for your account, including dated snapshots; the `chatgpt-image-latest` alias is also supported. The selected model ID is sent unchanged to the image editing API. The ChatGPT settings selector reuses the cached discovery response.

With no key, an unavailable API, or no compatible models returned, the selector uses built-in fallback options, including GPT Image 2.5 Sunburst and Flare. Your saved compatible model stays selectable even if it is absent from discovery. Failed lookups are also cached for 15 minutes to avoid repeated delays. Fallback options do not guarantee model access for your account. After saving a new API key, reload settings; changing the resolved key uses a separate cache entry. An unchanged key picks up new models on the next page load after cache expiry (or after clearing Craft's data cache).

API key fields support Craft environment-variable references. Add keys to `.env`, for example `OPENAI_API_KEY=sk-...`, and select `$OPENAI_API_KEY` from the field suggestions. The saved project config contains only the environment-variable reference; Craft resolves the key when making API requests. The xAI and Google fields work the same way.

#### Grok Imagine / xAI

1. Create an xAI API key in the xAI console.
2. In **Enhancement**, set **AI image provider** to **Grok Imagine (xAI)**.
3. Enter the key under **Enhancement → Video generation and shared credentials → xAI API key**.
4. Keep the model set to `grok-imagine-image-quality` unless xAI adds another compatible image-editing model.

Grok Imagine enhancement uses xAI's image editing API with the source image sent as a base64 data URI.
The same xAI key makes Grok Imagine available in the control-panel **Create Video** selector, regardless of the selected still-image provider.

#### Google Nano Banana

1. Create a Gemini API key in Google AI Studio.
2. Enter the key under **Enhancement → Video generation and shared credentials → Google AI API key**. This field remains visible because it is also used by **Create Video**.
3. For Google image enhancement, set **AI image provider** to **Google Nano Banana**.
4. Choose a Nano Banana model:
   - `gemini-3.1-flash-image` for Nano Banana 2.
   - `gemini-3-pro-image` for Nano Banana Pro.
   - `gemini-2.5-flash-image` for the original Nano Banana model.

Google enhancement uses the Gemini `generateContent` image API with the source image sent inline as base64 data.

The control-panel **Create Video** action lets the editor choose a separately configured video provider and model. Google sends the source image and optional instructions to `gemini-omni-1.1-flash`; xAI supports `grok-imagine-video-1.5` and `grok-imagine-video`. Only providers with configured API keys are offered when at least one key is available. The chosen pair is remembered in the browser for the next request. Generated MP4 files remain in protected temporary storage while available for download; choosing **Done** removes them immediately. See Google's [Gemini Omni Flash video documentation](https://ai.google.dev/gemini-api/docs/omni) and xAI's [image-to-video documentation](https://docs.x.ai/developers/model-capabilities/video/image-to-video) for access, safety restrictions, and billing details.

#### Frontend provider choice

1. Add API keys for every provider editors should be allowed to use.
2. In **Enhancement**, set **AI image provider** to **Choose in frontend**.
3. Configure the frontend enhancement component to send `imageEnhancementProvider` and `imageEnhancementModel` when queueing enhancement.

When this mode is enabled, the settings page shows all provider API key and model fields. Frontend requests are validated against the known provider/model options before a queue job is created.

### Frontend Image Enhancer Component

The repository includes `imageEnhancer.vue` as a copyable Vue component for article preview pages or headless frontend projects. It displays the image, lets permitted editors queue an enhancement, custom edit, or face-blur preview, polls the queue status, shows a before/after comparison slider, and lets the editor keep, discard, cancel, retry, reset, or hide the enhancement UI.

The **Custom edit** action accepts a one-off generative instruction and uses the selected provider and model. Prompts remain in the currently open component or modal so a failed or canceled request can be adjusted and retried, but they are not stored in browser persistence or returned by the status endpoint. The queue job retains the prompt while it is needed to execute or retry the request.

The **Blur faces** action uses the ChatGPT/OpenAI API key to detect face/head bounding boxes and then applies a fragmented oval anonymization mask locally with Imagick. The **Manual blur** action lets editors draw one or more oval regions on the image; those normalized coordinates are sent directly to the same Imagick blur job and skip AI detection entirely. Both paths create a preview asset first, so editors can compare and decide whether to keep or discard the blurred result.

By default the component uses the existing Craft action endpoints, so it works with the current plugin controllers:

```twig
<image-enhancer
	:asset-id="{{ articleThumbnail ? articleThumbnail.id : 'null' }}"
	:show-enhancement-options="{{ isRedactie ? 'true' : 'false' }}"
	:provider-choice-enabled="{{ craft.app.plugins.plugin('craft-image-enhancer').settings.imageEnhancementProvider == 'frontend' ? 'true' : 'false' }}"
	:open-ai-image-enhancement-models="{{ craft.app.plugins.plugin('craft-image-enhancer').getImageEnhancementModelOptions()|json_encode|e('html_attr') }}"
	src="{{ articleThumbnail and articleThumbnail.url ? articleThumbnail.url ~ '?v=' ~ cachebuster : '' }}"
	alt="{{ entry.title ?? '' }}"
	category="{{ articleCategory ?? '' }}"
	credits="{{ imageCredits|striptags }}"
	csrf-token-name="{{ craft.app.config.general.csrfTokenName }}"
	csrf-token-value="{{ craft.app.request.csrfToken }}"
></image-enhancer>
```

Current action endpoint mode assumes a logged-in Craft user, same-origin requests, CSRF, and asset save permissions. This is the right mode for Craft preview pages.

Pass `open-ai-image-enhancement-models` as shown above to keep the copyable Vue component's OpenAI selector synchronized with the cached server list. Without this prop, the component uses its bundled model defaults.

The component is also prepared for a future GraphQL transport. Keep `api-transport` unset for now. Once GraphQL mutations/queries are added to the backend, the same component can switch transports:

```vue
<image-enhancer
	:asset-id="assetId"
	:show-enhancement-options="canEnhance"
	api-transport="graphql"
	graphql-endpoint="/api"
	graphql-token="..."
	:graphql-operations="imageEnhancerGraphqlOperations"
	:src="imageUrl"
/>
```

GraphQL operations can be provided for `enhance`, `blurFaces`, `status`, `cancel`, `reset`, `keep`, and `discard`. Each operation may be a query/mutation string or an object with `query`, `operationName`, `variables`, and `dataPath`.
When manual blur is used, the `blurFaces` payload includes `manualFaces`, an array of normalized face/head boxes with `x`, `y`, `width`, and `height` values from 0 to 1000.

### Control Panel Asset Fields

The plugin also adds a compact **Enhance** button below image assets inside Craft asset fields. Clicking it opens a control-panel modal that can queue a standard enhancement, a custom edit, automatic face blurring, a custom blur drawn over one or more selected areas, or a downloadable video. Custom blur supports undo and uses the same queued preview workflow as automatic blur. The modal polls the queue status, shows a before/after slider for image operations, and lets the editor save an image preview as the replacement file for the existing asset. Saving does not change the relation field value; it replaces the file behind the selected asset. Video generation has separate provider, model, and optional prompt controls and never replaces the image. The modal expands on larger browser windows, while keeping queue feedback and its action footer visible on short and mobile viewports. Field-requirement details are only shown when the modal was opened by the invalid-upload assistant.

If **AI image provider** is set to **Choose in frontend**, the modal also shows provider and model selectors and remembers the last selected combination in the browser.

#### Upload Requirement Assistant

Enable **Assist image uploads that do not meet field requirements** on the **Volumes** settings tab to replace Craft's generic “asset is not selectable” message for repairable image uploads. The assistant applies to the asset fields selected under **Enable Image Enhancer tools in these asset fields**.

When an uploaded image fails a width, height, or file-size selection condition, the plugin keeps it in Craft's temporary upload folder and shows a modal with:

- The filename, current dimensions, file size, and failed field rules.
- A proportional local resize using Craft's configured GD or Imagick image driver.
- Queued AI enhancement using the configured provider, including the before/after comparison.
- A discard action that permanently removes the temporary upload.

After local or AI processing, the plugin evaluates the complete field selection condition again. Only a passing image is moved into the field's configured upload folder and selected in the field. Unsupported failures continue through Craft's normal rejection path, and closing or discarding the assistant cleans up the temporary asset.

### Asset Volumes

Select the asset volumes that should be analyzed. Images uploaded to other volumes are skipped.

## Usage

1. Enter an OpenAI API key for analysis.
2. Make sure **Run quality check on upload** is turned on under **Utilities → Image Enhancer**.
3. Choose a model or keep **Latest available model** selected.
4. Select the asset volumes that should be checked.
5. Choose whether low-scoring images should be enhanced and replaced, or whether every uploaded image should always be enhanced.
6. If using AI enhancement, choose the AI image provider and enter the required provider API key.
7. Configure Slack and/or email notifications if needed.
8. Upload a JPEG or PNG image asset to a selected volume.

When **Enhancement mode** and **Run quality check on upload** are both enabled, the plugin queues an analysis job immediately after upload. If the returned score is below the configured threshold, enabled enhancement and notifications are run. With enhancement mode disabled, new uploads do not create an automatic analysis job.
The queue job reports milestone progress while it loads the asset, runs the quality check, enhances/replaces the image, and sends notifications. If runtime prompt overrides are set in **Utilities → Image Enhancer**, queued enhancement and face-blur jobs use those prompts instead of the project-config defaults.

To troubleshoot a queue run, enable debug logging and watch Craft's web log:

```bash
tail -f storage/logs/web.log | grep 'ImageEnhancer DEBUG'
```

Debug output includes the PHP process user, original asset ownership, temporary replacement ownership, and final replaced file ownership so server permission issues can be traced.

## Development checks

Run `php tests/openai-models.php` for dependency-free regression checks covering model discovery, cache expiry, credential changes, fallbacks, saved selections, and frontend request validation. These checks use framework and HTTP doubles; they do not replace testing in a running Craft installation with an OpenAI API key.

## Current Limitations

- Only newly uploaded image assets are analyzed.
- The current file lookup supports local JPEG and PNG files.
- Remote filesystems may need additional handling before their assets can be analyzed.
- The Vue component is GraphQL-ready, but the plugin currently ships Craft action endpoints only; GraphQL schema/resolvers still need to be added before `api-transport="graphql"` can be used.
- AI enhancement can alter image details more than Imagick safe optimization, depending on the selected provider, configured prompt, and model output.
- Custom edits are generative and can intentionally alter image content or composition. Always review the before/after preview before keeping the result.
- The upload requirement assistant repairs numeric width, height, and file-size selection conditions while preserving the source aspect ratio. Other failed selection-condition rules remain non-repairable.
