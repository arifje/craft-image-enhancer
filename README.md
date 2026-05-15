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

Only the OpenAI API key is required to run the analysis. Slack and email can be configured independently.

### Asset Volumes

Select the asset volumes that should be analyzed. Images uploaded to other volumes are skipped.

## Usage

1. Enter an OpenAI API key.
2. Choose a model or keep **Latest available model** selected.
3. Select the asset volumes that should be checked.
4. Configure Slack and/or email notifications if needed.
5. Upload a JPEG or PNG image asset to a selected volume.

The plugin queues an analysis job shortly after upload. If the returned score is below the configured threshold, enabled notifications are sent.

## Current Limitations

- Only newly uploaded image assets are analyzed.
- The current file lookup supports local JPEG and PNG files.
- Remote filesystems may need additional handling before their assets can be analyzed.
