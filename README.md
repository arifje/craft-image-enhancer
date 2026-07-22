# Image Enhancer

Checks newly uploaded image assets for quality issues such as blur, noise, motion blur, and poor sharpness. The plugin sends the image to OpenAI for analysis and can notify users when the returned quality score is below the configured threshold. Enhanced replacement images can be generated with OpenAI, Grok Imagine, or Google Nano Banana.

## Requirements

This plugin requires Craft CMS 5.7.0 or later, and PHP 8.2 or later.

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
4. Choose an OpenAI image model, for example `gpt-image-2`.

The same OpenAI key is used for quality analysis, face detection fallback, and OpenAI image enhancement.

API key fields support Craft environment-variable references. Add keys to `.env`, for example `OPENAI_API_KEY=sk-...`, and select `$OPENAI_API_KEY` from the field suggestions. The saved project config contains only the environment-variable reference; Craft resolves the key when making API requests. The xAI and Google fields work the same way.

#### Grok Imagine / xAI

1. Create an xAI API key in the xAI console.
2. In **Enhancement**, set **AI image provider** to **Grok Imagine (xAI)**.
3. Enter the key in **xAI API key**.
4. Keep the model set to `grok-imagine-image-quality` unless xAI adds another compatible image-editing model.

Grok Imagine enhancement uses xAI's image editing API with the source image sent as a base64 data URI.

#### Google Nano Banana

1. Create a Gemini API key in Google AI Studio.
2. In **Enhancement**, set **AI image provider** to **Google Nano Banana**.
3. Enter the key in **Google AI API key**.
4. Choose a Nano Banana model:
   - `gemini-3.1-flash-image` for Nano Banana 2.
   - `gemini-3-pro-image` for Nano Banana Pro.
   - `gemini-2.5-flash-image` for the original Nano Banana model.

Google enhancement uses the Gemini `generateContent` image API with the source image sent inline as base64 data.

#### Frontend provider choice

1. Add API keys for every provider editors should be allowed to use.
2. In **Enhancement**, set **AI image provider** to **Choose in frontend**.
3. Configure the frontend enhancement component to send `imageEnhancementProvider` and `imageEnhancementModel` when queueing enhancement.

When this mode is enabled, the settings page shows all provider API key and model fields. Frontend requests are validated against the known provider/model options before a queue job is created.

### Frontend Image Enhancer Component

The repository includes `imageEnhancer.vue` as a copyable Vue component for article preview pages or headless frontend projects. It displays the image, lets permitted editors queue an enhancement or face-blur preview, polls the queue status, shows a before/after comparison slider, and lets the editor keep, discard, cancel, retry, reset, or hide the enhancement UI.

The **Blur faces** action uses the ChatGPT/OpenAI API key to detect face/head bounding boxes and then applies a fragmented oval anonymization mask locally with Imagick. The **Manual blur** action lets editors draw one or more oval regions on the image; those normalized coordinates are sent directly to the same Imagick blur job and skip AI detection entirely. Both paths create a preview asset first, so editors can compare and decide whether to keep or discard the blurred result.

By default the component uses the existing Craft action endpoints, so it works with the current plugin controllers:

```twig
<image-enhancer
	:asset-id="{{ articleThumbnail ? articleThumbnail.id : 'null' }}"
	:show-enhancement-options="{{ isRedactie ? 'true' : 'false' }}"
	:provider-choice-enabled="{{ craft.app.plugins.plugin('craft-image-enhancer').settings.imageEnhancementProvider == 'frontend' ? 'true' : 'false' }}"
	src="{{ articleThumbnail and articleThumbnail.url ? articleThumbnail.url ~ '?v=' ~ cachebuster : '' }}"
	alt="{{ entry.title ?? '' }}"
	category="{{ articleCategory ?? '' }}"
	credits="{{ imageCredits|striptags }}"
	csrf-token-name="{{ craft.app.config.general.csrfTokenName }}"
	csrf-token-value="{{ craft.app.request.csrfToken }}"
></image-enhancer>
```

Current action endpoint mode assumes a logged-in Craft user, same-origin requests, CSRF, and asset save permissions. This is the right mode for Craft preview pages.

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

The plugin also adds a small **Enhance** action below image assets inside Craft asset fields. Clicking it opens a control-panel modal that queues the same enhancement job, polls the queue status, shows a before/after slider, and lets the editor save the enhanced preview as the replacement file for the existing asset. Saving does not change the relation field value; it replaces the file behind the selected asset.

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

The plugin queues an analysis job immediately after upload. If the returned score is below the configured threshold, enabled enhancement and notifications are run.
The queue job reports milestone progress while it loads the asset, runs the quality check, enhances/replaces the image, and sends notifications. If runtime prompt overrides are set in **Utilities → Image Enhancer**, queued enhancement and face-blur jobs use those prompts instead of the project-config defaults.

To troubleshoot a queue run, enable debug logging and watch Craft's web log:

```bash
tail -f storage/logs/web.log | grep 'ImageEnhancer DEBUG'
```

Debug output includes the PHP process user, original asset ownership, temporary replacement ownership, and final replaced file ownership so server permission issues can be traced.

## Current Limitations

- Only newly uploaded image assets are analyzed.
- The current file lookup supports local JPEG and PNG files.
- Remote filesystems may need additional handling before their assets can be analyzed.
- The Vue component is GraphQL-ready, but the plugin currently ships Craft action endpoints only; GraphQL schema/resolvers still need to be added before `api-transport="graphql"` can be used.
- AI enhancement can alter image details more than Imagick safe optimization, depending on the selected provider, configured prompt, and model output.
- The upload requirement assistant repairs numeric width, height, and file-size selection conditions while preserving the source aspect ratio. Other failed selection-condition rules remain non-repairable.
