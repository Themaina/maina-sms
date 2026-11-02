<?php

namespace Maina888\Sms\Http\Requests;

use Botble\Support\Http\Requests\Request;
use Maina888\Sms\Http\Controllers\SendSmsController;
use Illuminate\Validation\Rule;

class SendSmsRequest extends Request
{
    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::in(array_merge(['single', 'customers', 'csv'], array_keys(app(SendSmsController::class)->extraAudiences())))],
            'phone' => ['required_if:audience,single', 'nullable', 'string', 'max:32'],
            'csv' => ['required_if:audience,csv', 'nullable', 'string', 'max:200000'],
            'message' => ['required', 'string', 'max:918'],
            'urgent' => ['nullable', 'boolean'],
        ];
    }
}
