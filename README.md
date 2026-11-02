# SMS plugin (Africa's Talking)

Every NattyGym plugin sends SMS through this plugin. Nothing else talks to Africa's Talking.

## Setup

1. `php artisan cms:plugin:activate sms` (runs the `sms_logs` migration).
2. Admin > Settings > SMS: enable, choose Sandbox or Live, username, API key (stored encrypted
   with APP_KEY, write-only), optional sender ID, country code (254), quiet hours.
3. Copy the "Delivery reports callback URL" from the settings page into the Africa's Talking
   dashboard (SMS > SMS Callback URLs > Delivery Reports).
4. Production: run a queue worker (`php artisan queue:work`) and the scheduler
   (`* * * * * php artisan schedule:run`). `sms:send-scheduled` runs every minute and releases
   messages held by quiet hours. On the `sync` queue driver messages are sent inline and a
   transient failure is marked failed at once (resend from SMS logs); a real queue retries
   4 times with 60 s / 5 min / 15 min backoff.

## Public API

```php
use MainaSuperintelligence\Sms\Services\SmsService;

$sms = app(SmsService::class);

// One recipient -> SmsLog. Many -> Collection<SmsLog>. Numbers are normalised to +2547XXXXXXXX.
$log = $sms->send('0712 345 678', 'Your class starts at 6pm', ['related' => $membership]);
$logs = $sms->send(['0712...', '0722...'], 'Gym closed tomorrow', [], urgent: true);

// Registered template, rendered with {{var}} placeholders. Returns null when SMS is disabled
// or the admin switched that template off.
$sms->sendTemplate($phone, 'membership_expiring', ['name' => 'Jane', 'plan' => 'Monthly', 'days' => 3],
    ['related' => $membership]);

$sms->renderTemplate('membership_expiring', $vars); // string
$sms->normalisePhone('0712345678');                // '+254712345678' or null
$sms->balance();                                   // 'KES 1234.5600' or null (cached 5 min)
$sms->enabled();                                   // bool

// Helpers
sms_send($to, $message, $context = [], $urgent = false);
sms_send_template($to, $key, $vars = [], $context = [], $urgent = null);
```

Context: `related` (an Eloquent model, stored as the `related` morph) and `template_key` are
pulled out; everything else is saved as JSON on the log.

`$urgent = false` (default for `send`) means the message waits if it is created during quiet
hours (21:00 to 07:00 Africa/Nairobi by default). `sendTemplate` uses the template's own
`urgent` flag unless you pass one.

### Registering templates

```php
add_filter('sms_templates', function (array $templates): array {
    $templates['membership_expiring'] = [
        'label' => 'Membership expiring',
        'group' => 'Gym',
        'default' => 'Hi {{name}}, your {{plan}} membership ends in {{days}} days. Renew at {{site_url}}',
        'variables' => ['name' => 'Member name', 'plan' => 'Plan', 'days' => 'Days left'],
        'urgent' => false,   // waits for quiet hours to end
        'enabled' => true,   // default state of the admin toggle
    ];

    return $templates;
});
```

Each template gets a toggle and an editable text box on Settings > SMS. `{{site_name}}` and
`{{site_url}}` are always available.

Built-in ecommerce templates: `order_placed` (on), `order_confirmed` (off), `order_payment_received`
(on), `order_completed` (off), hooked to `OrderPlacedEvent`, `OrderConfirmedEvent`,
`OrderPaymentConfirmedEvent`, `OrderCompletedEvent`. An order already paid online at checkout gets
`order_payment_received` instead of `order_placed`. Each (order, template) is sent once.

### Events

- `MainaSuperintelligence\Sms\Events\SmsSent` (`$log`) when Africa's Talking accepts the message.
- `MainaSuperintelligence\Sms\Events\SmsFailed` (`$log`, `$reason`) on send failure, rejection, invalid number,
  disabled SMS, or a Failed/Rejected delivery report.
- `MainaSuperintelligence\Sms\Events\SmsDelivered` (`$log`) on a Success delivery report.

## Table `sms_logs`

id, to, message, template_key, status (queued, sent, delivered, failed, rejected), provider,
provider_message_id, cost, currency, segments, error, context (json), related_type/related_id,
urgent, attempts, send_after, sent_at, delivered_at, timestamps.

## Routes

- `POST /sms/africastalking/delivery/{token}` public, CSRF-exempt; token = first 40 hex chars of
  HMAC-SHA256(APP_KEY, "sms.africastalking.delivery-report"); anything else gets 403.
- Admin: `sms/logs` (filters, resend, delete), `sms/send` (one number, all customers, active gym
  members when the gym plugin is active, pasted CSV), `settings/sms` (+ `test`, `balance`),
  dashboard widget `sms/widgets/stats`.

## Tests

`tests/Unit` (phone normalisation, quiet hours, template rendering, segments) are plain PHPUnit.
`tests/Feature` boots the app against the `.env` database, wraps each test in a rolled-back
transaction and fakes Africa's Talking: sending, delivery-report token and status updates, quiet
hours hold/release, and the `sms_templates` contract with the gym and mpesa plugins.

```
vendor/bin/phpunit -c platform/plugins/sms/phpunit.xml      # from botble/
```

Dev dependencies must be installed (`composer install` run with PHP 8.4, since
phpoffice/phpspreadsheet does not allow PHP 8.5 yet).
