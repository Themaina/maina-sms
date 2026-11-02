<?php

use Botble\Base\Facades\AdminHelper;
use MainaSuperintelligence\Sms\Http\Controllers\DeliveryReportController;
use MainaSuperintelligence\Sms\Http\Controllers\SendSmsController;
use MainaSuperintelligence\Sms\Http\Controllers\Settings\SmsSettingController;
use MainaSuperintelligence\Sms\Http\Controllers\SmsLogController;
use Illuminate\Support\Facades\Route;

// Africa's Talking delivery reports. Public and CSRF-exempt; the token in the path is an
// HMAC derived from APP_KEY (see SmsSettings::deliveryToken()), anything else gets 403.
Route::post('sms/africastalking/delivery/{token}', [DeliveryReportController::class, 'store'])
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'App\Http\Middleware\VerifyCsrfToken',
    ])
    ->middleware('throttle:600,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('sms.delivery-report');

AdminHelper::registerRoutes(function (): void {
    Route::group(['prefix' => 'sms', 'as' => 'sms.'], function (): void {
        Route::match(['GET', 'POST'], 'logs', [SmsLogController::class, 'index'])->name('logs.index');

        Route::post('logs/{log}/resend', [SmsLogController::class, 'resend'])
            ->name('logs.resend')
            ->wherePrimaryKey('log');

        Route::delete('logs/{log}', [SmsLogController::class, 'destroy'])
            ->name('logs.destroy')
            ->wherePrimaryKey('log');

        Route::get('send', [SendSmsController::class, 'create'])->name('send');

        Route::post('send', [SendSmsController::class, 'store'])
            ->name('send.store')
            ->permission('sms.send');

        Route::get('widgets/stats', [SmsLogController::class, 'widget'])
            ->name('widgets.stats')
            ->permission('sms.logs.index');
    });

    Route::group(['prefix' => 'settings/sms', 'as' => 'sms.'], function (): void {
        Route::get('/', [SmsSettingController::class, 'edit'])->name('settings');

        Route::put('/', [SmsSettingController::class, 'update'])
            ->name('settings.update')
            ->permission('sms.settings');

        Route::post('test', [SmsSettingController::class, 'test'])
            ->name('settings.test')
            ->permission('sms.settings');

        Route::get('balance', [SmsSettingController::class, 'balance'])
            ->name('settings.balance')
            ->permission('sms.settings');
    });
});
