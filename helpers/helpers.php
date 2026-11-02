<?php

use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Services\SmsService;
use Illuminate\Support\Collection;

if (! function_exists('sms_service')) {
    function sms_service(): SmsService
    {
        return app(SmsService::class);
    }
}

if (! function_exists('sms_send')) {
    /**
     * Queue an SMS through the sms plugin (Africa's Talking).
     *
     *   sms_send('+2547XXXXXXXX', 'Your order is ready for pickup');
     *   sms_send($phones, 'We are closed tomorrow for stock-taking', ['related' => $order], urgent: true);
     *
     * @return SmsLog|Collection<int, SmsLog>
     */
    function sms_send(string|array $to, string $message, array $context = [], bool $urgent = false): SmsLog|Collection
    {
        return app(SmsService::class)->send($to, $message, $context, $urgent);
    }
}

if (! function_exists('sms_send_template')) {
    /**
     * Render a template registered through the "sms_templates" filter and queue it.
     * Returns null when SMS is disabled or the template is switched off.
     */
    function sms_send_template(string|array $to, string $key, array $vars = [], array $context = [], ?bool $urgent = null): SmsLog|Collection|null
    {
        return app(SmsService::class)->sendTemplate($to, $key, $vars, $context, $urgent);
    }
}
