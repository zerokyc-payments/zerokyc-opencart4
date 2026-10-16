# ZeroKYC Pay for OpenCart 4.x

Official **ZeroKYC Pay** cryptocurrency payment gateway extension for **OpenCart 4.x** (`4.0.0.0` – `4.0.2.x` / `4.1.x`).

Accept **USDT (TRC20)**, **USDC (ERC20)**, **Bitcoin (BTC)**, **Ethereum (ETH)**, **Solana (SOL)**, and **TON** directly in your OpenCart 4 store — with zero KYC, deterministic invoice idempotency, HMAC-SHA256 signed webhooks, and automatic background reconciliation.

---

## Features

- **OpenCart 4.x Native Architecture** — Uses the modern `Opencart\*\Extension\Zerokyc\Payment\Zerokyc` namespace, `install.json` manifest, and Bootstrap 5 + `data-oc-toggle="ajax"` admin interface.
- **Cross-4.x Compatibility (`4.0.0.0` – `4.0.2.x+`)** — Implements both `getMethods()` (OpenCart `4.0.2.0+`) and `getMethod()` (OpenCart `4.0.0.0` – `4.0.1.1`) as well as automatic `.` / `|` route action separator detection.
- **Zero Runtime Dependencies** — Bundles [`zerokyc/zkp-sdk-php`](https://github.com/zerokyc-payments/zkp-sdk-php) inside `extension/zerokyc/system/library/zerokyc/vendor/` so merchants do not need SSH or Composer on their server.
- **Deterministic Idempotency & Live Invoice Reuse** — Prevents duplicate invoices on double clicks and automatically creates a new invoice with an incremented sequence number if the order total changes.
- **Signed Webhooks (`WebhookVerifier`)** — Verifies `X-ZeroKYC-Signature` / `X-Signature` headers using constant-time HMAC-SHA256, enforces timestamp replay tolerance, and deduplicates events in `{DB_PREFIX}zerokyc_webhook_events`.
- **Fallback Reconciliation Cron** — Includes an authenticated HTTP cron endpoint to reconcile pending invoices if a webhook is missed.
- **Bilingual Out-of-the-Box** — Includes full English (`en-gb`) and Russian (`ru-ru`) language packs for both Admin and Catalog.

---

## Requirements

- **OpenCart**: `4.0.0.0` – `4.0.2.x` / `4.1.x`
- **PHP**: `8.0+` with `curl`, `json`, `hash`, `mbstring`
- **Store or Checkout Currency**: `USD` or `EUR`

---

## Installation

### Option A: OpenCart 4 Extension Installer (`zerokyc.ocmod.zip`)
1. Download `zerokyc.ocmod.zip` (or build it via `./bin/build-zip.sh`).
2. In your OpenCart 4 Admin panel, navigate to **Extensions → Installer** (`Расширения → Установка расширений`).
3. Upload `zerokyc.ocmod.zip` and click the **Install (`+`)** button next to **ZeroKYC Pay — Crypto Payments** in the Installed Extensions list.
4. Navigate to **Extensions → Extensions**, select **Payments** (`Оплата`) from the filter dropdown, find **ZeroKYC Pay — Crypto Payments**, and click the green **Install (`+`)** button.

### Option B: Direct Copy
1. Copy the `extension/zerokyc` directory into the `extension/` directory of your OpenCart 4 installation (`<opencart-root>/extension/zerokyc`).
2. In OpenCart Admin, go to **Extensions → Extensions → Payments** and install **ZeroKYC Pay — Crypto Payments**.

---

## Configuration

1. Open **Extensions → Extensions → Payments → ZeroKYC Pay — Crypto Payments → Edit**.
2. Enter your **API Key** and **Webhook Signing Secret** from your [ZeroKYC Pay Dashboard](https://zerokycpay.com).
3. Copy the **Webhook Endpoint URL** displayed in the **Diagnostics & Webhook** tab and register it in your ZeroKYC Pay merchant settings:
   - OpenCart `4.0.2.0+`:
     ```text
     https://your-store.com/index.php?route=extension/zerokyc/payment/zerokyc.callback
     ```
   - OpenCart `4.0.0.0` – `4.0.1.1`:
     ```text
     https://your-store.com/index.php?route=extension/zerokyc/payment/zerokyc|callback
     ```
4. *(Optional)* Use the **Test API Connection** button to verify connectivity with `GET /v1/ping`.
5. *(Optional)* Configure a system cron job every 5 minutes using the **Cron Reconciliation URL** shown in the admin panel.

---

## Development & Testing

Run the unit and integration test suite via Composer / PHPUnit:

```bash
composer install
vendor/bin/phpunit
```

Build the distributable OpenCart 4 `zerokyc.ocmod.zip` archive:

```bash
./bin/build-zip.sh
# Output: dist/zerokyc.ocmod.zip
```

---

## License

MIT © [ZeroKYC Pay](https://zerokycpay.com)
