<?php

declare(strict_types=1);

if (!defined('ZEROKYC_OC_VERSION')) {
    define('ZEROKYC_OC_VERSION', '0.1.0');
}

if (!defined('ZEROKYC_SDK_BOOTSTRAPPED')) {
    define('ZEROKYC_SDK_BOOTSTRAPPED', true);

    spl_autoload_register(static function (string $class): void {
        $sdkPrefix = 'ZeroKYC\\';
        if (strncmp($sdkPrefix, $class, strlen($sdkPrefix)) === 0) {
            $relative = substr($class, strlen($sdkPrefix));
            $file     = __DIR__ . '/vendor/zerokyc/zkp-sdk-php/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }

        $ocPrefix = 'ZeroKyc\\OpenCart\\';
        if (strncmp($ocPrefix, $class, strlen($ocPrefix)) === 0) {
            $relative = substr($class, strlen($ocPrefix));
            $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}
