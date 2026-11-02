<?php

namespace Maina888\Sms\Http\Requests;

use Botble\Base\Rules\OnOffRule;
use Maina888\Sms\Services\SmsService;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class SmsSettingRequest extends Request
{
    public function rules(): array
    {
        $rules = [
            'sms_enabled' => [new OnOffRule()],
            'sms_environment' => ['required', Rule::in(['sandbox', 'live'])],
            'sms_username' => ['nullable', 'string', 'max:120'],
            'sms_api_key' => ['nullable', 'string', 'max:255'],
            'sms_sender_id' => ['nullable', 'string', 'max:20'],
            'sms_default_country_code' => ['nullable', 'regex:/^\+?\d{1,4}$/'],
            'sms_quiet_hours_enabled' => [new OnOffRule()],
            'sms_quiet_hours_start' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sms_quiet_hours_end' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'sms_timezone' => ['nullable', 'timezone:all'],
        ];

        foreach (array_keys(app(SmsService::class)->templates()) as $key) {
            $rules['sms_template_' . $key . '_enabled'] = [new OnOffRule()];
            $rules['sms_template_' . $key] = ['nullable', 'string', 'max:918'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'sms_default_country_code' => trans('plugins/sms::sms.settings.default_country_code'),
            'sms_quiet_hours_start' => trans('plugins/sms::sms.settings.quiet_hours_start'),
            'sms_quiet_hours_end' => trans('plugins/sms::sms.settings.quiet_hours_end'),
        ];
    }
}
