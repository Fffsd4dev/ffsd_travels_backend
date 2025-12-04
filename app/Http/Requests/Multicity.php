<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class Multicity extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorize the request based on your logic
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
   
 
return [
    'currencyCode' => 'required|string|size:3',

    'originDestinations' => 'required|array|min:1',
    'originDestinations.*.id' => 'required|string',
    'originDestinations.*.originLocationCode' => 'required|string|size:3',
    'originDestinations.*.destinationLocationCode' => 'required|string|size:3',
    'originDestinations.*.departureDateTimeRange' => 'required|array',
    'originDestinations.*.departureDateTimeRange.date' => 'required|date_format:Y-m-d|after_or_equal:today',
    'originDestinations.*.departureDateTimeRange.time' => 'sometimes|date_format:H:i:s',

    'travelers' => 'required|array|min:1',
    'travelers.*.id' => 'required|string',
    'travelers.*.travelerType' => 'required|string|in:ADULT,CHILD,HELD_INFANT',
    'travelers.*.associatedAdultId' => 'nullable|string', // Adjusted to 'nullable' as 'required_if' might not be necessary without additional context
    'travelers.*.fareOptions' => 'required|array|min:1',
    'travelers.*.fareOptions.*' => 'required|string',

    'sources' => 'required|array|min:1',
    'sources.*' => 'required|string|in:GDS,LTC,NDC,Pyton,EAC',

    'searchCriteria' => 'required|array',
    'searchCriteria.maxFlightOffers' => 'required|integer|min:1',
    'searchCriteria.addOneWayOffers' => 'nullable|boolean',
    'searchCriteria.pricingOptions' => 'nullable|array',
    'searchCriteria.pricingOptions.fareType' => 'nullable|array',
    'searchCriteria.pricingOptions.fareType.*' => 'nullable|string|in:PUBLISHED,NEGOTIATED,CORPORATE',
    
    'searchCriteria.pricingOptions.additionalInformation.brandedFares' => 'sometimes|boolean',
    'searchCriteria.pricingOptions.additionalInformation.chargeableCheckedBags' => 'sometimes|boolean',

    'searchCriteria.flightFilters' => 'nullable|array',
    'searchCriteria.flightFilters.cabinRestrictions' => 'nullable|array|min:1',
    'searchCriteria.flightFilters.cabinRestrictions.*.cabin' => 'required|string|in:ECONOMY,BUSINESS,PREMIUM_ECONOMY,FIRST',
    'searchCriteria.flightFilters.cabinRestrictions.*.coverage' => 'required|string|in:MOST_SEGMENTS,ALL_SEGMENTS',
    'searchCriteria.flightFilters.cabinRestrictions.*.originDestinationIds' => 'sometimes|array|min:1',
    'searchCriteria.flightFilters.cabinRestrictions.*.originDestinationIds.*' => 'sometimes|string',

    'searchCriteria.flightFilters.carrierRestrictions' => 'nullable|array',
    'searchCriteria.flightFilters.carrierRestrictions.excludedCarrierCodes' => 'nullable|array',
    'searchCriteria.flightFilters.carrierRestrictions.excludedCarrierCodes.*' => 'nullable|string',
    'searchCriteria.flightFilters.carrierRestrictions.includedCarrierCodes' => 'nullable|array',
    'searchCriteria.flightFilters.carrierRestrictions.includedCarrierCodes.*' => 'nullable|string',
];

    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $requestData = $this->all();

        throw new HttpResponseException(response()->json([
            'errors' => $errors,
            'request_data' => $requestData
        ], 422));
    }
}
