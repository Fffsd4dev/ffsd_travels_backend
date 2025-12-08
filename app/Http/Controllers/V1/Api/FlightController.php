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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use App\Models\MarkUp;
use Illuminate\Support\Carbon;
use App\Models\TicketStatus;
use Illuminate\Http\Request;
use App\Notifications\BookedNotification;
use App\Mail\BookedMailable;
use Illuminate\Support\Facades\Notification;

class FlightController extends Controller
{
    private $concatPrem;

    public function __construct()
    {
        $this->concatPrem = storage_path('cacert.pem');
    }


private function filterAirportsWithKeyword(array $airportData, string $keyword): \Generator
{
    foreach ($airportData as $airport) {
        if (!empty($airport['iata_code'])) {
            // Convert fields to lowercase once for comparison
            $iataCode = strtolower($airport['iata_code']);
            $name = strtolower($airport['name']);
            $city = strtolower($airport['city']);
            $country = strtolower($airport['country']);
            $time_zone = strtolower($airport['tz']);

            // Check if the keyword matches any of the fields
            if (
                strpos($iataCode, $keyword) !== false ||
                strpos($name, $keyword) !== false ||
                strpos($city, $keyword) !== false ||
                strpos($country, $keyword) !== false ||
                strpos($time_zone, $keyword) !== false
            ) {
                yield [
                    'iata' => $airport['iata_code'],
                    'name' => $airport['name'],
                    'city' => $airport['city'],
                    'country' => $airport['country'],
                    'time_zone' => $airport['tz'],
                ];
            }
        }
    }
}

/**
 * Calculate a match score for sorting airports based on match priority.
 *
 * @param array $airport
 * @param string $keyword
 * @return int
 */
private function getMatchScore(array $airport, string $keyword): int
{
    $score = 0;

    // Exact iata_code match
    if (strtolower($airport['iata']) === $keyword) {
        $score += 100; // Highest priority for exact iata_code match
    }
    
    // Exact city match
    if (strtolower($airport['city']) === $keyword) {
        $score += 10; // Medium priority for exact city match
    }

    // Partial country match
    if (strpos(strtolower($airport['country']), $keyword) !== false) {
        $score += 1; // Lower priority for partial country match
    }

    return $score;
}




