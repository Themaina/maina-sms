<?php

namespace MainaSuperintelligence\Sms\Events;

use Botble\Base\Events\Event;
use MainaSuperintelligence\Sms\Models\SmsLog;
use Illuminate\Queue\SerializesModels;

class SmsSent extends Event
{
    use SerializesModels;

    public function __construct(public SmsLog $log)
    {
    }
}
