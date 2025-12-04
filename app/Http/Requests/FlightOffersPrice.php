<?php 
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class FlightOffersPrice extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    
    public function rules(): array
{
    return [
        'data.extra_bag'=>'sometimes|boolean',
         'data.extra_bag_weight'=>'sometimes|integer',
        'data.type' => 'required|in:flight-offers-pricing',
        'data.flightOffers' => 'required|array',
        'data.flightOffers.*.type' => 'required|in:flight-offer',
        'data.flightOffers.*.id' => 'required|string',
        'data.flightOffers.*.services.type' => 'nullable|string', 
        'data.flightOffers.*.services.travelerIds' => 'nullable|array',
        'data.flightOffers.*.source' => 'required|string',
        'data.flightOffers.*.instantTicketingRequired' => 'nullable|boolean',
        'data.flightOffers.*.nonHomogeneous' => 'nullable|boolean',
        'data.flightOffers.*.oneWay' => 'nullable|boolean',
        'data.flightOffers.*.isUpsellOffer' => 'nullable|boolean',
        'data.flightOffers.*.lastTicketingDate' => 'required|date',
        'data.flightOffers.*.lastTicketingDateTime' => 'nullable|date',
        'data.flightOffers.*.numberOfBookableSeats' => 'nullable|integer',
        'data.flightOffers.*.itineraries' => 'required|array',
        'data.flightOffers.*.itineraries.*.duration' => 'nullable|string',
        'data.flightOffers.*.itineraries.*.segments' => 'required|array',
        'data.flightOffers.*.itineraries.*.segments.*.departure.iataCode' => 'required|string|size:3',
        'data.flightOffers.*.itineraries.*.segments.*.departure.at' => 'required|date_format:Y-m-d\TH:i:s',
        'data.flightOffers.*.itineraries.*.segments.*.arrival.iataCode' => 'required|string|size:3',
        'data.flightOffers.*.itineraries.*.segments.*.arrival.at' => 'required|date_format:Y-m-d\TH:i:s',
        'data.flightOffers.*.itineraries.*.segments.*.carrierCode' => 'required|string|size:2',
        'data.flightOffers.*.itineraries.*.segments.*.number' => 'required|string',
        'data.flightOffers.*.itineraries.*.segments.*.aircraft.code' => 'required|string',
        'data.flightOffers.*.itineraries.*.segments.*.operating.carrierCode' => 'required|string|size:2',
        'data.flightOffers.*.itineraries.*.segments.*.duration' => 'required|string',
        'data.flightOffers.*.itineraries.*.segments.*.id' => 'required|string',
        'data.flightOffers.*.itineraries.*.segments.*.numberOfStops' => 'required|integer',
        'data.flightOffers.*.itineraries.*.segments.*.blacklistedInEU' => 'required|boolean',
        'data.flightOffers.*.price.currency' => 'required|string|size:3',
        'data.flightOffers.*.price.total' => 'required|numeric',
        'data.flightOffers.*.price.base' => 'required|numeric',
        'data.flightOffers.*.price.fees' => 'required|array',
        'data.flightOffers.*.price.fees.*.amount' => 'required|numeric',
        'data.flightOffers.*.price.fees.*.type' => 'required|string',
        'data.flightOffers.*.price.grandTotal' => 'required|numeric',
        'data.flightOffers.*.price.additionalServices.*'=>'sometimes|array',
        'data.flightOffers.*.pricingOptions.fareType' => 'required|array',
        'data.flightOffers.*.pricingOptions.fareType.*' => 'required|string',
        'data.flightOffers.*.pricingOptions.includedCheckedBagsOnly' => 'required|boolean',
        'data.flightOffers.*.validatingAirlineCodes' => 'required|array',
        'data.flightOffers.*.validatingAirlineCodes.*' => 'required|string|size:2',
        'data.flightOffers.*.travelerPricings' => 'required|array',
        'data.flightOffers.*.travelerPricings.*.travelerId' => 'required|string',
        'data.flightOffers.*.travelerPricings.*.fareOption' => 'required|string',
        'data.flightOffers.*.travelerPricings.*.travelerType' => 'required|string|in:ADULT,CHILD,HELD_INFANT',
        'data.flightOffers.*.travelerPricings.*.associatedAdultId' => 'required_if:data.flightOffers.*.travelerPricings.*.travelerType,HELD_INFANT|string',
        'data.flightOffers.*.travelerPricings.*.price.currency' => 'required|string|size:3',
        'data.flightOffers.*.travelerPricings.*.price.total' => 'required|numeric',
        'data.flightOffers.*.travelerPricings.*.price.base' => 'required|numeric',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment' => 'array',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.segmentId' => 'string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.cabin' => 'string|required|in:BUSINESS,ECONOMY,PREMIUM_ECONOMY,FIRST',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.fareBasis' => 'string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.class' => 'string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.includedCheckedBags.quantity' => 'integer',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities' => 'array',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.description' => 'nullable|string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.isChargeable' => 'nullable|boolean',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.amenityType' => 'nullable|string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.amenityProvider.name' => 'nullable|string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.amenitySeat.legSpace' => 'nullableinteger',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.amenitySeat.spaceUnit' => 'nullable|string',
        'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.amenities.*.amenitySeat.tilt' => 'nullable|string',
    ];
}


  

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