    public function confirmPrice(FlightOffersPrice $request): JsonResponse
    {
        $validatedData = $request->validated();
        
      
        $extra = false;
        if(isset ($validatedData['data']['extra_bag'])){
            $extra = $validatedData['data']['extra_bag'];
            
            
        }
        //dd($validatedData);
      
        
        $accessToken = $this->getBearerToken($request->header('Authorization'));
  $amaClient = $this->getBearerToken($request->header('ama-client-ref') ?? null);

        if($extra){
            
        
            $bag = true;
             $confirmPriceExtra = $this->confirmStatusExtra($accessToken, $validatedData, $amaClient);  
            
        }else{
           $confirmPrice = $this->confirmStatus($accessToken, $validatedData,   $amaClient);  
           $bag = false;
            
        }

        
        if($bag){
             if ($this->hasAccessTokenExpired($confirmPriceExtra)) {
            $accessToken = $this->getAccessToken();
            $confirmPriceExtra = $this->confirmStatusExtra($accessToken, $validatedData,   $amaClient); 
            
        }
        
         $confirmPrice = $this->confirmStatus($accessToken,$confirmPriceExtra,   $amaClient); 
         
         
         //do the final pricing
            
        }else{
         
        
           if ($this->hasAccessTokenExpired($confirmPrice)) {
               
            $accessToken = $this->getAccessToken();
            $confirmPrice = $this->confirmStatus($accessToken, $validatedData,   $amaClient);  
            
            
            
        }
        
        
            
            
        }
        

        // Get carrier names if there is valid output
        if (isset($confirmPrice['data'])) {
            $carrier_names = $this->getCarrierCodes($confirmPrice);
            $flights = [];
            $confirmPrice['client'] = $accessToken ;
            // Check if the response contains airline data
            if (isset($carrier_names['data'])) {
                foreach ($carrier_names['data'] as $carrier) {
                    if (isset($carrier['iataCode']) && isset($carrier['commonName'])) {
                        $flights[$carrier['iataCode']] = $carrier['commonName'];
                    }
                }
            }

            // Add airline names and logos to each segment
            foreach ($confirmPrice['data']['flightOffers'] as &$flightOffer) {
                foreach ($flightOffer['itineraries'] as &$itinerary) {
                    foreach ($itinerary['segments'] as &$segment) {
                        $airlineCode = $segment['carrierCode'];
                        if (isset($flights[$airlineCode])) {
                            $segment['carrier_name'] = $flights[$airlineCode];
                            $segment['airlineLogo'] = $this->getAirlineLogo($airlineCode);
                        }
                    }
                }
            }
        }

        return response()->json($confirmPrice);
    }

  
//


public function citySearch(CitySearchRequest $request): JsonResponse
{
    // Validate and extract request parameters
    $params = $request->validated();
    $keyword = strtolower($params['keyword'] ?? '');

    // Fetch cached airport data
    $airportData = $this->getAirportData();
    // Filter and collect matching airports
    $airports = iterator_to_array($this->filterAirportsWithKeyword($airportData, $keyword));

    // Sort the results by priority: exact iata_code match, then exact city match, then partial country match
    usort($airports, function ($a, $b) use ($keyword) {
        // Create a score for each airport based on match criteria
        $scoreA = $this->getMatchScore($a, $keyword);
        $scoreB = $this->getMatchScore($b, $keyword);

        // Higher scores come first
        return $scoreB <=> $scoreA;
    });

    // Slice the sorted array to get the top 10 results
    $topAirports = array_slice($airports, 0, 15);

    // Return the top 10 sorted and filtered airports as a JSON response
    return response()->json($topAirports);
}





private function fetchApiData(string $url, string $accessToken, array $params): array
{
    
    // Cache the airport data
    $cacheKey = 'airports_iata_codes';
    $airportData = $this->getAirportData();

    
    // Create a lookup for airport names by IATA code
    $airportLookup = [];
    foreach ($airportData as $air_data) {
        $airportLookup[$air_data['iata_code']] = $air_data['name'];
        $airportLookupTimeZone[$air_data['iata_code']] = strtoupper($air_data['timezone']);
        $airportLookupCountry[$air_data['iata_code']] = strtoupper($air_data['country']);
        $airportLookupCity[$air_data['iata_code']] = strtoupper($air_data['city']);
    }


    // Cache the airline logos data
    $cacheKey = 'airlineLogos';
    $airlineLogos = $this->getAirlineLogos();
    
    // Create a lookup for airline logos by IATA code
    $airlineLogoData = [];
    foreach ($airlineLogos['data'] as $airline) {
        if (isset($airline['iata_code']) && isset($airline['logo'])) {
            $airlineLogoData[$airline['iata_code']] = $airline['logo'];
        }
    }

    // Make the API request
    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $accessToken,
    ])->withOptions([
        'verify' => $this->concatPrem, // Use the custom CA bundle file
        'verify_peer' => true, // Disable SSL certificate verification
        'verify_host' => true, // Disable host name verification
    ])->get($url, $params);

    // Decode the JSON response
    $responseData = $response->json();

    if (isset($responseData['errors'][0]['code']) && $responseData['errors'][0]['code'] === 38189) {
        $responseData = [];
        $responseData['message'] = 'Network failure';
    }

    $responseData['accessToken'] = $accessToken;

    // Check if dictionaries and locations are present
    if (isset($responseData['dictionaries'])) {
        $locations = $responseData['dictionaries']['locations'] ?? [];
        $carriers = $responseData['dictionaries']['carriers'] ?? [];

        // Initialize variables to avoid undefined variable errors
        $arrivalAirport = null;
        $departureAirport = null;
        $airlinelogo = null;

        // Loop through the response data to add airport_name and airlineName to each segment
        if (isset($responseData['data']) && is_array($responseData['data'])) {
            foreach ($responseData['data'] as &$flightOffer) {
                $this->applyPricing($flightOffer);
                if (isset($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
                    foreach ($flightOffer['itineraries'] as &$itinerary) {
                        if (isset($itinerary['segments']) && is_array($itinerary['segments'])) {
                           foreach ($itinerary['segments'] as $index => &$segment) {
                                // Add airport_name to departure and arrival locations
                                if (isset($segment['departure']['iataCode'])) {
                                    $iataCode = $segment['departure']['iataCode'];
                                    if (isset($airportLookup[$iataCode])) {
                                        $segment['departure_airport'] = $airportLookup[$iataCode];
                                        $departureAirport = $segment['departure_airport'];
                                        $departureTimeZone = $airportLookupTimeZone[$iataCode];
                                        $departureTime = $segment['departure']['at'];
                                        $segment['departureTimeZone'] = $departureTimeZone;
                                        $segment['departureCountry'] = $airportLookupCountry[$iataCode] ?? null;
                                        $segment['departureCity'] = $airportLookupCity[$iataCode] ?? null;
                                        

                                    }
                                }
                                if (isset($segment['arrival']['iataCode'])) {
                                    $iataCode = $segment['arrival']['iataCode'];
                                    if (isset($airportLookup[$iataCode])) {
                                        $segment['arrival_airport'] = $airportLookup[$iataCode];
                                        $segment['arrivalTimeZone'] = $airportLookupTimeZone[$iataCode];
                                        $segment['arrivalCountry'] = $airportLookupCountry[$iataCode] ?? null;
                                        $segment['arrivalCity'] = $airportLookupCity[$iataCode] ?? null;
                                    
                                        $duration = $this->getFlightDuration(
                                            $departureTime,
                                            $segment['arrival']['at'],
                                            $departureTimeZone,
                                            $segment['arrivalTimeZone'],
                                        );
                                        $segment['flight_duration'] = $duration;
                                        //$segment['layover_duration'] = $duration['layover_duration']; 
                                        if ($index > 0) {
                        $previousSegment = $itinerary['segments'][$index - 1];

                        $prevArrivalAt   = Carbon::parse($previousSegment['arrival']['at']);
                        $thisDepartureAt = Carbon::parse($segment['departure']['at']);

                        $layoverMinutes = $prevArrivalAt->diffInMinutes($thisDepartureAt);
                        $layoverHours   = floor($layoverMinutes / 60);
                        $layoverMins    = $layoverMinutes % 60;

                        $segment['layover_data'] = [
                            'layover_duration'   => sprintf('%dh %02dm', $layoverHours, $layoverMins),
                            'layover_in_minutes'  => $layoverMinutes,
                            'airport'     => $previousSegment['arrival']['iataCode'],
                            'airport_name'=> $previousSegment['arrival_airport'] 
                                           ?? $previousSegment['arrival']['iataCode'],
                            'airport_country' => $previousSegment['arrivalCountry'] ?? null,
                            'airport_city' => $previousSegment['arrivalCity'] ?? null
                        ];
                    }  
                                    }
                                }

                                // Add airlineName and logo to each segment
                                if (isset($segment['carrierCode'])) {
                                    $carrierCode = $segment['operating']['carrierCode'];
                                    if (isset($carriers[$carrierCode])) {
                                        $segment['airlineName'] = $carriers[$carrierCode];
                                    }
                                    // Check if the logo exists for the carrier code
                                    if (isset($airlineLogoData[$carrierCode])) {
                                        $segment['airlineLogo'] = substr($airlineLogoData[$carrierCode], 2);
                                        $airlinelogo = $segment['airlineLogo'];
                                    } else {
                                        $segment['airlineLogo'] = null; // Or set a default value if needed
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Add arrival and departure airports and logo to each flight offer
            foreach ($responseData['data'] as &$flightOffer) {
                $flightOffer['arrivalAirport'] = $arrivalAirport;
                $flightOffer['DepartureAirport'] = $departureAirport;
                $flightOffer['logo'] = $airlinelogo ? substr($airlinelogo, 2) : null;
            }
        }
    }

    return $responseData;
}

private function getFlightDuration($departureTime, $arrivalTime, $departureTimeZone, $arrivalTimeZone)
{
    // Calculate flight duration
   $departure = Carbon::parse($departureTime, $departureTimeZone);
    $arrival = Carbon::parse($arrivalTime, $arrivalTimeZone);
    $durationInSeconds = $arrival->getTimestamp() - $departure->getTimestamp();
    return gmdate("H:i:s", $durationInSeconds);
}

    private function confirmStatus(string $accessToken, array $data, string $amaClient): array
    {
     
        // Cache the airport data
        $cacheKey = 'airports_iata_codes';
         //Cache::remember($cacheKey, 60 * 60 * 720, function () {
            $jsonPath = storage_path('app/airports_iata_codes.json');
            $json = File::get($jsonPath); // Read file content
        $airportData = json_decode($json, true); // Decode JSON to associative array
       // });

        $airportMap = [];
        foreach ($airportData as $value) {
            $airportMap[$value['iata_code']] = $value['name'];
        }

        // Make the API request
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ])->withOptions([
            'verify' => $this->concatPrem, // Use the custom CA bundle file
            'verify_peer' => true, // Disable SSL certificate verification
            'verify_host' => true, // Disable host name verification
        ]) ->withHeaders(['Ama-Client' => $amaClient])
        ->post(env('CONFIRM_PRICE'), $data);

        $responseData = $response->json();

        // Add airport names to each segment if data is present
        if (isset($responseData['data']['flightOffers'])) {
            foreach ($responseData['data']['flightOffers'] as &$flightOffer) {
                 $this->applyPricing($flightOffer);
                if (isset($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
                    foreach ($flightOffer['itineraries'] as &$itinerary) {
                        if (isset($itinerary['segments']) && is_array($itinerary['segments'])) {
                            foreach ($itinerary['segments'] as &$segment) {
                                $departureCode = $segment['departure']['iataCode'];
                                $arrivalCode = $segment['arrival']['iataCode'];

                                if (isset($airportMap[$departureCode])) {
                                    $segment['departure']['airport_name'] = $airportMap[$departureCode];
                                }
                                if (isset($airportMap[$arrivalCode])) {
                                    $segment['arrival']['airport_name'] = $airportMap[$arrivalCode];
                                }
                            }
                        }
                    }
                }
            }
        }

        $responseData['httpCode'] = $response->status();
        $responseData['accessToken'] = $accessToken;

        // Log request and response for debugging
        Log::info('Amadeus Request Data:', ['data' => $data]);
        Log::info('Amadeus Response Data:', ['response' => $responseData]);

        // Check for specific error code
        if (isset($responseData['errors']) && is_array($responseData['errors'])) {
            foreach ($responseData['errors'] as $error) {
                if ($error['code'] == 38189) {
                    Log::error('Amadeus Internal Error', ['error' => $error]);
                }
            }
        }

        return $responseData;
    }

    private function getBearerToken(?string $authorizationHeader): ?string
    {
        return str_replace("Bearer ", "", $authorizationHeader);
    }

   

    private function getAirlineLogo(string $airlineCode): ?string
    {
        $cacheKey = 'airlineLogos';
        $airlineLogos = Cache::remember($cacheKey, 60 * 60 * 720, function () {
            $jsonPath = storage_path('app/airlines.json');
            $json = File::get($jsonPath); // Read file content
            return json_decode($json, true); // Decode JSON to associative array
        });

        foreach ($airlineLogos['data'] as $airline) {
            if (isset($airline['iata_code']) && $airline['iata_code'] === $airlineCode && isset($airline['logo'])) {
                return  substr($airline['logo'], 2);
            }
        }

        return null; // Return null if logo not found
    }

   public function bookFlight(FlightBookRequest $request): JsonResponse
{
    // Retrieve access token from the request header
    $accessToken = $this->getBearerToken($request->header('Authorization'));
 $amaClient = $this->getBearerToken($request->header('ama-client-ref') ?? null);


    // Validate and get the request data
    $data = $request->validated();

    // Attempt to generate PNR with the provided data and access token
    $feedback = $this->generatePNR($data, $accessToken,  $amaClient);

    // Check if access token has expired, and refresh if necessary
    if ($this->hasAccessTokenExpired($feedback)) {
        $accessToken = $this->getAccessToken();
        $feedback = $this->generatePNR($data, $accessToken,  $amaClient);
    
        
        //send email here
       
    }
$this->SendEmail($feedback);
    // Check if the response contains errors
    if (isset($feedback['errors']) && !empty($feedback['errors'])) {
        // Log the error details for debugging
        \Log::error('Flight booking error:', $feedback['errors']);
        
        // Extract error details
        $errorDetails = collect($feedback['errors'])->map(function ($error) {
            return [
                'status' => $error['status'] ?? 'N/A',
                'code' => $error['code'] ?? 'N/A',
                'title' => $error['title'] ?? 'Unknown Error',
                'detail' => $error['detail'] ?? 'No details provided',
                'source' => $error['source']['pointer'] ?? 'N/A'
            ];
        });



        // Return the error response with appropriate status code
        return response()->json([
            'success' => false,
            'message' => 'Flight booking failed.',
            'errors' => $errorDetails
        ], 400); // Status code 400 indicates a bad request
    }

    // If no errors, proceed to store the booking details
   $this->StoreBooked($feedback);
   //move these to queue worker as well as emailing
 $feedback['client'] = $accessToken;
    // Return a successful response
    return response()->json([
        'success' => true,
        'message' => 'Flight booked successfully.',
        'data' => $feedback
    ]);
}


    private function generatePNR(array $info, string $accessToken, string  $amaClient): array
    {
        $url = env('FLIGHT_BOOK');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->withHeaders(['Ama-Client' => $amaClient])->post($url, $info, ['verify' => true])->json();

        $response['accessToken'] = $accessToken;

        // Handle the response
        return $response;
    }

    private function getCarrierCodes(array $responseData): array
    {
        $carrierCodes = [];

        foreach ($responseData['data']['flightOffers'] as $flightOffer) {
            foreach ($flightOffer['itineraries'] as $itinerary) {
                foreach ($itinerary['segments'] as $segment) {
                    $carrierCodes[] = $segment['carrierCode'];
                }
            }
        }

        // Remove duplicate carrier codes
        $carrierCodes = array_unique($carrierCodes);
        $carrierCodesString = implode(', ', $carrierCodes);

        $baseUrl = env('CARRIER_SEARCH');
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $responseData['accessToken'],
            'Content-Type' => 'application/json',
        ])->get($baseUrl, [
            'airlineCodes' => $carrierCodesString,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        // Handle errors
        return [
            'error' => $response->status(),
            'message' => $response->body(),
        ];
    }
      public function getFlightOrder(ManageFlight $request)
{
     $accessToken = $this->getAccessToken();
     //handle expired token
    $params = $request->validated();
    $reference = urlencode($request['flightOrderId']);

    $flightData = $this->fetchFlightDetails($accessToken, $reference);

    if ($this->hasAccessTokenExpired($flightData)) {
     
     
        if (!$accessToken) {
            return response()->json(['error' => 'Unable to retrieve access token'], 401);
        }
        $flightData = $this->fetchFlightDetails($accessToken, $reference);
    }

    if (isset($flightData['errors'])) {
        Log::error('Flight data error', ['flightData' => $flightData, 'accessToken' => $accessToken]);
        return response()->json([
            'flightData' => $flightData,
            'accessToken' => $accessToken,
        ]);
    }

    return response()->json($flightData);
}

    

private function fetchFlightDetails($accessToken, $reference)
{
    try {
        // Send request to fetch flight details
        $response = Http::withToken($accessToken)
                        ->get(env('FLIGHT_DETAILS') . "/{$reference}");

        // Check if the response is successful
        if ($response->successful()) {
            $responseData = $response->json();
            $responseData['accessToken'] = $accessToken;

            return $responseData;
        } else {
            // Handle non-successful response (e.g., 4xx, 5xx)
            return [
                'errors' => [
                    [
                        'title' => 'Unable to fetch flight order',
                        'detail' => $response->body(),
                        'status' => $response->status()
                    ]
                ]
            ];
        }
    } catch (\Exception $e) {
        // Catch and handle any exceptions that occur
        return [
            'errors' => [
                [
                    'title' => 'Unable to fetch flight order',
                    'detail' => $e->getMessage(),
                    'status' => 500
                ]
            ]
        ];
    }
}


private function StoreBooked($data)
{
    // Check if the main data is set
    if (isset($data['data'])) {
        $data = $data['data'];
        $data['queuingOfficeId'] = env('AM_QUEUING_OFFICEID');

        // Validate the flight order data
        $validatedData = $this->validateFlightOrderData($data);

        // Insert flight order and get the ID
        $flightOrder = FlightOrders::insertGetId([
            'type' => $validatedData['type'],
            'queueing_office_id' => $validatedData['queuingOfficeId'],
            'pnr' => $validatedData['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pnr = $validatedData['id'];

        // Check if associated records are present and create them
        
        if (isset($data['associatedRecords'])) {
            $this->createAssociatedRecords($data['associatedRecords'], $flightOrder);
        
        }

        // Check if travelers data is present and create travelers
        if (isset($data['travelers'])) {
               
            $this->createTravelers($data['travelers'], $flightOrder);
         
        }
      

        // Check if flight offers are present and create flight offers
        if (isset($data['flightOffers'])) {
           $this->createFlightOffers($data['flightOffers'], $flightOrder, $pnr);
          
        }

        // Create ticketing agreement if present
        if (isset($data['ticketingAgreement'])) {
            $this->createTicketingAgreement($data['ticketingAgreement'], $flightOrder);
        }

        // Create automated processes if present
        if (isset($data['automatedProcess'])) {
            $this->createAutomatedProcesses($data['automatedProcess'], $flightOrder);
        }


$this->TicketStatus( $flightOrder,$pnr);
        // Return success response
        return response()->json(['message' => 'Flight Order created successfully'], 201);

    } else {
        // Handle the scenario where booking fails
        $accessToken = $data['accessToken'] ?? null;

        // Check for specific error code and return appropriate response
        if (isset($data['errors'][0]['code']) && $data['errors'][0]['code'] === 38189) {
            return response()->json([
                'message' => 'Network failure',
                'accessToken' => $accessToken,
            ]);
        }

        // Return a general booking failure response
        return response()->json([
            'message' => 'Booking failed',
            'errors' => $data['errors'] ?? [],
            'accessToken' => $accessToken,
        ]);
    }
}



private function createAssociatedRecords(array $associatedRecords, int $flightOrderId)
    {
        foreach ($associatedRecords as $record) {
            AssociatedRecords::create([
                'flight_order_id' => $flightOrderId,
                'reference' => $record['reference'],
                'creation_date' => $record['creationDate'],
                'origin_system_code' => $record['originSystemCode'],
                'flight_offer_id' => $record['flightOfferId'],
            ]);
        }
    }

    private function createFlightOffers(array $flightOffers, int $flightOrderId,$pnr)
    {
        
        foreach ($flightOffers as $offer) {
            
            $flightOffer = FlightOffers::create([
                'flight_order_id' => $flightOrderId,
                'am_flight_offer_id'=> $offer['id'],
                'type' => $offer['type'],
                'source' => $offer['source'],
                'non_homogeneous' => $offer['nonHomogeneous'],
                'last_ticketing_date' => $offer['lastTicketingDate'],
                'price' => json_encode($offer['price']),
                'pricing_options' => json_encode($offer['pricingOptions']),
                'validating_airline_codes' => json_encode($offer['validatingAirlineCodes']),
            ]);

           $this->createItineraries($offer['itineraries'], $flightOffer->id);
           
          
           $this->createTravelerPricings($offer['travelerPricings'], $flightOffer->id, $pnr);
           
        }
        
    }

    private function createItineraries(array $itineraries, int $flightOfferId)
    {
        foreach ($itineraries as $itineraryData) {
            $itinerary = Itinerary::create([
                'flight_offer_id' => $flightOfferId,
                'segments' => json_encode($itineraryData['segments']),
            ]);
        
            $this->createSegments($itineraryData['segments'], $itinerary->id);
        }
    }

   private function createSegments(array $segments, int $itineraryId)
{
    foreach ($segments as $segmentData) {
        // Calculate the duration
        $departureTime = Carbon::parse($segmentData['departure']['at']);
        $arrivalTime = Carbon::parse($segmentData['arrival']['at']);
        $duration = $departureTime->diff($arrivalTime);
        $formattedDuration = $duration->format('%H:%I:%S'); // Format as H:M:S

        try {
            // Create the segment
            Segments::create([
                'am_segment_id' => $segmentData['id'],
                'itinerary_id' => $itineraryId,
                'departure' => json_encode($segmentData['departure']),
                'arrival' => json_encode($segmentData['arrival']),
                'carrier_code' => $segmentData['carrierCode'],
                'number' => $segmentData['number'],
                'aircraft' => json_encode($segmentData['aircraft']),
                'duration' => $formattedDuration,
                'number_of_stops' => $segmentData['numberOfStops'],
                // Provide a default JSON value if co2Emissions is missing
                'co2_emissions' => isset($segmentData['co2Emissions']) ? json_encode($segmentData['co2Emissions']) : '[]',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to save segment data: ' . $e->getMessage());
           // dd($e->getMessage()); // Display the error message for debugging purposes
        }
    }
}

  private function createTravelerPricings(array $travelerPricings, int $flightOfferId, $pnr)
{
    foreach ($travelerPricings as $pricing) {
    
        try {
            $travelerPricing = TravelerPricing::create([
                'flight_pnr' => $pnr,
                'am_traveler_pricing_id'=>$pricing['travelerId'],
                'flight_offer_id' => $flightOfferId,
                'traveler_id' => $pricing['travelerId'],
                'fare_option' => $pricing['fareOption'],
                'traveler_type' => $pricing['travelerType'],
                'total_price' => $pricing['price']['total'],
                'base_price' => $pricing['price']['base'],
                'taxes' => json_encode($pricing['price']['taxes']),
                'refundable_taxes' => isset($pricing['price']['refundableTaxes']) ? $pricing['price']['refundableTaxes'] : null,
            ]);
             // Display the created traveler pricing for debugging purposes
        } catch (\Exception $e) {
            \Log::error('Failed to save traveler pricing data: ' . $e->getMessage());
            //dd('Error creating traveler pricing: ' . $e->getMessage()); // Display the error message for debugging
        }
    }
}
    private function createFareDetails(array $fareDetailsBySegment, int $travelerPricingId)
    {
        foreach ($fareDetailsBySegment as $fareDetailData) {
            FareDetail::create([
                'traveler_pricing_id' => $travelerPricingId,
                'segment_id' => $fareDetailData['segmentId'],
                'cabin' => $fareDetailData['cabin'],
                'fare_basis' => $fareDetailData['fareBasis'],
                'class' => $fareDetailData['class'],
                'included_checked_bags' => json_encode($fareDetailData['includedCheckedBags']),
            ]);
        }
    }

    

    
    private function createTravelers(array $travelers, int $flightOrderId)
{
    foreach ($travelers as $travelerData) {
        
        try {
            // Validate required fields
            if (empty($travelerData['dateOfBirth']) || empty($travelerData['gender']) || 
                empty($travelerData['name']['firstName']) || empty($travelerData['name']['lastName'])) {
                throw new \Exception('Required traveler data is missing.');
            }

            // Create traveler record
            Travelers::create([
                'flight_order_id' => $flightOrderId,
                'date_of_birth' => $travelerData['dateOfBirth'],
                'gender' => $travelerData['gender'],
                'first_name' => $travelerData['name']['firstName'],
                'last_name' => $travelerData['name']['lastName'],
                'documents' => json_encode($travelerData['documents']),
                'contact' => json_encode($travelerData['contact']),
                 'am_traveler_id'=>$travelerData['id'],             
            ]);
        

        } catch (\Exception $e) {
        
            // Log the error for debugging
            \Log::error('Failed to save traveler data: ' . $e->getMessage());
        }
    }
}

private function createTicketingAgreement(array $ticketingAgreement, int $flightOrderId)
    {
        if (!empty($ticketingAgreement)) {
            TicketAgreements::create([
                'flight_order_id' => $flightOrderId,
                'option' => $ticketingAgreement['option'],
                'delay' => $ticketingAgreement['delay'],
            ]);
        }
    }

    private function createAutomatedProcesses(array $automatedProcesses, int $flightOrderId)
    {
        foreach ($automatedProcesses as $processData) {
            AutomatedProcesses::create([
                'flight_order_id' => $flightOrderId,
                'code' => $processData['code'],
                'queue_number' => $processData['queue']['number'],
                'queue_category' => $processData['queue']['category'],
                'office_id' => $processData['officeId'],
            ]);
        }
    }

private function validateFlightOrderData($data)
{
    $rules = [
        'type' => 'required|string',
        'queuingOfficeId' => 'required|string',
        'id' => 'required|string|max:255',
        // Add validation rules for associatedRecords, flightOffers, travelers, etc.
    ];

    $validator = Validator::make($data, $rules);

    if ($validator->fails()) {
        throw ValidationException::withMessages($validator->errors()->toArray());
    }

    return $validator->validated();
}
   
private function getAirportData(): array
{
     $jsonPath = storage_path('app/airports_iata_codes.json');
        $json = File::get($jsonPath);
        return json_decode($json, true);
  
}

private function getAirlineLogos(): array
{
    return Cache::remember('airlineLogos', 60 * 60 * 720, function () {
        $jsonPath = storage_path('app/airlines.json');
        $json = File::get($jsonPath);
        return json_decode($json, true);
    });
}


private function getMarkupPercentage()
{
    // Retrieve the fee_percentage of the 'markup' record or return 0 if not found
    return optional(MarkUp::where('fee_name', 'markup')->first())->fee_percentage ?? 0;
}



public function searchFlights(FlightSearchRequest $request): JsonResponse
{
    //$accessToken = $this->getBearerToken($request->header('Authorization'));
    $accessToken = $this->getAccessToken();
    $data = $request->validated();
       $data['max']=250;
    $flightOffers = $this->fetchApiData(env('FLIGHT_SEARCH'), $accessToken, $data);

    if ($this->hasAccessTokenExpired($flightOffers)) {
        $accessToken = $this->getAccessToken();
        $flightOffers = $this->fetchApiData(env('FLIGHT_SEARCH'), $accessToken, $data);
    }
    

    $decoded = json_decode(json_encode($flightOffers), true);
    $markup = $this->getMarkupPercentage();

    if (isset($decoded['data'])) {
     
        $decoded['data'] = $this->sortCost($decoded['data']);
        $this->computeFees($markup, $decoded['data']);
    }

    return response()->json($decoded);
}



    private function TicketStatus($flight_order_id,$pnr){
        
        TicketStatus::create([
            'pnr'=>$pnr,
            'flight_order_id'=>$flight_order_id,
            ]);
    }

// public function searchMultiple(Multicity $request): JsonResponse
// {
//     $extraBg = false;
//   if(isset($request['extra_bag'])){
//       $extra_bag = $request['extra_bag'];
//       $extraBg = true;
       
//   }

//     // Retrieve airport data and create a lookup table
//     $airportData = $this->getAirportData();
    
    
    
//     $airportLookup = [];
//     foreach ($airportData as $airData) {
//         $airportLookup[$airData['iata_code']] = $airData['name'];
//     }

//     // Retrieve airline logos and create a lookup table
//     $airlineLogos = $this->getAirlineLogos();
//     $airlineLogoData = [];
   
//     foreach ($airlineLogos['data'] as $airline) {
//     if (isset($airline['iata_code'], $airline['logo'], $airline['name'])) {
//         $airlineData[$airline['iata_code']] = [
//             'logo' => $airline['logo'],
//             'name' => $airline['name']
//         ];
//     }
// }

//     // Retrieve access tokens
//     $accessToken = $this->getBearerToken($request->header('Authorization'));
//     if(empty($accessToken)){
//       $accessToken = $this->getAccessToken();
//     }
//     $amaClient = $this->getBearerToken($request->header('ama-client-ref') ?? null);

//     // Validate request data
//     $data = $request->validated();

//     // Choose the correct URL based on search criteria
//     $url = env('FLIGHT_SEARCH');

//     // Fetch flight offers
//     $flightOffers = $this->fetchFlightOffers($url, $accessToken, $data, $amaClient);

//     // Handle potential errors in the flight offers response
//     if ($this->hasErrors($flightOffers)) {
//         if ($this->needsTokenRefresh($flightOffers['error']['errors'])) {
//             // Attempt to get a new access token
//             $accessToken = $this->getAccessToken();
//             // Retry fetching flight offers with the new access token
//             $flightOffers = $this->fetchFlightOffers($url, $accessToken, $data, $amaClient);
            
//         }
//     }
    


//     if (isset($flightOffers['data']) && is_array($flightOffers['data'])) {
        
        
//       if ($extraBg) {
         
//     $flightOffers['data'] = $this->GetExtraBagsProvider($flightOffers['data']);
//     $flightOffers['meta']['count'] = count($flightOffers['data']);
// }
// $flightOffers['data'] = $this->sortCost($flightoffers);
//         // Process each flight offer and enrich data
//         foreach ($flightOffers['data'] as &$flightOffer) {
//             // Apply pricing modifications
//             $this->applyPricing($flightOffer);
                            
//             if (isset($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
//                 foreach ($flightOffer['itineraries'] as &$itinerary) {
//                     foreach ($itinerary['segments'] as &$segment) {
//                         // Enrich departure and arrival information
//                         $departureCode = $segment['departure']['iataCode'] ?? null;
//                         $arrivalCode = $segment['arrival']['iataCode'] ?? null;

//                         if ($departureCode && isset($airportLookup[$departureCode])) {
//                             $segment['departure_airport'] = $airportLookup[$departureCode];
//                         }

//                         if ($arrivalCode && isset($airportLookup[$arrivalCode])) {
//                             $segment['arrival_airport'] = $airportLookup[$arrivalCode];
//                         }

//                         // Add airline logo if available
//                         $carrierCode = $segment['carrierCode'] ?? null;
//                       if ($carrierCode) {
//     // Add airline logo if available
//     $segment['airlineLogo'] = $airlineData[$carrierCode]['logo'] ?? null;
//     $segment['airlineLogo'] = $segment['airlineLogo'] ? substr($segment['airlineLogo'], 2) : null;

//     // Add airline name if available
//     $segment['airlineName'] = $airlineData[$carrierCode]['name'] ?? null;
// }

//                     }
//                 }
//             }
//         }
//     }
  
   
 
//  $flightOffers['accessToken'] = $accessToken; 

//     return response()->json($flightOffers);
// }

public function searchMultiple(Multicity $request): JsonResponse
{ 
    
      
    $extraBg = false;
    if (isset($request['extra_bag'])) {
        $extra_bag = $request['extra_bag'];
        $extraBg = true;
    }

    // Retrieve airport data and create a lookup table
    $airportData = $this->getAirportData();
    $airportLookup = [];
    foreach ($airportData as $airData) {
        $airportLookup[$airData['iata_code']] = $airData['name'];
    }

    // Retrieve airline logos and create a lookup table
    $airlineLogos = $this->getAirlineLogos();
    $airlineData = []; // Fixed variable name inconsistency
    foreach ($airlineLogos['data'] as $airline) {
        if (isset($airline['iata_code'], $airline['logo'], $airline['name'])) {
            $airlineData[$airline['iata_code']] = [
                'logo' => $airline['logo'],
                'name' => $airline['name']
            ];
        }
    }

    // Retrieve access tokens
    $accessToken = $this->getBearerToken($request->header('Authorization'));
    if (empty($accessToken)) {
        $accessToken = $this->getAccessToken();
    }
    $amaClient = $this->getBearerToken($request->header('ama-client-ref') ?? null);

    // Validate request data
    $data = $request->validated();
    
    $data['searchCriteria']['maxFlightOffers']=250;
    // Choose the correct URL based on search criteria
    $url = env('FLIGHT_SEARCH');

    // Fetch flight offers
    $flightOffers = $this->fetchFlightOffers($url, $accessToken, $data, $amaClient);

    // Handle potential errors in the flight offers response
    if ($this->hasErrors($flightOffers)) {
        if ($this->needsTokenRefresh($flightOffers['error']['errors'])) {
            $accessToken = $this->getAccessToken();
            $flightOffers = $this->fetchFlightOffers($url, $accessToken, $data, $amaClient);
        }
    }

    if (isset($flightOffers['data']) && is_array($flightOffers['data'])) {
        if ($extraBg) {
            $flightOffers['data'] = $this->GetExtraBagsProvider($flightOffers['data']);
            $flightOffers['meta']['count'] = count($flightOffers['data']);
        }

        // Sort the flight offers by price
        $flightOffers['data'] = $this->sortCost($flightOffers['data']); // Pass the data array and assign the result

        // Process each flight offer and enrich data
        foreach ($flightOffers['data'] as &$flightOffer) {
            $this->applyPricing($flightOffer);
            
            if (isset($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
                foreach ($flightOffer['itineraries'] as &$itinerary) {
                    foreach ($itinerary['segments'] as &$segment) {
                        
                        $departureCode = $segment['departure']['iataCode'] ?? null;
                        $arrivalCode = $segment['arrival']['iataCode'] ?? null;

                        if ($departureCode && isset($airportLookup[$departureCode])) {
                            $segment['departure_airport'] = $airportLookup[$departureCode];
                        }

                        if ($arrivalCode && isset($airportLookup[$arrivalCode])) {
                            $segment['arrival_airport'] = $airportLookup[$arrivalCode];
                        }

                        $carrierCode = $segment['operating']['carrierCode'] ?? null;
                        
                        if ($carrierCode && isset($airlineData[$carrierCode])) {
                            $segment['airlineLogo'] = $airlineData[$carrierCode]['logo'] ?? null;
                            $segment['airlineLogo'] = $segment['airlineLogo'] ? substr($segment['airlineLogo'], 2) : null;
                            $segment['airlineName'] = $airlineData[$carrierCode]['name'] ?? null;
                        }
                    }
                }
            }
        }
    }

    $flightOffers['accessToken'] = $accessToken;

    return response()->json($flightOffers);
}

private function sortCost($flightOffers)
{
    usort($flightOffers, function ($a, $b) {
        // Sort by 'total' price in ascending order (lowest to highest)
        return floatval($a['price']['total']) <=> floatval($b['price']['total']);
    });
    return $flightOffers; // Return the sorted array
}
/**
 * Apply pricing details and compute markup if necessary.
 */
private function applyPricing(array &$flightOffer): void
{
    // Ensure 'price' exists and is an array
    if (isset($flightOffer['price']) && is_array($flightOffer['price'])) {
        // Ensure the price structure exists and calculate the total price
        $total = isset($flightOffer['price']['grandTotal']) 
            ? (float)$flightOffer['price']['grandTotal'] 
            : (isset($flightOffer['price']['total']) ? (float)$flightOffer['price']['total'] : 0);

        // Calculate service charge based on markup
        $markup = $this->getMarkupPercentage();
        $serviceCharge = round($markup * 0.01 * $flightOffer['price']['base'], 2);
        // Update offer price with service charge and total amount
        $flightOffer['price']['service_charge'] = $serviceCharge;
        $flightOffer['price']['ffsd_total'] = ceil($total + $serviceCharge);
       
    }
  if (isset($flightOffer['travelerPricings'])) {
    foreach ($flightOffer['travelerPricings'] as &$traveler) {  // Using the & to reference the traveler by reference
        $traveler['price']['service_charge'] = ceil(round($traveler['price']['base'] * $markup * 0.01, 2));
        $traveler['price']['total_charge'] =ceil(round($traveler['price']['service_charge'] + $traveler['price']['total'], 2));
    }
}


    
}


private function computeFees(float $markup, array &$offers): void
{
    foreach ($offers as &$offer) {
        if (isset($offer['price']) && is_array($offer['price'])) {
            $total = isset($offer['price']['base']) 
                ? (float)$offer['price']['grandTotal'] 
                : (isset($offer['price']['total']) ? (float)$offer['price']['total'] : 0);

            $serviceCharge = round($markup * 0.01 * $total, 2);

            $offer['price']['service_charge'] = $serviceCharge;
            $offer['price']['ffsd_total'] = $total + $serviceCharge;
        
        }
        if (isset($Offer['travelerPricings'])) {
    foreach ($Offer['travelerPricings'] as &$traveler) {  // Using the & to reference the traveler by reference
        $traveler['price']['service_charge'] = round($traveler['price']['base'] * $markup * 0.01, 2);
        $traveler['price']['total_charge'] =round($traveler['price']['service_charge'] + $traveler['price']['total'], 2);
    }
}
    }
}


private function fetchFlightOffers(string $url, string $accessToken, array $payload, string $amaClient): array
{
    
    
    
    try {
        // Send a POST request to the Amadeus API with the token and additional header
        $response = Http::withToken($accessToken)
                        ->withHeaders(['Ama-Client' => $amaClient]) // Wrap the string in an associative array
                        ->post($url, $payload);
        // Check if the response is successful and return decoded response as array
        if ($response->successful()) {
            $responseData = $response->json();
            //load airport information 
            
            
            return is_array($responseData) ? $responseData : ['error' => 'Invalid response format'];
        } else {
            return ['error' => $response->json()];
        }
    } catch (\Exception $e) {
        // Return the exception error message in case of failure
        return ['error' => $e->getMessage()];
    }
}





private function hasAccessTokenExpired(array $response): bool
{
    // Check for token expiration errors in the response
    return isset($response['errors']) && is_array($response['errors']) && 
           collect($response['errors'])->contains(function ($error) {
               return (isset($error['title']) && $error['title'] === 'Access token expired') ||
                      (isset($error['code']) && $error['code'] == 38190);
           });
}


private function getAccessToken(): ?string
{
    
    // Request a new access token from the auth service
    $response = Http::asForm()->post(env('AUTH_URL'), [
        'grant_type' => 'client_credentials',
        'client_id' => env('CLIENT_ID'),
        'client_secret' => env('CLIENT_SECRET'),
    ]);
    // Return the access token if available
    return $response->json()['access_token'] ?? null;
}


public function BrandedFares(BrandedUpsell $request)
{
    // Step 1: Retrieve access token (you should have your logic for this in getAccessToken())
    $accessToken = $this->getAccessToken();

    // Step 2: Validate request data
    $data = $request->validated();
    // Step 3: Set up the endpoint and headers
    $endpoint = 'https://test.travel.api.amadeus.com/v1/shopping/flight-offers/upselling';
    $headers = [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ];

    // Step 4: Prepare payload for the API request
    $payload = [
        'data' => [
            'type' => 'flight-offers-upselling',
            'flightOffers' => $data['data']['flightOffers'],  // Assuming flightOffers comes from validated data
            'payments' => $data['data']['payments']           // Assuming payments also come from validated data
        ]
    ];

    // Step 5: Make the POST request to Amadeus API
    try {
        $response = Http::withHeaders($headers)->post($endpoint, $payload);

        // Step 6: Check if the request was successful
        if ($response->successful()) {
            // Handle the successful response, return the branded fares
            return response()->json([
                'status' => 'success',
                'data' => $response->json()
            ], 200);
        } else {
            // If the request failed, return the error response
            return response()->json([
                'status' => 'error',
                'message' => $response->json()['errors'] ?? 'Something went wrong'
            ], $response->status());
        }
    } catch (\Exception $e) {
        // Handle any exceptions during the HTTP request
        return response()->json([
            'status' => 'error',
            'message' => 'An error occurred: ' . $e->getMessage()
        ], 500);
    }
}




private function hasErrors(array $response): bool
{
    return isset($response['error']) && isset($response['error']['errors']);
}

private function needsTokenRefresh(array $errors): bool
{
    foreach ($errors as $error) {
          Log::info('Amadeus error:', ['data' => $error]);
        //logError($error); // Log each error for monitoring
        if (in_array($error['code'], ["38190", "38192"])) {
            return true; // Return true if an invalid or expired token error is found
        }
    }
    return false; // No need to refresh if no relevant errors are found
}
private function confirmStatusExtra(string $accessToken, array $data, string $amaClient): array{
    
        
       $cost = $this->getBagCharge($data);
       
       
     // Cache the airport data
        $cacheKey = 'airports_iata_codes';
        $airportData = Cache::remember($cacheKey, 60 * 60 * 720, function () {
            $jsonPath = storage_path('app/airports_iata_codes.json');
            $json = File::get($jsonPath); // Read file content
            return json_decode($json, true); // Decode JSON to associative array
        });

        $airportMap = [];
        foreach ($airportData as $value) {
            $airportMap[$value['iata_code']] = $value['name'];
        }

        // Make the API request
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken,
        ])->withOptions([
            'verify' => $this->concatPrem, // Use the custom CA bundle file
            'verify_peer' => true, // Disable SSL certificate verification
            'verify_host' => true, // Disable host name verification
        ]) ->withHeaders(['Ama-Client' => $amaClient])->post(env('Extra_Bag'), $data);
        
        //call prepare data for final pricing
        

        $responseData = $response->json();
    

        // Add airport names to each segment if data is present
        if (isset($responseData['data']['flightOffers'])) {
        
           
            foreach ($responseData['data']['flightOffers'] as &$flightOffer) {
              $flightOffer['additionalServices'] = $this->prepare_2nd_bag($flightOffer, $cost['cost']);
              $flightOffer['travelerPricings'] = $this->prepare_bag_quantity( $flightOffer['travelerPricings'],$cost['no_bags']);
            $flightOffer['price']['grandTotal'] = $flightOffer['price']['grandTotal'] + (float) $cost['cost'];
             
                if (isset($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
                    foreach ($flightOffer['itineraries'] as &$itinerary) {
                        if (isset($itinerary['segments']) && is_array($itinerary['segments'])) {
                            foreach ($itinerary['segments'] as &$segment) {
                                $departureCode = $segment['departure']['iataCode'];
                                $arrivalCode = $segment['arrival']['iataCode'];

                                if (isset($airportMap[$departureCode])) {
                                    $segment['departure']['airport_name'] = $airportMap[$departureCode];
                                }
                                if (isset($airportMap[$arrivalCode])) {
                                    $segment['arrival']['airport_name'] = $airportMap[$arrivalCode];
                                }
                            }
                        }
                    }
                }
            }
        }

        $responseData['httpCode'] = $response->status();
        $responseData['accessToken'] = $accessToken;
 
        // Log request and response for debugging
        Log::info('Amadeus Request Data:', ['data' => $data]);
        Log::info('Amadeus Response Data:', ['response' => $responseData]);

        // Check for specific error code
        if (isset($responseData['errors']) && is_array($responseData['errors'])) {
            foreach ($responseData['errors'] as $error) {
                if ($error['code'] == 38189) {
                    Log::error('Amadeus Internal Error', ['error' => $error]);
                }
            }
        }

        return $responseData;
    
}
public function getToken(){
      $response = Http::asForm()->post(env('AUTH_URL'), [
        'grant_type' => 'client_credentials',
        'client_id' => env('CLIENT_ID'),
        'client_secret' => env('CLIENT_SECRET'),
    ]);

    // Return the access token if available
    return $response->json()['access_token'] ?? null;
}





private function GetExtraBagsProvider(array $offers): array
{
    $filteredOffers = [];

    foreach ($offers as $offer) {
        if (!empty($offer['price']['additionalServices'])) {
            foreach ($offer['price']['additionalServices'] as $service) {
                if (isset($service['type']) && $service['type'] === 'CHECKED_BAGS') {
                    $filteredOffers[] = $offer; // Add to the filtered list
                    break; // No need to check other services for this offer
                }
            }
        }
    }

    return $filteredOffers;
}
private function getBagCharge($offer) {
     $weight =$offer['data']['extra_bag_weight'];

            $charge = 0;

        // Retrieve the additional services
        foreach ($offer['data']['flightOffers'][0]['price']['additionalServices'] as $service) {
            // Check if the service type is 'CHECKED_BAGS'
            if ($service['type'] === 'CHECKED_BAGS') {
                // Get the cost for checked bags
                $cost = $service['amount'];
                // Calculate charge based on the weight, where the weight is charged per 23kg
                $charge = $cost * ceil($weight / 23);
                break; // Exit loop after finding the checked bags service
            }
        }

        // Return the calculated charge
        
        return ['cost'=>$charge, 'no_bags'=>ceil($weight / 23)];
    
}

private function prepare_2nd_bag($response, $cost){


// Locate the price array

return $response['additionalServices'] = [
        
            'amount' => $cost,
            'type' => 'CHECKED_BAGS'
        ];



// Append additional services to the price arra
}
private function prepare_bag_quantity($response, $no_bags) {
    foreach ($response as &$resp) { // Pass by reference to modify the original array
        // Ensure fareDetailsBySegment exists in the response
        if (isset($resp['fareDetailsBySegment'])) {
            foreach ($resp['fareDetailsBySegment'] as &$fare_segment) { // Pass by reference to modify the original array
                // Ensure additionalServices and chargeableCheckedBags exist in the fare segment
                
                    $fare_segment['additionalServices']['chargeableCheckedBags'] = ['quantity' => $no_bags];
                
            }
        }
    }

    
    return $response;
}


private function sendEmail($flight_data)
{
    // Decode the recipients from the .env file
    $bookedRecipients = json_decode(env('BOOKED_RECIPIENT'), true);

    // Check if 'associatedRecords' exists and is an array
    if (!isset($flight_data['data']['associatedRecords']) || !is_array($flight_data['data']['associatedRecords'])) {
        throw new \Exception("Invalid flight data: associatedRecords not found");
    }

    // Check if there are any recipients to process
    if (empty($bookedRecipients)) {
        throw new \Exception("No booked recipients found in the configuration.");
    }

 foreach ($bookedRecipients as $email_reciever) {
    if (isset($email_reciever['email']) && isset($email_reciever['full_name'])) {
        $pnr_array = [];
        foreach ($flight_data['data']['associatedRecords'] as $pnr) {
            $pnr_array[] = $pnr['reference'];
        }

        // Send the notification with full name
        Notification::route('mail', $email_reciever['email'])
            ->notify(new BookedNotification($flight_data['data']['id'], $pnr_array, $email_reciever['full_name']));
    }
}

}



}

