<?php

/**
 * PHPUnit bootstrap. Maps the plugin's namespaces and, inside a Botble project
 * (platform/plugins/sms), reuses the project's vendor directory and its Tests\TestCase
 * for the feature tests.
 */

$project = dirname(__DIR__, 4);

spl_autoload_register(function (string $class) use ($project): void {
    $map = [
        'Maina888\\Sms\\Tests\\' => __DIR__ . '/',
        'Maina888\\Sms\\' => dirname(__DIR__) . '/src/',
        'Tests\\' => $project . '/tests/',
    ];

    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

foreach ([$project . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
    }
}
