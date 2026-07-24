<?php

namespace arjanbrinkman\craftimageenhancer\web\assets\imageenhancer;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Image Enhancer asset bundle
 */
class ImageEnhancerAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [
            CpAsset::class,
        ];
        $this->js = [
            'js/check.js',
            'creator/image-creator.js',
            'js/cp-field-enhancer.js',
        ];
        $this->css = [
            'creator/image-creator.css',
            'css/cp-field-enhancer.css',
        ];

        parent::init();
    }
}
