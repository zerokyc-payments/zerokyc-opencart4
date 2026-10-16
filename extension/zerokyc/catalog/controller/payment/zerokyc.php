<?php
/**
 * ZeroKYC Pay for OpenCart 4.x — Catalog Checkout, Webhook & Cron Controller.
 *
 * Routes (OpenCart 4.0.2.0+ `.` or 4.0.0.0–4.0.1.1 `|`):
 * - extension/zerokyc/payment/zerokyc          (renders checkout payment form)
 * - extension/zerokyc/payment/zerokyc.send     (creates/reuses ZeroKYC invoice & returns redirect URL)
 * - extension/zerokyc/payment/zerokyc.callback (verifies HMAC-SHA256 webhook & updates order status)
 * - extension/zerokyc/payment/zerokyc.cron     (reconciles open invoices from the last 24h)
 */

declare(strict_types=1);

namespace Opencart\Catalog\Controller\Extension\Zerokyc\Payment;

require_once dirname(__DIR__, 3) . '/system/library/zerokyc/bootstrap.php';

use Throwable;
use ZeroKYC\Exception\ApiException;
use ZeroKYC\Exception\WebhookVerificationException;
use ZeroKYC\Exception\ZeroKYCException;
use ZeroKYC\Http\HttpClientInterface;
use ZeroKYC\Idempotency\IdempotencyKey;
use ZeroKYC\Invoice\CreateInvoiceRequest;
use ZeroKYC\Invoice\InvoiceStatus;
use ZeroKYC\Webhook\WebhookVerifier;
use ZeroKyc\OpenCart\Cron;
use ZeroKyc\OpenCart\EventStore;
use ZeroKyc\OpenCart\InvoiceMap;
use ZeroKyc\OpenCart\Logger;
use ZeroKyc\OpenCart\OpenCartOrderAdapter;
use ZeroKyc\OpenCart\OrderService;
use ZeroKyc\OpenCart\Settings;

class Zerokyc extends \Opencart\System\Engine\Controller
{
    /** @var HttpClientInterface|null Optional HTTP client override for unit/integration tests */
    private ?HttpClientInterface $httpClient = null;

    /** @var string|null Optional raw request body override for webhook tests */
    private ?string $rawBodyOverride = null;

    /** @var int|null Optional unix timestamp override for webhook replay/tolerance tests */
    private ?int $nowOverride = null;

    /** @var Logger|null */
    private ?Logger $loggerInstance = null;

    public function setHttpClient(?HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    public function setRawBodyOverride(?string $rawBody): void
    {
        $this->rawBodyOverride = $rawBody;
    }

    public function setNowOverride(?int $now): void
    {
        $this->nowOverride = $now;
    }

    public function setLogger(?Logger $logger): void
    {
        $this->loggerInstance = $logger;
    }

    private function sep(): string
    {
        return (defined('VERSION') && version_compare(VERSION, '4.0.2.0', '>=')) ? '.' : '|';
    }

    /**
     * Renders the payment method confirmation widget on checkout.
     */
    public function index(): string
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');

        $settings = new Settings($this->config);
        $sep      = $this->sep();

        $data = [
            'description'    => $settings->description(),
            'button_confirm' => $this->language->get('button_confirm'),
            'text_loading'   => $this->language->get('text_loading'),
            'send_url'       => str_replace('&amp;', '&', $this->url->link('extension/zerokyc/payment/zerokyc' . $sep . 'send')),
        ];

        return $this->load->view('extension/zerokyc/payment/zerokyc', $data);
    }

