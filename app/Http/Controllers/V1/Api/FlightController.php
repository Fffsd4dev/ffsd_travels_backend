<?php
namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightSearchRequest;
use App\Http\Requests\CitySearchRequest;
use App\Http\Requests\FlightOffersPrice;
use App\Http\Requests\FlightBookRequest;
use App\Http\Requests\BrandedUpsell;
use App\Http\Requests\ManageFlight;
use App\Http\Requests\Multicity;

use App\Models\FlightOrders;
use App\Models\AssociatedRecords;
use App\Models\FlightOffers;
use App\Models\Itinerary;
use App\Models\Segments;
use App\Models\Travelers;
use App\Models\TravelerPricing;
use App\Models\FareDetail;
use App\Models\TicketAgreements;
use App\Models\AutomatedProcesses;
use App\Models\MarkUp;
use App\Models\TicketStatus;
use App\Models\FlightSearchSession;

use App\Notifications\BookedNotification;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use Throwable;

class FlightController extends Controller
{
    private const TOKEN_CACHE_KEY = 'amadeus:access_token';
    private const CONFIRMED_FLIGHT_PREFIX = 'flight:confirmed:';
    private const BOOKING_LOCK_PREFIX = 'flight:booking:lock:';
    private const CONFIRM_LOCK_PREFIX = 'flight:confirm:lock:';

    private string $concatPrem;

