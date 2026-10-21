<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Composer\Semver\Semver;

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
foreach ([
    !Semver::satisfies('4.18.9', $manifest['require']['craftcms/cms']),
    !Semver::satisfies('5.10.14', $manifest['require']['craftcms/cms']),
    Semver::satisfies('5.11.0', $manifest['require']['craftcms/cms']),
    Semver::satisfies('5.11.5.1', $manifest['require']['craftcms/cms']),
    !Semver::satisfies('6.0.0', $manifest['require']['craftcms/cms']),
    !Semver::satisfies('8.1.99', $manifest['require']['php']),
    Semver::satisfies('8.2.0', $manifest['require']['php']),
    Semver::satisfies('8.4.0', $manifest['require']['php']),
    !Semver::satisfies('9.0.0', $manifest['require']['php']),
    !str_contains(file_get_contents($root . '/src/ImageEnhancer.php'), 'EVENT_REGISTER_UTILITY_TYPES'),
    !str_contains(file_get_contents($root . '/src/web/assets/imageenhancer/dist/js/cp-field-enhancer.js'), 'elements/get-element-html'),
    str_contains(file_get_contents($root . '/src/web/assets/imageenhancer/dist/js/cp-field-enhancer.js'), 'app/render-elements'),
] as $valid) {
    if (!$valid) throw new RuntimeException('Craft 5 release contract failed at check ' . ($checks + 1));
    $checks++;
}
echo "Passed {$checks} Craft 5 release contract checks.\n";
