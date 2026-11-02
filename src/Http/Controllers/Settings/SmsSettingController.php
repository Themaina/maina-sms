<?php

namespace Maina888\Sms\Http\Controllers\Settings;

use Botble\Base\Http\Responses\BaseHttpResponse;
use Botble\Base\Supports\Breadcrumb;
use Botble\Setting\Http\Controllers\SettingController;
use Maina888\Sms\Enums\SmsStatusEnum;
use Maina888\Sms\Forms\SmsSettingForm;
use Maina888\Sms\Http\Requests\SmsSettingRequest;
use Maina888\Sms\Http\Requests\TestSmsRequest;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsSettings;
use Illuminate\Support\Facades\Cache;

class SmsSettingController extends SettingController
{
    protected function breadcrumb(): Breadcrumb
    {
        return parent::breadcrumb()
            ->add(trans('core/base::base.panel.others'));
    }

    public function edit()
    {
        $this->pageTitle(trans('plugins/sms::sms.settings.title'));

        return SmsSettingForm::create()->renderForm();
    }

    public function update(SmsSettingRequest $request, SmsService $sms): BaseHttpResponse
    {
        $data = $request->validated();

        // The API key is write-only: blank keeps the saved key, anything else is stored encrypted.
        $apiKey = trim((string) ($data['sms_api_key'] ?? ''));
        unset($data['sms_api_key']);

        if ($apiKey !== '') {
            $data[SmsSettings::API_KEY] = SmsSettings::encryptApiKey($apiKey);
        }

        $data['sms_default_country_code'] = ltrim((string) ($data['sms_default_country_code'] ?? ''), '+');
        $data['sms_timezone'] = trim((string) ($data['sms_timezone'] ?? ''));

        // A template saved unchanged stays "default", so later improvements to the default
        // text reach sites that never customised it.
        foreach ($sms->templates() as $key => $template) {
            $field = 'sms_template_' . $key;

            if (! array_key_exists($field, $data)) {
                continue;
            }

            $text = trim((string) $data[$field]);

            if ($this->normalise($text) === $this->normalise($template['default'])) {
                $text = '';
            }

            $data[$field] = $text;
        }

        Cache::forget(SmsService::BALANCE_CACHE_KEY);

        return $this->performUpdate($data);
    }

    public function test(TestSmsRequest $request, SmsService $sms): BaseHttpResponse
    {
        $phone = (string) $request->input('phone');

        if (! $sms->normalisePhone($phone)) {
            return $this
                ->httpResponse()
                ->setError()
                ->setMessage(trans('plugins/sms::sms.invalid_phone', ['phone' => $phone]));
        }

        $message = trim((string) $request->input('message')) ?: trans('plugins/sms::sms.settings.test_default_message', [
            'site' => $sms->globalVariables()['site_name'],
            'time' => now()->setTimezone(SmsSettings::timezone())->format('H:i'),
        ]);

        $log = $sms->send($phone, $message, ['source' => 'settings_test', 'user_id' => auth()->id()], true)->refresh();

        if ($log->isStatus(SmsStatusEnum::FAILED, SmsStatusEnum::REJECTED)) {
            return $this
                ->httpResponse()
                ->setError()
                ->setData(['status' => $log->statusValue(), 'log_id' => $log->getKey()])
                ->setMessage(trans('plugins/sms::sms.settings.test_failed', ['error' => $log->error]));
        }

        return $this
            ->httpResponse()
            ->setData(['status' => $log->statusValue(), 'log_id' => $log->getKey()])
            ->setMessage(trans('plugins/sms::sms.settings.test_result', [
                'to' => $log->to,
                'status' => $log->status->label(),
            ]));
    }

    public function balance(SmsService $sms): BaseHttpResponse
    {
        $balance = $sms->balance(true);

        return $this
            ->httpResponse()
            ->setError(! $balance)
            ->setData(['balance' => $balance])
            ->setMessage($balance
                ? trans('plugins/sms::sms.settings.balance_value', ['balance' => $balance])
                : trans('plugins/sms::sms.settings.balance_unavailable'));
    }

    protected function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }
}
