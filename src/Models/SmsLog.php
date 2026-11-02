<?php

namespace Maina888\Sms\Models;

use Botble\Base\Models\BaseModel;
use Maina888\Sms\Enums\SmsStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $to
 * @property string $message
 * @property string|null $template_key
 * @property SmsStatusEnum $status
 * @property string|null $provider_message_id
 * @property float|null $cost
 * @property string|null $currency
 * @property int $segments
 * @property string|null $error
 * @property array|null $context
 * @property bool $urgent
 * @property int $attempts
 */
class SmsLog extends BaseModel
{
    protected $table = 'sms_logs';

    protected $fillable = [
        'to',
        'message',
        'template_key',
        'status',
        'provider',
        'provider_message_id',
        'cost',
        'currency',
        'segments',
        'error',
        'context',
        'related_type',
        'related_id',
        'urgent',
        'attempts',
        'send_after',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'status' => SmsStatusEnum::class,
        'context' => 'array',
        'cost' => 'float',
        'segments' => 'int',
        'urgent' => 'bool',
        'attempts' => 'int',
        'send_after' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function statusValue(): string
    {
        return (string) ($this->status instanceof SmsStatusEnum ? $this->status->getValue() : $this->status);
    }

    public function isStatus(string ...$statuses): bool
    {
        return in_array($this->statusValue(), $statuses, true);
    }

    public function scopeStatusIn(Builder $query, string|array $statuses): Builder
    {
        return $query->whereIn('status', (array) $statuses);
    }
}
