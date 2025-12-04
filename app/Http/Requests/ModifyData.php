<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ModifyData extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
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
            // Required fields
            'data.type' => 'required|string',
            'data.id' => 'required|string',
            'data.queuingOfficeId' => 'nullable|string',

            // All other fields are 'sometimes' (optional, but validated if present)
            'data.associatedRecords' => 'sometimes|array|min:1',
            'data.associatedRecords.*.reference' => 'sometimes|string',
            'data.associatedRecords.*.originSystemCode' => 'sometimes|string',
            'data.associatedRecords.*.flightOfferId' => 'sometimes|string',

            'data.flightOffers' => 'sometimes|array|min:1',
            'data.flightOffers.*.type' => 'sometimes|string',
            'data.flightOffers.*.id' => 'sometimes|string',
            'data.flightOffers.*.source' => 'sometimes|string',
            'data.flightOffers.*.lastTicketingDate' => 'sometimes|date',
            'data.flightOffers.*.itineraries' => 'sometimes|array|min:1',
            'data.flightOffers.*.itineraries.*.segments' => 'sometimes|array|min:1',
            'data.flightOffers.*.itineraries.*.segments.*.departure.iataCode' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.departure.at' => 'sometimes|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.iataCode' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.at' => 'sometimes|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.carrierCode' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.number' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.aircraft.code' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.duration' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.bookingStatus' => 'sometimes|string',
            'data.flightOffers.*.itineraries.*.segments.*.segmentType' => 'sometimes|string',

            'data.flightOffers.*.price.currency' => 'sometimes|string',
            'data.flightOffers.*.price.total' => 'sometimes|numeric',
            'data.flightOffers.*.price.base' => 'sometimes|numeric',
            'data.flightOffers.*.price.grandTotal' => 'sometimes|numeric',

            'data.flightOffers.*.travelerPricings' => 'sometimes|array|min:1',
            'data.flightOffers.*.travelerPricings.*.travelerId' => 'sometimes|string',
            'data.flightOffers.*.travelerPricings.*.travelerType' => 'sometimes|string',
            'data.flightOffers.*.travelerPricings.*.price.currency' => 'sometimes|string',
            'data.flightOffers.*.travelerPricings.*.price.total' => 'sometimes|numeric',
            'data.flightOffers.*.fareDetailsBySegment' => 'sometimes|array|min:1',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.segmentId' => 'sometimes|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.cabin' => 'sometimes|string',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.fareBasis' => 'sometimes|string',

            'data.travelers' => 'sometimes|array|min:1',
            'data.travelers.*.id' => 'sometimes|string',
            'data.travelers.*.name.firstName' => 'sometimes|string',
            'data.travelers.*.name.lastName' => 'sometimes|string',
            'data.travelers.*.contact.purpose' => 'sometimes|string',
            'data.travelers.*.contact.phones' => 'sometimes|array|min:1',
            'data.travelers.*.contact.phones.*.deviceType' => 'sometimes|string',
            'data.travelers.*.contact.phones.*.countryCallingCode' => 'sometimes|string',
            'data.travelers.*.contact.phones.*.number' => 'sometimes|string',

            'data.ticketingAgreement.option' => 'sometimes|string',
            'data.ticketingAgreement.dateTime' => 'sometimes|date',

            'data.contacts' => 'sometimes|array|min:1',
            'data.contacts.*.addresseeName.firstName' => 'sometimes|string',
            'data.contacts.*.address.lines' => 'sometimes|array|min:1',
            'data.contacts.*.address.lines.*' => 'sometimes|string',
            'data.contacts.*.address.postalCode' => 'sometimes|string',
            'data.contacts.*.address.countryCode' => 'sometimes|string',
            'data.contacts.*.address.cityName' => 'sometimes|string',
            'data.contacts.*.companyName' => 'sometimes|string',
            'data.contacts.*.emailAddress' => 'sometimes|email',

            'data.commissions' => 'sometimes|array',
            'data.commissions.*.controls' => 'sometimes|array',
            'data.commissions.*.values' => 'sometimes|array',
            'data.commissions.*.values.*.commissionType' => 'sometimes|string',
            'data.commissions.*.values.*.percentage' => 'sometimes|numeric|min:0|max:100',

            // Validation for formOfIdentifications
            'data.formOfIdentifications' => 'sometimes|array|min:1',
            'data.formOfIdentifications.*.identificationType' => 'sometimes|string',
            'data.formOfIdentifications.*.carrierCode' => 'sometimes|string',
            'data.formOfIdentifications.*.number' => 'sometimes|string',
            'data.formOfIdentifications.*.travelerIds' => 'sometimes|array|min:1',
            'data.formOfIdentifications.*.travelerIds.*' => 'sometimes|string',
            'data.formOfIdentifications.*.flightOfferIds' => 'sometimes|array|min:1',
            'data.formOfIdentifications.*.flightOfferIds.*' => 'sometimes|integer',

            // New fields based on your JSON
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.additionalServices' => 'sometimes|array',
            'data.flightOffers.*.travelerPricings.*.fareDetailsBySegment.*.additionalServices.chargeableSeatNumber' => 'sometimes|string',
        ];
    }

    /**
     * Handle failed validation.
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
