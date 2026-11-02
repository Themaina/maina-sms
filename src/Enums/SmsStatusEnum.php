<?php

namespace Maina888\Sms\Enums;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Enum;
use Illuminate\Support\HtmlString;

/**
 * @method static SmsStatusEnum QUEUED()
 * @method static SmsStatusEnum SENT()
 * @method static SmsStatusEnum DELIVERED()
 * @method static SmsStatusEnum FAILED()
 * @method static SmsStatusEnum REJECTED()
 */
class SmsStatusEnum extends Enum
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public static $langPath = 'plugins/sms::sms.statuses';

    public function toHtml(): HtmlString|string
    {
        $color = match ($this->value) {
            self::QUEUED => 'secondary',
            self::SENT => 'info',
            self::DELIVERED => 'success',
            self::FAILED, self::REJECTED => 'danger',
            default => 'primary',
        };

        return BaseHelper::renderBadge($this->label(), $color);
    }

    /**
     * Statuses that count as a successful hand-off to the network.
     */
    public static function successful(): array
    {
        return [self::SENT, self::DELIVERED];
    }

    public static function unsuccessful(): array
    {
        return [self::FAILED, self::REJECTED];
    }
}