    public function __construct()
    {
        $this->concatPrem = config(
            'services.amadeus.ca_bundle',
            storage_path('cacert.pem')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration and HTTP helpers
    |--------------------------------------------------------------------------
    */

    private function amadeusConfig(
        string $key,
        mixed $default = null
    ): mixed {
        return config('services.amadeus.' . $key, $default);
    }

    private function amadeusRequest()
    {
        return Http::acceptJson()
            ->withOptions([
                'verify' => $this->concatPrem,
            ])
            ->timeout(45);
    }

    private function hasAccessTokenExpired(array $response): bool
    {
        $errors = $response['errors'] ?? [];

        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = (string) ($error['code'] ?? '');
            $title = (string) ($error['title'] ?? '');
            $detail = (string) ($error['detail'] ?? '');

            if (
                in_array($code, ['38190', '38192'], true) ||
                stripos($title, 'access token expired') !== false ||
                stripos($detail, 'access token expired') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Request a new provider access token.
     *
     * The token is stored only in the server-side cache.
     * Never log the token or return it to the API client.
     */
    private function requestNewAccessToken(): ?string
    {
        try {
            $authUrl = $this->amadeusConfig('auth_url');
            $clientId = $this->amadeusConfig('client_id');
            $clientSecret = $this->amadeusConfig('client_secret');

            if (
                !is_string($authUrl) ||
                trim($authUrl) === '' ||
                !is_string($clientId) ||
                trim($clientId) === '' ||
                !is_string($clientSecret) ||
                trim($clientSecret) === ''
            ) {
                Log::error(
                    'Amadeus authentication configuration is incomplete.'
                );

                return null;
            }

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->withOptions([
                    'verify' => $this->concatPrem,
                ])
                ->post($authUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if ($response->failed()) {
                Log::error('Amadeus authentication failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $token = $response->json('access_token');

            if (!is_string($token) || trim($token) === '') {
                Log::error(
                    'Amadeus returned no valid access token.'
                );

                return null;
            }

            $expiresIn = max(
                1,
                (int) $response->json('expires_in', 1800)
            );

            // Refresh one minute before the provider token expires.
            $ttl = max(1, $expiresIn - 60);

            Cache::store('file')->put(
                self::TOKEN_CACHE_KEY,
                $token,
                now()->addSeconds($ttl)
            );

            return $token;
        } catch (Throwable $e) {
            Log::error('Amadeus token request failed.', [
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Retrieve the cached provider token or refresh it safely.
     *
     * Pass the rejected token when force-refreshing after an
     * authentication failure:
     *
     * $this->getAccessToken(true, $accessToken);
     */
    private function getAccessToken(
        bool $forceRefresh = false,
        ?string $rejectedToken = null
    ): ?string {
        $cache = Cache::store('file');

        if (!$forceRefresh) {
            $token = $cache->get(self::TOKEN_CACHE_KEY);

            if (is_string($token) && trim($token) !== '') {
                return $token;
            }
        }

        try {
            return $cache->lock(
                self::TOKEN_CACHE_KEY . ':lock',
                60
            )->block(15, function () use (
                $cache,
                $forceRefresh,
                $rejectedToken
            ): ?string {
                $cachedToken = $cache->get(self::TOKEN_CACHE_KEY);

                if (
                    $forceRefresh &&
                    is_string($rejectedToken) &&
                    $rejectedToken !== ''
                ) {
                    /*
                     * Another request may already have refreshed
                     * the token while this request waited.
                     */
                    if (
                        is_string($cachedToken) &&
                        trim($cachedToken) !== '' &&
                        !hash_equals(
                            $rejectedToken,
                            $cachedToken
                        )
                    ) {
                        return $cachedToken;
                    }

                    // Remove only the token the provider rejected.
                    if (
                        is_string($cachedToken) &&
                        hash_equals(
                            $rejectedToken,
                            $cachedToken
                        )
                    ) {
                        $cache->forget(self::TOKEN_CACHE_KEY);
                    }
                } elseif (
                    is_string($cachedToken) &&
                    trim($cachedToken) !== ''
                ) {
                    return $cachedToken;
                }

                return $this->requestNewAccessToken();
            });
        } catch (LockTimeoutException $e) {
            Log::warning(
                'Timed out waiting for the Amadeus token lock.'
            );

            $cachedToken = $cache->get(self::TOKEN_CACHE_KEY);

            if (
                is_string($cachedToken) &&
                trim($cachedToken) !== '' &&
                (
                    !$forceRefresh ||
                    !is_string($rejectedToken) ||
                    $rejectedToken === '' ||
                    !hash_equals(
                        $rejectedToken,
                        $cachedToken
                    )
                )
            ) {
                return $cachedToken;
            }

            return null;
        } catch (Throwable $e) {
            Log::error(
                'Unable to retrieve Amadeus access token.',
                ['exception' => $e::class]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Airport and airline data
    |--------------------------------------------------------------------------
    */

    private function getAirportData(): array
    {
        $path = storage_path('app/airports_iata_codes.json');

        if (!File::exists($path)) {
            Log::error('Airport data file not found.', [
                'path' => $path,
            ]);

            return [];
        }

        $data = json_decode(File::get($path), true);

        return is_array($data) ? $data : [];
    }

    private function getAirlineLogos(): array
    {
        return Cache::remember(
            'airlineLogos',
            now()->addDays(30),
            function (): array {
                $path = storage_path('app/airlines.json');

                if (!File::exists($path)) {
                    return ['data' => []];
                }

                $data = json_decode(File::get($path), true);

                return is_array($data)
                    ? $data
                    : ['data' => []];
            }
        );
    }

    private function getAirlineLogo(
        string $airlineCode
    ): ?string {
        foreach (
            ($this->getAirlineLogos()['data'] ?? [])
            as $airline
        ) {
            if (
                ($airline['iata_code'] ?? null) === $airlineCode &&
                !empty($airline['logo'])
            ) {
                return preg_replace(
                    '/^.\//',
                    '',
                    $airline['logo']
                );
            }
        }

        return null;
    }

    private function filterAirportsWithKeyword(
        array $airportData,
        string $keyword
    ): \Generator {
        foreach ($airportData as $airport) {
            if (empty($airport['iata_code'])) {
                continue;
            }

            $fields = [
                'iata' => $airport['iata_code'] ?? '',
                'name' => $airport['name'] ?? '',
                'city' => $airport['city'] ?? '',
                'country' => $airport['country'] ?? '',
                'time_zone' => $airport['timezone'] ?? '',
            ];

            foreach ($fields as $value) {
                if (stripos((string) $value, $keyword) !== false) {
                    yield $fields;
                    continue 2;
                }
            }
        }
    }

    private function getMatchScore(
        array $airport,
        string $keyword
    ): int {
        $score = 0;

        if (strtolower($airport['iata'] ?? '') === $keyword) {
            $score += 100;
        }

        if (strtolower($airport['city'] ?? '') === $keyword) {
            $score += 10;
        }

        if (strtolower($airport['name'] ?? '') === $keyword) {
            $score += 8;
        }

        if (
            stripos(
                (string) ($airport['country'] ?? ''),
                $keyword
            ) !== false
        ) {
            $score += 1;
        }

        return $score;
    }

    public function citySearch(
        CitySearchRequest $request
    ): JsonResponse {
        $params = $request->validated();
        $keyword = strtolower(
            trim($params['keyword'] ?? '')
        );

        if ($keyword === '') {
            return response()->json([]);
        }

        $airports = iterator_to_array(
            $this->filterAirportsWithKeyword(
                $this->getAirportData(),
                $keyword
            ),
            false
        );

        usort($airports, function ($a, $b) use ($keyword) {
            return $this->getMatchScore($b, $keyword)
                <=> $this->getMatchScore($a, $keyword);
        });

        return response()->json(
            array_slice($airports, 0, 15)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    private function getMarkupPercentage(): float
    {
        return (float) (
            MarkUp::where('fee_name', 'markup')
                ->value('fee_percentage') ?? 0
        );
    }

    /**
     * Apply markup once to each offer.
     */
    private function applyMarkupToOffers(
        array &$offers
    ): void {
        $markup = $this->getMarkupPercentage();

        foreach ($offers as &$offer) {
            if (
                !isset($offer['price']) ||
                !is_array($offer['price'])
            ) {
                continue;
            }

            $base = (float) ($offer['price']['base'] ?? 0);

            $total = (float) (
                $offer['price']['grandTotal']
                ?? $offer['price']['total']
                ?? 0
            );

            $serviceCharge = round(
                $base * $markup / 100,
                2
            );

            $offer['price']['service_charge'] = $serviceCharge;

            $offer['price']['ffsd_total'] = ceil(
                $total + $serviceCharge
            );

            if (
                !isset($offer['travelerPricings']) ||
                !is_array($offer['travelerPricings'])
            ) {
                continue;
            }

            foreach ($offer['travelerPricings'] as &$traveler) {
                if (!isset($traveler['price'])) {
                    continue;
                }

                $travelerBase = (float) (
                    $traveler['price']['base'] ?? 0
                );

                $travelerTotal = (float) (
                    $traveler['price']['total'] ?? 0
                );

                $travelerCharge = round(
                    $travelerBase * $markup / 100,
                    2
                );

                $traveler['price']['service_charge'] =
                    $travelerCharge;

                $traveler['price']['total_charge'] = ceil(
                    $travelerTotal + $travelerCharge
                );
            }

            unset($traveler);
        }

        unset($offer);
    }

    private function sortCost(array $flightOffers): array
    {
        usort($flightOffers, function ($a, $b) {
            $priceA = (float) (
                $a['price']['ffsd_total']
                ?? $a['price']['grandTotal']
                ?? $a['price']['total']
                ?? 0
            );

            $priceB = (float) (
                $b['price']['ffsd_total']
                ?? $b['price']['grandTotal']
                ?? $b['price']['total']
                ?? 0
            );

            return $priceA <=> $priceB;
        });

        return $flightOffers;
    }

    private function getFlightDuration(
        ?string $departureTime,
        ?string $arrivalTime,
        ?string $departureTimeZone = null,
        ?string $arrivalTimeZone = null
    ): ?string {
        if (!$departureTime || !$arrivalTime) {
            return null;
        }

        try {
            $departure = Carbon::parse($departureTime);
            $arrival = Carbon::parse($arrivalTime);

            $seconds = $arrival->getTimestamp()
                - $departure->getTimestamp();

            if ($seconds < 0) {
                return null;
            }

            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $remainingSeconds = $seconds % 60;

            return sprintf(
                '%02d:%02d:%02d',
                $hours,
                $minutes,
                $remainingSeconds
            );
        } catch (Throwable $e) {
            Log::warning(
                'Unable to calculate flight duration.'
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Flight offer enrichment
    |--------------------------------------------------------------------------
    */

    private function enrichOffers(
        array &$offers,
        array $carriers = []
    ): void {
        $airportLookup = [];
        $airportTimeZones = [];
        $airportCountries = [];
        $airportCities = [];

        foreach ($this->getAirportData() as $airport) {
            $code = $airport['iata_code'] ?? null;

            if (!$code) {
                continue;
            }

            $airportLookup[$code] = $airport['name'] ?? $code;
            $airportTimeZones[$code] = $airport['timezone'] ?? null;
            $airportCountries[$code] = $airport['country'] ?? null;
            $airportCities[$code] = $airport['city'] ?? null;
        }

        $logoLookup = [];

        foreach (($this->getAirlineLogos()['data'] ?? []) as $airline) {
            if (!empty($airline['iata_code'])) {
                $logoLookup[$airline['iata_code']] = [
                    'logo' => $airline['logo'] ?? null,
                    'name' => $airline['name'] ?? null,
                ];
            }
        }

        foreach ($offers as &$offer) {
            $firstDeparture = null;
            $lastArrival = null;
            $firstLogo = null;

            foreach (($offer['itineraries'] ?? []) as &$itinerary) {
                $segments = $itinerary['segments'] ?? [];

                foreach ($segments as $index => &$segment) {
                    $departureCode =
                        $segment['departure']['iataCode'] ?? null;

                    $arrivalCode =
                        $segment['arrival']['iataCode'] ?? null;

                    if ($departureCode) {
                        $segment['departure_airport'] =
                            $airportLookup[$departureCode] ?? $departureCode;

                        $segment['departureTimeZone'] =
                            $airportTimeZones[$departureCode] ?? null;

                        $segment['departureCountry'] =
                            $airportCountries[$departureCode] ?? null;

                        $segment['departureCity'] =
                            $airportCities[$departureCode] ?? null;

                        $firstDeparture ??=
                            $segment['departure_airport'];
                    }

                    if ($arrivalCode) {
                        $segment['arrival_airport'] =
                            $airportLookup[$arrivalCode] ?? $arrivalCode;

                        $segment['arrivalTimeZone'] =
                            $airportTimeZones[$arrivalCode] ?? null;

                        $segment['arrivalCountry'] =
                            $airportCountries[$arrivalCode] ?? null;

                        $segment['arrivalCity'] =
                            $airportCities[$arrivalCode] ?? null;

                        $lastArrival = $segment['arrival_airport'];
                    }

                    $segment['flight_duration'] = $this->getFlightDuration(
                        $segment['departure']['at'] ?? null,
                        $segment['arrival']['at'] ?? null,
                        $airportTimeZones[$departureCode ?? ''] ?? null,
                        $airportTimeZones[$arrivalCode ?? ''] ?? null
                    );

                    if ($index > 0) {
                        $previousSegment = $segments[$index - 1] ?? null;

                        if (
                            $previousSegment &&
                            !empty($previousSegment['arrival']['at']) &&
                            !empty($segment['departure']['at'])
                        ) {
                            try {
                                $previousArrival = Carbon::parse(
                                    $previousSegment['arrival']['at']
                                );

                                $nextDeparture = Carbon::parse(
                                    $segment['departure']['at']
                                );

                                $minutes = max(
                                    0,
                                    $previousArrival->diffInMinutes(
                                        $nextDeparture,
                                        false
                                    )
                                );

                                $segment['layover_data'] = [
                                    'layover_duration' => sprintf(
                                        '%dh %02dm',
                                        intdiv($minutes, 60),
                                        $minutes % 60
                                    ),
                                    'layover_in_minutes' => $minutes,
                                    'airport' =>
                                        $previousSegment['arrival']['iataCode']
                                        ?? null,
                                    'airport_name' =>
                                        $previousSegment['arrival_airport']
                                        ?? (
                                            $previousSegment['arrival']['iataCode']
                                            ?? null
                                        ),
                                    'airport_country' =>
                                        $previousSegment['arrivalCountry']
                                        ?? null,
                                    'airport_city' =>
                                        $previousSegment['arrivalCity']
                                        ?? null,
                                ];
                            } catch (Throwable $e) {
                                // Keep the offer even if a layover
                                // timestamp cannot be parsed.
                            }
                        }
                    }

                    $carrierCode = $segment['carrierCode'] ?? null;

                    if ($carrierCode) {
                        $segment['airlineName'] =
                            $carriers[$carrierCode]
                            ?? $logoLookup[$carrierCode]['name']
                            ?? $carrierCode;

                        $logo = $logoLookup[$carrierCode]['logo'] ?? null;

                        $segment['airlineLogo'] = is_string($logo)
                            && $logo !== ''
                            ? preg_replace('/^.\//', '', $logo)
                            : $this->getAirlineLogo($carrierCode);

                        $firstLogo ??= $segment['airlineLogo'];
                    }
                }

                unset($segment);
            }

            unset($itinerary);

            $offer['DepartureAirport'] = $firstDeparture;
            $offer['arrivalAirport'] = $lastArrival;
            $offer['logo'] = $firstLogo;
        }

        unset($offer);
    }

    /*
    |--------------------------------------------------------------------------
    | Standard flight search
    |--------------------------------------------------------------------------
    */


private function fetchApiData(
    string $url,
    string $accessToken,
    array $params,
    array $additionalHeaders = []
): array {
    try {
        $response = $this->amadeusRequest()
            ->withToken($accessToken)
            ->withHeaders($additionalHeaders)
            ->get($url, $params);

        $responseData = $response->json();

        if (!is_array($responseData)) {
            $responseData = [
                'message' => 'Invalid response from Amadeus.',
            ];
        }

        $responseData['httpCode'] = $response->status();

        if (
            isset($responseData['data']) &&
            is_array($responseData['data'])
        ) {
            $carriers =
                $responseData['dictionaries']['carriers'] ?? [];

            $this->enrichOffers(
                $responseData['data'],
                $carriers
            );
        }

        return $responseData;
    } catch (Throwable $e) {
        Log::error('Amadeus flight search failed.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return [
            'errors' => [[
                'title' => 'Flight search failed',
                'detail' => 'Unable to retrieve flight offers.',
            ]],
            'httpCode' => 502,
        ];
    }
}


   
    
public function searchFlights(
    FlightSearchRequest $request
): JsonResponse {
    $data = $request->validated();

    $url = $this->amadeusConfig('flight_search');

    if (!is_string($url) || trim($url) === '') {
        return response()->json([
            'message' => 'Flight search endpoint is not configured.',
        ], 500);
    }

    $accessToken = $this->getAccessToken();

    if (!$accessToken) {
        return response()->json([
            'message' => 'Unable to authenticate with Amadeus.',
        ], 502);
    }

    $data['max'] = 250;

    // Generate and persist the reference before calling Amadeus.
    $session = $this->createFlightSearchSession($data);

    $amaClientRef = $session->ama_client_ref;

    // Send the generated reference to Amadeus as a custom header.
    $result = $this->fetchApiData(
        $url,
        $accessToken,
        $data,
        [
            'ama-client-ref' => $amaClientRef,
        ]
    );

    if ($this->hasAccessTokenExpired($result)) {
        $accessToken = $this->getAccessToken(
            true,
            $accessToken
        );

        if (!$accessToken) {
            return response()->json([
                'message' => 'Unable to refresh Amadeus authentication.',
                'ama_client_ref' => $amaClientRef,
            ], 502);
        }

        // Reuse the same reference when retrying.
        $result = $this->fetchApiData(
            $url,
            $accessToken,
            $data,
            [
                'ama-client-ref' => $amaClientRef,
            ]
        );
    }

    if (isset($result['data']) && is_array($result['data'])) {
        $this->applyMarkupToOffers($result['data']);
        $result['data'] = $this->sortCost($result['data']);
    }

    unset($result['accessToken'], $result['client']);

    $result['ama_client_ref'] = $amaClientRef;

    return response()->json(
        $result,
        $result['httpCode'] ?? 200
    );
}


    /*
    |--------------------------------------------------------------------------
    | Multicity search
    |--------------------------------------------------------------------------
    */

private function fetchFlightOffers(
    string $url,
    string $accessToken,
    array $payload,
    ?string $amaClientRef = null
): array {
    try {
        $request = $this->amadeusRequest()
            ->withToken($accessToken);

        if (
            is_string($amaClientRef) &&
            trim($amaClientRef) !== ''
        ) {
            $request = $request->withHeaders([
                'ama-client-ref' => trim($amaClientRef),
            ]);
        }

        $response = $request->post($url, $payload);

        $data = $response->json();

        if (!is_array($data)) {
            $data = [
                'errors' => [[
                    'title' => 'Invalid response from Amadeus.',
                ]],
            ];
        }

        $data['httpCode'] = $response->status();

        return $data;
    } catch (Throwable $e) {
        Log::error('Multicity flight search failed.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return [
            'errors' => [[
                'title' => 'Flight search failed',
                'detail' => 'Unable to retrieve flight offers.',
            ]],
            'httpCode' => 502,
        ];
    }
}


  
public function searchMultiple(
    Multicity $request
): JsonResponse {
    $data = $request->validated();

    if (
        !isset($data['searchCriteria']) ||
        !is_array($data['searchCriteria'])
    ) {
        return response()->json([
            'message' => 'The searchCriteria field is required.',
        ], 422);
    }

    $url = $this->amadeusConfig('flight_search');

    if (!is_string($url) || trim($url) === '') {
        return response()->json([
            'message' => 'Flight search endpoint is not configured.',
        ], 500);
    }

    $data['searchCriteria']['maxFlightOffers'] = 250;

    $accessToken = $this->getAccessToken();

    if (!$accessToken) {
        return response()->json([
            'message' => 'Unable to authenticate with Amadeus.',
        ], 502);
    }

    /*
     * Create and persist a session for this multicity search.
     * Ensure createFlightSearchSession() supports the nested
     * searchCriteria structure used by Multicity.
     */
    $session = $this->createFlightSearchSession($data);

    $amaClientRef = $session->ama_client_ref;

    // Send the generated reference to Amadeus.
    $result = $this->fetchFlightOffers(
        $url,
        $accessToken,
        $data,
        $amaClientRef
    );

    if ($this->hasAccessTokenExpired($result)) {
        $accessToken = $this->getAccessToken(
            true,
            $accessToken
        );

        if (!$accessToken) {
            return response()->json([
                'message' => 'Unable to refresh Amadeus authentication.',
                'ama_client_ref' => $amaClientRef,
            ], 502);
        }

        // Reuse the same reference when retrying.
        $result = $this->fetchFlightOffers(
            $url,
            $accessToken,
            $data,
            $amaClientRef
        );
    }

    if (
        isset($result['data']) &&
        is_array($result['data'])
    ) {
        if (!empty($data['extra_bag'])) {
            $result['data'] = $this->GetExtraBagsProvider(
                $result['data']
            );
        }

        $carriers = $result['dictionaries']['carriers'] ?? [];

        $this->enrichOffers(
            $result['data'],
            $carriers
        );

        $this->applyMarkupToOffers($result['data']);

        $result['data'] = $this->sortCost(
            $result['data']
        );

        if (isset($result['meta'])) {
            $result['meta']['count'] = count(
                $result['data']
            );
        }
    }

    unset($result['accessToken'], $result['client']);

    // Return the reference to the API consumer.
    $result['ama_client_ref'] = $amaClientRef;

    return response()->json(
        $result,
        $result['httpCode'] ?? 200
    );
}


    /*
    |--------------------------------------------------------------------------
    | Confirm flight price
    |--------------------------------------------------------------------------
    */

    private function confirmStatus(
        string $accessToken,
        array $data,
        ?string $amaClient
    ): array {
        $url = $this->amadeusConfig('confirm_price');

        if (!is_string($url) || trim($url) === '') {
            return [
                'errors' => [[
                    'title' => 'Price confirmation endpoint is not configured.',
                ]],
                'httpCode' => 500,
            ];
        }

        try {
            $request = $this->amadeusRequest()
                ->withToken($accessToken);

            if ($amaClient !== null && $amaClient !== '') {
                $request = $request->withHeaders([
                    'Ama-Client' => $amaClient,
                ]);
            }

            $response = $request->post($url, $data);
            $responseData = $response->json();

            if (!is_array($responseData)) {
                $responseData = [
                    'errors' => [[
                        'title' => 'Invalid response from Amadeus.',
                    ]],
                ];
            }

            if (
                isset($responseData['data']['flightOffers']) &&
                is_array($responseData['data']['flightOffers'])
            ) {
                $airportMap = [];

                foreach ($this->getAirportData() as $airport) {
                    if (!empty($airport['iata_code'])) {
                        $airportMap[$airport['iata_code']] =
                            $airport['name']
                            ?? $airport['iata_code'];
                    }
                }

                foreach (
                    $responseData['data']['flightOffers']
                    as &$offer
                ) {
                    foreach (
                        ($offer['itineraries'] ?? [])
                        as &$itinerary
                    ) {
                        foreach (
                            ($itinerary['segments'] ?? [])
                            as &$segment
                        ) {
                            $departureCode =
                                $segment['departure']['iataCode'] ?? null;

                            $arrivalCode =
                                $segment['arrival']['iataCode'] ?? null;

                            if ($departureCode) {
                                $segment['departure']['airport_name'] =
                                    $airportMap[$departureCode]
                                    ?? $departureCode;
                            }

                            if ($arrivalCode) {
                                $segment['arrival']['airport_name'] =
                                    $airportMap[$arrivalCode]
                                    ?? $arrivalCode;
                            }
                        }

                        unset($segment);
                    }

                    unset($itinerary);
                }

                unset($offer);
            }

            $responseData['httpCode'] = $response->status();

            if ($response->failed()) {
                Log::warning(
                    'Amadeus price confirmation returned an error.',
                    [
                        'status' => $response->status(),
                    ]
                );
            }

            return $responseData;
        } catch (Throwable $e) {
            Log::error('Amadeus price confirmation failed.', [
                'exception' => $e::class,
            ]);

            return [
                'errors' => [[
                    'title' => 'Price confirmation failed',
                    'detail' => 'Unable to confirm the flight price.',
                ]],
                'httpCode' => 502,
            ];
        }
    }

    private function confirmStatusExtra(
        string $accessToken,
        array $data,
        ?string $amaClient
    ): array {
        $url = $this->amadeusConfig('extra_bag');

        if (!is_string($url) || trim($url) === '') {
            return [
                'errors' => [[
                    'title' => 'Extra baggage endpoint is not configured.',
                ]],
                'httpCode' => 500,
            ];
        }

        $cost = $this->getBagCharge($data);

        try {
            $request = $this->amadeusRequest()
                ->withToken($accessToken);

            if ($amaClient !== null && $amaClient !== '') {
                $request = $request->withHeaders([
                    'Ama-Client' => $amaClient,
                ]);
            }

            $response = $request->post($url, $data);
            $responseData = $response->json();

            if (!is_array($responseData)) {
                return [
                    'errors' => [[
                        'title' => 'Invalid extra baggage response.',
                    ]],
                    'httpCode' => $response->status(),
                ];
            }

            if (
                isset($responseData['data']['flightOffers']) &&
                is_array($responseData['data']['flightOffers'])
            ) {
                foreach (
                    $responseData['data']['flightOffers']
                    as &$offer
                ) {
                    $offer['additionalServices'] = [
                        'amount' => (float) $cost['cost'],
                        'type' => 'CHECKED_BAGS',
                    ];

                    if (isset($offer['price']['grandTotal'])) {
                        $offer['price']['grandTotal'] =
                            (float) $offer['price']['grandTotal']
                            + (float) $cost['cost'];
                    }

                    if (isset($offer['travelerPricings'])) {
                        $offer['travelerPricings'] =
                            $this->prepare_bag_quantity(
                                $offer['travelerPricings'],
                                (int) $cost['no_bags']
                            );
                    }

                    foreach (
                        ($offer['itineraries'] ?? [])
                        as &$itinerary
                    ) {
                        foreach (
                            ($itinerary['segments'] ?? [])
                            as &$segment
                        ) {
                            $segment['departure']['airport_name'] ??=
                                $segment['departure']['iataCode'] ?? null;

                            $segment['arrival']['airport_name'] ??=
                                $segment['arrival']['iataCode'] ?? null;
                        }

                        unset($segment);
                    }

                    unset($itinerary);
                }

                unset($offer);
            }

            $responseData['httpCode'] = $response->status();

            return $responseData;
        } catch (Throwable $e) {
            Log::error(
                'Extra baggage price confirmation failed.',
                ['exception' => $e::class]
            );

            return [
                'errors' => [[
                    'title' => 'Extra baggage price confirmation failed',
                ]],
                'httpCode' => 502,
            ];
        }
    }

    private function getCarrierCodes(
        array $responseData,
        string $accessToken
    ): array {
        $codes = [];

        foreach (
            ($responseData['data']['flightOffers'] ?? [])
            as $offer
        ) {
            foreach (($offer['itineraries'] ?? []) as $itinerary) {
                foreach (($itinerary['segments'] ?? []) as $segment) {
                    if (!empty($segment['carrierCode'])) {
                        $codes[] = $segment['carrierCode'];
                    }
                }
            }
        }

        $codes = array_values(array_unique($codes));
        $url = $this->amadeusConfig('carrier_search');

        if (
            !$codes ||
            !is_string($url) ||
            trim($url) === ''
        ) {
            return ['data' => []];
        }

        try {
            $response = $this->amadeusRequest()
                ->withToken($accessToken)
                ->get($url, [
                    'airlineCodes' => implode(',', $codes),
                ]);

            $carrierData = $response->json();

            return $response->successful() &&
                is_array($carrierData)
                ? $carrierData
                : ['data' => []];
        } catch (Throwable $e) {
            Log::warning('Unable to retrieve carrier names.', [
                'exception' => $e::class,
            ]);

            return ['data' => []];
        }
    }



public function confirmPrice(FlightOffersPrice $request): JsonResponse
{
    $cache = Cache::store('file');

    // Keep this consistent with your existing confirmation TTL.
    $ttl = 900;

    // This is a session reference, not an authorization token.
    $amaClient = trim(
        (string) $request->header('ama-client-ref')
    );

    if ($amaClient === '') {
        return response()->json([
            'status' => false,
            'message' => 'The ama-client-ref header is required.',
        ], 422);
    }

    $session = $this->findFlightSearchSession($amaClient);

    if (!$session) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid or unauthorized flight search session.',
        ], 404);
    }

    $validated = $request->validated();
    $data = $validated['data'] ?? null;

    if (!is_array($data) || empty($data)) {
        return response()->json([
            'status' => false,
            'message' => 'Valid flight offer data is required.',
        ], 422);
    }

    try {
        $fingerprint = $this->requestFingerprint($data);
    } catch (\Throwable $e) {
        Log::warning('Unable to fingerprint flight confirmation.', [
            'session_id' => $session->id,
            'exception' => $e->getMessage(),
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Unable to validate the flight offer data.',
        ], 422);
    }

    $lockKey = self::CONFIRM_LOCK_PREFIX . $session->id;

    try {
        return $cache->lock($lockKey, 120)->block(
            10,
            function () use (
                $cache,
                $session,
                $validated,
                $data,
                $amaClient,
                $fingerprint,
                $ttl
            ): JsonResponse {
                $session->refresh();

                Log::info('Flight confirmation cache diagnostic', [
                    'ama_client_ref' => $amaClient,
                    'session_id' => $session->id,
                    'status' => $session->status,
                    'stored_fingerprint' =>
                        $session->confirmation_fingerprint,
                    'incoming_fingerprint' => $fingerprint,
                    'fingerprint_matches' =>
                        is_string($session->confirmation_fingerprint)
                        && hash_equals(
                            $session->confirmation_fingerprint,
                            $fingerprint
                        ),
                    'stored_idempotency_key' =>
                        $session->idempotency_key,
                    'stored_cache_key' =>
                        $session->confirmed_offer_key,
                    'cache_exists' => $session->confirmed_offer_key
                        ? $cache->has($session->confirmed_offer_key)
                        : false,
                ]);

                /*
                 * 1. Reuse a previous confirmation when the request
                 * fingerprint matches the stored fingerprint.
                 */
                $hasPreviousConfirmation =
                    $session->status === 'confirmed'
                    && !empty($session->confirmation_fingerprint)
                    && !empty($session->idempotency_key)
                    && !empty($session->confirmed_offer_key);

                $sameConfirmation = $hasPreviousConfirmation
                    && hash_equals(
                        (string) $session->confirmation_fingerprint,
                        $fingerprint
                    );

                if ($sameConfirmation) {
                    if (
                        $session->expires_at === null
                        || !$session->expires_at->isFuture()
                    ) {
                        return response()->json([
                            'status' => false,
                            'message' =>
                                'This confirmation has expired. Please start a new flight search.',
                            'ama_client_ref' => $amaClient,
                        ], 410);
                    }

                    $cached = $cache->get(
                        $session->confirmed_offer_key
                    );

                    if (
                        !is_array($cached)
                        || !isset($cached['response'])
                        || !is_array($cached['response'])
                        || ($cached['session_id'] ?? null)
                            != $session->id
                        || !isset($cached['fingerprint'])
                        || !hash_equals(
                            (string) $cached['fingerprint'],
                            $fingerprint
                        )
                    ) {
                        Log::warning(
                            'Confirmed flight cache missing or inconsistent.',
                            [
                                'ama_client_ref' => $amaClient,
                                'session_id' => $session->id,
                                'idempotency_key' =>
                                    $session->idempotency_key,
                            ]
                        );

                        return response()->json([
                            'status' => false,
                            'message' =>
                                'The previous confirmation is recorded, but its cached response is unavailable. Please start a new flight search.',
                            'ama_client_ref' => $amaClient,
                            'idempotency_key' =>
                                $session->idempotency_key,
                        ], 409);
                    }

                    $response = $cached['response'];

                    unset(
                        $response['accessToken'],
                        $response['client']
                    );

                    $response['ama_client_ref'] = $amaClient;
                    $response['idempotency_key'] =
                        $session->idempotency_key;
                    $response['confirmed_at'] =
                        $session->confirmed_at?->toIso8601String();
                    $response['reused_confirmation'] = true;

                    return response()->json($response);
                }

                /*
                 * A previously confirmed session must not silently
                 * confirm a different offer under the same session.
                 */
                if ($session->status === 'confirmed') {
                    return response()->json([
                        'status' => false,
                        'message' =>
                            'This session already has a different confirmed offer. Please start a new flight search.',
                        'ama_client_ref' => $amaClient,
                    ], 409);
                }

                /*
                 * 2. Obtain the server-side Amadeus access token.
                 */
                $accessToken = $this->getAccessToken();

                if (!$accessToken) {
                    return response()->json([
                        'status' => false,
                        'message' =>
                            'Unable to authenticate with Amadeus.',
                    ], 502);
                }

                $confirmationPayload = $validated;

                /*
                 * 3. Handle optional extra baggage.
                 */
                if (!empty($data['extra_bag'])) {
                    $result = $this->confirmStatusExtra(
                        $accessToken,
                        $validated,
                        $amaClient
                    );

                    if ($this->hasAccessTokenExpired($result)) {
                        $accessToken = $this->getAccessToken(
                            true,
                            $accessToken
                        );

                        if (!$accessToken) {
                            return response()->json([
                                'status' => false,
                                'message' =>
                                    'Unable to refresh the Amadeus token.',
                            ], 502);
                        }

                        $result = $this->confirmStatusExtra(
                            $accessToken,
                            $validated,
                            $amaClient
                        );
                    }

                    if (
                        $this->hasAccessTokenExpired($result)
                        || !empty($result['errors'])
                    ) {
                        return response()->json(
                            $result,
                            (int) ($result['httpCode'] ?? 422)
                        );
                    }

                    // Preserve your existing helper's payload contract.
                    $confirmationPayload = $result;
                }

                /*
                 * 4. Confirm the flight price.
                 */
                $result = $this->confirmStatus(
                    $accessToken,
                    $confirmationPayload,
                    $amaClient
                );

                if ($this->hasAccessTokenExpired($result)) {
                    $accessToken = $this->getAccessToken(
                        true,
                        $accessToken
                    );

                    if (!$accessToken) {
                        return response()->json([
                            'status' => false,
                            'message' =>
                                'Unable to refresh the Amadeus token.',
                        ], 502);
                    }

                    $result = $this->confirmStatus(
                        $accessToken,
                        $confirmationPayload,
                        $amaClient
                    );
                }

                if ($this->hasAccessTokenExpired($result)) {
                    return response()->json([
                        'status' => false,
                        'message' =>
                            'Amadeus authentication failed after token refresh.',
                    ], 502);
                }

                if (
                    !empty($result['errors'])
                    || !isset($result['data']['flightOffers'])
                    || !is_array($result['data']['flightOffers'])
                    || empty($result['data']['flightOffers'])
                ) {
                    return response()->json(
                        $result,
                        (int) ($result['httpCode'] ?? 422)
                    );
                }

                /*
                 * 5. Enrich the confirmed offers and apply markup.
                 */
                $carriers = $this->getCarrierCodes(
                    $result,
                    $accessToken
                );

                $carrierNames = [];

                foreach (($carriers['data'] ?? []) as $carrier) {
                    if (!empty($carrier['iataCode'])) {
                        $carrierNames[$carrier['iataCode']] =
                            $carrier['commonName']
                            ?? $carrier['businessName']
                            ?? $carrier['iataCode'];
                    }
                }

                $this->enrichOffers(
                    $result['data']['flightOffers'],
                    $carrierNames
                );

                $this->applyMarkupToOffers(
                    $result['data']['flightOffers']
                );

                /*
                 * 6. Extract the final confirmed flight price.
                 *
                 * This assumes the confirmation response contains
                 * the selected offer at index zero.
                 */
                $confirmedOffer =
                    $result['data']['flightOffers'][0] ?? null;

                $price = is_array($confirmedOffer)
                    ? ($confirmedOffer['price'] ?? [])
                    : [];

                $flightCurrency = $price['currency'] ?? null;
                $flightFee = $price['grandTotal'] ?? null;
                $serviceFee = $price['service_charge'] ?? null;
                $totalPayable = $price['ffsd_total'] ?? null;

                if (
                    !is_string($flightCurrency)
                    || !preg_match('/^[A-Z]{3}$/', $flightCurrency)
                    || !is_numeric($flightFee)
                    || !is_numeric($serviceFee)
                    || !is_numeric($totalPayable)
                    || (float) $flightFee <= 0
                    || (float) $serviceFee < 0
                    || (float) $totalPayable <= 0
                ) {
                    Log::error(
                        'Invalid confirmed flight pricing.',
                        [
                            'ama_client_ref' => $amaClient,
                            'session_id' => $session->id,
                            'flight_currency' => $flightCurrency,
                            'flight_fee' => $flightFee,
                            'service_fee' => $serviceFee,
                            'total_payable' => $totalPayable,
                        ]
                    );

                    return response()->json([
                        'status' => false,
                        'message' =>
                            'Unable to determine the final flight payment amount.',
                    ], 502);
                }

                /*
                 * 7. Generate a stable confirmation idempotency key
                 * and a cache key for this successful confirmation.
                 */
                $idempotencyKey = (string) \Illuminate\Support\Str::uuid();

                $cacheKey = 'flight:confirmed:' . $idempotencyKey;

                $confirmedAt = now();

                $expiresAt = $confirmedAt->copy()
                    ->addSeconds($ttl);

                /*
                 * 8. Persist confirmation and pricing in the database.
                 */
                $session->confirmation_fingerprint = $fingerprint;
                $session->idempotency_key = $idempotencyKey;
                $session->confirmed_offer_key = $cacheKey;
                $session->status = 'confirmed';
                $session->confirmed_at = $confirmedAt;
                $session->expires_at = $expiresAt;

                $session->flight_fee = $flightFee;
                $session->flight_currency = $flightCurrency;
                $session->service_fee = $serviceFee;
                $session->total_payable = $totalPayable;

                $session->save();
                $session->refresh();

                Log::info(
                    'Confirmed flight pricing persisted.',
                    [
                        'ama_client_ref' => $amaClient,
                        'session_id' => $session->id,
                        'confirmation_fingerprint_saved' =>
                            $session->confirmation_fingerprint,
                        'fingerprint_matches' =>
                            is_string($session->confirmation_fingerprint)
                            && hash_equals(
                                $fingerprint,
                                $session->confirmation_fingerprint
                            ),
                        'idempotency_key' =>
                            $session->idempotency_key,
                        'flight_currency' =>
                            $session->flight_currency,
                        'flight_fee' => $session->flight_fee,
                        'service_fee' => $session->service_fee,
                        'total_payable' => $session->total_payable,
                    ]
                );

                /*
                 * 9. Prepare and cache the successful response.
                 */
                $response = $result;

                unset(
                    $response['accessToken'],
                    $response['client']
                );

                $response['ama_client_ref'] = $amaClient;
                $response['idempotency_key'] = $idempotencyKey;
                $response['confirmed_at'] =
                    $confirmedAt->toIso8601String();
                $response['reused_confirmation'] = false;

                $cachePayload = [
                    'session_id' => $session->id,
                    'fingerprint' => $fingerprint,
                    'response' => $response,
                ];

                $cache->put(
                    $cacheKey,
                    $cachePayload,
                    $ttl
                );

                return response()->json($response);
            }
        );
    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
        return response()->json([
            'status' => false,
            'message' =>
                'Another confirmation request is being processed. Please try again shortly.',
            'ama_client_ref' => $amaClient,
        ], 409);
    } catch (\Throwable $e) {
        Log::error('Flight price confirmation failed.', [
            'ama_client_ref' => $amaClient,
            'session_id' => $session->id ?? null,
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'status' => false,
            'message' =>
                'An unexpected error occurred while confirming the flight price.',
            'ama_client_ref' => $amaClient,
        ], 500);
    }
}


    /*
    |--------------------------------------------------------------------------
    | Flight booking and idempotency
    |--------------------------------------------------------------------------
    */

 
public function bookFlight(
    FlightBookRequest $request
): JsonResponse 
{
    $data = $request->validated();
  
    

    $idempotencyKey = trim(
        (string) $request->header('idempotency_key', '')
    );

    if ($idempotencyKey === '') {
        return response()->json([
            'success' => false,
            'message' => 'The idempotency_key header is required.',
        ], 422);
    }

    $amaClient = trim(
        (string) $request->header('ama-client-ref', '')
    );

    if ($amaClient === '') {
        return response()->json([
            'success' => false,
            'message' => 'The ama-client-ref header is required.',
        ], 422);
    }

    $session = $this->findFlightSearchSession($amaClient);

    if (!$session) {
        return response()->json([
            'success' => false,
            'message' => 'The flight search session is invalid or unauthorized.',
        ], 403);
    }

    // Validate traveler information before attempting a booking.
    if (
        empty($data['data'])
        || !is_array($data['data'])
        || empty($data['data']['travelers'])
        || !is_array($data['data']['travelers'])
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Traveler information is required.',
        ], 422);
    }

    try {
        return Cache::store('file')
            ->lock(
                self::BOOKING_LOCK_PREFIX . $idempotencyKey,
                180
            )
            ->block(15, function () use (
                $data,
                $idempotencyKey,
                $session,
                $amaClient
            ): JsonResponse {
                $cache = Cache::store('file');

                /*
                 * 1. Return an existing booking before checking session
                 * expiry, so legitimate retries remain idempotent.
                 */
                $existingOrder = FlightOrders::where(
                    'idempotency_key',
                    $idempotencyKey
                )->first();

                if ($existingOrder) {
                    // Prevent reuse of another session's idempotency key.
                    if (
                        (int) $existingOrder->flight_search_session_id
                        !== (int) $session->id
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' => 'The idempotency key does not belong to this flight session.',
                        ], 403);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'This booking was already processed.',
                        'idempotency_key' => $idempotencyKey,
                        'flight_order_id' => $existingOrder->id,
                        'pnr' => $existingOrder->pnr,
                    ]);
                }

                /*
                 * 2. Refresh and validate the current session.
                 */
               $session->refresh();

                if (
                    $session->status !== 'confirmed'
                    || !is_string($session->idempotency_key)
                    || !hash_equals(
                        $session->idempotency_key,
                        $idempotencyKey
                    )
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The confirmed flight session is invalid or unauthorized.',
                    ], 403);
                }

                if (
                    $session->expires_at === null
                    || !$session->expires_at->isFuture()
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The confirmed flight offer has expired. Confirm the price again.',
                    ], 410);
                }

                /*
                 * 3. Read the cache using the same structure written
                 * by confirmPrice():
                 *
                 * [
                 *     'session_id' => ...,
                 *     'fingerprint' => ...,
                 *     'response' => [
                 *         'data' => [
                 *             'flightOffers' => [...]
                 *         ]
                 *     ]
                 * ]
                 */
                $cacheKey = self::CONFIRMED_FLIGHT_PREFIX
                    . $idempotencyKey;

                $confirmed = $cache->get($cacheKey);

                if (
                    !is_array($confirmed)
                    || (int) ($confirmed['session_id'] ?? 0)
                        !== (int) $session->id
                    || !isset($confirmed['response'])
                    || !is_array($confirmed['response'])
                    || !isset($confirmed['fingerprint'])
                    || !is_string($confirmed['fingerprint'])
                    || $confirmed['fingerprint'] === ''
                    || !is_string($session->confirmation_fingerprint)
                    || !hash_equals(
                        $session->confirmation_fingerprint,
                        $confirmed['fingerprint']
                    )
                ) {
                    Log::warning(
                        'Confirmed flight cache is missing or inconsistent.',
                        [
                            'ama_client_ref' => $amaClient,
                            'session_id' => $session->id,
                            'idempotency_key' => $idempotencyKey,
                        ]
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'The confirmed flight offer is unavailable or invalid. Confirm the price again.',
                    ], 410);
                }

                $confirmedOffers =
                    $confirmed['response']['data']['flightOffers'] ?? null;

                if (
                    !is_array($confirmedOffers)
                    || empty($confirmedOffers)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The cached flight confirmation contains no valid offers. Confirm the price again.',
                    ], 410);
                }

                /*
                 * 4. Use only the server-confirmed offers.
                 * Do not trust flightOffers submitted by the client.
                 */
                $data['data']['flightOffers'] = $confirmedOffers;

                /*
                 * 5. Authenticate with Amadeus and submit the booking.
                 */
                $accessToken = $this->getAccessToken();

                if (!$accessToken) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to authenticate with Amadeus.',
                    ], 502);
                }

                $feedback = $this->generatePNR(
                    $data,
                    $accessToken,
                    $amaClient
                );

                if ($this->hasAccessTokenExpired($feedback)) {
                    $accessToken = $this->getAccessToken(
                        true,
                        $accessToken
                    );

                    if (!$accessToken) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to refresh Amadeus authentication.',
                        ], 502);
                    }

                    $feedback = $this->generatePNR(
                        $data,
                        $accessToken,
                        $amaClient
                    );
                }

                if (
                    $this->hasAccessTokenExpired($feedback)
                    || !empty($feedback['errors'])
                    || empty($feedback['data']['id'])
                ) {
                    unset(
                        $feedback['accessToken'],
                        $feedback['client']
                    );

                    Log::error('Amadeus flight booking failed.', [
                        'ama_client_ref' => $amaClient,
                        'session_id' => $session->id,
                        'idempotency_key' => $idempotencyKey,
                        'status' => $feedback['httpCode'] ?? null,
                    ]);

                    $statusCode = (int) ($feedback['httpCode'] ?? 400);

                    if ($statusCode < 400 || $statusCode > 599) {
                        $statusCode = 400;
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Flight booking failed.',
                        'errors' => $feedback['errors'] ?? [],
                    ], $statusCode);
                }

                /*
                 * 6. Persist the successful booking.
                 */
                    try {
    $flightOrderId = $this->StoreBooked(
        $feedback,
        $idempotencyKey,
        $session->id          //
    );
                } catch (Throwable $e) {
                    Log::critical(
                        'Amadeus booking succeeded but local persistence failed.',
                        [
                            'ama_client_ref' => $amaClient,
                            'session_id' => $session->id,
                            'idempotency_key' => $idempotencyKey,
                            'amadeus_order_id' =>
                                $feedback['data']['id'] ?? null,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]
                    );

                    return response()->json([
                        'success' => false,
                        'message' => 'The airline booking may have succeeded, but saving it locally failed. Contact support before retrying.',
                        'idempotency_key' => $idempotencyKey,
                    ], 500);
                }

                /*
                 * 7. Email failure must not undo a successful booking.
                 */
                try {
                    $this->sendEmail($feedback);
                } catch (Throwable $e) {
                    Log::error(
                        'Flight booking email notification failed.',
                        [
                            'flight_order_id' => $flightOrderId,
                            'idempotency_key' => $idempotencyKey,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]
                    );
                }

                /*
                 * 8. Mark the session as booked and remove the
                 * confirmed-offer cache.
                 */
               $bookedAt = now();

$session->update([
    'status' => 'booked',
    'booked_at' => $bookedAt,
]);

                $cache->forget($cacheKey);

                unset(
                    $feedback['accessToken'],
                    $feedback['client']
                );

                /*
                 * data.id is the Amadeus flight-order ID, not
                 * necessarily the airline's PNR/reference.
                 */
                $pnr = $feedback['data']['associatedRecords'][0]['reference']
                    ?? $feedback['data']['id'];

                return response()->json([
                    'success' => true,
                    'message' => 'Flight booked successfully.',
                    'idempotency_key' => $idempotencyKey,
                    'flight_order_id' => $flightOrderId,
                    'pnr' => $pnr,
                    'data' => $feedback,
                ]);
            });
    } catch (LockTimeoutException $e) {
        return response()->json([
            'success' => false,
            'message' => 'This booking request is already being processed. Check its status before retrying.',
            'idempotency_key' => $idempotencyKey,
        ], 409);
    } catch (Throwable $e) {
        Log::error('Flight booking request failed.', [
            'ama_client_ref' => $amaClient,
            'idempotency_key' => $idempotencyKey,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Unable to process the flight booking.',
        ], 500);
    }
}

