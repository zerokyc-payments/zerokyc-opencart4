<?php
/**
 * ZeroKYC Pay for OpenCart 4.x — Admin Controller.
 *
 * Route: extension/zerokyc/payment/zerokyc
 */

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Zerokyc\Payment;

require_once dirname(__DIR__, 3) . '/system/library/zerokyc/bootstrap.php';

use Throwable;
use ZeroKYC\Config as SdkConfig;
use ZeroKYC\Exception\ZeroKYCException;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\ZeroKYC;
use ZeroKyc\OpenCart\EventStore;
use ZeroKyc\OpenCart\InvoiceMap;
use ZeroKyc\OpenCart\Settings;

class Zerokyc extends \Opencart\System\Engine\Controller
{
    /** @var HttpClientInterface|null Optional HTTP client override for testing */
    private ?HttpClientInterface $httpClient = null;

    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    private function sep(): string
    {
        return (defined('VERSION') && version_compare(VERSION, '4.0.2.0', '>=')) ? '.' : '|';
    }

    public function index(): void
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        $userToken = (string) ($this->session->data['user_token'] ?? '');
        $sep       = $this->sep();

        $data = [];

        // Store currency check warning
        $storeCurrency = strtoupper((string) $this->config->get('config_currency'));
        $data['store_currency']            = $storeCurrency;
        $data['is_supported_fiat']         = Settings::isSupportedFiatCurrency($storeCurrency);
        $data['supported_fiat_currencies'] = implode(', ', ['USD', 'EUR', 'RUB']);

