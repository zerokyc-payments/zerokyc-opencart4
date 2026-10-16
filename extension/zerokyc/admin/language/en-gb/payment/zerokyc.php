<?php
// Heading
$_['heading_title']            = 'ZeroKYC Pay (Crypto)';

// Text
$_['text_extension']           = 'Extensions';
$_['text_success']             = 'Success: You have modified ZeroKYC Pay settings!';
$_['text_edit']                = 'Edit ZeroKYC Pay';
$_['text_zerokyc']             = '<a href="https://zerokycpay.com" target="_blank" rel="noopener"><img src="view/image/payment/zerokyc.png" alt="ZeroKYC Pay" title="ZeroKYC Pay" style="border: 1px solid #EEEEEE; max-height: 25px;" onerror="this.style.display=\'none\'" /> ZeroKYC Pay</a>';
$_['text_testing']             = 'Testing connection...';
$_['text_copied']              = 'Copied!';
$_['text_ping_ok']             = 'Connected to ZeroKYC Pay (%s mode).';
$_['text_currency_notice']     = 'Warning: Your store default currency (%s) is not directly supported by ZeroKYC Pay (%s). Please ensure USD, EUR, or RUB is configured in System &rarr; Localisation &rarr; Currencies.';

// Entry
$_['entry_status']             = 'Status';
$_['entry_title']              = 'Checkout Title';
$_['entry_description']        = 'Checkout Description';
$_['entry_api_key']            = 'API Key';
$_['entry_webhook_secret']     = 'Webhook Secret';
$_['entry_webhook_url']        = 'Webhook URL';
$_['entry_cron_url']           = 'Cron / Reconciliation URL';
$_['entry_payment_currency']   = 'Accepted Crypto Currency';
$_['entry_ttl_minutes']        = 'Invoice Expiration (minutes)';
$_['entry_auto_cancel']        = 'Auto-cancel Expired Orders';
$_['entry_double_check']       = 'Server-side Double Check';
$_['entry_debug']              = 'Debug Logging';
$_['entry_total']              = 'Minimum Order Total';
$_['entry_pending_status']     = 'Pending Status';
$_['entry_confirming_status']  = 'Confirming (Detected) Status';
$_['entry_paid_status']        = 'Paid (Confirmed) Status';
$_['entry_underpaid_status']   = 'Underpaid / Review Status';
$_['entry_expired_status']     = 'Expired / Canceled Status';
$_['entry_geo_zone']           = 'Geo Zone';
$_['entry_sort_order']         = 'Sort Order';

// Help
$_['help_api_key']             = 'Project API key from dashboard.zerokycpay.com (starts with pk_live_ or pk_test_). Mode is detected automatically from the key prefix.';
$_['help_webhook_secret']      = 'HMAC-SHA256 signing secret from your ZeroKYC project webhook settings (whsec_...).';
$_['help_webhook_url']         = 'Paste this URL into your ZeroKYC Dashboard &rarr; Webhooks.';
$_['help_cron_url']            = 'Optional background reconciliation URL (polls open invoices from the last 24h). You can call it via system cron every 5 minutes.';
$_['help_payment_currency']    = 'Choose "Let the buyer choose" to display all enabled coins on the ZeroKYC hosted checkout, or pin a specific coin/network.';
$_['help_ttl_minutes']         = 'Invoice validity window in minutes (between 5 and 1440, default 30).';
$_['help_auto_cancel']         = 'Automatically transition unconfirmed orders to Expired/Canceled status when the invoice expires.';
$_['help_double_check']        = 'Before completing an order on a payment.confirmed webhook, verify the invoice status directly via GET /v1/invoices/{id}. Strongly recommended.';
$_['help_debug']               = 'Write detailed diagnostic messages to system/storage/logs/zerokyc.log (secrets are never logged).';
$_['help_total']               = 'The checkout total the order must reach before this payment method becomes active.';

// Button
$_['button_ping']              = 'Test Connection';
$_['button_copy']              = 'Copy';

// Error
$_['error_permission']         = 'Warning: You do not have permission to modify ZeroKYC Pay!';
$_['error_api_key_empty']      = 'API Key is required when ZeroKYC Pay is enabled.';
$_['error_api_key_prefix']     = 'API Key must start with pk_live_ or pk_test_.';
$_['error_webhook_secret']     = 'Webhook Secret is required when ZeroKYC Pay is enabled.';
$_['error_ttl_minutes']        = 'Invoice expiration must be between 5 and 1440 minutes.';
$_['error_ping_failed']        = 'Connection test failed: %s';
