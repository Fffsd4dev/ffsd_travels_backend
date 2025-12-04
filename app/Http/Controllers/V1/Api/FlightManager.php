<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\ManageFlight;
use App\Http\Requests\ModifyData;
use Illuminate\Support\Facades\Http;

class FlightManager extends Controller
{
    public function getFlightOrder(ManageFlight $request)
    {
        $accessToken = $this->getBearerToken($request->header('Authorization'));
        $params = $request->validated();
        $reference = urlencode($request['flightOrderId']);

        $flightData = $this->fetchFlightDetails($accessToken, $reference);

        if ($this->hasAccessTokenExpired($flightData)) {
            $accessToken = $this->getAccessToken();
            $flightData = $this->fetchFlightDetails($accessToken, $reference);
        }

        if (isset($flightData['errors'])) {
            return response()->json([
                'flightData' => $flightData,
                'accessToken' => $accessToken,
            ]);
        }

        return response()->json($flightData);
    }


private function Modify_PNR($data){
    
}
    private function fetchFlightDetails($accessToken, $reference)
    {
        try {
            $response = Http::withToken($accessToken)
                            ->get(env('FLIGHT_DETAILS') . "/{$reference}");

            if ($response->successful()) {
                $responseData = $response->json();
                $responseData['accessToken'] = $accessToken;

                return $responseData;
            } else {
                return [
                    'errors' => [
                        [
                            'title' => 'Unable to fetch flight order',
                            'detail' => $response->body(),
                            'status' => $response->status(),
                        ],
                    ],
                ];
            }
        } catch (\Exception $e) {
            return [
                'errors' => [
                    [
                        'title' => 'Unable to fetch flight order',
                        'detail' => $e->getMessage(),
                        'status' => 500,
                    ],
                ],
            ];
        }
    }

    private function hasAccessTokenExpired(array $response): bool
    {
        return isset($response['errors']) && is_array($response['errors']) &&
               collect($response['errors'])->contains(function ($error) {
                   return (isset($error['title']) && $error['title'] === 'Access token expired') ||
                          (isset($error['code']) && $error['code'] == 38190);
               });
    }

    public function delete(ManageFlight $request)
    {
        $accessToken = $this->getAccessToken();
        $params = $request->validated();
        
        
        if (!$this->isAuthorized(auth()->user())) {
    return response()->json(['error' => 'Unauthorized'], 403);
}

        $reference = urlencode($request['flightOrderId']);
        $flightData = $this->destroy($accessToken, $reference);

        if ($this->hasAccessTokenExpired($flightData)) {
            $accessToken = $this->getAccessToken();
            $flightData = $this->destroy($accessToken, $reference);
        }

        return response()->json($flightData);
    }
    
    
    
public function modifyData(ModifyData $request)
{
    // Validate user authorization
    if (!$this->isAuthorized(auth()->user())) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    // Retrieve and validate parameters
    $params = $request->validated();
    $flightOrderId = $params['data']['id'];

    // Update the flight order and return response
    return $this->updateFlightOrder($flightOrderId, $params);
}

public function updateFlightOrder(string $flightOrderId, array $data)
{
    // Construct the URL for the PATCH request
    $url = env('FLIGHT_DETAILS') . "/{$flightOrderId}";
    
    
    // Get the access token for authentication
    $accessToken = $this->getAccessToken();

    // Make the PATCH request
    $response = Http::withToken($accessToken)->patch($url, $data);

    // Check if the request was successful and return appropriate response
    if ($response->successful()) {
        return response()->json($response->json(), 200); // Return successful response
    }

    // Handle errors with detailed response
    return response()->json([
        'error' => $response->body(),
        'status' => $response->status()
    ], $response->status());
}
    


    private function destroy($accessToken, $reference)
    {
        try {
            $response = Http::withToken($accessToken)
                            ->delete(env('FLIGHT_DETAILS') . "/{$reference}");

            if ($response->successful()) {
                
                $this->deleteBooked($reference);
                return [
                    'message' => 'Flight order deleted successfully',
                    'data' => $response->json(),
                ];
            } else {
                return [
                    'errors' => [
                        [
                            'title' => 'Failed to delete flight order',
                            'detail' => $response->body(),
                            'status' => $response->status(),
                        ],
                    ],
                ];
            }
        } catch (\Exception $e) {
            return [
                'errors' => [
                    [
                        'title' => 'Failed to delete flight order',
                        'detail' => $e->getMessage(),
                        'status' => 500,
                    ],
                ],
            ];
        }
    }


private function deleteBooked($PNR)
{
    // Find the flight order by PNR (or another unique identifier)
    $flightOrder = FlightOrders::where('pnr', $PNR)->first();

    if (!$flightOrder) {
        return response()->json(['message' => 'Flight Order not found'], 404);
    }

    // Delete related records (e.g., associated records, travelers, flight offers, etc.)
    $this->deleteAssociatedRecords($flightOrder->id);
    $this->deleteTravelers($flightOrder->id);
    $this->deleteFlightOffers($flightOrder->id);
    $this->deleteTicketingAgreement($flightOrder->id);
    $this->deleteAutomatedProcesses($flightOrder->id);

    // Delete the flight order itself
    $flightOrder->delete();

    return response()->json(['message' => 'Flight Order and related records deleted successfully'], 200);
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
    
    
    
    
     private function isAuthorized($user)
    {
        return in_array($user->user_type, ['admin', 'system_admin']);
    }
    

private function deleteAssociatedRecords($flightOrderId)
{
    AssociatedRecords::where('flight_order_id', $flightOrderId)->delete();
}

private function deleteTravelers($flightOrderId)
{
    Travelers::where('flight_order_id', $flightOrderId)->delete();
}

private function deleteFlightOffers($flightOrderId)
{
    FlightOffers::where('flight_order_id', $flightOrderId)->delete();
}

private function deleteTicketingAgreement($flightOrderId)
{
    TicketingAgreement::where('flight_order_id', $flightOrderId)->delete();
}

private function deleteAutomatedProcesses($flightOrderId)
{
    AutomatedProcess::where('flight_order_id', $flightOrderId)->delete();
}

}