        // Breadcrumbs
        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $userToken),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $userToken . '&type=payment'),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/zerokyc/payment/zerokyc', 'user_token=' . $userToken),
            ],
        ];

        $data['save']     = $this->url->link('extension/zerokyc/payment/zerokyc' . $sep . 'save', 'user_token=' . $userToken);
        $data['back']     = $this->url->link('marketplace/extension', 'user_token=' . $userToken . '&type=payment');
        $data['ping_url'] = str_replace('&amp;', '&', $this->url->link('extension/zerokyc/payment/zerokyc' . $sep . 'ping', 'user_token=' . $userToken));

        $catalogBase = (defined('HTTPS_CATALOG') && HTTPS_CATALOG !== '')
            ? HTTPS_CATALOG
            : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');
        $data['webhook_url'] = rtrim($catalogBase, '/') . '/index.php?route=extension/zerokyc/payment/zerokyc' . $sep . 'callback';
        $data['cron_url']    = rtrim($catalogBase, '/') . '/index.php?route=extension/zerokyc/payment/zerokyc' . $sep . 'cron';

        $fields = [
            'payment_zerokyc_status'               => '0',
            'payment_zerokyc_title'                => 'Crypto (ZeroKYC Pay)',
            'payment_zerokyc_description'          => 'Pay with USDT, USDC, BTC, ETH, TON, SOL, XMR and more. Instant confirmation, no account required.',
            'payment_zerokyc_api_key'              => '',
            'payment_zerokyc_webhook_secret'       => '',
            'payment_zerokyc_payment_currency'     => 'any',
            'payment_zerokyc_ttl_minutes'          => '30',
            'payment_zerokyc_auto_cancel'          => '1',
            'payment_zerokyc_double_check'         => '1',
            'payment_zerokyc_debug'                => '0',
            'payment_zerokyc_total'                => '',
            'payment_zerokyc_pending_status_id'    => '1',
            'payment_zerokyc_confirming_status_id' => '1',
            'payment_zerokyc_paid_status_id'       => '2',
            'payment_zerokyc_underpaid_status_id'  => '1',
            'payment_zerokyc_expired_status_id'    => '7',
            'payment_zerokyc_geo_zone_id'          => '0',
            'payment_zerokyc_sort_order'           => '1',
        ];

        foreach ($fields as $field => $default) {
            $configured = $this->config->get($field);
            $data[$field] = ($configured !== null && $configured !== '') ? $configured : $default;
        }

        $data['payment_currencies'] = Settings::PAYMENT_CURRENCIES;

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/zerokyc/payment/zerokyc', $data));
    }

    /**
     * AJAX save endpoint for OpenCart 4 (`data-oc-toggle="ajax"`).
     */
    public function save(): void
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');

        $json = [];

        if (!$this->user->hasPermission('modify', 'extension/zerokyc/payment/zerokyc')) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        $enabled       = !empty($this->request->post['payment_zerokyc_status']);
        $apiKey        = trim((string) ($this->request->post['payment_zerokyc_api_key'] ?? ''));
        $webhookSecret = trim((string) ($this->request->post['payment_zerokyc_webhook_secret'] ?? ''));
        $ttlRaw        = (int) ($this->request->post['payment_zerokyc_ttl_minutes'] ?? 30);

        if ($apiKey !== '' && strpos($apiKey, 'pk_live_') !== 0 && strpos($apiKey, 'pk_test_') !== 0) {
            $json['error']['api_key'] = $this->language->get('error_api_key_prefix');
        } elseif ($enabled && $apiKey === '') {
            $json['error']['api_key'] = $this->language->get('error_api_key_empty');
        }

        if ($enabled && $webhookSecret === '') {
            $json['error']['webhook_secret'] = $this->language->get('error_webhook_secret');
        }

        if ($ttlRaw < 10 || $ttlRaw > 1440) {
            $json['error']['ttl_minutes'] = $this->language->get('error_ttl_minutes');
        }

        if (!$json) {
            $this->load->model('setting/setting');
            $post = $this->sanitizePost($this->request->post);
            $this->model_setting_setting->editSetting('payment_zerokyc', $post);
            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    public function install(): void
    {
        $prefix = defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_';
        (new EventStore($this->db, $prefix))->install();
        (new InvoiceMap($this->db, $prefix))->install();
    }

    public function uninstall(): void
    {
        // Intentionally preserve zerokyc_events and zerokyc_invoices tables.
    }

    public function ping(): void
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/zerokyc/payment/zerokyc')) {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => $this->language->get('error_permission'),
            ]));
            return;
        }

        $apiKey = '';
        if (isset($this->request->post['api_key'])) {
            $apiKey = trim((string) $this->request->post['api_key']);
        }
        if ($apiKey === '') {
            $apiKey = trim((string) $this->config->get('payment_zerokyc_api_key'));
        }

        if ($apiKey === '') {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => $this->language->get('error_api_key_empty'),
            ]));
            return;
        }

        if (strpos($apiKey, 'pk_live_') !== 0 && strpos($apiKey, 'pk_test_') !== 0) {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => $this->language->get('error_api_key_prefix'),
            ]));
            return;
        }

        try {
            $cfg = SdkConfig::fromArray([
                'api_key'     => $apiKey,
                'max_retries' => 1,
            ]);
            $sdk  = new ZeroKYC($cfg, $this->httpClient);
            $pong = $sdk->ping();

            $mode = strpos($apiKey, 'pk_test_') === 0 ? 'sandbox' : 'production';
            $this->response->setOutput((string) json_encode([
                'ok'      => true,
                'mode'    => $mode,
                'message' => sprintf($this->language->get('text_ping_ok'), $mode),
                'data'    => $pong,
            ]));
        } catch (ZeroKYCException $e) {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => sprintf($this->language->get('error_ping_failed'), $e->getMessage()),
            ]));
        } catch (Throwable $e) {
            $this->response->setOutput((string) json_encode([
                'ok'      => false,
                'message' => sprintf($this->language->get('error_ping_failed'), $e->getMessage()),
            ]));
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function sanitizePost(array $post): array
    {
        $post['payment_zerokyc_api_key']        = trim((string) ($post['payment_zerokyc_api_key'] ?? ''));
        $post['payment_zerokyc_webhook_secret'] = trim((string) ($post['payment_zerokyc_webhook_secret'] ?? ''));
        $post['payment_zerokyc_title']          = trim((string) ($post['payment_zerokyc_title'] ?? 'Crypto (ZeroKYC Pay)'));
        $post['payment_zerokyc_description']    = trim((string) ($post['payment_zerokyc_description'] ?? ''));

        $ttl = (int) ($post['payment_zerokyc_ttl_minutes'] ?? 30);
        if ($ttl <= 0) {
            $ttl = 30;
        }
        $post['payment_zerokyc_ttl_minutes'] = (string) max(10, min(1440, $ttl));

        $currency = (string) ($post['payment_zerokyc_payment_currency'] ?? 'any');
        if (!array_key_exists($currency, Settings::PAYMENT_CURRENCIES)) {
            $currency = 'any';
        }
        $post['payment_zerokyc_payment_currency'] = $currency;

        return $post;
    }
}
