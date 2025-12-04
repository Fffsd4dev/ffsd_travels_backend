<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Models\TicketStatus;
use Illuminate\Support\Facades\Validator;
class TicketController extends Controller
{
    public function createTicket(Request $request)
    {
        $user = Auth::guard('sanctum')->user();

        if (!$this->isAuthorized($user)) {
            return response()->json(['error' => 'Access not permitted for this user type'], 403);
        }

        // Validate request data
        $request->validate([
            'pnr' => 'required|string',
        ]);

        $pnr = $request->input('pnr');
        $url = env('TICKET_URL') . $pnr . '/issuance';
        $accessToken = $request->header('ticket_access_code');

        // Attempt to issue a ticket
        $ticketResponse = $this->ticket($accessToken, $url);
        $ticketData = $ticketResponse->getData(true); // Extract data array from the response

        // Check if the response has errors and handle them appropriately
        if (isset($ticketData['errors']) && is_array($ticketData['errors'])) {
            // Check if the access token has expired and retry if necessary
            if ($this->hasAccessTokenExpired($ticketData)) {
                $accessToken = $this->getAccessToken();
                $ticketResponse = $this->ticket($accessToken, $url);
                $ticketData = $ticketResponse->getData(true);

                // Handle the new response appropriately
                if ($ticketResponse->status() !== 200) {
                    return response()->json(['error' => 'Failed to issue ticket after retry'], $ticketResponse->status());
                }
            } else {
                return response()->json(['error' => 'Failed to issue ticket', 'errors' => $ticketData['errors']], $ticketResponse->status());
            }
        }

        // Handle successful ticket issuance
        if ($ticketResponse->status() === 200) {
            //save ticket information
            return response()->json(['message' => 'Ticket issued successfully', 'data' => $ticketData], 200);
        }

        // Fallback if the response was not successful
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }

    private function hasAccessTokenExpired(array $response): bool
    {
        if (isset($response['errors']) && is_array($response['errors'])) {
            return collect($response['errors'])->contains(function ($error) {
                return (isset($error['title']) && $error['title'] === 'Access token expired') ||
                       (isset($error['code']) && $error['code'] == 38190);
            });
        }

        return false;
    }

    private function getAccessToken(): ?string
    {
        $response = Http::asForm()->post(env('AUTH_URL'), [
            'grant_type' => 'client_credentials',
            'client_id' => env('CLIENT_ID'),
            'client_secret' => env('CLIENT_SECRET'),
        ]);

        return $response->json()['access_token'] ?? null;
    }

    private function ticket(?string $accessToken, string $url)
    {
        try {
            $response = Http::withToken($accessToken)->post($url);

            if ($response->successful()) {
                return response()->json($response->json(), 200);
            } else {
                return response()->json([
                    'message' => 'Failed to issue flight order',
                    'errors' => $response->json()['errors'],
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while issuing the flight order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function isAuthorized($user)
    {
        return in_array($user->user_type, ['admin', 'system_admin']);
    }
    public function UpdateTicketStatus(Request $request)
{
    $user = Auth::guard('sanctum')->user();
    
    if (!$user->user_type && empty($user->user_type)||$user->user_type!='system_admin') {
        return response()->json(['error' => 'Unauthorized'],401);
    }
    // Validate the input data
    $validator = Validator::make($request->all(), [
        'reference' => 'required|string|exists:ticket_statuses,pnr', // Ensure the PNR exists
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    // Update the ticket status where the PNR matches
    $affectedRows = TicketStatus::where('pnr', strip_tags($request->reference))
                    ->update(['ticket_status' => 'done']);


    if ($affectedRows > 0) {
        return response()->json(['message' => 'Ticket status updated successfully'], 200);
    }

    return response()->json(['error' => 'Ticket not found'], 404);
}

   
}
