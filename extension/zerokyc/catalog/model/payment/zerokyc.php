<?php
/**
 * ZeroKYC Pay for OpenCart 4.x — Catalog Payment Method Model.
 *
 * Implements both `getMethods(array $address = [])` (OpenCart 4.0.2.0+)
 * and `getMethod(array $address = [], $total = 0.0)` (OpenCart 4.0.0.0–4.0.1.1).
 */

declare(strict_types=1);

namespace Opencart\Catalog\Model\Extension\Zerokyc\Payment;

require_once dirname(__DIR__, 3) . '/system/library/zerokyc/bootstrap.php';

use ZeroKyc\OpenCart\Settings;

class Zerokyc extends \Opencart\System\Engine\Model
{
    /**
     * OpenCart 4.0.2.0+ payment method discovery entrypoint.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public function getMethods(array $address = []): array
    {
        $cartTotal = (isset($this->cart) && is_object($this->cart) && method_exists($this->cart, 'getTotal'))
            ? (float) $this->cart->getTotal()
            : 0.0;

        $legacy = $this->getMethod($address, $cartTotal);
        if ($legacy === []) {
            return [];
        }

        $title = (string) $legacy['title'];

        return [
            'code'       => Settings::CODE,
            'name'       => $title,
            'option'     => [
                Settings::CODE => [
                    'code' => Settings::CODE . '.' . Settings::CODE,
                    'name' => $title,
                ],
            ],
            'sort_order' => $legacy['sort_order'],
        ];
    }

    /**
     * OpenCart 4.0.0.0 – 4.0.1.1 (and unit test) payment method discovery entrypoint.
     *
     * @param array<string, mixed> $address
     * @param float                $total
     * @return array<string, mixed>
     */
    public function getMethod(array $address = [], $total = 0.0): array
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');

        $settings = new Settings($this->config);

        if (!$settings->isEnabled()) {
            return [];
        }

        if ($settings->apiKey() === '' || $settings->webhookSecret() === '') {
            return [];
        }

        $minTotal = $settings->totalMinimum();
        if ($minTotal > 0.0 && (float) $total < $minTotal) {
            return [];
        }

        // Check currency support (session currency or store default currency)
        $sessionCurrency = isset($this->session->data['currency']) ? (string) $this->session->data['currency'] : '';
        $storeCurrency   = (string) $this->config->get('config_currency');
        if (!Settings::isSupportedFiatCurrency($sessionCurrency) && !Settings::isSupportedFiatCurrency($storeCurrency)) {
            return [];
        }

        // Geo-zone check
        $geoZoneId = $settings->geoZoneId();
        if ($geoZoneId > 0) {
            $countryId = isset($address['country_id']) ? (int) $address['country_id'] : 0;
            $zoneId    = isset($address['zone_id']) ? (int) $address['zone_id'] : 0;

            $query = $this->db->query(sprintf(
                "SELECT * FROM `%szone_to_geo_zone` WHERE `geo_zone_id` = '%d' AND `country_id` = '%d' AND (`zone_id` = '%d' OR `zone_id` = '0')",
                DB_PREFIX,
                $geoZoneId,
                $countryId,
                $zoneId
            ));

            if (empty($query->num_rows)) {
                return [];
            }
        }

        $title = $settings->title();
        if ($title === '') {
            $title = $this->language->get('text_title');
        }

        return [
            'code'       => Settings::CODE,
            'title'      => $title,
            'name'       => $title,
            'terms'      => '',
            'sort_order' => $settings->sortOrder(),
        ];
    }
}
