<?php

declare(strict_types=1);

if (!defined('DIR_SYSTEM')) {
    define('DIR_SYSTEM', dirname(__DIR__) . '/extension/zerokyc/system/');
}

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

if (!defined('HTTP_CATALOG')) {
    define('HTTP_CATALOG', 'http://shop.example.com/');
}

if (!defined('HTTPS_CATALOG')) {
    define('HTTPS_CATALOG', 'https://shop.example.com/');
}

if (!defined('VERSION')) {
    define('VERSION', '4.0.2.3');
}

require_once DIR_SYSTEM . 'library/zerokyc/bootstrap.php';
require_once __DIR__ . '/Support/FakeOpenCart.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'ZeroKyc\\OpenCart\\Tests\\';
    if (strncmp($prefix, $class, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

if (!class_exists('Controller')) {
    abstract class Controller
    {
        /** @var array<string, mixed> */
        protected array $registryData = [];

        /**
         * @param array<string, mixed> $services
         */
        public function __construct(array $services = [])
        {
            $this->registryData = $services;
        }

        /**
         * @return mixed
         */
        public function __get(string $key)
        {
            return $this->registryData[$key] ?? null;
        }

        /**
         * @param mixed $value
         */
        public function __set(string $key, $value): void
        {
            $this->registryData[$key] = $value;
        }

        public function __isset(string $key): bool
        {
            return isset($this->registryData[$key]);
        }
    }
}

if (!class_exists('Model')) {
    abstract class Model extends Controller
    {
    }
}

if (!class_exists(\Opencart\System\Engine\Controller::class)) {
    class_alias('Controller', \Opencart\System\Engine\Controller::class);
}

if (!class_exists(\Opencart\System\Engine\Model::class)) {
    class_alias('Model', \Opencart\System\Engine\Model::class);
}

require_once dirname(__DIR__) . '/extension/zerokyc/catalog/model/payment/zerokyc.php';
require_once dirname(__DIR__) . '/extension/zerokyc/catalog/controller/payment/zerokyc.php';

if (!class_exists('ModelExtensionPaymentZerokyc')) {
    class_alias(\Opencart\Catalog\Model\Extension\Zerokyc\Payment\Zerokyc::class, 'ModelExtensionPaymentZerokyc');
}

if (!class_exists('ControllerExtensionPaymentZerokyc')) {
    class_alias(\Opencart\Catalog\Controller\Extension\Zerokyc\Payment\Zerokyc::class, 'ControllerExtensionPaymentZerokyc');
}
