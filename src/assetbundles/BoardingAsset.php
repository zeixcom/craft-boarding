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
            'js/utils.js',
            'js/core.js',
            'js/ui.js',
            'js/tour-manager.js',
            'js/tour-edit.js',
            'js/init.js',
        ];

        $this->css = [
            'css/boarding.css',
            // Use CDN for Shepherd.js CSS (matching JS version)
            'https://cdn.jsdelivr.net/npm/shepherd.js@11.2.0/dist/css/shepherd.css'
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
        // Register Shepherd.js from CDN (use older version with UMD build)
        $view->registerJsFile(
            'https://cdn.jsdelivr.net/npm/shepherd.js@11.2.0/dist/js/shepherd.min.js',
            [
                'position' => \yii\web\View::POS_HEAD
            ]
        );

        // Register the rest of the assets normally
        parent::registerAssetFiles($view);
    }

    /**
     * Get an instance configured for the tour editor
     * 
     * @return self
     */
    public static function forTourEditor(): self
    {
        $bundle = new self();
        return $bundle;
    }
}