    /**
     * AJAX endpoint: creates or reuses a ZeroKYC Pay invoice for the active session order.
     */
    public function send(): void
    {
        $this->load->language('extension/zerokyc/payment/zerokyc');
        $this->load->model('checkout/order');
        $this->response->addHeader('Content-Type: application/json');

        if (empty($this->session->data['order_id'])) {
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_no_order'),
            ]));
            return;
        }

        $orderId   = (int) $this->session->data['order_id'];
        $orderInfo = $this->model_checkout_order->getOrder($orderId);

        if (!is_array($orderInfo) || empty($orderInfo['order_id'])) {
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_no_order'),
            ]));
            return;
        }

        $settings = new Settings($this->config);
        $logger   = $this->getLogger($settings);
        $sep      = $this->sep();

        // Resolve supported fiat currency and amount
        $resolved = $this->resolveOrderFiatAmount($orderInfo);
        if ($resolved === null) {
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_currency'),
            ]));
            return;
        }

        [$fiatAmount, $fiatCurrency] = $resolved;
        $pinnedAsset                 = $settings->pinnedPaymentCurrency();
        $expectedAssetKey            = $pinnedAsset ?? '';

        $prefix       = defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_';
        $invoiceMap   = new InvoiceMap($this->db, $prefix);
        $eventStore   = new EventStore($this->db, $prefix);
        $sdk          = $settings->buildSdk($this->httpClient);
        $orderAdapter = new OpenCartOrderAdapter($this->model_checkout_order);
        $orderService = new OrderService($orderAdapter, $invoiceMap, $settings, $logger, $sdk, $eventStore);

        $successUrl = str_replace('&amp;', '&', $this->url->link('checkout/success'));
        $webhookUrl = str_replace('&amp;', '&', $this->url->link('extension/zerokyc/payment/zerokyc' . $sep . 'callback'));

        // 1. Check if there is a reusable live invoice for this order with identical amount/currency/asset
        $latest = $invoiceMap->findLatestForOrder($orderId);
        if (
            $latest !== null
            && (string) ($latest['amount_fiat'] ?? '') === $fiatAmount
            && (string) ($latest['currency_fiat'] ?? '') === $fiatCurrency
            && (string) ($latest['asset'] ?? '') === $expectedAssetKey
        ) {
            $existingId = (string) ($latest['invoice_id'] ?? '');
            if ($existingId !== '') {
                try {
                    $existing = $sdk->getInvoice($existingId);

                    if ($existing->isPaid() || $existing->status === InvoiceStatus::OVERPAID) {
                        $orderService->applyInvoice($orderId, $existing);
                        $this->response->setOutput((string) json_encode([
                            'redirect' => $successUrl,
                        ]));
                        return;
                    }

                    $expiresTs = $existing->expiresAt !== ''
                        ? (is_numeric($existing->expiresAt) ? (int) $existing->expiresAt : (int) strtotime($existing->expiresAt))
                        : 0;

                    if (
                        !$existing->isTerminal()
                        && ($expiresTs <= 0 || $expiresTs > time() + 60)
                    ) {
                        $redirectUrl = $existing->checkoutUrl !== ''
                            ? $existing->checkoutUrl
                            : (string) ($existing->raw['payment_url'] ?? $latest['checkout_url'] ?? '');
                        if ($redirectUrl !== '') {
                            $this->ensureOrderPending($orderId, $orderInfo, $settings, $invoiceMap, $existingId);
                            $this->response->setOutput((string) json_encode([
                                'redirect' => $redirectUrl,
                            ]));
                            return;
                        }
                    }
                } catch (Throwable $e) {
                    $logger->debug('Could not reuse existing invoice; creating a new one', [
                        'order_id'   => $orderId,
                        'invoice_id' => $existingId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        // 2. Create a new invoice with deterministic per-attempt idempotency key
        $seq            = $invoiceMap->nextAttemptSeq($orderId);
        $idempotencyKey = IdempotencyKey::make('opencart', 'order', "{$orderId}:{$seq}");
        $storeName      = (string) ($orderInfo['store_name'] ?? $this->config->get('config_name') ?? 'OpenCart');
        $description    = sprintf($this->language->get('text_order_description'), $storeName, (string) $orderId);

        $request = CreateInvoiceRequest::make($fiatAmount, $fiatCurrency)
            ->withOrderId((string) $orderId)
            ->withDescription($description)
            ->withTtlMinutes($settings->ttlMinutes())
            ->withWebhookUrl($webhookUrl)
            ->withSuccessUrl($successUrl)
            ->withMetadata([
                'source'     => 'opencart4',
                'plugin_ver' => ZEROKYC_OC_VERSION,
                'oc_version' => defined('VERSION') ? (string) VERSION : '4.0.2.3',
                'order_id'   => (string) $orderId,
            ]);

        if ($pinnedAsset !== null) {
            $request = $request->withPaymentCurrency($pinnedAsset);
        }

        try {
            $created = $sdk->createInvoice($request, $idempotencyKey);
            $invoice = $created->invoice;
        } catch (ZeroKYCException $e) {
            $logger->warning('ZeroKYC createInvoice failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'code'     => $e instanceof ApiException ? $e->errorCode : null,
                'status'   => $e instanceof ApiException ? $e->status : null,
            ]);
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_payment_gateway'),
            ]));
            return;
        } catch (Throwable $e) {
            $logger->warning('Unexpected error during createInvoice', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_payment_gateway'),
            ]));
            return;
        }

        $checkoutUrl = $invoice->checkoutUrl !== ''
            ? $invoice->checkoutUrl
            : (string) ($invoice->raw['payment_url'] ?? '');

        if ($checkoutUrl === '') {
            $logger->warning('ZeroKYC invoice missing checkoutUrl', [
                'order_id'   => $orderId,
                'invoice_id' => $invoice->id,
            ]);
            $this->response->setOutput((string) json_encode([
                'error' => $this->language->get('error_payment_gateway'),
            ]));
            return;
        }

        $opt          = $pinnedAsset !== null ? $invoice->option($pinnedAsset) : ($invoice->options[0] ?? null);
        $cryptoAmount = (string) ($opt['amount'] ?? $invoice->raw['crypto_amount'] ?? $invoice->raw['amount_crypto'] ?? '');

        $invoiceMap->record($invoice->id, $orderId, [
            'seq'           => $seq,
            'checkout_url'  => $checkoutUrl,
            'amount_fiat'   => $fiatAmount,
            'currency_fiat' => $fiatCurrency,
            'asset'         => $expectedAssetKey,
            'amount_crypto' => $cryptoAmount,
        ]);

        $this->ensureOrderPending($orderId, $orderInfo, $settings, $invoiceMap, $invoice->id);

        $this->response->setOutput((string) json_encode([
            'redirect' => $checkoutUrl,
        ]));
    }

    /**
     * Signed Webhook Endpoint: POST index.php?route=extension/zerokyc/payment/zerokyc.callback
     */
    public function callback(): void
    {
        $settings = new Settings($this->config);
        $logger   = $this->getLogger($settings);

        $method = strtoupper((string) ($this->request->server['REQUEST_METHOD'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            $this->sendJsonStatus(405, ['error' => 'Method Not Allowed']);
            return;
        }

        $secret = $settings->webhookSecret();
        if ($secret === '') {
            $logger->warning('Webhook received while webhook_secret is not configured');
            $this->sendJsonStatus(503, ['error' => 'Webhook secret not configured']);
            return;
        }

        $rawBody   = $this->rawBodyOverride ?? (string) file_get_contents('php://input');
        $sigHeader = (string) (
            $this->request->server['HTTP_X_ZKP_SIGNATURE']
            ?? $_SERVER['HTTP_X_ZKP_SIGNATURE']
            ?? ''
        );

        try {
            $verifier = new WebhookVerifier($secret, WebhookVerifier::DEFAULT_TOLERANCE);
            $event    = $verifier->verify($rawBody, $sigHeader, $this->nowOverride);
        } catch (WebhookVerificationException $e) {
            $logger->warning('Webhook signature verification failed', [
                'reason'  => $e->reason,
                'message' => $e->getMessage(),
            ]);
            $this->sendJsonStatus(400, ['error' => $e->getMessage()]);
            return;
        }

        $prefix     = defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_';
        $eventStore = new EventStore($this->db, $prefix);

        if (!$eventStore->claim($event->id, $event->invoiceId, $event->type)) {
            $logger->debug('Duplicate webhook event ignored', [
                'event_id'   => $event->id,
                'invoice_id' => $event->invoiceId,
                'type'       => $event->type,
            ]);
            $this->sendJsonStatus(200, ['ok' => true, 'duplicate' => true]);
            return;
        }

        $this->load->model('checkout/order');
        $invoiceMap   = new InvoiceMap($this->db, $prefix);
        $sdk          = $settings->buildSdk($this->httpClient);
        $orderAdapter = new OpenCartOrderAdapter($this->model_checkout_order);
        $orderService = new OrderService($orderAdapter, $invoiceMap, $settings, $logger, $sdk, $eventStore);

        try {
            $orderService->handleEvent($event);
            $eventStore->markProcessed($event->id);
        } catch (Throwable $e) {
            $eventStore->release($event->id);
            $logger->warning('Webhook processing failed; released event claim for retry', [
                'event_id'   => $event->id,
                'invoice_id' => $event->invoiceId,
                'error'      => $e->getMessage(),
            ]);
            $this->sendJsonStatus(500, ['error' => 'Internal processing error']);
            return;
        }

        $this->sendJsonStatus(200, ['ok' => true]);
    }

    /**
     * Background reconciliation endpoint: GET/POST index.php?route=extension/zerokyc/payment/zerokyc.cron
     */
    public function cron(): void
    {
        $this->load->model('checkout/order');

        $settings     = new Settings($this->config);
        $logger       = $this->getLogger($settings);
        $prefix       = defined('DB_PREFIX') ? (string) DB_PREFIX : 'oc_';
        $invoiceMap   = new InvoiceMap($this->db, $prefix);
        $eventStore   = new EventStore($this->db, $prefix);
        $sdk          = $settings->buildSdk($this->httpClient);
        $orderAdapter = new OpenCartOrderAdapter($this->model_checkout_order);
        $orderService = new OrderService($orderAdapter, $invoiceMap, $settings, $logger, $sdk, $eventStore);

        $cron   = new Cron($invoiceMap, $eventStore, $orderService, $settings, $logger);
        $polled = $cron->poll($sdk);

        $this->sendJsonStatus(200, [
            'ok'     => true,
            'polled' => $polled,
        ]);
    }

    /**
     * Resolves the order total into a supported ZeroKYC fiat currency (USD, EUR, or RUB).
     *
     * @param array<string, mixed> $orderInfo
     * @return array{0: string, 1: string}|null [formattedAmount, currencyCode]
     */
    private function resolveOrderFiatAmount(array $orderInfo): ?array
    {
        $orderCurrency = strtoupper(trim((string) ($orderInfo['currency_code'] ?? '')));
        $baseTotal     = (float) ($orderInfo['total'] ?? 0.0);
        $currencyValue = (float) ($orderInfo['currency_value'] ?? 1.0);
        if ($currencyValue <= 0.0) {
            $currencyValue = 1.0;
        }

        if (Settings::isSupportedFiatCurrency($orderCurrency)) {
            if (isset($this->currency) && is_object($this->currency) && method_exists($this->currency, 'format')) {
                $converted = (float) $this->currency->format($baseTotal, $orderCurrency, $currencyValue, false);
            } else {
                $converted = $baseTotal * $currencyValue;
            }
            return [Settings::formatFiatAmount($converted), $orderCurrency];
        }

        $storeCurrency = strtoupper(trim((string) $this->config->get('config_currency')));
        if (Settings::isSupportedFiatCurrency($storeCurrency)) {
            return [Settings::formatFiatAmount($baseTotal), $storeCurrency];
        }

        return null;
    }

    /**
     * Ensures a newly confirmed checkout order moves from status 0 to the configured Pending status.
     *
     * @param array<string, mixed> $orderInfo
     */
    private function ensureOrderPending(
        int $orderId,
        array $orderInfo,
        Settings $settings,
        InvoiceMap $invoiceMap,
        string $invoiceId
    ): void {
        $currentStatus = (int) ($orderInfo['order_status_id'] ?? 0);
        $noteKey       = 'created:' . $invoiceId;

        if ($currentStatus === 0) {
            $comment = sprintf('ZeroKYC Pay invoice %s created. Awaiting customer payment.', $invoiceId);
            $this->model_checkout_order->addOrderHistory($orderId, $settings->pendingStatusId(), $comment, false);
            $invoiceMap->markNoteSent($invoiceId, $noteKey);
        } elseif (!$invoiceMap->hasNoteBeenSent($invoiceId, $noteKey)) {
            $comment = sprintf('ZeroKYC Pay invoice %s created. Awaiting customer payment.', $invoiceId);
            $this->model_checkout_order->addOrderHistory($orderId, $currentStatus, $comment, false);
            $invoiceMap->markNoteSent($invoiceId, $noteKey);
        }
    }

    private function getLogger(Settings $settings): Logger
    {
        if ($this->loggerInstance !== null) {
            return $this->loggerInstance;
        }

        $ocLog = $this->log ?? null;
        $this->loggerInstance = new Logger($ocLog, $settings->debug());
        return $this->loggerInstance;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendJsonStatus(int $httpStatus, array $payload): void
    {
        $statusTexts = [
            200 => '200 OK',
            400 => '400 Bad Request',
            405 => '405 Method Not Allowed',
            500 => '500 Internal Server Error',
            503 => '503 Service Unavailable',
        ];
        $statusLine = $statusTexts[$httpStatus] ?? ($httpStatus . ' Status');

        $protocol = (string) ($this->request->server['SERVER_PROTOCOL'] ?? $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        $this->response->addHeader($protocol . ' ' . $statusLine);
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($payload));
    }
}
