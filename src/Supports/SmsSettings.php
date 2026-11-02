<?php

namespace MainaSuperintelligence\Sms\Supports;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed access to the plugin's settings (stored in Botble's settings table, prefix "sms_").
 */
class SmsSettings
{
    public const API_KEY = 'sms_api_key';

    public static function enabled(): bool
    {
        return (bool) setting('sms_enabled', false);
    }

    public static function environment(): string
    {
        return setting('sms_environment', 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public static function isSandbox(): bool
    {
        return self::environment() === 'sandbox';
    }

    public static function username(): string
    {
        $username = trim((string) setting('sms_username', ''));

        if (self::isSandbox()) {
            return 'sandbox';
        }

        return $username;
    }

    public static function apiKey(): ?string
    {
        $stored = setting(self::API_KEY);

        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Value saved before encryption was introduced, or APP_KEY rotated.
            return null;
        }
    }

    public static function hasApiKey(): bool
    {
        return (bool) setting(self::API_KEY);
    }

    public static function encryptApiKey(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    public static function senderId(): ?string
    {
        $sender = trim((string) setting('sms_sender_id', ''));

        return $sender !== '' ? $sender : null;
    }

    /**
     * Calling codes of the countries Africa's Talking sends to, used when the setting is empty.
     */
    public const CALLING_CODES = [
        'KE' => '254', 'UG' => '256', 'TZ' => '255', 'RW' => '250', 'MW' => '265', 'ZM' => '260',
        'NG' => '234', 'GH' => '233', 'ET' => '251', 'CI' => '225', 'CM' => '237', 'SN' => '221',
        'ZA' => '27', 'BJ' => '229', 'BF' => '226', 'CD' => '243', 'BI' => '257', 'SS' => '211',
    ];

    /**
     * Country calling code added to local numbers (0XXX...). The setting wins; otherwise the
     * ecommerce store country is used when Africa's Talking serves it. Empty means numbers must
     * be written in international format.
     */
    public static function defaultCountryCode(): string
    {
        $code = preg_replace('/\D+/', '', (string) setting('sms_default_country_code', '')) ?: '';

        if ($code !== '') {
            return $code;
        }

        if (function_exists('get_ecommerce_setting') && function_exists('is_plugin_active') && is_plugin_active('ecommerce')) {
            $country = strtoupper((string) get_ecommerce_setting('store_country'));

            return self::CALLING_CODES[$country] ?? '';
        }

        return '';
    }

    public static function timezone(): string
    {
        $fallback = (string) config('app.timezone', 'UTC') ?: 'UTC';
        $timezone = (string) setting('sms_timezone', '') ?: $fallback;

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    public static function quietHours(): QuietHours
    {
        return new QuietHours(
            (string) setting('sms_quiet_hours_start', '21:00'),
            (string) setting('sms_quiet_hours_end', '07:00'),
            self::timezone(),
            (bool) setting('sms_quiet_hours_enabled', true),
        );
    }

    public static function baseUrl(): string
    {
        return self::isSandbox()
            ? 'https://api.sandbox.africastalking.com'
            : 'https://api.africastalking.com';
    }

    public static function templateEnabled(string $key, bool $default = true): bool
    {
        return (bool) setting('sms_template_' . $key . '_enabled', $default);
    }

    public static function templateText(string $key): ?string
    {
        $text = setting('sms_template_' . $key);

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * Token in the delivery report URL. Derived from APP_KEY so it never has to be stored,
     * and changes if the app key changes.
     */
    public static function deliveryToken(): string
    {
        return substr(hash_hmac('sha256', 'sms.africastalking.delivery-report', (string) config('app.key')), 0, 40);
    }

    public static function deliveryReportUrl(): string
    {
        return route('sms.delivery-report', ['token' => self::deliveryToken()]);
    }
}
