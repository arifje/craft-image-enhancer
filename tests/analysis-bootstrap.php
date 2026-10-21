<?php

declare(strict_types=1);

// Native probes receive these paths from their external guarded Craft bootstrap.
if (!defined('CRAFT_BASE_PATH')) {
    define('CRAFT_BASE_PATH', getcwd());
}
if (!defined('CRAFT_VENDOR_PATH')) {
    define('CRAFT_VENDOR_PATH', getcwd() . '/vendor');
}
