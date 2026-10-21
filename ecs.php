<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __FILE__,
    ]);

    // Upstream names this shared coding-style set CRAFT_CMS_4; it is not a runtime support constraint.
    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