private function generatePNR(
    array $info,
    string $accessToken,
    ?string $amaClient
): array {
    // Local testing only: set services.amadeus.use_static_booking = true
    if ($this->amadeusConfig('use_static_booking', false) === true) {
        return $this->staticBookingResponse();
    }

    $url = $this->amadeusConfig('flight_book');

    if (!is_string($url) || trim($url) === '') {
        return [
            'errors' => [[
                'title' => 'Flight booking endpoint is not configured.',
            ]],
            'httpCode' => 500,
        ];
    }

    try {
        $request = $this->amadeusRequest()
            ->withToken($accessToken);

        if ($amaClient !== null && $amaClient !== '') {
            $request = $request->withHeaders([
                'Ama-Client' => $amaClient,
            ]);
        }

        $response = $request->post($url, $info);
        $result = $response->json();

        if (!is_array($result)) {
            $result = [
                'errors' => [[
                    'title' => 'Invalid response from Amadeus.',
                ]],
            ];
        }

        $result['httpCode'] = $response->status();

        return $result;
    } catch (Throwable $e) {
        Log::error('Amadeus booking API request failed.', [
            'exception' => $e::class,
        ]);

        return [
            'errors' => [[
                'title' => 'Unable to contact the flight booking service.',
            ]],
            'httpCode' => 502,
        ];
    }
}

