<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\payment as Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Initiate a payment request and save reference if successful
     */
    public function initiatePay(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1', // Amount must be positive
            'paid_by_email' => 'required|email',
            'user_id' => 'nullable|integer|exists:users,id',
            'flight_order_id' => 'nullable|integer|exists:flight_orders,id',
            'paid_by_email' => 'nullable|email',
            'paid_by_user_id' => 'nullable|integer|exists:users,id',
            'paid_by_company_id' => 'nullable|integer|exists:companies,id',
            'flight_pnr' => 'nullable|string',
            'flight_am_order_id' => 'nullable|string',
            'wallet_id' => 'nullable|integer|exists:wallets,id',
        ]);

        // Return validation errors if any
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Get validated data
        $validatedData = $validator->validated();

        // Generate a unique payment reference
        $validatedData['payment_reference'] = $this->generate_id();

        // Initiate payment link and handle success or failure
        $paymentLinkResponse = $this->generate_link($validatedData);
        if ($paymentLinkResponse['status']) {
            // Save payment reference if the initiation is successful
            $payment = $this->save_reference($validatedData);
            return response()->json([
                'message' => 'Payment initiated successfully.',
                'payment' => $payment,
                'payment_link' => $paymentLinkResponse['data']['authorization_url'],
                'reference'=> $paymentLinkResponse['data']['reference'],
                'access_code'=>$paymentLinkResponse['data']['access_code'],
            ], 200);
        }

        // Handle failed payment link initiation
        return response()->json([
            'message' => 'Payment initiation failed.',
            'errors' => $paymentLinkResponse['message'],
        ], 500);
    }

    /**
     * Generate a unique payment reference ID
     */
    private function generate_id()
    {
        // Generate a unique ID with a prefix
        $uniqueReferralId = 'FFSD_TRAVELS_' . Str::random(30);

        // Ensure the generated ID is unique in the database
        while (Payment::where('payment_reference', $uniqueReferralId)->exists()) {
            $uniqueReferralId = 'FFSD_TRAVELS' . Str::random(30); // Regenerate if not unique
        }

        return $uniqueReferralId;
    }

    /**
     * Save payment reference details in the database
     */
    private function save_reference($data)
    {
        // Create a new payment record using the provided data
        return Payment::create([
            'amount' => $data['amount'],
            'user_id' => $data['user_id'] ?? null,
            'paid_by_email' => $data['paid_by_email'] ?? $data['email'],
            'payment_reference' => $data['payment_reference'],
            'payment_status' => 'not_confirmed', // Default status
            'flight_pnr' => $data['flight_pnr'] ?? null,
            'flight_order_id' => $data['flight_order_id'] ?? null,
            'paid_by_user_id' => $data['paid_by_user_id'] ?? null,
            'paid_by_company_id' => $data['paid_by_company_id'] ?? null,
            'flight_am_order_id' => $data['flight_am_order_id'] ?? null,
            'wallet_id' => $data['wallet_id'] ?? null,
        ]);
    }

    /**
     * Initiate a payment link request with the payment provider (e.g., Paystack)
     */  
    private function generate_link($data)
    {
        // Define the URL and fields for payment initiation
        $url = "https://api.paystack.co/transaction/initialize";
        $fields = [
            'email' => $data['paid_by_email'],
            'amount' => ceil($data['amount'] * 100), // Convert amount to smallest currency unit (e.g., kobo for NGN)
            'reference' => $data['payment_reference'],
        ];

        // Make the HTTP POST request to initiate the payment
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'), // Ensure the secret key is properly configured in .env
            'Cache-Control' => 'no-cache',
        ])->post($url, $fields);

        // Handle the response
        if ($response->successful()) {
            return $response->json(); // Return the response data if successful
        }

        // Return error details if the request fails
        return [
            'status' => false,
            'message' => 'Failed to initiate payment link. Please try again.',
        ];
    }
    

public function verifyTransaction(Request $request)
{
    // Validate the incoming request data
    $validator = Validator::make($request->all(), [
        'amount' => 'required|integer|min:1', // Amount must be a positive integer
        'reference' => 'required|exists:payments,payment_reference', // Ensure the reference exists in the payments table
    ]);

    // Return validation errors if any
    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Get validated data
    $validatedData = $validator->validated();
    $reference = $validatedData['reference'];
    $amount = $validatedData['amount'] * 100; // Convert amount to kobo (assuming Paystack uses kobo)

    // Prepare the URL for the Paystack API request
    $url = "https://api.paystack.co/transaction/verify/{$reference}";

    // Make the HTTP GET request
    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'), // Ensure SECRET_KEY is set in your .env file
        'Cache-Control' => 'no-cache',
    ])->get($url);

    // Check if the request was successful
    if ($response->successful()) {
        $responseData = $response->json('data'); // Retrieve the data from the response

        // Check if the amount is exactly or more than the recorded amount
        if ($amount >= $responseData['amount']) {
            // Update the payment record
            $this->updateTransaction($reference);

            // Return the response body
            return response()->json(['success'=>true, 'message'=>'payment confirmed'],200);
        } else {
            // Amount is less than expected
            return response()->json([
                'error' => 'Amount mismatch',
                'message' => 'The amount provided is less than the amount recorded in Paystack.'
            ], 400);
        }
    } else {
        // Handle errors from Paystack API
        return response()->json([
            'error' => $response->status(),
            'message' => $response->body()
        ], $response->status());
    }
}

private function updateTransaction($reference)
{
    // Update the payment status and confirmation in the database
    Payment::where('payment_reference', $reference)->update([
        'payment_status' => 'confirmed',
        'payment_confirmed' => true,
        'updated_at'=>now(),
    ]);

    return true;
}

}
