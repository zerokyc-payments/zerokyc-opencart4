<?php

declare(strict_types=1);

namespace ZeroKyc\OpenCart\Tests\Support;

use stdClass;

/**
 * In-memory OpenCart 2.3 DB stub that faithfully executes the SQL queries
 * used by EventStore, InvoiceMap, and ModelExtensionPaymentZerokyc without
 * requiring an external MySQL server.
 */
final class FakeDb
{
    /** @var array<string, array{event_id: string, invoice_id: string, event_type: string, received_at: string, processed_at: ?string}> */
    public array $events = [];

    /** @var array<string, array<string, mixed>> */
    public array $invoices = [];

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    /** @var list<array{geo_zone_id: int, country_id: int, zone_id: int}> */
    public array $geoZones = [];

    /** @var list<string> */
    public array $queries = [];

    private int $lastAffected = 0;

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function countAffected(): int
    {
        return $this->lastAffected;
    }

    public function query(string $sql): stdClass
    {
        $this->queries[]    = $sql;
        $this->lastAffected = 0;
        $normalized         = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        // 1. CREATE TABLE
        if (stripos($normalized, 'CREATE TABLE') === 0) {
            return $this->emptyResult();
        }

        // 2. EventStore::claim() / markProcessed() INSERT IGNORE INTO oc_zerokyc_events
        if (preg_match("/^INSERT IGNORE INTO `[^`]*zerokyc_events` \(`event_id`, `invoice_id`, `event_type`, `received_at`(?:, `processed_at`)?\) VALUES \('([^']*)', '([^']*)', '([^']*)', '([^']*)'(?:, '([^']*)')?\)$/i", $normalized, $m)) {
            $eventId = stripslashes($m[1]);
            if (isset($this->events[$eventId])) {
                $this->lastAffected = 0;
                return $this->emptyResult();
            }
            $this->events[$eventId] = [
                'event_id'     => $eventId,
                'invoice_id'   => stripslashes($m[2]),
                'event_type'   => stripslashes($m[3]),
                'received_at'  => stripslashes($m[4]),
                'processed_at' => isset($m[5]) ? stripslashes($m[5]) : null,
            ];
            $this->lastAffected = 1;
            return $this->emptyResult();
        }

        // 3. EventStore::has()
        if (preg_match("/^SELECT `event_id` FROM `[^`]*zerokyc_events` WHERE `event_id` = '([^']*)' LIMIT 1$/i", $normalized, $m)) {
            $eventId = stripslashes($m[1]);
            if (isset($this->events[$eventId])) {
                return $this->rowsResult([$this->events[$eventId]]);
            }
            return $this->emptyResult();
        }

        // 4. EventStore::markProcessed() UPDATE
        if (preg_match("/^UPDATE `[^`]*zerokyc_events` SET `processed_at` = '([^']*)' WHERE `event_id` = '([^']*)'$/i", $normalized, $m)) {
            $processedAt = stripslashes($m[1]);
            $eventId     = stripslashes($m[2]);
            if (isset($this->events[$eventId])) {
                $this->events[$eventId]['processed_at'] = $processedAt;
                $this->lastAffected                     = 1;
            }
            return $this->emptyResult();
        }

        // 5. EventStore::release()
        if (preg_match("/^DELETE FROM `[^`]*zerokyc_events` WHERE `event_id` = '([^']*)' AND `processed_at` IS NULL$/i", $normalized, $m)) {
            $eventId = stripslashes($m[1]);
            if (isset($this->events[$eventId]) && $this->events[$eventId]['processed_at'] === null) {
                unset($this->events[$eventId]);
                $this->lastAffected = 1;
            }
            return $this->emptyResult();
        }

        // 6. EventStore::cleanup()
        if (preg_match("/^DELETE FROM `[^`]*zerokyc_events` WHERE `received_at` < '([^']*)'$/i", $normalized, $m)) {
            $cutoff  = stripslashes($m[1]);
            $deleted = 0;
            foreach ($this->events as $id => $row) {
                if ($row['received_at'] < $cutoff) {
                    unset($this->events[$id]);
                    $deleted++;
                }
            }
            $this->lastAffected = $deleted;
            return $this->emptyResult();
        }

        // 7. InvoiceMap::record()
        if (preg_match("/^INSERT INTO `[^`]*zerokyc_invoices` \(`invoice_id`, `order_id`, `seq`, `checkout_url`, `amount_fiat`, `currency_fiat`, `asset`, `amount_crypto`, `notes_sent`, `created_at`\) VALUES \('([^']*)', (\d+), (\d+), '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)', '([^']*)'\) ON DUPLICATE KEY UPDATE/i", $normalized, $m)) {
            $invoiceId = stripslashes($m[1]);
            $existing  = $this->invoices[$invoiceId] ?? null;

            $this->invoices[$invoiceId] = [
                'invoice_id'    => $invoiceId,
                'order_id'      => (int) $m[2],
                'seq'           => (int) $m[3],
                'checkout_url'  => stripslashes($m[4]),
                'amount_fiat'   => stripslashes($m[5]),
                'currency_fiat' => stripslashes($m[6]),
                'asset'         => stripslashes($m[7]),
                'amount_crypto' => stripslashes($m[8]),
                'paid_asset'    => (string) ($existing['paid_asset'] ?? ''),
                'paid_amount'   => (string) ($existing['paid_amount'] ?? ''),
                'paid_txid'     => (string) ($existing['paid_txid'] ?? ''),
                'notes_sent'    => (string) ($existing['notes_sent'] ?? stripslashes($m[9])),
                'created_at'    => (string) ($existing['created_at'] ?? stripslashes($m[10])),
            ];
            $this->lastAffected = 1;
            return $this->emptyResult();
        }

        // 8. InvoiceMap::findByInvoiceId()
        if (preg_match("/^SELECT \* FROM `[^`]*zerokyc_invoices` WHERE `invoice_id` = '([^']*)' LIMIT 1$/i", $normalized, $m)) {
            $invoiceId = stripslashes($m[1]);
            if (isset($this->invoices[$invoiceId])) {
                return $this->rowsResult([$this->invoices[$invoiceId]]);
            }
            return $this->emptyResult();
        }

        // 9. InvoiceMap::findLatestForOrder()
        if (preg_match("/^SELECT \* FROM `[^`]*zerokyc_invoices` WHERE `order_id` = (\d+) ORDER BY `seq` DESC, `created_at` DESC LIMIT 1$/i", $normalized, $m)) {
            $orderId = (int) $m[1];
            $matches = array_values(array_filter(
                $this->invoices,
                static fn(array $r): bool => (int) $r['order_id'] === $orderId
            ));
            if ($matches === []) {
                return $this->emptyResult();
            }
            usort($matches, static function (array $a, array $b): int {
                if ((int) $a['seq'] !== (int) $b['seq']) {
                    return (int) $b['seq'] <=> (int) $a['seq'];
                }
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });
            return $this->rowsResult([$matches[0]]);
        }

        // 10. InvoiceMap::markPaid()
        if (preg_match("/^UPDATE `[^`]*zerokyc_invoices` SET `paid_asset` = '([^']*)', `paid_amount` = '([^']*)', `paid_txid` = '([^']*)' WHERE `invoice_id` = '([^']*)'$/i", $normalized, $m)) {
            $invoiceId = stripslashes($m[4]);
            if (isset($this->invoices[$invoiceId])) {
                $this->invoices[$invoiceId]['paid_asset']  = stripslashes($m[1]);
                $this->invoices[$invoiceId]['paid_amount'] = stripslashes($m[2]);
                $this->invoices[$invoiceId]['paid_txid']   = stripslashes($m[3]);
                $this->lastAffected                        = 1;
            }
            return $this->emptyResult();
        }

        // 11. InvoiceMap::markNoteSent()
        if (preg_match("/^UPDATE `[^`]*zerokyc_invoices` SET `notes_sent` = '(.*)' WHERE `invoice_id` = '([^']*)'$/is", $normalized, $m)) {
            $invoiceId = stripslashes($m[2]);
            if (isset($this->invoices[$invoiceId])) {
                $this->invoices[$invoiceId]['notes_sent'] = stripslashes($m[1]);
                $this->lastAffected                       = 1;
            }
            return $this->emptyResult();
        }

        // 12. InvoiceMap::findOpenInvoices()
        if (stripos($normalized, 'SELECT i.`order_id`, i.`invoice_id` FROM') === 0) {
            preg_match("/WHERE i\.`created_at` >= '([^']*)' AND o\.`order_status_id` IN \(([^)]+)\)/i", $normalized, $m);
            $cutoff   = isset($m[1]) ? stripslashes($m[1]) : '1970-01-01 00:00:00';
            $statuses = isset($m[2]) ? array_map('intval', explode(',', $m[2])) : [0];

            // Compute max seq per order_id
            $maxSeqByOrder = [];
            foreach ($this->invoices as $inv) {
                $oid = (int) $inv['order_id'];
                $seq = (int) $inv['seq'];
                if (!isset($maxSeqByOrder[$oid]) || $seq > $maxSeqByOrder[$oid]) {
                    $maxSeqByOrder[$oid] = $seq;
                }
            }

            $rows = [];
            foreach ($this->invoices as $inv) {
                $oid = (int) $inv['order_id'];
                if ((int) $inv['seq'] !== ($maxSeqByOrder[$oid] ?? 0)) {
                    continue;
                }
                if ((string) $inv['created_at'] < $cutoff) {
                    continue;
                }
                $order = $this->orders[$oid] ?? null;
                if ($order === null) {
                    continue;
                }
                if (!in_array((int) $order['order_status_id'], $statuses, true)) {
                    continue;
                }
                $rows[] = [
                    'order_id'   => $oid,
                    'invoice_id' => (string) $inv['invoice_id'],
                ];
            }

            return $this->rowsResult($rows);
        }

        // 13. Geo zone check in ModelExtensionPaymentZerokyc
        if (preg_match("/^SELECT \* FROM `[^`]*zone_to_geo_zone` WHERE `geo_zone_id` = '(\d+)' AND `country_id` = '(\d+)' AND \(`zone_id` = '(\d+)' OR `zone_id` = '0'\)$/i", $normalized, $m)) {
            $gzId      = (int) $m[1];
            $countryId = (int) $m[2];
            $zoneId    = (int) $m[3];

            $matched = [];
            foreach ($this->geoZones as $gz) {
                if (
                    $gz['geo_zone_id'] === $gzId
                    && $gz['country_id'] === $countryId
                    && ($gz['zone_id'] === $zoneId || $gz['zone_id'] === 0)
                ) {
                    $matched[] = $gz;
                }
            }
            return $this->rowsResult($matched);
        }

        return $this->emptyResult();
    }

