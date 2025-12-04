<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlightBookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // Assuming authorization logic if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'data' => 'required|array',
            'data.type' => 'required|in:flight-order',
            'data.flightOffers' => 'required|array|min:1',
            'data.flightOffers.*.type' => 'required|in:flight-offer',
            'data.flightOffers.*.id' => 'required|string',
            'data.flightOffers.*.source' => 'required|string',
            'data.flightOffers.*.instantTicketingRequired' => 'required|boolean',
            'data.flightOffers.*.nonHomogeneous' => 'required|boolean',
            //'data.flightOffers.*.oneWay' => 'required|boolean',
            //'data.flightOffers.*.isUpsellOffer' => 'required|boolean',
            'data.flightOffers.*.lastTicketingDate' => 'required|date_format:Y-m-d',
            //'data.flightOffers.*.lastTicketingDateTime' => 'required|date_format:Y-m-d',
            //'data.flightOffers.*.numberOfBookableSeats' => 'required|integer',
            'data.flightOffers.*.itineraries' => 'required|array|min:1',
           // 'data.flightOffers.*.itineraries.*.duration' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments' => 'required|array|min:1',
            'data.flightOffers.*.itineraries.*.segments.*.departure.iataCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.departure.at' => 'required|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.iataCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.at' => 'required|date_format:Y-m-d\TH:i:s',
            'data.flightOffers.*.itineraries.*.segments.*.arrival.terminal' => 'nullable|string',
            'data.flightOffers.*.itineraries.*.segments.*.carrierCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.number' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.aircraft.code' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.operating.carrierCode' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.duration' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.id' => 'required|string',
            'data.flightOffers.*.itineraries.*.segments.*.numberOfStops' => 'required|integer',
           // 'data.flightOffers.*.itineraries.*.segments.*.blacklistedInEU' => 'required|boolean',
            'data.flightOffers.*.price' => 'required|array',
            'data.flightOffers.*.price.currency' => 'required|string',
            'data.flightOffers.*.validatingAirlineCodes' => 'required|array',
            'data.flightOffers.*.validatingAirlineCodes.*' => 'required|string',
            'data.flightOffers.*.travelerPricings' => 'required|array',
            'data.flightOffers.*.price.total' => 'required|string',
            'data.flightOffers.*.price.base' => 'required|string',
            'data.flightOffers.*.price.fees' => 'required|array|min:1',
            'data.flightOffers.*.price.fees.*.amount' => 'required|string',
            'data.flightOffers.*.price.fees.*.type' => 'required|in:SUPPLIER,TICKETING,FORM_OF_PAYMENT',
            'data.flightOffers.*.price.grandTotal' => 'required|string',
            'data.flightOffers.*.pricingOptions' => 'required|array',
            'data.flightOffers.*.pricingOptions.fareType' => 'required|array|min:1',
            'data.flightOffers.*.pricingOptions.fareType.*' => 'required|in:PUBLISHED,NEGOTIATED,CORPORATE',
            'data.flightOffers.*.pricingOptions.includedCheckedBagsOnly' => 'required|boolean',
            'data.travelers' => 'required|array|min:1',
            'data.travelers.*.id' => 'required|string',
            'data.travelers.*.dateOfBirth' => 'required|date_format:Y-m-d',
            'data.travelers.*.gender' => 'required|in:MALE,FEMALE,OTHER',
            'data.travelers.*.name' => 'required|array',
            'data.travelers.*.name.firstName' => 'required|string',
            'data.travelers.*.name.lastName' => 'required|string',
            'data.travelers.*.contact' => 'sometimes|array',
            'data.travelers.*.contact.emailAddress' => 'required|email',
            'data.travelers.*.contact.phones' => 'required|array|min:1',
            'data.travelers.*.contact.phones.*.deviceType' => 'required|in:MOBILE,LANDLINE',
            'data.travelers.*.contact.phones.*.countryCallingCode' => 'required|string',
            'data.travelers.*.contact.phones.*.number' => 'required|string',
            'data.travelers.*.documents' => 'sometimes|array|min:1',
            'data.travelers.*.documents.*.documentType' => 'sometimes|string',
            'data.travelers.*.documents.*.birthPlace' => 'required|string',
            'data.travelers.*.documents.*.issuanceLocation' => 'sometimes|string',
            'data.travelers.*.documents.*.issuanceDate' => 'sometimes|date_format:Y-m-d',
            'data.travelers.*.documents.*.number' => 'sometimes|string',
            'data.travelers.*.documents.*.expiryDate' => 'sometimes|date_format:Y-m-d',
            'data.travelers.*.documents.*.issuanceCountry' => 'sometimes|string',
            'data.travelers.*.documents.*.validityCountry' => 'sometimes|string',
            'data.travelers.*.documents.*.nationality' => 'sometimes|string',
            'data.travelers.*.documents.*.holder' => 'sometimes|boolean',
            'data.remarks' => 'nullable|array',
            'data.remarks.general' => 'nullable|array|min:1',
            'data.remarks.general.*.subType' => 'required|string|in:GENERAL_MISCELLANEOUS',
            'data.remarks.general.*.text' => 'required|string',
            'data.ticketingAgreement' => 'required|array',
            'data.ticketingAgreement.option' => 'required|string|in:DELAY_TO_CANCEL',
            'data.ticketingAgreement.delay' => 'required|string',
            'data.contacts' => 'required|array|min:1',
            'data.contacts.*.addresseeName' => 'required|array',
            'data.contacts.*.addresseeName.firstName' => 'required|string',
            'data.contacts.*.addresseeName.lastName' => 'required|string',
            'data.contacts.*.companyName' => 'required|string',
            'data.contacts.*.purpose' => 'required|string',
            'data.contacts.*.phones' => 'required|array|min:1',
            'data.contacts.*.phones.*.deviceType' => 'required|in:MOBILE,LANDLINE',
            'data.contacts.*.phones.*.countryCallingCode' => 'required|string',
            'data.contacts.*.phones.*.number' => 'required|string',
            'data.contacts.*.emailAddress' => 'required|email',
            'data.contacts.*.address' => 'required|array',
            'data.contacts.*.address.lines' => 'required|array|min:1',
            'data.contacts.*.address.lines.*' => 'required|string',
            'data.contacts.*.address.postalCode' => 'required|string',
            'data.contacts.*.address.cityName' => 'required|string',
            'data.contacts.*.address.countryCode' => 'required|string',
        ];

        
    }
}
