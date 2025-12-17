<?php

namespace zeix\boarding\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class BoardingAsset extends AssetBundle
{
    /**
     * Initialize the asset bundle
     */
    public function init(): void
    {
        $this->sourcePath = '@boarding/web';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/boarding.js',
            'js/tour-editor.js',
        ];

        $this->css = [
            // Load Shepherd first so our plugin CSS can override it
            'https://cdn.jsdelivr.net/npm/shepherd.js@11.2.0/dist/css/shepherd.css',
            'css/boarding.css',
        ];

        $this->publishOptions = [
            'forceCopy' => true,
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view)
    {
        $view->registerJsFile(
            'https://cdn.jsdelivr.net/npm/shepherd.js@11.2.0/dist/js/shepherd.min.js',
            [
                'position' => \yii\web\View::POS_HEAD
            ]
        );


        parent::registerAssetFiles($view);
    }
}
