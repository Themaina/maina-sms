<?php

namespace MainaSuperintelligence\Sms\Http\Controllers;

use MainaSuperintelligence\Sms\Services\SmsService;
use MainaSuperintelligence\Sms\Supports\SmsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Receives Africa's Talking delivery reports (form fields id, status, phoneNumber,
 * networkCode, failureReason, retryCount). Set the URL shown on Settings > SMS as the
 * delivery reports callback in the Africa's Talking dashboard.
 */
class DeliveryReportController extends Controller
{
    public function store(string $token, Request $request, SmsService $sms): JsonResponse
    {
        abort_unless(hash_equals(SmsSettings::deliveryToken(), $token), 403);

        $log = $sms->handleDeliveryReport($request->only([
            'id',
            'status',
            'phoneNumber',
            'networkCode',
            'failureReason',
            'retryCount',
        ]));

        // Always 200 for a valid token: Africa's Talking retries non-2xx responses, and an
        // unknown id (e.g. a message sent from the AT dashboard) will never become known.
        return response()->json([
            'ok' => true,
            'matched' => (bool) $log,
            'status' => $log?->statusValue(),
        ]);
    }
}
