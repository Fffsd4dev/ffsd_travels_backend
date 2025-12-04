<?php

namespace App\Http\Controllers\V1\Api;

use App\Http\Controllers\Controller;
//use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\PaymentType as Payment_type;
use Illuminate\Support\Facades\File;
use App\Models\Wallet;
use App\Models\ExpenseTracker;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\FlightBookRequest;
use App\Models\FlightOffers;
use App\Models\FlightOrders;
use App\Models\Travelers;
use App\Models\TravelerPricing;
use App\Models\Staff_flight;
use App\Models\Itinerary;
use App\Models\FareDetail;
use App\Models\Segments;
use App\Models\Staff;




class CorporateTravel extends Controller
{
    //
    public function bookFlight(FlightBookRequest $request){
        $user = Auth::guard('sanctum')->user();

        
        if (!$user || in_array($user->user_type, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Invalid Access'], 422);
        }

        if (!$user) {

            return response()->json(['error' => 'Invalid Access'], 422);

        }
//process payment type
        $payment_type = $this->PaymentType($user);
        $flightDetails =$request->validated();
        $accessToken = $request->header('amadeus-token');
    

        if($payment_type ==='prepaid'){
            //process wallet payment
           $response = $this-> WalletPayment( $flightDetails, $user, $accessToken);

            return $response;
        }else if($payment_type==='postpaid'){
            //process invoicing and this should be after successfully booking a flight


        }
       
    }

    private function PaymentType($user){
        $payment_type = Payment_type::where('company_id', $user->user_company_id)->value('payment_type');
        return $payment_type;


    }

    private function WalletPayment($flightdetails, $user,$accessToken)
{
    // Initialize the total amount
    $total = ['grand_total' => 0];
    // Decode the JSON file contents

    $travelers =$flightdetails['data']['travelers'];

    $flight_offer_id = $flightdetails['data']['flightOffers'][0]['id'];
    $flightOffers = $flightdetails['data']['flightOffers'];
    $itineraries =  $flightOffers [0]['itineraries'];
    $traveler_pricings =$flightdetails['data']['flightOffers'][0]['travelerPricings'];
    foreach ($flightdetails['data']['flightOffers'] as $fare) {
        $total['grand_total'] += $fare['price']['grandTotal'];
    }
    $company_id = $user->user_company_id;
    
    $wallet = Wallet::where('company_id', $company_id)->first();
    
    if (!$wallet) {
        return response()->json(['error' => 'Wallet not found'], 404);
    }


    //Check if the wallet has sufficient balance
    if ($wallet->balance < $total['grand_total']) {
        return response()->json(['error' => 'Insufficient funds', 'message'=>'Please top up your account and try again.'], 422);
    }

    //book the flight
        $received = $this->processFlight($flightdetails, $accessToken);
        if(isset($received['message'])&& $received['message']==='Network failure'){
            return $received;
        }

      $pnr = $recieved['data']['id'];
     $queueing_office_id = $recieved['data']['queuingOfficeId'];
      $type =$recieved['data']['type'];
     $this ->CaptureStaff($user, $flight);//save staff
    // Calculate the new total spent and balance
    $total_spent = $wallet->total_spent + $total['grand_total'];
    $balance = $wallet->total_deposit - $total_spent;
    //save travellers
   
    $flight_order_id= $this->CompleteBooker($flightOffers,$flight_offer_id,$company_id,$balance,$total_spent,$pnr,$type,$queueing_office_id);
    $itinerary_ids = $this->save_itineraries($itineraries,$flight_offer_id);
   $traveler_ids= $this->SaveTravelers($travelers, $flight_order_id);
 $this->save_traveler_pricing($traveler_pricings,$traveler_ids,$flight_offer_id,$pnr);
 $segment_ids =$this->save_segments($itineraries,$itinerary_ids );
 $this-> save_fare_details($traveler_pricings,$flight_order_id,$segment_ids);
    return response()->json(['success' => 'Payment processed successfully. Check your email for tickets '], 200);
}



    private function InvoicePayment($flightDetails){

        foreach($json['data']['flightOffers'] as $fare){
         
            $total['grand_total'] =  $total['grand_total']+$fare['price']['grandTotal'];
     
 
         }
         //update wallet fee
         $user = Auth::guard('sanctum')->user();




    
    }

   
    private function update_expense_tracker($balance,$flightDetailsJson,$company_id,$flight_offer_id){
        $user = Auth::guard('sanctum')->user();

        //$flightOffer = FlightOffers::find($flight_offer_id);
    

       $expense_tracker = ExpenseTracker::create(
            ['company_id'=>$company_id,
            'flight_offer_id'=>$flight_offer_id, 
            'flight_details'=>json_encode($flightDetailsJson),
             'balance'=>$balance, 
             'created_by_user_id'=>$user->id
             ]
        );


    }

   
    private function CompleteBooker($flightDetailsJson,$flight_offer_id,$company_id,$balance,$total_spent,$pnr,$type,$queueing_office_id){
    
        $this->updateWallet($balance, $total_spent,$company_id);
        $this->createFlightoffer($flightDetailsJson,$flight_offer_id,$pnr);
        $flight_order_id = $this->createFlightOrder($pnr,$type,$queueing_office_id);
        $this->update_expense_tracker($balance, $flightDetailsJson, $company_id, $flight_offer_id);
        
        return $flight_order_id;
        

    }

    private function updateWallet($balance, $total_spent,$company_id){
        $wallet = Wallet::where('company_id', $company_id)->first();

        $wallet->update([
            'total_spent' => $total_spent,
            'balance' => $balance,
            'updated_at' => now(),
        ]);
return true;


    }
   
    
    private function createFlightOffer($flightDetails,$flight_offer_id,$pnr)
{
    

    foreach ($flightDetails as $offer) {
        $flightOffer = new FlightOffers();
       
        $flightOffer->flight_orders_id = $pnr;
        $flightOffer->type = $offer['type'];
        $flightOffer->flight_offer_id = $offer['id'];
        $flightOffer->source = $offer['source'];
        $flightOffer->non_homogeneous = $offer['nonHomogeneous'];
        $flightOffer->last_ticketing_date = $offer['lastTicketingDate'];
        $flightOffer->price = json_encode($offer['price']);
        $flightOffer->pricing_options = json_encode($offer['pricingOptions']);
        $flightOffer->validating_airline_codes = json_encode($offer['validatingAirlineCodes']);

        $flightOffer->save();
    }
}
private function createFlightOrder($pnr,$type,$queueing_office_id){
    
    $flightOrder = FlightOrders::create(
        [
            'pnr'=>strip_tags($pnr),
            'queueing_office_id'=>strip_tags($queueing_office_id),
            'type'=>$type,
        ]
    );

    return $flightOrder->id;

}

private function createFlight($data){
    return true;

}
private function SaveTravelers($travelers, $flight_order_id)
{
    $traveler_ids = [];

    foreach ($travelers as $key) {
        $traveler = Travelers::create([
            'flight_order_id' => $flight_order_id,
            'date_of_birth' => $key['dateOfBirth'],
            'gender' => $key['gender'],
            'first_name' => $key['name']['firstName'],
            'last_name' => $key['name']['lastName'],
            'documents' => json_encode($key['documents']),
            'contact' => json_encode($key['contact']),
            'am_traveler_id' => $key['id']
        ]);
        
        $traveler_ids[] = $traveler->id; // Push the traveler ID into the array
    }
    
    return $traveler_ids;
}

   
private function save_traveler_pricing($traveler_pricings, $traveler_ids, $flight_offer_id, $pnr)
{
    foreach($traveler_pricings as $index => $key) {
        TravelerPricing::create([
            'flight_offer_id' => $flight_offer_id,
            'traveler_id' => $traveler_ids[$index], // assuming traveler_ids is indexed similarly
            'fare_option' => $key['fareOption'],
            'am_traveler_pricing_id' => $key['travelerId'],
            'traveler_type' => $key['travelerType'],
            'flight_pnr' => $pnr,
            'total_price' => $key['price']['total'],
            'base_price' => $key['price']['base'],
            'taxes' => json_encode($key['price']['taxes']),
            'refundable_taxes' => $key['price']['refundableTaxes'],
            'created_at' => now(),
        ]);
    }
}

private function save_itineraries($itineraries,$flight_offer_id){
    $itinerary_ids =[];
    foreach($itineraries as $data=>$key){
     $itinerary_ids[]=   Itinerary::create(
            ['flight_offer_id'=>$flight_offer_id,
            'segments'=>json_encode($key['segments']),
            ]
        )->id;

    }

    return $itinerary_ids;
}

private function save_fare_details($traveler_pricings, $flight_order_id, $segmentsId)
{
    foreach ($traveler_pricings as $pricing) {
        foreach ($pricing['fareDetailsBySegment'] as $index => $segment) {
            FareDetail::create([
                'segment_id' => $segmentsId[$index], // Assuming $segmentsId matches the segment order
                'am_segment_id' => $segment['segmentId'],
                'cabin' => $segment['cabin'],
                'fare_basis' => $segment['fareBasis'],
                'class' => $segment['class'],
                'flight_order_id' => $flight_order_id,
                'included_checked_bags' => json_encode($segment['includedCheckedBags']), // Accessing quantity
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }
}


private function save_segments($itineraries, $itinerary_ids)
{
    $segments_id = [];
    foreach ($itineraries as $key => $data) {
        $itinerary_id = $itinerary_ids[$key];
        foreach ($data['segments'] as $seg) {
            $segments_id[] = Segments::create([
                'itinerary_id' => $itinerary_id,
                'departure' => json_encode($seg['departure']),
                'arrival' => json_encode($seg['arrival']),
                'carrier_code' => $seg['carrierCode'],
                'number' => $seg['number'],
                'aircraft' => json_encode($seg['aircraft']),
                'duration' => $seg['duration'],
                'am_segment_id' => $seg['id'],
                'number_of_stops' => $seg['numberOfStops'],
                'co2_emissions' => json_encode($seg['co2Emissions']),
            ])->id;
        }
    }
    return $segments_id;
}

private function processFlight($data, $accessToken): array
{
    $feedback = $this->generatePNR($data, $accessToken); // This returns an array from HTTP request

    if ($this->hasAccessTokenExpired($feedback)) {
        $accessToken = $this->getAccessToken();
        $feedback = $this->generatePNR($data, $accessToken);
    }

    // Ensure $feedback is treated as an array
    if (isset($feedback['errors'][0]['code']) && $feedback['errors'][0]['code'] === 38189) {
        $feedback = ['message' => 'Network failure'];
    }

    // Add the access token to the response array
    $feedback['accessToken'] = $accessToken;

    return $feedback;
}

private function generatePNR(array $info, $accessToken):array
{
    $url = 'https://test.api.amadeus.com/v1/booking/flight-orders';

    $response = Http::withHeaders([
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
    ])->post($url, $info, ['verify' => true])->json();

  
    // Handle the response
    return $response;
}
private function captureStaff($user, $flightInfo)
{
  
    // Ensure that user is an active staff of a company before booking flight
    Staff_flight::create([
        'staff_full_name' => $user->firstName . '_' . $user->lastName,
        'flight_details' => json_encode($flightInfo),
        'comp_id' => $user->user_company_id,
        'booked_time' => now(),
        'pnr' => $flightInfo['id'],
        'staff_id' => Staff::where('user_id', $user->id)->first()->id,
    ]);
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
 
}
