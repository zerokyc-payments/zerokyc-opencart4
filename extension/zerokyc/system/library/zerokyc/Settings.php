<?php
/**
 * ZeroKYC Pay for OpenCart 3.0.x — Configuration & SDK Factory.
 */

declare(strict_types=1);

namespace ZeroKyc\OpenCart;

use ZeroKYC\Config;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\ZeroKYC;

final class Settings
{
    public const CODE   = 'zerokyc';
    public const PREFIX = 'payment_zerokyc_';

    /**
     * Supported crypto payment currencies (mirrors zerokyc-woocommerce).
     *
     * @var array<string, string>
     */
    public const PAYMENT_CURRENCIES = [
        'any'           => 'Let the buyer choose (USDT, USDC, ETH, BNB, SOL, DOGE, TRX, LTC, BCH, BTC, XMR, TON)',
        'USDT_TRON'     => 'USDT (TRC-20)',
        'USDT_BSC'      => 'USDT (BSC, BEP-20)',
        'USDT_ARBITRUM' => 'USDT (Arbitrum)',
        'ETH'           => 'Ethereum (native)',
        'USDT_ETHEREUM' => 'USDT (Ethereum)',
        'USDC_POLYGON'  => 'USDC (Polygon)',
        'USDC_ARBITRUM' => 'USDC (Arbitrum)',
        'USDT_POLYGON'  => 'USDT (Polygon)',
        'TON'           => 'TON',
        'USDT_TON'      => 'USDT (TON)',
        'BNB'           => 'BNB (native, BSC)',
        'TRX'           => 'TRON (native)',
        'LTC'           => 'Litecoin',
        'DOGE'          => 'Dogecoin',
        'BCH'           => 'Bitcoin Cash',
        'SOL'           => 'Solana (native)',
        'USDT_SOL'      => 'USDT (Solana)',
        'USDC_SOL'      => 'USDC (Solana)',
    ];

    /** @var object OpenCart \Config instance or compatible getter with get(string $key) */
    private object $config;

    public function __construct(object $config)
    {
        $this->config = $config;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('status', false);
    }

    public function title(): string
    {
        $title = trim((string) $this->get('title', ''));
        return $title !== '' ? $title : 'Crypto (ZeroKYC Pay)';
    }

    public function description(): string
    {
        $desc = trim((string) $this->get('description', ''));
        return $desc !== ''
            ? $desc
            : 'Pay with USDT, USDC, BTC, ETH, TON, SOL, XMR and more. Instant confirmation, no account required.';
    }

    public function apiKey(): string
    {
        return trim((string) $this->get('api_key', ''));
    }

    public function webhookSecret(): string
    {
        return trim((string) $this->get('webhook_secret', ''));
    }

    public function paymentCurrency(): string
    {
        $val = trim((string) $this->get('payment_currency', 'any'));
        return array_key_exists($val, self::PAYMENT_CURRENCIES) ? $val : 'any';
    }

    /**
     * Returns the pinned payment currency code, or null when "any" (buyer chooses on hosted checkout).
     */
    public function pinnedPaymentCurrency(): ?string
    {
        $currency = $this->paymentCurrency();
        return ($currency === '' || $currency === 'any') ? null : $currency;
    }

    public function ttlMinutes(): int
    {
        $raw = (int) $this->get('ttl_minutes', 30);
        if ($raw <= 0) {
            $raw = 30;
        }
        return max(10, min(1440, $raw));
    }

    public function autoCancel(): bool
    {
        return $this->boolSetting('auto_cancel', true);
    }

    public function doubleCheck(): bool
    {
        return $this->boolSetting('double_check', true);
    }

    public function debug(): bool
    {
        return $this->boolSetting('debug', false);
    }

    public function totalMinimum(): float
    {
        return (float) $this->get('total', 0.0);
    }

    public function geoZoneId(): int
    {
        return (int) $this->get('geo_zone_id', 0);
    }

    public function sortOrder(): int
    {
        return (int) $this->get('sort_order', 0);
    }

    public function pendingStatusId(): int
    {
        $id = (int) $this->get('pending_status_id', 1);
        return $id > 0 ? $id : 1;
    }

    public function confirmingStatusId(): int
    {
        $id = (int) $this->get('confirming_status_id', 1);
        return $id > 0 ? $id : $this->pendingStatusId();
    }

    public function paidStatusId(): int
    {
        $id = (int) $this->get('paid_status_id', 2);
        return $id > 0 ? $id : 2;
    }

    public function underpaidStatusId(): int
    {
        $id = (int) $this->get('underpaid_status_id', 1);
        return $id > 0 ? $id : $this->pendingStatusId();
    }

    public function expiredStatusId(): int
    {
        $id = (int) $this->get('expired_status_id', 7);
        return $id > 0 ? $id : 7;
    }

    public static function isSupportedFiatCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), CreateInvoiceRequest::CURRENCIES, true);
    }

    /**
     * Instantiates the ZeroKYC SDK client using the configured API key and webhook secret.
     */
    public function buildSdk(?HttpClientInterface $http = null): ZeroKYC
    {
        $cfg = Config::fromArray([
            'api_key'        => $this->apiKey(),
            'webhook_secret' => $this->webhookSecret(),
            'max_retries'    => 2,
        ]);

        return new ZeroKYC($cfg, $http);
    }

    /**
     * Formats a decimal fiat amount to 2 decimal places ("19.99") as required by CreateInvoiceRequest.
     */
    public static function formatFiatAmount(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function get(string $shortKey, $default = null)
    {
        $val = $this->config->get(self::PREFIX . $shortKey);
        if ($val === null || $val === '') {
            $val = $this->config->get('zerokyc_' . $shortKey);
        }
        if ($val === null || $val === '') {
            return $default;
        }
        return $val;
    }

    private function boolSetting(string $shortKey, bool $default): bool
    {
        $val = $this->config->get(self::PREFIX . $shortKey);
        if ($val === null || $val === '') {
            $val = $this->config->get('zerokyc_' . $shortKey);
        }
        if ($val === null || $val === '') {
            return $default;
        }
        if (is_bool($val)) {
            return $val;
        }
        return in_array((string) $val, ['1', 'true', 'yes', 'on'], true);
    }
}
