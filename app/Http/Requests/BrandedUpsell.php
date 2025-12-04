<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class BrandedUpsell extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Set to true to allow this request, typically you would check user permissions here
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.type' => 'required|string|in:flight-offers-upselling',
            'data.flightOffers' => 'required|array',
            'data.flightOffers.*.type' => 'required|string|in:flight-offer',
            'data.flightOffers.*.id' => 'required|string',
            'data.flightOffers.*.source' => 'required|string|in:GDS',
            'data.flightOffers.*.instantTicketingRequired' => 'required|boolean',
            'data.flightOffers.*.nonHomogeneous' => 'required|boolean',
            'data.flightOffers.*.oneWay' => 'required|boolean',
            'data.flightOffers.*.lastTicketingDate' => 'required|date',
            'data.flightOffers.*.numberOfBookableSeats' => 'required|integer|min:1',
            'data.flightOffers.*.itineraries' => 'required|array',
            'data.flightOffers.*.itineraries.*.duration' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments' => 'required|array',
            'data.flightOffers.*.itineraries.*.segments.*.departure.iataCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.departure.terminal' => 'nullable|string',
            'data.flightOffers.*.itineraries.*.segments.*.departure.at' => 'required|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.iataCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.terminal' => 'nullable|string',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.at' => 'required|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.carrierCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.number' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.aircraft.code' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.operating.carrierCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.duration' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.id' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.numberOfStops' => 'required|integer|min:0',
            'data.flightOffers.*.itineraries.*.segments.*.blacklistedInEU' => 'required|boolean',
            'data.flightOffers.*.price.currency' => 'required|string|size:3',
            'data.flightOffers.*.price.total' => 'required|numeric|min:0',
            'data.flightOffers.*.price.base' => 'required|numeric|min:0',
            'data.flightOffers.*.price.fees' => 'required|array',
            'data.flightOffers.*.price.fees.*.amount' => 'required|numeric|min:0',
            'data.flightOffers.*.price.fees.*.type' => 'required|string',
            'data.flightOffers.*.price.grandTotal' => 'required|numeric|min:0',
            'data.flightOffers.*.price.additionalServices' => 'array',
            'data.flightOffers.*.price.additionalServices.*.amount' => 'required|numeric|min:0',
            'data.flightOffers.*.price.additionalServices.*.type' => 'required|string',
            'data.flightOffers.*.pricingOptions.fareType' => 'required|array',
            'data.flightOffers.*.pricingOptions.fareType.*' => 'string',
            'data.flightOffers.*.pricingOptions.includedCheckedBagsOnly' => 'required|boolean',
            'data.flightOffers.*.validatingAirlineCodes' => 'required|array',
            'data.flightOffers.*.validatingAirlineCodes.*' => 'string',
            'data.flightOffers.*.travelerPricings' => 'required|array',
            'data.flightOffers.*.travelerPricings.*.travelerId' => 'required|string',
            'data.flightOffers.*.travelerPricings.*.fareOption' => 'required|string|in:STANDARD',
            'data.flightOffers.*.travelerPricings.*.travelerType' => 'required|string|in:ADULT,CHILD,HELD_INFANT,SEATED_INFANT',
            'data.flightOffers.*.travelerPricings.*.price.currency' => 'required|string|size:3',
            'data.flightOffers.*.travelerPricings.*.price.total' => 'required|numeric|min:0',
            'data.flightOffers.*.travelerPricings.*.price.base' => 'required|numeric|min:0',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment' => 'required|array',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.segmentId' => 'required|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.cabin' => 'required|string|in:ECONOMY',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.fareBasis' => 'required|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.brandedFare' => 'required|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.class' => 'required|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.includedCheckedBags.quantity' => 'nullable|integer|min:0',
            'data.flightOffers.*.fareRules.rules' => 'array',
            'data.flightOffers.*.fareRules.rules.*.category' => 'required|string',
            'data.flightOffers.*.fareRules.rules.*.maxPenaltyAmount' => 'nullable|numeric|min:0',
            'data.flightOffers.*.fareRules.rules.*.notApplicable' => 'nullable|boolean',
            'data.payments' => 'required|array',
            'data.payments.*.brand' => 'required|string',
            'data.payments.*.binNumber' => 'required|numeric',
            'data.payments.*.flightOfferIds' => 'required|array',
            'data.payments.*.flightOfferIds.*' => 'required|integer',
             'data.flightOffers.*.travelerPricings.*.associatedAdultId' => 'nullable|string',
        ];
    }
    
     protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
