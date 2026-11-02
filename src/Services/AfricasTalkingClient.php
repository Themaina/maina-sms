<?php

namespace Maina888\Sms\Services;

use Maina888\Sms\Supports\SmsSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for Africa's Talking messaging and user (balance) APIs.
 */
class AfricasTalkingClient
{
    /**
     * Status codes for a recipient that mean the message was accepted by the network.
     * 100 Processed, 101 Sent, 102 Queued.
     */
    public const ACCEPTED_CODES = [100, 101, 102];

    /**
     * Recipient status codes worth retrying: 500 InternalServerError, 501 GatewayError,
     * 502 RejectedByGateway.
     */
    public const TRANSIENT_CODES = [500, 501, 502];

    /**
     * Codes that mean the number itself was refused: 403 InvalidPhoneNumber,
     * 404 UnsupportedNumberType, 406 UserInBlacklist, 409 DoNotDisturbRejection.
     */
    public const REJECTED_CODES = [403, 404, 406, 409];

    public function __construct(
        protected ?string $username = null,
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
        protected ?string $senderId = null,
    ) {
        $this->username ??= SmsSettings::username();
        $this->apiKey ??= SmsSettings::apiKey();
        $this->baseUrl ??= SmsSettings::baseUrl();
        $this->senderId ??= SmsSettings::senderId();
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->username !== null && ! empty($this->apiKey);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'apiKey' => (string) $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->timeout(20)
            ->connectTimeout(10);
    }

    /**
     * Send one message to one recipient.
     *
     * Returns ['accepted' => bool, 'transient' => bool, 'rejected' => bool, 'status_code' => ?int,
     * 'status' => ?string, 'message_id' => ?string, 'cost' => ?float, 'currency' => ?string,
     * 'error' => ?string, 'raw' => array].
     *
     * Throws AfricasTalkingException for errors where the request should be retried later
     * (network failures, HTTP 5xx). Permanent errors (bad credentials, HTTP 4xx) are returned.
     */
    public function send(string $to, string $message): array
    {
        if (! $this->isConfigured()) {
            return $this->result(error: "Africa's Talking username or API key is not set.");
        }

        $payload = [
            'username' => $this->username,
            'to' => $to,
            'message' => $message,
        ];

        if ($this->senderId) {
            $payload['from'] = $this->senderId;
        }

        try {
            $response = $this->request()->asForm()->post('/version1/messaging', $payload);
        } catch (ConnectionException $exception) {
            throw new AfricasTalkingException('Connection to Africa\'s Talking failed: ' . $exception->getMessage(), true, $exception);
        }

        if ($response->serverError()) {
            throw new AfricasTalkingException(sprintf('Africa\'s Talking HTTP %s: %s', $response->status(), mb_substr(trim($response->body()), 0, 300)), true);
        }

        $json = $response->json();

        if ($response->clientError() || ! is_array($json)) {
            return $this->result(error: sprintf('HTTP %s: %s', $response->status(), mb_substr(trim($response->body()), 0, 300) ?: 'Unexpected response'));
        }

        $recipients = Arr::get($json, 'SMSMessageData.Recipients', []);
        $recipient = collect($recipients)->first() ?: null;

        if (! $recipient) {
            return $this->result(
                error: (string) (Arr::get($json, 'SMSMessageData.Message') ?: 'No recipients accepted'),
                raw: $json,
            );
        }

        $code = (int) Arr::get($recipient, 'statusCode');
        [$cost, $currency] = $this->parseCost((string) Arr::get($recipient, 'cost', ''));

        $accepted = in_array($code, self::ACCEPTED_CODES, true);

        return $this->result(
            accepted: $accepted,
            transient: in_array($code, self::TRANSIENT_CODES, true),
            rejected: in_array($code, self::REJECTED_CODES, true),
            statusCode: $code,
            status: Arr::get($recipient, 'status'),
            messageId: ($id = Arr::get($recipient, 'messageId')) && $id !== 'None' ? (string) $id : null,
            cost: $cost,
            currency: $currency,
            error: $accepted ? null : sprintf('%s (%s)', Arr::get($recipient, 'status', 'Error'), $code),
            raw: $json,
        );
    }

    /**
     * Account balance as returned by Africa's Talking, e.g. "KES 1234.5600".
     */
    public function balance(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request()->get('/version1/user', ['username' => $this->username]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $balance = $response->json('UserData.balance');

        return is_string($balance) && $balance !== '' ? $balance : null;
    }

    /**
     * "KES 0.8000" → [0.8, 'KES'].
     */
    public function parseCost(string $cost): array
    {
        if (preg_match('/^\s*([A-Z]{3})\s+([0-9]+(?:\.[0-9]+)?)\s*$/', $cost, $matches)) {
            return [(float) $matches[2], $matches[1]];
        }

        if (is_numeric(trim($cost))) {
            return [(float) trim($cost), null];
        }

        return [null, null];
    }

    protected function result(
        bool $accepted = false,
        bool $transient = false,
        bool $rejected = false,
        ?int $statusCode = null,
        ?string $status = null,
        ?string $messageId = null,
        ?float $cost = null,
        ?string $currency = null,
        ?string $error = null,
        array $raw = [],
    ): array {
        return [
            'accepted' => $accepted,
            'transient' => $transient,
            'rejected' => $rejected,
            'status_code' => $statusCode,
            'status' => $status,
            'message_id' => $messageId,
            'cost' => $cost,
            'currency' => $currency,
            'error' => $error,
            'raw' => $raw,
        ];
    }
}