private function staticBookingResponse(): array
{
    $file = storage_path('app/testing/amadeus-booking-response.json');

    try {
        if (!is_file($file) || !is_readable($file)) {
            return [
                'errors' => [['title' => 'Static booking response not found.']],
                'httpCode' => 500,
            ];
        }

        $result = json_decode(
            file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($result)) {
            return [
                'errors' => [['title' => 'Invalid static booking response.']],
                'httpCode' => 500,
            ];
        }

        $result['httpCode'] = 201;

        return $result;
    } catch (Throwable $e) {
        Log::error('Failed to load static booking response.', [
            'exception' => $e::class,
        ]);

        return [
            'errors' => [['title' => 'Unable to load static booking response.']],
            'httpCode' => 500,
        ];
    }
}

// private function generatePNR(
//     array $info,
//     string $accessToken,
//     ?string $amaClient
// ): array {
//   $file = storage_path(
//         'app/testing/amadeus-booking-response.json'
//     );
//     try {
//         if (!is_file($file) || !is_readable($file)) {
//             Log::error('Amadeus static booking response file is missing or unreadable.', [
//                 'file' => $file,
//             ]);

//             return [
//                 'errors' => [[
//                     'title' => 'Static Amadeus booking response file not found or unreadable.',
//                 ]],
//                 'httpCode' => 500,
//             ];
//         }

