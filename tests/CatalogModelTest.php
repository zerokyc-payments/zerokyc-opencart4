<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests;

use ModelExtensionPaymentZerokyc;
use PHPUnit\Framework\TestCase;
use ZeroKyc\OpenCart\Tests\Support\FakeConfig;
use ZeroKyc\OpenCart\Tests\Support\FakeDb;
use ZeroKyc\OpenCart\Tests\Support\FakeLanguage;
use ZeroKyc\OpenCart\Tests\Support\FakeLoader;
use ZeroKyc\OpenCart\Tests\Support\FakeSession;

final class CatalogModelTest extends TestCase
{
    private function makeModel(array $configOverrides = [], string $sessionCurrency = 'USD', ?FakeDb $db = null): ModelExtensionPaymentZerokyc
    {
        $db      = $db ?? new FakeDb();
        $config  = new FakeConfig(array_merge([
            'config_currency'                => 'USD',
            'payment_zerokyc_status'         => '1',
            'payment_zerokyc_api_key'        => 'pk_test_12345',
            'payment_zerokyc_webhook_secret' => 'whsec_12345',
            'payment_zerokyc_title'          => 'Crypto (ZeroKYC Pay)',
            'payment_zerokyc_total'          => '0',
            'payment_zerokyc_geo_zone_id'    => '0',
            'payment_zerokyc_sort_order'     => '3',
        ], $configOverrides));
        $session = new FakeSession();
        $session->data['currency'] = $sessionCurrency;
        $lang    = new FakeLanguage();

        $model = new ModelExtensionPaymentZerokyc([
            'db'       => $db,
            'config'   => $config,
            'session'  => $session,
            'language' => $lang,
        ]);
        $model->load = new FakeLoader($model);

        return $model;
    }

    public function testReturnsMethodWhenEnabledAndConfigured(): void
    {
        $model  = $this->makeModel();
        $method = $model->getMethod(['country_id' => 222, 'zone_id' => 3553], 49.99);

        self::assertSame('zerokyc', $method['code']);
        self::assertSame('Crypto (ZeroKYC Pay)', $method['title']);
        self::assertSame(3, $method['sort_order']);
    }

    public function testGetMethodsReturnsOpenCart402OptionStructure(): void
    {
        $model   = $this->makeModel();
        $methods = $model->getMethods(['country_id' => 222, 'zone_id' => 3553]);

        self::assertSame('zerokyc', $methods['code']);
        self::assertSame('Crypto (ZeroKYC Pay)', $methods['name']);
        self::assertSame('zerokyc.zerokyc', $methods['option']['zerokyc']['code'] ?? null);
        self::assertSame(3, $methods['sort_order']);
    }

    public function testSimpleCheckoutCompatibilityWithEmptyAddress(): void
    {
        // Simple Checkout frequently passes an empty $address array when address fields are hidden
        $model  = $this->makeModel(['payment_zerokyc_geo_zone_id' => '0']);
        $method = $model->getMethod([], 15.00);

        self::assertNotEmpty($method);
        self::assertSame('zerokyc', $method['code']);
    }

    public function testHidesWhenDisabledOrMissingKeys(): void
    {
        $disabled = $this->makeModel(['payment_zerokyc_status' => '0']);
        self::assertSame([], $disabled->getMethod([], 50.00));

        $noKey = $this->makeModel(['payment_zerokyc_api_key' => '']);
        self::assertSame([], $noKey->getMethod([], 50.00));

        $noSecret = $this->makeModel(['payment_zerokyc_webhook_secret' => '']);
        self::assertSame([], $noSecret->getMethod([], 50.00));
    }

    public function testHidesWhenBelowMinimumTotal(): void
    {
        $model = $this->makeModel(['payment_zerokyc_total' => '25.00']);

        self::assertSame([], $model->getMethod([], 24.99));
        self::assertNotEmpty($model->getMethod([], 25.00));
    }

    public function testHidesWhenNeitherSessionNorStoreCurrencyIsSupported(): void
    {
        $model = $this->makeModel(['config_currency' => 'GBP'], 'JPY');
        self::assertSame([], $model->getMethod([], 50.00));

        // Works when store currency is EUR even if session currency is GBP
        $fallbackModel = $this->makeModel(['config_currency' => 'EUR'], 'GBP');
        self::assertNotEmpty($fallbackModel->getMethod([], 50.00));
    }

    public function testGeoZoneFiltering(): void
    {
        $db = new FakeDb();
        $db->geoZones[] = ['geo_zone_id' => 5, 'country_id' => 222, 'zone_id' => 0];

        $model = $this->makeModel(['payment_zerokyc_geo_zone_id' => '5'], 'USD', $db);

        self::assertNotEmpty($model->getMethod(['country_id' => 222, 'zone_id' => 10], 50.00));
        self::assertSame([], $model->getMethod(['country_id' => 100, 'zone_id' => 10], 50.00));
        self::assertSame([], $model->getMethod([], 50.00));
    }
}
