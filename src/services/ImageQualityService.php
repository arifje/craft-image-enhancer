<?php

namespace arjanbrinkman\craftimageenhancer\services;

use Craft;
use yii\base\Component;
use Imagick;
 
/**
 * Image Quality Service service
 */
 
 class ImageQualityService extends Component
 {
	 public static function analyze(string $path, array $settings): array
	 {
		 $result = [
			 'blurScore' => null,
			 'noiseScore' => null,
			 'brightness' => null,
			 'width' => null,
			 'height' => null,
			 'isLowQuality' => false,
			 'failingReasons' => [],
		 ];
 
		 if (!file_exists($path)) {
			 Craft::warning("Image not found: $path", __METHOD__);
			 return $result;
		 }
 
		 try {
			 $image = new Imagick($path);
			 $image->setImageColorspace(Imagick::COLORSPACE_GRAY);
 
			 $width = $image->getImageWidth();
			 $height = $image->getImageHeight();
 
			 $result['width'] = $width;
			 $result['height'] = $height;
 
			 // Resolution check
			 if ($width < $settings['minWidth'] || $height < $settings['minHeight']) {
				 $result['failingReasons'][] = 'lowResolution';
			 }
 
			 // Brightness check
			 $stats = $image->getImageChannelMean(Imagick::CHANNEL_GRAY);
			 $brightness = $stats['mean'] / 65535;
			 $result['brightness'] = $brightness;
 
			 if ($brightness < $settings['minBrightness']) {
				 $result['failingReasons'][] = 'tooDark';
			 } elseif ($brightness > $settings['maxBrightness']) {
				 $result['failingReasons'][] = 'tooBright';
			 }
 
			 // Blurriness check (approximate Laplacian variance)
			 $edge = clone $image;
			 $edge->edgeImage(1);
 
			 $histogram = $edge->getImageHistogram();
			 $values = array_map(fn($p) => $p->getColor()['r'], $histogram);
			 $mean = array_sum($values) / count($values);
			 $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / count($values);
			 $result['blurScore'] = $variance;
 
			 if ($variance < $settings['maxBlur']) {
				 $result['failingReasons'][] = 'blurry';
			 }
 
			 // Noise check
			 $blurred = clone $image;
			 $blurred->gaussianBlurImage(1, 1);
			 $diff = clone $image;
			 $diff->compositeImage($blurred, Imagick::COMPOSITE_DIFFERENCE, 0, 0);
 
			 $noiseHistogram = $diff->getImageHistogram();
			 $noiseLevel = array_sum(array_map(fn($p) => $p->getColor()['r'], $noiseHistogram)) / count($noiseHistogram);
			 $result['noiseScore'] = $noiseLevel;
 
			 if ($noiseLevel > $settings['maxNoise']) {
				 $result['failingReasons'][] = 'noisy';
			 }
 
			 // Set final flag
			 $result['isLowQuality'] = count($result['failingReasons']) > 0;
 
		 } catch (\Throwable $e) {
			 Craft::error("Image quality analysis failed: " . $e->getMessage(), __METHOD__);
		 }
 
		 return $result;
	 }
 }