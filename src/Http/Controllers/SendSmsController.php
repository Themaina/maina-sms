<?php

namespace Maina888\Sms\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Http\Responses\BaseHttpResponse;
use Maina888\Sms\Enums\SmsStatusEnum;
use Maina888\Sms\Http\Requests\SendSmsRequest;
use Maina888\Sms\Services\SmsService;
use Maina888\Sms\Supports\SmsSettings;
use Maina888\Sms\Supports\SmsText;
use Illuminate\Support\Collection;

class SendSmsController extends BaseController
{
    public function create(SmsService $sms)
    {
        $this->pageTitle(trans('plugins/sms::sms.send.title'));

        return view('plugins/sms::send.index', [
            'customersCount' => $this->customersAvailable() ? $this->customerRecipients()->count() : null,
            'extraAudiences' => collect($this->extraAudiences())->map(fn (array $audience) => [
                'label' => $audience['label'],
                'count' => $audience['recipients']()->count(),
            ])->all(),
            'enabled' => SmsSettings::enabled(),
            'quietNow' => SmsSettings::quietHours()->isQuiet(),
        ]);
    }

    public function store(SendSmsRequest $request, SmsService $sms): BaseHttpResponse
    {
        $audience = $request->input('audience');

        $recipients = match ($audience) {
            'single' => collect([['phone' => (string) $request->input('phone'), 'name' => '']]),
            'customers' => $this->customersAvailable() ? $this->customerRecipients() : collect(),
            'csv' => $this->csvRecipients((string) $request->input('csv')),
            default => isset($this->extraAudiences()[$audience]) ? $this->extraAudiences()[$audience]['recipients']() : collect(),
        };

        // De-duplicate on the normalised number; invalid numbers are kept so they are logged.
        $recipients = $recipients
            ->unique(fn (array $row) => $sms->normalisePhone($row['phone']) ?? $row['phone'])
            ->values();

        if ($recipients->isEmpty()) {
            return $this
                ->httpResponse()
                ->setNextUrl(route('sms.send'))
                ->setError()
                ->withInput()
                ->setMessage(trans('plugins/sms::sms.send.no_recipients'));
        }

        $message = (string) $request->input('message');
        $urgent = $request->boolean('urgent');
        $context = ['source' => 'admin_send', 'audience' => $audience, 'user_id' => auth()->id()];
        $personalised = SmsText::placeholders($message) !== [];

        $logs = collect();

        if ($personalised) {
            foreach ($recipients as $row) {
                $text = SmsText::render($message, array_merge($sms->globalVariables(), [
                    'name' => $row['name'],
                    'first_name' => trim(explode(' ', trim($row['name']))[0] ?? ''),
                    'phone' => $row['phone'],
                ]));

                $logs->push($sms->send($row['phone'], $text, $context, $urgent));
            }
        } else {
            $logs = $sms->send($recipients->pluck('phone')->all(), $message, $context, $urgent);
        }

        $failed = $logs->filter(fn ($log) => $log->isStatus(SmsStatusEnum::FAILED, SmsStatusEnum::REJECTED))->count();
        $ok = $logs->count() - $failed;

        return $this
            ->httpResponse()
            ->setNextUrl($ok === 0 ? route('sms.send') : route('sms.logs.index'))
            ->withInput($ok === 0)
            ->setError($ok === 0)
            ->setMessage(trans('plugins/sms::sms.send.result', [
                'ok' => $ok,
                'failed' => $failed,
            ]));
    }

    protected function customersAvailable(): bool
    {
        return is_plugin_active('ecommerce') && class_exists(\Botble\Ecommerce\Models\Customer::class);
    }

    /**
     * @return Collection<int, array{phone: string, name: string}>
     */
    protected function customerRecipients(): Collection
    {
        return \Botble\Ecommerce\Models\Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select(['id', 'name', 'phone'])
            ->get()
            ->map(fn ($customer) => ['phone' => (string) $customer->phone, 'name' => (string) $customer->name]);
    }

    /**
     * Extra recipient groups registered by other plugins through the "sms_audiences" filter:
     *
     *   add_filter('sms_audiences', function (array $audiences) {
     *       $audiences['vip'] = [
     *           'label' => 'VIP customers',
     *           'recipients' => fn () => collect([['phone' => '...', 'name' => '...']]),
     *       ];
     *
     *       return $audiences;
     *   });
     *
     * @return array<string, array{label: string, recipients: callable(): Collection}>
     */
    public function extraAudiences(): array
    {
        $audiences = [];

        foreach ((array) apply_filters('sms_audiences', []) as $key => $audience) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]+$/', $key) || in_array($key, ['single', 'customers', 'csv'], true)) {
                continue;
            }

            if (! is_array($audience) || ! isset($audience['recipients']) || ! is_callable($audience['recipients'])) {
                continue;
            }

            $recipients = $audience['recipients'];

            $audiences[$key] = [
                'label' => (string) ($audience['label'] ?? $key),
                'recipients' => fn (): Collection => collect($recipients())
                    ->map(fn ($row) => ['phone' => (string) ($row['phone'] ?? ''), 'name' => (string) ($row['name'] ?? '')])
                    ->filter(fn (array $row) => $row['phone'] !== '')
                    ->values(),
            ];
        }

        return $audiences;
    }

    /**
     * Pasted CSV or one number per line. In each row the first cell that looks like a phone
     * number is the recipient and the first other non-empty cell is the name. Header rows and
     * rows without a number are skipped.
     *
     * @return Collection<int, array{phone: string, name: string}>
     */
    public function csvRecipients(string $csv): Collection
    {
        $rows = collect(preg_split('/\r\n|\r|\n/', $csv) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter();

        return $rows
            ->map(function (string $line) {
                $cells = array_map('trim', str_getcsv($line, str_contains($line, ';') && ! str_contains($line, ',') ? ';' : ','));

                $phone = null;
                $name = '';

                foreach ($cells as $cell) {
                    $digits = preg_replace('/\D+/', '', $cell);

                    if ($phone === null && strlen((string) $digits) >= 9 && preg_match('/^[+\d\s().-]+$/', $cell)) {
                        $phone = $cell;
                    } elseif ($name === '' && $cell !== '' && ! is_numeric($cell)) {
                        $name = $cell;
                    }
                }

                return $phone ? ['phone' => $phone, 'name' => $name] : null;
            })
            ->filter()
            ->values();
    }
}
