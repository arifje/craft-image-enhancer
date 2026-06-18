<?php

namespace arjanbrinkman\craftimagequalitychecker\web\assets\imagequalitychecker;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Image Quality Checker asset bundle
 */
class ImageQualityCheckerAsset extends AssetBundle
{
   public function init(): void
   {
	   $this->sourcePath = __DIR__ . '/dist';
	   $this->depends = [
		   CpAsset::class,
	   ];
	   $this->js = [
		   'js/check.js',
	   ];
	   $this->css = [
		   //'css/style.css'
	   ];
	   
	   parent::init();
   }
}
