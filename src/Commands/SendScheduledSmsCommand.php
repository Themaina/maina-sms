<?php

namespace Maina888\Sms\Commands;

use Maina888\Sms\Services\SmsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('sms:send-scheduled', 'Dispatch SMS that waited for quiet hours to end')]
class SendScheduledSmsCommand extends Command
{
    public function handle(SmsService $sms): int
    {
        $count = $sms->dispatchDue();

        $this->components->info(sprintf('Dispatched %d scheduled SMS.', $count));

        return self::SUCCESS;
    }
}
