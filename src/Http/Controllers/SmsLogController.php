<?php

namespace Maina888\Sms\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Maina888\Sms\Enums\SmsStatusEnum;
use Maina888\Sms\Models\SmsLog;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsSettings;
use Maina888\Sms\Tables\SmsLogTable;

class SmsLogController extends BaseController
{
    public function index(SmsLogTable $table)
    {
        $this->pageTitle(trans('plugins/sms::sms.logs.title'));

        return $table->renderTable();
    }

    public function resend(SmsLog $log, SmsService $sms): BaseHttpResponse
    {
        $copy = $sms->resend($log);

        if ($copy->isStatus(SmsStatusEnum::FAILED, SmsStatusEnum::REJECTED)) {
            return $this
                ->httpResponse()
                ->setError()
                ->setMessage(trans('plugins/sms::sms.logs.resend_failed', ['error' => $copy->error]));
        }

        return $this
            ->httpResponse()
            ->setMessage(trans('plugins/sms::sms.logs.resent', ['to' => $copy->to]));
    }

    public function destroy(SmsLog $log)
    {
        return DeleteResourceAction::make($log);
    }

    public function widget(SmsService $sms): BaseHttpResponse
    {
        $today = now()->startOfDay();

        $sentToday = SmsLog::query()
            ->whereIn('status', SmsStatusEnum::successful())
            ->where('created_at', '>=', $today)
            ->count();

        $failedToday = SmsLog::query()
            ->whereIn('status', SmsStatusEnum::unsuccessful())
            ->where('created_at', '>=', $today)
            ->count();

        $queued = SmsLog::query()->where('status', SmsStatusEnum::QUEUED)->count();

        $costToday = (float) SmsLog::query()
            ->where('created_at', '>=', $today)
            ->sum('cost');

        $balance = SmsSettings::hasApiKey() ? $sms->balance() : null;

        return $this
            ->httpResponse()
            ->setData(view('plugins/sms::widgets.stats', [
                'sentToday' => $sentToday,
                'failedToday' => $failedToday,
                'queued' => $queued,
                'costToday' => $costToday,
                'balance' => $balance,
                'enabled' => SmsSettings::enabled(),
            ])->render());
    }
}
