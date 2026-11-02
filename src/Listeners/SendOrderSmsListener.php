<?php

namespace MainaSuperintelligence\Sms\Listeners;

use Botble\Base\Facades\BaseHelper;
use Botble\Ecommerce\Events\OrderCompletedEvent;
use Botble\Ecommerce\Events\OrderConfirmedEvent;
use Botble\Ecommerce\Events\OrderPaymentConfirmedEvent;
use Botble\Ecommerce\Events\OrderPlacedEvent;
use Botble\Ecommerce\Models\Order;
use MainaSuperintelligence\Sms\Models\SmsLog;
use MainaSuperintelligence\Sms\Services\SmsService;
use Throwable;

/**
 * Built-in ecommerce order notifications. One SMS per (order, template): repeated events are
 * ignored. Errors are logged, never thrown into the checkout flow.
 */
class SendOrderSmsListener
{
    public function __construct(protected SmsService $sms)
    {
    }

    public function handle(object $event): void
    {
        if (! isset($event->order) || ! $event->order instanceof Order) {
            return;
        }

        $key = match (true) {
            $event instanceof OrderPlacedEvent => $this->placedTemplate($event->order),
            $event instanceof OrderConfirmedEvent => 'order_confirmed',
            $event instanceof OrderPaymentConfirmedEvent => 'order_payment_received',
            $event instanceof OrderCompletedEvent => 'order_completed',
            default => null,
        };

        if ($key) {
            $this->notify($event->order, $key);
        }
    }

    /**
     * An order paid online at checkout gets "payment received" instead of "order placed",
     * so the customer is not sent two messages for one purchase.
     */
    protected function placedTemplate(Order $order): string
    {
        $paid = (string) ($order->payment?->status ?? '') === 'completed';

        return $paid && $this->sms->templateEnabled('order_payment_received')
            ? 'order_payment_received'
            : 'order_placed';
    }

    public function notify(Order $order, string $key): ?SmsLog
    {
        try {
            if (! $this->sms->enabled() || ! $this->sms->templateEnabled($key)) {
                return null;
            }

            $alreadySent = SmsLog::query()
                ->where('template_key', $key)
                ->where('related_type', $order->getMorphClass())
                ->where('related_id', $order->getKey())
                ->exists();

            if ($alreadySent) {
                return null;
            }

            $phone = $order->address?->phone ?: $order->user?->phone ?: $order->shippingAddress?->phone;

            if (! $phone) {
                return null;
            }

            $log = $this->sms->sendTemplate($phone, $key, $this->variables($order), [
                'related' => $order,
                'event' => $key,
            ]);

            return $log instanceof SmsLog ? $log : null;
        } catch (Throwable $exception) {
            BaseHelper::logError($exception);

            return null;
        }
    }

    public function variables(Order $order): array
    {
        $name = $order->address?->name ?: $order->user?->name ?: '';

        return [
            'customer_name' => $name,
            'first_name' => trim(explode(' ', trim($name))[0] ?? ''),
            'order_code' => (string) $order->code,
            'amount' => function_exists('format_price') ? strip_tags((string) format_price($order->amount)) : (string) $order->amount,
            'payment_method' => $this->paymentMethod($order),
            'order_status' => (string) ($order->status?->label() ?? ''),
        ];
    }

    protected function paymentMethod(Order $order): string
    {
        $channel = $order->payment?->payment_channel;

        if (! $channel) {
            return '';
        }

        return (string) (method_exists($channel, 'displayName') ? $channel->displayName() : $channel->label());
    }

    public static function templates(): array
    {
        $variables = [
            'customer_name' => 'Customer name',
            'first_name' => 'Customer first name',
            'order_code' => 'Order code, e.g. #10000012',
            'amount' => 'Order total with currency',
            'payment_method' => 'Payment method',
            'order_status' => 'Order status',
            'site_name' => 'Site name',
        ];

        return [
            'order_placed' => [
                'label' => 'Order placed',
                'group' => 'Ecommerce',
                'default' => 'Hi {{first_name}}, we have received your order {{order_code}} for {{amount}}. Thank you for choosing {{site_name}}.',
                'variables' => $variables,
                'urgent' => true,
                'enabled' => true,
            ],
            'order_confirmed' => [
                'label' => 'Order confirmed',
                'group' => 'Ecommerce',
                'default' => 'Hi {{first_name}}, your order {{order_code}} has been confirmed. {{site_name}}',
                'variables' => $variables,
                'urgent' => true,
                'enabled' => false,
            ],
            'order_payment_received' => [
                'label' => 'Order payment received',
                'group' => 'Ecommerce',
                'default' => 'Hi {{first_name}}, we have received your payment of {{amount}} for order {{order_code}}. Thank you! {{site_name}}',
                'variables' => $variables,
                'urgent' => true,
                'enabled' => true,
            ],
            'order_completed' => [
                'label' => 'Order completed',
                'group' => 'Ecommerce',
                'default' => 'Hi {{first_name}}, your order {{order_code}} is complete. Thank you for choosing {{site_name}}.',
                'variables' => $variables,
                'urgent' => true,
                'enabled' => false,
            ],
        ];
    }
}
