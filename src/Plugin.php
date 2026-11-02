<?php

namespace Maina888\Sms;

use Botble\PluginManagement\Abstracts\PluginOperationAbstract;
use Botble\Setting\Models\Setting;
use Illuminate\Support\Facades\Schema;

class Plugin extends PluginOperationAbstract
{
    public static function remove(): void
    {
        Schema::dropIfExists('sms_logs');

        Setting::query()->where('key', 'LIKE', 'sms\_%')->delete();
    }
}
