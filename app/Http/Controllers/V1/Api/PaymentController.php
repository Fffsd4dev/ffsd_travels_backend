<?php
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Models\FlightSearchSession;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class PaymentController extends Controller
{
    private const PAYSTACK_INITIALIZE_URL =
        'https://api.paystack.co/transaction/initialize';

    private const PAYSTACK_VERIFY_URL =
        'https://api.paystack.co/transaction/verify/';

    /**
     * Initiate payment using the confirmed flight session.
     *
     * Required headers:
     *   ama-client-ref: FFSD-000001
     *   Idempotency-Key: UUID saved against the confirmed session
     *
     * The payable amount and currency are read from the database,
     * not from the request body.
     */
    public function initiatePay(Request $request): JsonResponse
    {
    
        $validator = Validator::make($request->all(), [
            'paid_by_email' => ['required', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'flight_order_id' => [
                'nullable',
                'integer',
                'exists:flight_orders,id',
            ],
            'paid_by_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
            ],
            'paid_by_company_id' => [
                'nullable',
                'integer',
                'exists:companies,id',
            ],
            'flight_pnr' => ['nullable', 'string', 'max:255'],
            'flight_am_order_id' => ['nullable', 'string', 'max:255'],
            'wallet_id' => [
                'nullable',
                'integer',
                'exists:wallets,id',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $amaClientRef = trim(
            (string) $request->header('ama-client-ref', '')
        );

        $idempotencyKey = trim(
            (string) $request->header('idempotency-key', '')
        );

        if ($amaClientRef === '' || ! Str::isUuid($idempotencyKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Valid ama-client-ref and Idempotency-Key headers are required.',
            ], 422);
        }

        $secretKey = config('services.paystack.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            Log::error('Paystack secret key is not configured.');

            return response()->json([
                'success' => false,
                'message' => 'Payment service is not configured.',
            ], 500);
        }

        $data = $validator->validated();

        /*
         * Lock the session while checking it and creating the payment.
         * The database unique constraint on payments.idempotency_key
         * should also be present to protect against duplicate records.
         */
        try {
            $result = DB::transaction(function () use (
                $amaClientRef,
                $idempotencyKey,
                $data
            ) {
                $session = FlightSearchSession::query()
                    ->where('ama_client_ref', $amaClientRef)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    return [
                        'error' => 'Flight search session was not found.',
                        'status' => 404,
                    ];
                }

                if (
                    ! is_string($session->idempotency_key) ||
                    ! hash_equals(
                        $session->idempotency_key,
                        $idempotencyKey
                    )
                ) {
                    return [
                        'error' => 'The idempotency key does not match the confirmed flight session.',
                        'status' => 409,
                    ];
                }

                // Enforce ownership for authenticated, user-owned sessions.
                if (
                    auth()->check() &&
                    $session->user_id !== null &&
                    (int) $session->user_id !== (int) auth()->id()
                ) {
                    return [
                        'error' => 'You are not authorized to pay for this flight session.',
                        'status' => 403,
                    ];
                }

                if ($session->status !== 'confirmed') {
                    return [
                        'error' => 'The flight session is not confirmed.',
                        'status' => 409,
                    ];
                }

                if (
                    ! $session->expires_at ||
                    $session->expires_at->isPast()
                ) {
                    return [
                        'error' => 'The confirmed flight session has expired. Please search for the flight again.',
                        'status' => 410,
                    ];
                }

                $amount = $session->total_payable;
                $currency = strtoupper(
                    (string) $session->flight_currency
                );

                if (
                    ! is_numeric($amount) ||
                    (float) $amount <= 0 ||
                    $currency === ''
                ) {
                    return [
                        'error' => 'The confirmed flight amount or currency is missing.',
                        'status' => 422,
                    ];
                }

                /*
                 * A payment is unique to the confirmed session's
                 * idempotency key. Never create a new reference on retry.
                 */
                $payment = Payment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($payment) {
                    if (
                        (int) $payment->flight_search_session_id !==
                            (int) $session->id ||
                        $payment->ama_client_ref !== $amaClientRef
                    ) {
                        return [
                            'error' => 'The idempotency key is already associated with another payment.',
                            'status' => 409,
                        ];
                    }

                    if ($payment->payment_confirmed) {
                        return [
                            'payment' => $payment,
                            'already_confirmed' => true,
                        ];
                    }

                    if (
                        ! empty($payment->authorization_url) &&
                        ! empty($payment->access_code)
                    ) {
                        return [
                            'payment' => $payment,
                            'existing_link' => true,
                        ];
                    }

                    /*
                     * Do not blindly initialize another transaction if
                     * the earlier Paystack request may have succeeded.
                     */
                    return [
                        'error' => 'A payment initialization is already in progress or requires reconciliation. Please retry verification or contact support.',
                        'status' => 409,
                    ];
                }

                $payment = Payment::create([
                    'amount' => $amount,
                    'currency' => $currency,
                    'user_id' => $data['user_id'] ?? $session->user_id,
                    'paid_by_email' => $data['paid_by_email'],
                    'payment_reference' => $this->generateReference(),
                    'payment_status' => 'initiating',
                    'payment_confirmed' => false,
                    'flight_pnr' => $data['flight_pnr'] ?? null,
                    'flight_order_id' => $data['flight_order_id'] ?? null,
                    'flight_am_order_id' =>
                        $data['flight_am_order_id'] ?? null,
                    'paid_by_user_id' =>
                        $data['paid_by_user_id'] ?? null,
                    'paid_by_company_id' =>
                        $data['paid_by_company_id'] ?? null,
                    'wallet_id' => $data['wallet_id'] ?? null,
                    'ama_client_ref' => $amaClientRef,
                    'flight_search_session_id' => $session->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return [
                    'payment' => $payment,
                    'initialize' => true,
                ];
            }, 3);
        } catch (Throwable $e) {
            Log::error('Could not prepare Paystack payment.', [
                'ama_client_ref' => $amaClientRef,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not prepare the payment. Please try again.',
            ], 500);
        }

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], $result['status']);
        }

        /** @var Payment $payment */
        $payment = $result['payment'];

        if (! empty($result['already_confirmed'])) {
            return response()->json([
                'success' => true,
                'message' => 'This payment has already been confirmed.',
                'payment' => $payment,
            ]);
        }

        if (! empty($result['existing_link'])) {
            return response()->json([
                'success' => true,
                'message' => 'Existing payment initialization returned.',
                'payment' => $payment,
                'payment_link' => $payment->authorization_url,
                'reference' => $payment->payment_reference,
                'access_code' => $payment->access_code,
            ]);
        }

        /*
         * Paystack expects the amount in the currency's minor unit.
         * The helper below handles currencies with 0, 2, or 3 decimals.
         */
        try {
            $minorAmount = $this->toMinorUnits(
                $payment->amount,
                $payment->currency
            );

            $response = Http::withToken($secretKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::PAYSTACK_INITIALIZE_URL, [
                    'email' => $payment->paid_by_email,
                    'amount' => $minorAmount,
                    'currency' => $payment->currency,
                    'reference' => $payment->payment_reference,
                    'metadata' => [
                        'ama_client_ref' => $payment->ama_client_ref,
                        'idempotency_key' => $payment->idempotency_key,
                        'payment_id' => $payment->id,
                    ],
                ]);

            $payload = $response->json();

            if (
                $response->successful() &&
                is_array($payload) &&
                ($payload['status'] ?? false) === true &&
                ! empty($payload['data']['authorization_url']) &&
                ! empty($payload['data']['access_code']) &&
                ($payload['data']['reference'] ?? null) ===
                    $payment->payment_reference
            ) {
                $payment->authorization_url =
                    $payload['data']['authorization_url'];

                $payment->access_code =
                    $payload['data']['access_code'];

                $payment->payment_status = 'not_confirmed';
                $payment->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Payment initiated successfully.',
                    'payment' => $payment->fresh(),
                    'payment_link' => $payment->authorization_url,
                    'reference' => $payment->payment_reference,
                    'access_code' => $payment->access_code,
                ]);
            }

            /*
             * Keep the record for reconciliation. Do not delete it and
             * create a new reference when provider outcome is uncertain.
             */
            $payment->payment_status = 'initialization_uncertain';
            $payment->save();

            Log::warning('Paystack initialization was not confirmed.', [
                'payment_id' => $payment->id,
                'reference' => $payment->payment_reference,
                'http_status' => $response->status(),
                'provider_message' => is_array($payload)
                    ? ($payload['message'] ?? null)
                    : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The payment initialization could not be confirmed. Please check the payment status before trying again.',
                'reference' => $payment->payment_reference,
            ], 502);
        } catch (Throwable $e) {
            /*
             * A timeout may happen after Paystack has received the
             * request. Keep the reference so it can be reconciled.
             */
            $payment->payment_status = 'initialization_uncertain';
            $payment->save();

            Log::error('Paystack initialization request failed.', [
                'payment_id' => $payment->id,
                'reference' => $payment->payment_reference,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment initialization could not be confirmed. Please check the transaction before retrying.',
                'reference' => $payment->payment_reference,
            ], 502);
        }
    }

    /**
     * Verify a Paystack transaction using the stored payment record.
     * Never trust an amount supplied by the client.
     */
    public function verifyTransaction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $reference = $validator->validated()['reference'];

        $payment = Payment::query()
            ->where('payment_reference', $reference)
            ->first();

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment reference was not found.',
            ], 404);
        }

        if ($payment->payment_confirmed) {
            return response()->json([
                'success' => true,
                'message' => 'Payment has already been confirmed.',
                'payment' => $payment,
            ]);
        }

        $secretKey = config('services.paystack.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            Log::error('Paystack secret key is not configured.');

            return response()->json([
                'success' => false,
                'message' => 'Payment service is not configured.',
            ], 500);
        }

        try {
            $response = Http::withToken($secretKey)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->get(
                    self::PAYSTACK_VERIFY_URL .
                    rawurlencode($reference)
                );
        } catch (Throwable $e) {
            Log::error('Paystack verification request failed.', [
                'reference' => $reference,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not contact the payment provider. Please retry verification.',
            ], 502);
        }

        $payload = $response->json();
        $transaction = is_array($payload)
            ? ($payload['data'] ?? null)
            : null;

        if (
            ! $response->successful() ||
            ! is_array($payload) ||
            ($payload['status'] ?? false) !== true ||
            ! is_array($transaction)
        ) {
            Log::warning('Paystack verification returned an error.', [
                'reference' => $reference,
                'http_status' => $response->status(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The payment provider could not verify this transaction.',
            ], 502);
        }

        $expectedMinorAmount = $this->toMinorUnits(
            $payment->amount,
            $payment->currency
        );

        $actualMinorAmount = filter_var(
            $transaction['amount'] ?? null,
            FILTER_VALIDATE_INT
        );

        $expectedCurrency = strtoupper((string) $payment->currency);
        $actualCurrency = strtoupper(
            (string) ($transaction['currency'] ?? '')
        );

        $verifiedReference = (string) (
            $transaction['reference'] ?? ''
        );

        if (! hash_equals($payment->payment_reference, $verifiedReference)) {
            return response()->json([
                'success' => false,
                'message' => 'The verified transaction reference does not match.',
            ], 422);
        }

        if (
            ($transaction['status'] ?? null) !== 'success'
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The transaction has not been completed successfully.',
                'transaction_status' => $transaction['status'] ?? 'unknown',
            ], 409);
        }

        if (
            $actualMinorAmount === false ||
            $actualMinorAmount !== $expectedMinorAmount
        ) {
            Log::warning('Paystack amount mismatch.', [
                'reference' => $reference,
                'expected_minor_amount' => $expectedMinorAmount,
                'actual_minor_amount' => $transaction['amount'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The verified transaction amount does not match the expected payment amount.',
            ], 422);
        }

        if ($actualCurrency !== $expectedCurrency) {
            Log::warning('Paystack currency mismatch.', [
                'reference' => $reference,
                'expected_currency' => $expectedCurrency,
                'actual_currency' => $actualCurrency,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The verified transaction currency does not match.',
            ], 422);
        }

        try {
            $updatedPayment = DB::transaction(function () use (
                $reference,
                $transaction
            ) {
                $lockedPayment = Payment::query()
                    ->where('payment_reference', $reference)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Make verification safe to repeat.
                if ($lockedPayment->payment_confirmed) {
                    return $lockedPayment;
                }

                $lockedPayment->payment_status = 'confirmed';
                $lockedPayment->payment_confirmed = true;
                $lockedPayment->save();

                return $lockedPayment;
            }, 3);

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed successfully.',
                'payment' => $updatedPayment,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to persist verified payment.', [
                'reference' => $reference,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The payment was verified, but its status could not be saved. Please retry verification.',
            ], 500);
        }
    }

    /**
     * Generate a unique payment reference.
     */
    private function generateReference(): string
    {
        do {
            $reference = 'FFSD_TRAVELS_' . Str::random(30);
        } while (
            Payment::query()
                ->where('payment_reference', $reference)
                ->exists()
        );

        return $reference;
    }

    /**
     * Convert a major currency amount to the currency's minor units.
     *
     * NGN 100.00 -> 10000 kobo.
     */
    private function toMinorUnits(
        string|int|float $amount,
        string $currency
    ): int {
        $decimalPlaces = match (strtoupper($currency)) {
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF',
            'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND',
            'VUV', 'XAF', 'XOF', 'XPF' => 0,

            'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR',
            'TND' => 3,

            default => 2,
        };

        $factor = 10 ** $decimalPlaces;
        $numericAmount = (float) $amount;
        $minorAmount = (int) round($numericAmount * $factor);

        if (
            $numericAmount <= 0 ||
            $minorAmount <= 0 ||
            abs(($minorAmount / $factor) - $numericAmount) > 0.000001
        ) {
            throw new \InvalidArgumentException(
                'The payment amount is invalid for the selected currency.'
            );
        }

        return $minorAmount;
    }
}
