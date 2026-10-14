<?php
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManageFlight;
use App\Http\Requests\ModifyData;
use App\Models\FlightOrders;
use App\Models\AssociatedRecords;
use App\Models\Travelers;
use App\Models\FlightOffers;
use App\Models\TicketingAgreement;
use App\Models\AutomatedProcesses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlightManager extends Controller
{
    private const TOKEN_CACHE_KEY = 'amadeus_access_token';
    private const TOKEN_EXPIRY_BUFFER = 60;
    private const CONNECT_TIMEOUT = 5;
    private const REQUEST_TIMEOUT = 20;

    /**
     * Retrieve a flight order.
     */
    public function getFlightOrder(ManageFlight $request): JsonResponse
    {
        if ($response = $this->authorizeManager()) {
            return $response;
        }

        $params = $request->validated();
        $reference = $params['flightOrderId'] ?? null;

        if (!is_string($reference) || trim($reference) === '') {
            return response()->json([
                'message' => 'A valid flight order ID is required.',
            ], 422);
        }

        $result = $this->flightApiRequest('GET', $reference);

        if (!$result['ok']) {
            return $this->providerErrorResponse(
                $result,
                'Unable to retrieve flight order.'
            );
        }

        return response()->json($result['data']);
    }

    /**
     * Delete a flight order from the provider and synchronize local records.
     */
    public function delete(ManageFlight $request): JsonResponse
    {
        if ($response = $this->authorizeManager()) {
            return $response;
        }

        $params = $request->validated();
        $reference = $params['flightOrderId'] ?? null;

        if (!is_string($reference) || trim($reference) === '') {
            return response()->json([
                'message' => 'A valid flight order ID is required.',
            ], 422);
        }

        $result = $this->flightApiRequest('DELETE', $reference);

        if (!$result['ok']) {
            return $this->providerErrorResponse(
                $result,
                'Unable to delete flight order.'
            );
        }

        try {
            $localDeleted = $this->deleteBooked($reference);
        } catch (Throwable $e) {
            Log::error(
                'Provider flight order deleted, but local cleanup failed.',
                [
                    'flight_order_reference' => $reference,
                    'exception' => $e::class,
                ]
            );

            return response()->json([
                'message' => 'The provider deleted the flight order, but local synchronization failed. Contact support.',
            ], 500);
        }

        return response()->json([
            'message' => 'Flight order deletion completed.',
            'provider_deleted' => true,
            'local_record_deleted' => $localDeleted,
        ]);
    }

    /**
     * Modify a flight order.
     */
    public function modifyData(ModifyData $request): JsonResponse
    {
        if ($response = $this->authorizeManager()) {
            return $response;
        }

        $params = $request->validated();
        $flightOrderId = $params['data']['id'] ?? null;

        if (
            (!is_string($flightOrderId) && !is_int($flightOrderId)) ||
            trim((string) $flightOrderId) === ''
        ) {
            return response()->json([
                'message' => 'A valid flight order ID is required.',
            ], 422);
        }

        return $this->updateFlightOrder(
            (string) $flightOrderId,
            $params
        );
    }

    /**
     * Update a flight order using the provider API.
     */
    private function updateFlightOrder(
        string $flightOrderId,
        array $data
    ): JsonResponse {
        $result = $this->flightApiRequest(
            'PATCH',
            $flightOrderId,
            $data
        );

        if (!$result['ok']) {
            return $this->providerErrorResponse(
                $result,
                'Unable to modify flight order.'
            );
        }

        return response()->json($result['data']);
    }

    /**
     * Make a provider request and retry once after token expiration.
     */
    private function flightApiRequest(
        string $method,
        string $reference,
        ?array $payload = null
    ): array {
        $baseUrl = rtrim(
            (string) config('services.amadeus.flight_details'),
            '/'
        );

        if ($baseUrl === '') {
            Log::error('Flight details URL is not configured.');

            return [
                'ok' => false,
                'status' => 503,
                'data' => [],
            ];
        }

        $url = $baseUrl . '/' . rawurlencode($reference);
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return [
                'ok' => false,
                'status' => 502,
                'data' => [],
            ];
        }

        $result = $this->sendFlightRequest(
            $method,
            $url,
            $accessToken,
            $payload
        );

        if (!$this->hasAccessTokenExpired($result)) {
            return $result;
        }

        $newToken = $this->getAccessToken(true, $accessToken);

        if (!$newToken) {
            return [
                'ok' => false,
                'status' => 502,
                'data' => [],
            ];
        }

        return $this->sendFlightRequest(
            $method,
            $url,
            $newToken,
            $payload
        );
    }

    /**
     * Send an HTTP request to the flight provider.
     */
    private function sendFlightRequest(
        string $method,
        string $url,
        string $accessToken,
        ?array $payload = null
    ): array {
        try {
            $request = Http::acceptJson()
                ->withToken($accessToken)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT);

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'DELETE' => $request->delete($url),
                'PATCH' => $request->patch($url, $payload ?? []),
                default => null,
            };

            if ($response === null) {
                return [
                    'ok' => false,
                    'status' => 500,
                    'data' => [],
                ];
            }

            $data = $response->json();

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'data' => is_array($data) ? $data : [],
            ];
        } catch (ConnectionException $e) {
            Log::warning('Flight provider connection failed.', [
                'method' => $method,
                'exception' => $e::class,
            ]);

            return [
                'ok' => false,
                'status' => 504,
                'data' => [],
            ];
        } catch (Throwable $e) {
            Log::error('Flight provider request failed.', [
                'method' => $method,
                'exception' => $e::class,
            ]);

            return [
                'ok' => false,
                'status' => 502,
                'data' => [],
            ];
        }
    }

    /**
     * Retrieve or refresh the cached provider access token.
     */
    private function getAccessToken(
        bool $forceRefresh = false,
        ?string $rejectedToken = null
    ): ?string {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if (!$forceRefresh && is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        if (
            $forceRefresh &&
            is_string($cachedToken) &&
            $cachedToken !== '' &&
            $rejectedToken !== null &&
            !hash_equals($rejectedToken, $cachedToken)
        ) {
            // Another request has already refreshed the token.
            return $cachedToken;
        }

        $authUrl = config('services.amadeus.auth_url');
        $clientId = config('services.amadeus.client_id');
        $clientSecret = config('services.amadeus.client_secret');

        if (
            !is_string($authUrl) || $authUrl === '' ||
            !is_string($clientId) || $clientId === '' ||
            !is_string($clientSecret) || $clientSecret === ''
        ) {
            Log::error('Provider OAuth configuration is incomplete.');

            return null;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(15)
                ->post($authUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (!$response->successful()) {
                Log::error('Provider OAuth request failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();
            $token = is_array($data)
                ? ($data['access_token'] ?? null)
                : null;

            if (!is_string($token) || $token === '') {
                Log::error('Provider OAuth response contained no access token.');

                return null;
            }

            $expiresIn = max(
                1,
                (int) ($data['expires_in'] ?? 1800)
            );

            Cache::put(
                self::TOKEN_CACHE_KEY,
                $token,
                now()->addSeconds(
                    max(1, $expiresIn - self::TOKEN_EXPIRY_BUFFER)
                )
            );

            return $token;
        } catch (Throwable $e) {
            Log::error('Provider OAuth request failed unexpectedly.', [
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Determine whether the provider rejected the access token.
     */
    private function hasAccessTokenExpired(array $result): bool
    {
        if (($result['status'] ?? null) === 401) {
            return true;
        }

        $errors = $result['data']['errors'] ?? [];

        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            if (
                ($error['title'] ?? null) === 'Access token expired' ||
                (string) ($error['code'] ?? '') === '38190'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check the authenticated user's permissions.
     */
    private function authorizeManager(): ?JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array(
            $user->user_type,
            ['admin', 'system_admin'],
            true
        )) {
            return response()->json([
                'message' => 'You are not authorized to manage flight orders.',
            ], 403);
        }

        return null;
    }

    /**
     * Return a safe error response without exposing provider internals.
     */
    private function providerErrorResponse(
        array $result,
        string $message
    ): JsonResponse {
        $status = (int) ($result['status'] ?? 502);

        $httpStatus = match ($status) {
            400, 422 => 422,
            404 => 404,
            408, 504 => 504,
            429 => 503,
            default => 502,
        };

        return response()->json([
            'message' => $message,
            'provider_status' => $status,
        ], $httpStatus);
    }

    /**
     * Delete local booking data transactionally.
     */
    private function deleteBooked(string $pnr): bool
    {
        return DB::transaction(function () use ($pnr): bool {
            $flightOrder = FlightOrders::where('pnr', $pnr)
                ->lockForUpdate()
                ->first();

            if (!$flightOrder) {
                Log::warning('No local flight order matched the provider reference.', [
                    'flight_order_reference' => $pnr,
                ]);

                return false;
            }

            $flightOrder->associatedRecords()->delete();
            $flightOrder->travelers()->delete();
            $flightOrder->flightOffers()->delete();
            $flightOrder->ticketingAgreement()->delete();
            $flightOrder->automatedProcesses()->delete();

            $flightOrder->delete();

            return true;
        }, 3);
    }
}
