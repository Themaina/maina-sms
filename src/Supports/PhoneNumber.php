<?php

namespace Maina888\Sms\Supports;

/**
 * Normalises phone numbers to E.164 ("+<country code><number>").
 *
 * Local numbers ("07XX XXX XXX") get the default country code. Kenyan numbers (254) are
 * validated strictly: only the mobile ranges 7XX and 1XX are accepted. Other numbers are
 * accepted when they have 8 to 15 digits. With an empty default country code, local numbers
 * (starting with 0) are rejected and numbers must be written in international format.
 *
 * Pure PHP so it can be unit tested without booting the app.
 */
class PhoneNumber
{
    public static function normalise(?string $phone, string $defaultCountryCode = ''): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $defaultCountryCode = preg_replace('/\D+/', '', $defaultCountryCode) ?: '';

        $hasPlus = str_starts_with($phone, '+');

        // Drop everything that is not a digit: spaces, dashes, dots, brackets, a leading "+".
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus) {
            $international = $digits;
        } elseif ($defaultCountryCode === '') {
            // No default country: only full international numbers can be understood.
            if (str_starts_with($digits, '0') || strlen($digits) < 10) {
                return null;
            }

            $international = $digits;
        } elseif (str_starts_with($digits, $defaultCountryCode)
            && strlen($digits) > strlen($defaultCountryCode) + 6) {
            $international = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $international = $defaultCountryCode . substr($digits, 1);
        } else {
            $international = $defaultCountryCode . $digits;
        }

        // Kenya: "+254 07XX..." is a common typo; drop the trunk zero after the country code.
        if (str_starts_with($international, '2540')) {
            $international = '254' . substr($international, 4);
        }

        if (str_starts_with($international, '254')) {
            return preg_match('/^254[17]\d{8}$/', $international) ? '+' . $international : null;
        }

        if (preg_match('/^[1-9]\d{7,14}$/', $international)) {
            return '+' . $international;
        }

        return null;
    }
}