    private function emptyResult(): stdClass
    {
        $res           = new stdClass();
        $res->num_rows = 0;
        $res->row      = [];
        $res->rows     = [];
        return $res;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rowsResult(array $rows): stdClass
    {
        $res           = new stdClass();
        $res->num_rows = count($rows);
        $res->row      = $rows[0] ?? [];
        $res->rows     = $rows;
        return $res;
    }
}

final class FakeConfig
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }
}

final class FakeLanguage
{
    /** @var array<string, string> */
    public array $strings = [
        'text_title'             => 'Crypto (ZeroKYC Pay)',
        'text_description'       => 'Pay with crypto.',
        'text_loading'           => 'Redirecting...',
        'text_order_description' => '%s - Order #%s',
        'button_confirm'         => 'Pay with Crypto',
        'error_no_order'         => 'No active order found in session.',
        'error_currency'         => 'Selected currency is not supported by ZeroKYC Pay.',
        'error_payment_gateway'  => 'Could not initialize cryptocurrency payment.',
        'error_permission'       => 'Warning: You do not have permission!',
        'error_api_key_empty'    => 'API Key is required.',
        'error_api_key_prefix'   => 'API Key must start with pk_live_ or pk_test_.',
        'text_ping_ok'           => 'Connected to ZeroKYC Pay (%s mode).',
        'error_ping_failed'      => 'Connection test failed: %s',
    ];

