# Image Quality Checker

Checks newly uploaded image assets for quality issues such as blur, noise, motion blur, and poor sharpness. The plugin sends the image to OpenAI for analysis and can notify users when the returned quality score is below the configured threshold.

## Requirements

This plugin requires Craft CMS 5.7.0 or later, and PHP 8.2 or later.

You also need an OpenAI API key to analyze images.

## Configuration

Configure the plugin from the Craft control panel plugin settings.

### ChatGPT

- **ChatGPT API Key**: Your OpenAI API key.
- **ChatGPT Prompt**: The prompt used to evaluate each image.
- **ChatGPT Model**: Choose a fixed OpenAI model, or select **Latest available model**.
- **Language of the ChatGPT result**: The language used for the returned reason.

When **Latest available model** is selected, the plugin fetches the available OpenAI models for the configured API key and uses the newest supported GPT model it can find. When a fixed model is selected, the plugin sends that exact model string to OpenAI.

### Notifications

- **Threshold**: Notifications are sent when the returned score is below this value.
- **Slack**: Enable Slack notifications and configure a Slack bot token and channel.
- **Email**: Enable email notifications to send the result to the author, with an optional CC recipient.
- **Debug logging**: Writes `ImageQualityChecker DEBUG` lines to Craft's `web.log` while queue jobs run.

Only the OpenAI API key is required to run the analysis. Slack and email can be configured independently.
When enhancement is enabled, notifications include whether the original image was replaced and which enhancement mode was used.
Slack notifications can be sent through a webhook URL or through a bot token and channel. If a webhook URL is configured, it is used first.

### Enhancement

Enhancement runs only when an image score is below the notification threshold.

- **Disabled**: Analyze and notify only.
- **Imagick safe optimization**: Replaces the original file with a locally enhanced version. This uses Imagick to improve clarity, sharpen the image, optionally upscale smaller images to the configured max width, strip metadata, and rewrite JPEG/PNG output without changing scene context.
- **OpenAI / ChatGPT AI enhancement**: Replaces the original file with an OpenAI-generated edit that follows the configured AI enhancement prompt. This can produce stronger visual improvements, but may make more noticeable changes than Imagick safe optimization.

Imagick safe optimization requires the PHP Imagick extension. OpenAI / ChatGPT AI enhancement requires an OpenAI API key with access to image editing.

### Asset Volumes

Select the asset volumes that should be analyzed. Images uploaded to other volumes are skipped.

## Usage

1. Enter an OpenAI API key.
2. Choose a model or keep **Latest available model** selected.
3. Select the asset volumes that should be checked.
4. Choose whether low-scoring images should be enhanced and replaced.
5. Configure Slack and/or email notifications if needed.
6. Upload a JPEG or PNG image asset to a selected volume.

The plugin queues an analysis job shortly after upload. If the returned score is below the configured threshold, enabled enhancement and notifications are run.

To troubleshoot a queue run, enable debug logging and watch Craft's web log:

```bash
tail -f storage/logs/web.log | grep 'ImageQualityChecker DEBUG'
```

Debug output includes the PHP process user, original asset ownership, temporary replacement ownership, and final replaced file ownership so server permission issues can be traced.

## Current Limitations

- Only newly uploaded image assets are analyzed.
- The current file lookup supports local JPEG and PNG files.
- Remote filesystems may need additional handling before their assets can be analyzed.
- OpenAI / ChatGPT AI enhancement can alter image details more than Imagick safe optimization, depending on the configured prompt and model output.
