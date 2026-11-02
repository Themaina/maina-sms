<?php

namespace Maina888\Sms\Supports;

/**
 * Template rendering and segment counting. Pure PHP, unit tested.
 */
class SmsText
{
    /**
     * Replace {{var}} / {{ var }} placeholders. Unknown placeholders render as empty strings,
     * then repeated whitespace left behind is collapsed.
     */
    public static function render(string $template, array $vars): string
    {
        $rendered = preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', function (array $matches) use ($vars) {
            $value = $vars[$matches[1]] ?? null;

            if (is_bool($value)) {
                return $value ? 'yes' : 'no';
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                return (string) $value;
            }

            return '';
        }, $template) ?? $template;

        $rendered = preg_replace('/[ \t]{2,}/', ' ', $rendered) ?? $rendered;
        $rendered = preg_replace('/ +([,.!?])/', '$1', $rendered) ?? $rendered;

        return trim($rendered);
    }

    /**
     * Placeholder names used in a template.
     */
    public static function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function isGsm7(string $text): bool
    {
        $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà"
            . '^{}\\[~]|€';

        foreach (mb_str_split($text) as $char) {
            if (! str_contains($gsm, $char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Number of SMS parts: GSM-7 160 single / 153 per part, UCS-2 70 / 67.
     * GSM extension characters (^{}\[~]|€) count double.
     */
    public static function segments(string $text): int
    {
        if ($text === '') {
            return 1;
        }

        if (self::isGsm7($text)) {
            $length = 0;

            foreach (mb_str_split($text) as $char) {
                $length += str_contains('^{}\\[~]|€', $char) ? 2 : 1;
            }

            return $length <= 160 ? 1 : (int) ceil($length / 153);
        }

        $length = mb_strlen($text);

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }
}
