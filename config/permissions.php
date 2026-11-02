<?php

return [
    [
        'name' => 'SMS',
        'flag' => 'sms.logs.index',
    ],
    [
        'name' => 'Resend',
        'flag' => 'sms.logs.resend',
        'parent_flag' => 'sms.logs.index',
    ],
    [
        'name' => 'Delete',
        'flag' => 'sms.logs.destroy',
        'parent_flag' => 'sms.logs.index',
    ],
    [
        'name' => 'Send SMS',
        'flag' => 'sms.send',
        'parent_flag' => 'sms.logs.index',
    ],
    [
        'name' => 'SMS',
        'flag' => 'sms.settings',
        'parent_flag' => 'settings.others',
    ],
];
