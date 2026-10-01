<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\MidtransClient;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notifikasi HTTP dari Midtrans (Payment Notification URL di dashboard Midtrans).
 * Tanpa login dan tanpa CSRF; keaslian dicek lewat signature_key.
 */
class MidtransNotificationController extends Controller
{
    public function __invoke(Request $request, MidtransClient $midtrans, PaymentService $payments): JsonResponse
    {
        $data = $request->all();

        if (! $midtrans->isValidSignature($data)) {
            Log::warning('Midtrans: signature tidak valid', ['order_id' => $data['order_id'] ?? null, 'ip' => $request->ip()]);

            return response()->json(['message' => 'invalid signature'], 403);
        }

        $payment = Payment::where('order_id', $data['order_id'])->first();
        if (! $payment) {
            // Order bukan dari aplikasi ini (mis. tes dari dashboard). Balas 200 agar Midtrans tidak mengulang.
            return response()->json(['message' => 'order not found, ignored']);
        }

        $payments->apply($payment, $data);

        return response()->json(['message' => 'ok', 'status' => $payment->fresh()->status->value]);
    }
}