    public function get(string $key): string
    {
        return $this->strings[$key] ?? $key;
    }
}

final class FakeSession
{
    /** @var array<string, mixed> */
    public array $data = [];
}

final class FakeRequest
{
    /** @var array<string, mixed> */
    public array $get = [];

    /** @var array<string, mixed> */
    public array $post = [];

    /** @var array<string, mixed> */
    public array $server = [
        'REQUEST_METHOD'  => 'GET',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
    ];
}

final class FakeResponse
{
    /** @var list<string> */
    public array $headers = [];

    public string $output = '';

    public string $redirectUrl = '';

    public function addHeader(string $header): void
    {
        $this->headers[] = $header;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
    }

    public function redirect(string $url): void
    {
        $this->redirectUrl = $url;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->output, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function httpStatus(): int
    {
        foreach ($this->headers as $h) {
            if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/i', $h, $m)) {
                return (int) $m[1];
            }
        }
        return 200;
    }
}

final class FakeUrl
{
    public function link(string $route, string $args = '', bool $secure = false): string
    {
        $base = $secure ? 'https://shop.example.com/' : 'http://shop.example.com/';
        $url  = $base . 'index.php?route=' . $route;
        if ($args !== '') {
            $url .= '&' . ltrim($args, '&');
        }
        return $url;
    }
}

final class FakeModelCheckoutOrder
{
    private FakeDb $db;

    /** @var list<array{order_id: int, order_status_id: int, comment: string, notify: bool}> */
    public array $history = [];

    public function __construct(FakeDb $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<string, mixed> $order
     */
    public function seedOrder(int $orderId, array $order = []): void
    {
        $this->db->orders[$orderId] = array_merge([
            'order_id'        => $orderId,
            'order_status_id' => 0,
            'total'           => 25.00,
            'currency_code'   => 'USD',
            'currency_value'  => 1.0,
            'store_name'      => 'Test Store',
        ], $order);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrder(int $orderId): ?array
    {
        return $this->db->orders[$orderId] ?? null;
    }

    public function addOrderHistory(int $orderId, int $orderStatusId, string $comment = '', bool $notify = false): void
    {
        if (isset($this->db->orders[$orderId])) {
            $this->db->orders[$orderId]['order_status_id'] = $orderStatusId;
        }
        $this->history[] = [
            'order_id'        => $orderId,
            'order_status_id' => $orderStatusId,
            'comment'         => $comment,
            'notify'          => $notify,
        ];
    }
}

final class FakeLoader
{
    /** @var object */
    private object $container;

    public function __construct(object $container)
    {
        $this->container = $container;
    }

    public function language(string $route): void
    {
        // FakeLanguage already populated on container
    }

    public function model(string $route): void
    {
        // Models are pre-attached on container for testing
    }

    /**
     * @param array<string, mixed> $data
     */
    public function view(string $route, array $data = []): string
    {
        return (string) json_encode(['view' => $route, 'data' => $data]);
    }

    public function controller(string $route): string
    {
        return '<!-- ' . $route . ' -->';
    }
}