//         $contents = file_get_contents($file);

//         if ($contents === false) {
//             throw new RuntimeException(
//                 'Unable to read the static Amadeus booking response file.'
//             );
//         }

//         $result = json_decode(
//             $contents,
//             true,
//             512,
//             JSON_THROW_ON_ERROR
//         );

//         if (!is_array($result)) {
//             return [
//                 'errors' => [[
//                     'title' => 'Invalid static Amadeus booking response.',
//                 ]],
//                 'httpCode' => 500,
//             ];
//         }

//         // Simulate a successful Amadeus booking response.
//         $result['httpCode'] = 201;

//         return $result;
//     } catch (Throwable $e) {
//         Log::error('Failed to load static Amadeus booking response.', [
//             'exception' => $e::class,
//             'message' => $e->getMessage(),
//         ]);

//         return [
//             'errors' => [[
//                 'title' => 'Unable to load the static flight booking response.',
//             ]],
//             'httpCode' => 500,
//         ];
//     }
// }


    /*
    |--------------------------------------------------------------------------
    | Persist the booked order
    |--------------------------------------------------------------------------
    */

   private function StoreBooked(
    array $bookingResponse,
    string $idempotencyKey,
    int $flightSearchSessionId          // ← new parameter
): int {
    $data = $bookingResponse['data'] ?? null;

    if (!is_array($data) || empty($data['id'])) {
        throw new \RuntimeException(
            'The booking response does not contain a flight order ID.'
        );
    }

    return DB::transaction(function () use (
        $data,
        $idempotencyKey,
        $flightSearchSessionId
    ): int {
        $existingOrder = FlightOrders::where(
            'idempotency_key',
            $idempotencyKey
        )->first();

        if ($existingOrder) {
            return (int) $existingOrder->id;
        }

        $data['queuingOfficeId'] =
            $data['queuingOfficeId']
            ?? $this->amadeusConfig('queuing_office_id');

        $validated = $this->validateFlightOrderData($data);

        $flightOrderId = FlightOrders::insertGetId([
            'type'                     => $validated['type'],
            'queueing_office_id'       => $validated['queuingOfficeId'],
            'pnr'                      => $validated['id'],
            'idempotency_key'          => $idempotencyKey,
            'flight_search_session_id' => $flightSearchSessionId, // ← now stored
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // … rest of the method unchanged (associatedRecords, travelers, etc.) …
        if (!empty($data['associatedRecords'])) {
            $this->createAssociatedRecords($data['associatedRecords'], $flightOrderId);
        }
        if (!empty($data['travelers'])) {
            $this->createTravelers($data['travelers'], $flightOrderId);
        }
        if (!empty($data['flightOffers'])) {
            $this->createFlightOffers($data['flightOffers'], $flightOrderId, $validated['id']);
        }
        if (!empty($data['ticketingAgreement'])) {
            $this->createTicketingAgreement($data['ticketingAgreement'], $flightOrderId);
        }
        if (!empty($data['automatedProcess'])) {
            $this->createAutomatedProcesses($data['automatedProcess'], $flightOrderId);
        }

        $this->TicketStatus($flightOrderId, $validated['id']);

        return (int) $flightOrderId;
    }, 3);
}

    private function validateFlightOrderData(
        array $data
    ): array {
        $validator = Validator::make($data, [
            'type' => ['required', 'string'],
            'queuingOfficeId' => ['required', 'string'],
            'id' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function createAssociatedRecords(
        array $associatedRecords,
        int $flightOrderId
    ): void {
        foreach ($associatedRecords as $record) {
            AssociatedRecords::create([
                'flight_order_id' => $flightOrderId,
                'reference' => $record['reference'] ?? null,
                'creation_date' => $record['creationDate'] ?? null,
                'origin_system_code' =>
                    $record['originSystemCode'] ?? null,
                'flight_offer_id' => $record['flightOfferId'] ?? null,
            ]);
        }
    }

    private function createFlightOffers(
        array $flightOffers,
        int $flightOrderId,
        string $pnr
    ): void {
        foreach ($flightOffers as $offer) {
            $flightOffer = FlightOffers::create([
                'flight_order_id' => $flightOrderId,
                'am_flight_offer_id' => $offer['id'] ?? null,
                'type' => $offer['type'] ?? null,
                'source' => $offer['source'] ?? null,
                'non_homogeneous' =>
                    $offer['nonHomogeneous'] ?? false,
                'last_ticketing_date' =>
                    $offer['lastTicketingDate'] ?? null,
                'price' => json_encode($offer['price'] ?? []),
                'pricing_options' =>
                    json_encode($offer['pricingOptions'] ?? []),
                'validating_airline_codes' => json_encode(
                    $offer['validatingAirlineCodes'] ?? []
                ),
            ]);

            if (!empty($offer['itineraries'])) {
                $this->createItineraries(
                    $offer['itineraries'],
                    $flightOffer->id
                );
            }

            if (!empty($offer['travelerPricings'])) {
                $this->createTravelerPricings(
                    $offer['travelerPricings'],
                    $flightOffer->id,
                    $pnr,
                   $flightOrderId 
                    
                );
            }
        }
    }

    private function createItineraries(
        array $itineraries,
        int $flightOfferId
    ): void {
        foreach ($itineraries as $itineraryData) {
            $segments = $itineraryData['segments'] ?? [];

            $itinerary = Itinerary::create([
                'flight_offer_id' => $flightOfferId,
                'segments' => json_encode($segments),
            ]);

            if ($segments) {
                $this->createSegments(
                    $segments,
                    $itinerary->id
                );
            }
        }
    }

    private function createSegments(
        array $segments,
        int $itineraryId
    ): void {
        foreach ($segments as $segment) {
            $departure = $segment['departure']['at'] ?? null;
            $arrival = $segment['arrival']['at'] ?? null;
            $duration = null;

            if ($departure && $arrival) {
                try {
                    $seconds = max(
                        0,
                        Carbon::parse($arrival)->getTimestamp()
                        - Carbon::parse($departure)->getTimestamp()
                    );

                    $duration = sprintf(
                        '%02d:%02d:%02d',
                        intdiv($seconds, 3600),
                        intdiv($seconds % 3600, 60),
                        $seconds % 60
                    );
                } catch (Throwable $e) {
                    $duration = null;
                }
            }

            Segments::create([
                'am_segment_id' => $segment['id'] ?? null,
                'itinerary_id' => $itineraryId,
                'departure' =>
                    json_encode($segment['departure'] ?? []),
                'arrival' =>
                    json_encode($segment['arrival'] ?? []),
                'carrier_code' => $segment['carrierCode'] ?? null,
                'number' => $segment['number'] ?? null,
                'aircraft' => json_encode($segment['aircraft'] ?? []),
                'duration' => $duration,
                'number_of_stops' => $segment['numberOfStops'] ?? 0,
                'co2_emissions' =>
                    json_encode($segment['co2Emissions'] ?? []),
            ]);
        }
    }

    private function createTravelerPricings(
        array $travelerPricings,
        int $flightOfferId,
        string $pnr,
          $flightOrderId 
    ): void {
        foreach ($travelerPricings as $pricing) {
            $price = $pricing['price'] ?? [];

            $travelerPricing = TravelerPricing::create([
                'flight_pnr' => $pnr,
                'am_traveler_pricing_id' =>
                    $pricing['travelerId'] ?? null,
                'flight_offer_id' => $flightOfferId,
                'traveler_id' => $pricing['travelerId'] ?? null,
                'fare_option' => $pricing['fareOption'] ?? null,
                'traveler_type' => $pricing['travelerType'] ?? null,
                'total_price' => $price['total'] ?? 0,
                'base_price' => $price['base'] ?? 0,
                'taxes' => json_encode($price['taxes'] ?? []),
                'refundable_taxes' =>
                    $price['refundableTaxes'] ?? null,
            ]);

            if (!empty($pricing['fareDetailsBySegment'])) {
                $this->createFareDetails(
                    $pricing['fareDetailsBySegment'],
                    $travelerPricing->id,
                    $flightOrderId
                );
            }
        }
    }


    private function createFareDetails(
    array $fareDetailsBySegment,
    int $travelerPricingId,
    int $flightOrderId,
): void {
    foreach ($fareDetailsBySegment as $fareDetail) {
        $amSegmentId = (string) (
            $fareDetail['segmentId'] ?? ''
        );

        if ($amSegmentId === '') {
            throw new \RuntimeException(
                'Fare detail is missing its Amadeus segment ID.'
            );
        }

        $segment = Segments::where(
            'am_segment_id',
            $amSegmentId
        )->first();

        if (!$segment) {
            throw new \RuntimeException(
                "No local segment found for Amadeus segment ID: {$amSegmentId}"
            );
        }

        FareDetail::create([
            'traveler_pricing_id' => $travelerPricingId,

            // Local segments.id
            'segment_id' => $segment->id,
             'flight_order_id' => $flightOrderId,

            // Amadeus segment identifier
            'am_segment_id' => $amSegmentId,

            'cabin' => $fareDetail['cabin'] ?? "Unknown",
            'fare_basis' => $fareDetail['fareBasis'] ?? null,
            'class' => $fareDetail['class'] ?? null,

            'included_checked_bags' => json_encode(
                $fareDetail['includedCheckedBags'] ?? [],
                JSON_THROW_ON_ERROR
            ),
        ]);
    }
}

    private function createTravelers(
        array $travelers,
        int $flightOrderId
    ): void {
        foreach ($travelers as $traveler) {
            if (
                empty($traveler['dateOfBirth']) ||
                empty($traveler['gender']) ||
                empty($traveler['name']['firstName']) ||
                empty($traveler['name']['lastName'])
            ) {
                throw new \RuntimeException(
                    'Required traveler data is missing.'
                );
            }

            Travelers::create([
                'flight_order_id' => $flightOrderId,
                'date_of_birth' => $traveler['dateOfBirth'],
                'gender' => $traveler['gender'],
                'first_name' => $traveler['name']['firstName'],
                'last_name' => $traveler['name']['lastName'],
                'documents' =>
                    json_encode($traveler['documents'] ?? []),
                'contact' =>
                    json_encode($traveler['contact'] ?? []),
                'am_traveler_id' => $traveler['id'] ?? null,
            ]);
        }
    }

    private function createTicketingAgreement(
        array $ticketingAgreement,
        int $flightOrderId
    ): void {
        TicketAgreements::create([
            'flight_order_id' => $flightOrderId,
            'option' => $ticketingAgreement['option'] ?? null,
            'delay' => $ticketingAgreement['delay'] ?? null,
        ]);
    }

    private function createAutomatedProcesses(
        array $automatedProcesses,
        int $flightOrderId
    ): void {
        foreach ($automatedProcesses as $process) {
            AutomatedProcesses::create([
                'flight_order_id' => $flightOrderId,
                'code' => $process['code'] ?? null,
                'queue_number' => $process['queue']['number'] ?? null,
                'queue_category' =>
                    $process['queue']['category'] ?? null,
                'office_id' => $process['officeId'] ?? null,
            ]);
        }
    }

    private function TicketStatus(
        int $flightOrderId,
        string $pnr
    ): void {
        TicketStatus::create([
            'pnr' => $pnr,
            'flight_order_id' => $flightOrderId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Flight order lookup
    |--------------------------------------------------------------------------
    */

    public function getFlightOrder(
        ManageFlight $request
    ): JsonResponse {
        $params = $request->validated();

        $reference = rawurlencode(
            $params['flightOrderId']
        );

        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            return response()->json([
                'message' => 'Unable to authenticate with Amadeus.',
            ], 502);
        }

        $result = $this->fetchFlightDetails(
            $accessToken,
            $reference
        );

        if ($this->hasAccessTokenExpired($result)) {
            $accessToken = $this->getAccessToken(
                true,
                $accessToken
            );

            if (!$accessToken) {
                return response()->json([
                    'message' => 'Unable to refresh Amadeus authentication.',
                ], 502);
            }

            $result = $this->fetchFlightDetails(
                $accessToken,
                $reference
            );
        }

        unset($result['accessToken'], $result['client']);

        return response()->json(
            $result,
            $result['httpCode'] ?? 200
        );
    }

    private function fetchFlightDetails(
        string $accessToken,
        string $reference
    ): array {
        $baseUrl = $this->amadeusConfig('flight_details');

        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return [
                'errors' => [[
                    'title' => 'Flight order endpoint is not configured.',
                ]],
                'httpCode' => 500,
            ];
        }

        try {
            $response = $this->amadeusRequest()
                ->withToken($accessToken)
                ->get(
                    rtrim($baseUrl, '/') . '/' . $reference
                );

            $data = $response->json();

            if (!is_array($data)) {
                $data = [
                    'message' => 'Invalid response from Amadeus.',
                ];
            }

            $data['httpCode'] = $response->status();

            if ($response->failed() && empty($data['errors'])) {
                $data['errors'] = [[
                    'title' => 'Unable to fetch flight order',
                    'status' => $response->status(),
                ]];
            }

            return $data;
        } catch (Throwable $e) {
            Log::error('Unable to fetch flight order.', [
                'exception' => $e::class,
            ]);

            return [
                'errors' => [[
                    'title' => 'Unable to fetch flight order',
                    'status' => 502,
                ]],
                'httpCode' => 502,
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Branded fares
    |--------------------------------------------------------------------------
    */

    public function BrandedFares(
        BrandedUpsell $request
    ): JsonResponse {
        $data = $request->validated();

        $endpoint = $this->amadeusConfig('upselling');

        if (
            !is_string($endpoint) ||
            trim($endpoint) === ''
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upselling endpoint is not configured.',
            ], 500);
        }

        $flightOffers = $data['data']['flightOffers'] ?? [];
        $payments = $data['data']['payments'] ?? [];

        if (!is_array($flightOffers) || empty($flightOffers)) {
            return response()->json([
                'status' => 'error',
                'message' => 'At least one flight offer is required.',
            ], 422);
        }

        $payload = [
            'data' => [
                'type' => 'flight-offers-upselling',
                'flightOffers' => $flightOffers,
                'payments' => $payments,
            ],
        ];

        try {
            $accessToken = $this->getAccessToken();

            if (!$accessToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to authenticate with Amadeus.',
                ], 502);
            }

            // Retry once after an expired-token response.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $response = $this->amadeusRequest()
                    ->withToken($accessToken)
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    return response()->json([
                        'status' => 'success',
                        'data' => $response->json(),
                    ]);
                }

                $responseData = $response->json();

                if (
                    $attempt === 0 &&
                    is_array($responseData) &&
                    $this->hasAccessTokenExpired($responseData)
                ) {
                    $accessToken = $this->getAccessToken(
                        true,
                        $accessToken
                    );

                    if (!$accessToken) {
                        return response()->json([
                            'status' => 'error',
                            'message' =>
                                'Unable to refresh Amadeus authentication.',
                        ], 502);
                    }

                    continue;
                }

                Log::warning(
                    'Amadeus branded fares request failed.',
                    ['status' => $response->status()]
                );

                return response()->json([
                    'status' => 'error',
                    'message' =>
                        'Unable to retrieve branded fares.',
                    'errors' => is_array($responseData)
                        ? ($responseData['errors'] ?? [])
                        : [],
                ], $response->status() >= 400 &&
                    $response->status() <= 599
                        ? $response->status()
                        : 502);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to retrieve branded fares.',
            ], 502);
        } catch (Throwable $e) {
            Log::error('Branded fare request failed.', [
                'exception' => $e::class,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unable to retrieve branded fares.',
            ], 502);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Extra baggage
    |--------------------------------------------------------------------------
    */

    private function GetExtraBagsProvider(
        array $offers
    ): array {
        return array_values(array_filter(
            $offers,
            function ($offer): bool {
                foreach (
                    ($offer['price']['additionalServices'] ?? [])
                    as $service
                ) {
                    if (
                        ($service['type'] ?? null) === 'CHECKED_BAGS'
                    ) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    private function getBagCharge(array $offer): array
    {
        $weight = max(
            0,
            (float) ($offer['data']['extra_bag_weight'] ?? 0)
        );

        $charge = 0.0;

        $services =
            $offer['data']['flightOffers'][0]['price']['additionalServices']
            ?? [];

        foreach ($services as $service) {
            if (
                ($service['type'] ?? null) === 'CHECKED_BAGS'
            ) {
                $charge = (float) ($service['amount'] ?? 0)
                    * ceil($weight / 23);

                break;
            }
        }

        return [
            'cost' => $charge,
            'no_bags' => (int) ceil($weight / 23),
        ];
    }

    private function prepare_2nd_bag(
        array $response,
        float $cost
    ): array {
        return [
            'amount' => $cost,
            'type' => 'CHECKED_BAGS',
        ];
    }

    private function prepare_bag_quantity(
        array $travelerPricings,
        int $numberOfBags
    ): array {
        foreach ($travelerPricings as &$travelerPricing) {
            foreach (
                ($travelerPricing['fareDetailsBySegment'] ?? [])
                as &$fareSegment
            ) {
                $fareSegment['additionalServices']
                    ['chargeableCheckedBags'] = [
                        'quantity' => $numberOfBags,
                    ];
            }

            unset($fareSegment);
        }

        unset($travelerPricing);

        return $travelerPricings;
    }

    /*
    |--------------------------------------------------------------------------
    | Email notifications
    |--------------------------------------------------------------------------
    */

    private function sendEmail(array $flightData): void
    {
        $configuredRecipients = $this->amadeusConfig(
            'booked_recipients',
            '[]'
        );

        $recipients = is_array($configuredRecipients)
            ? $configuredRecipients
            : json_decode(
                (string) $configuredRecipients,
                true
            );

        if (!is_array($recipients) || empty($recipients)) {
            Log::warning(
                'No booked flight email recipients are configured.'
            );

            return;
        }

        $data = $flightData['data'] ?? [];

        if (
            empty($data['associatedRecords']) ||
            !is_array($data['associatedRecords'])
        ) {
            Log::warning(
                'Flight booking response has no associated records.'
            );

            return;
        }

        $pnrs = [];

        foreach ($data['associatedRecords'] as $record) {
            if (!empty($record['reference'])) {
                $pnrs[] = $record['reference'];
            }
        }

        foreach ($recipients as $recipient) {
            if (
                empty($recipient['email']) ||
                empty($recipient['full_name'])
            ) {
                continue;
            }

            Notification::route(
                'mail',
                $recipient['email']
            )->notify(
                new BookedNotification(
                    $data['id'] ?? '',
                    $pnrs,
                    $recipient['full_name']
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Optional authentication health endpoint
    |--------------------------------------------------------------------------
    |
    | This endpoint reports whether provider authentication is available.
    | It never returns the provider's actual access token.
    */

    public function getToken(): JsonResponse
    {
        if (!$this->getAccessToken()) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Flight provider authentication is unavailable.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Flight provider authentication is available.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Flight search session helpers
    |--------------------------------------------------------------------------
    */

    private function createFlightSearchSession(
        array $criteria
    ): FlightSearchSession {
        return DB::transaction(function () use ($criteria) {
            $session = FlightSearchSession::create([
                'ama_client_ref' => 'TMP-' . Str::uuid(),
               'user_id' => auth()->id() ?? null,
                'status' => 'searching',
                'search_fingerprint' =>
                    $this->requestFingerprint($criteria),
                'search_criteria' => $criteria,
            ]);

            $session->update([
                'ama_client_ref' => 'FFSD-' .
                    str_pad(
                        (string) $session->id,
                        6,
                        '0',
                        STR_PAD_LEFT
                    ),
            ]);

            return $session->fresh();
        });
    }

    private function requestFingerprint(array $data): string
{
    $normalize = function (mixed $value) use (&$normalize): mixed {
        if (!is_array($value)) {
            return $value;
        }

        // Preserve the order of lists, such as flight itineraries
        // and traveler arrays.
        if (array_is_list($value)) {
            return array_map($normalize, $value);
        }

        // Sort object-like arrays recursively.
        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $normalize($item);
        }

        return $value;
    };

    $normalizedData = $normalize($data);

    return hash(
        'sha256',
        json_encode(
            $normalizedData,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        )
    );
}

    /**
     * Authenticated users can only access their own sessions.
     *
     * Guest searches require a separate guest-session authorization
     * mechanism; a reference alone should not authorize access.
     */
private function findFlightSearchSession(
    string $amaClientRef
): ?FlightSearchSession {
    $query = FlightSearchSession::query()
        ->where('ama_client_ref', $amaClientRef);

    if (auth()->check()) {
        // Authenticated users can only access their own sessions.
        $query->where('user_id', auth()->id());
    } else {
        // Guests can access guest sessions only.
        $query->whereNull('user_id');
    }

    return $query->first();
}
}
