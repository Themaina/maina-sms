<?php

namespace MainaSuperintelligence\Sms\Http\Requests;

use Botble\Support\Http\Requests\Request;

class TestSmsRequest extends Request
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:918'],
        ];
    }
}
