<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return static function(RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_82,
    ]);

    // Import Craft CMS 5.x ruleset if available
    $craftRectorSet = __DIR__ . '/vendor/craftcms/rector/sets/craft-cms-50.php';
    if (file_exists($craftRectorSet)) {
        $rectorConfig->import($craftRectorSet);
    }
};
