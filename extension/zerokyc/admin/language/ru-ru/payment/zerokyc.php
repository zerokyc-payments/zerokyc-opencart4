<?php
// Heading
$_['heading_title']            = 'ZeroKYC Pay (Криптовалюта)';

// Text
$_['text_extension']           = 'Расширения';
$_['text_success']             = 'Настройки модуля ZeroKYC Pay успешно сохранены!';
$_['text_edit']                = 'Редактирование ZeroKYC Pay';
$_['text_zerokyc']             = '<a href="https://zerokycpay.com" target="_blank" rel="noopener"><img src="view/image/payment/zerokyc.png" alt="ZeroKYC Pay" title="ZeroKYC Pay" style="border: 1px solid #EEEEEE; max-height: 25px;" onerror="this.style.display=\'none\'" /> ZeroKYC Pay</a>';
$_['text_testing']             = 'Проверка соединения...';
$_['text_copied']              = 'Скопировано!';
$_['text_ping_ok']             = 'Успешное подключение к ZeroKYC Pay (режим: %s).';
$_['text_currency_notice']     = 'Внимание: базовая валюта магазина (%s) не входит в список напрямую поддерживаемых валют ZeroKYC Pay (%s). Убедитесь, что в Система &rarr; Локализация &rarr; Валюты настроена одна из валют: USD, EUR или RUB.';

// Entry
$_['entry_status']             = 'Статус';
$_['entry_title']              = 'Название при оформлении заказа';
$_['entry_description']        = 'Описание при оформлении заказа';
$_['entry_api_key']            = 'API ключ (API Key)';
$_['entry_webhook_secret']     = 'Секрет вебхуков (Webhook Secret)';
$_['entry_webhook_url']        = 'Webhook URL';
$_['entry_cron_url']           = 'Cron / Reconciliation URL';
$_['entry_payment_currency']   = 'Валюта оплаты (криптовалюта)';
$_['entry_ttl_minutes']        = 'Время жизни инвойса (в минутах)';
$_['entry_auto_cancel']        = 'Автоотмена просроченных заказов';
$_['entry_double_check']       = 'Серверная перепроверка (Double Check)';
$_['entry_debug']              = 'Отладочное логирование (Debug)';
$_['entry_total']              = 'Минимальная сумма заказа';
$_['entry_pending_status']     = 'Статус «Ожидание оплаты» (Pending)';
$_['entry_confirming_status']  = 'Статус «Подтверждение в сети» (Confirming)';
$_['entry_paid_status']        = 'Статус «Оплачен» (Paid)';
$_['entry_underpaid_status']   = 'Статус «Недоплата / Проверка» (Underpaid)';
$_['entry_expired_status']     = 'Статус «Истёк / Отменён» (Expired)';
$_['entry_geo_zone']           = 'Географическая зона';
$_['entry_sort_order']         = 'Порядок сортировки';

// Help
$_['help_api_key']             = 'Ключ проекта из личного кабинета dashboard.zerokycpay.com (начинается с pk_live_ или pk_test_). Режим работы определяется автоматически.';
$_['help_webhook_secret']      = 'Секретный ключ подписи HMAC-SHA256 из настроек вебхука проекта (whsec_...).';
$_['help_webhook_url']         = 'Скопируйте и укажите этот URL в настройках вебхуков вашего проекта в ZeroKYC Dashboard.';
$_['help_cron_url']            = 'Опциональный URL для фоновой сверки открытых заказов за последние 24 часа. Можно вызывать по расписанию (cron) каждые 5 минут.';
$_['help_payment_currency']    = 'Выберите «Let the buyer choose», чтобы покупатель сам выбрал монету и сеть на странице оплаты ZeroKYC, либо зафиксируйте конкретную валюту.';
$_['help_ttl_minutes']         = 'Срок действия инвойса в минутах (от 5 до 1440, по умолчанию 30).';
$_['help_auto_cancel']         = 'Автоматически переводить неоплаченные заказы в статус «Истёк / Отменён» по истечении времени жизни инвойса.';
$_['help_double_check']        = 'Перед завершением заказа по вебхуку дополнительно запрашивать актуальный статус инвойса через GET /v1/invoices/{id}. Настоятельно рекомендуется.';
$_['help_debug']               = 'Записывать подробные диагностические сообщения в system/storage/logs/zerokyc.log (секретные ключи никогда не логируются).';
$_['help_total']               = 'Минимальная сумма заказа, при которой данный способ оплаты становится доступен.';

// Button
$_['button_ping']              = 'Проверить соединение';
$_['button_copy']              = 'Копировать';

// Error
$_['error_permission']         = 'У вас нет прав для изменения настроек модуля ZeroKYC Pay!';
$_['error_api_key_empty']      = 'Укажите API ключ для включения ZeroKYC Pay.';
$_['error_api_key_prefix']     = 'API ключ должен начинаться с pk_live_ или pk_test_.';
$_['error_webhook_secret']     = 'Укажите секрет вебхуков (Webhook Secret) для включения ZeroKYC Pay.';
$_['error_ttl_minutes']        = 'Время жизни инвойса должно быть от 5 до 1440 минут.';
$_['error_ping_failed']        = 'Ошибка проверки соединения: %s';
