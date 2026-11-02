<?php

namespace Maina888\Sms\Events;

use Botble\Base\Events\Event;
use Maina888\Sms\Models\SmsLog;
use Illuminate\Queue\SerializesModels;

class SmsFailed extends Event
{
    use SerializesModels;

    public function __construct(public SmsLog $log, public ?string $reason = null)
    {
    }
}
