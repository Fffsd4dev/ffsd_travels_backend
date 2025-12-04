<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class FlightSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Change to true to allow the request
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'originLocationCode' => 'required|string|min:3|max:3',
            'destinationLocationCode' => 'required|string|min:3|max:3',
            'departureDate' => 'required|date|after_or_equal:today',
            'returnDate' => 'nullable|date|after_or_equal:departureDate',
            'adults' => 'required|integer|min:1',
            'max' => 'nullable|integer|min:1',
            'infants' => 'nullable|integer|min:0',
            'children' => 'nullable|integer|min:0',
            'currencyCode' => 'required|string|size:3',
            'travelClass' => 'required|string|in:ECONOMY,BUSINESS,FIRST',
            'fareType'=>'nullable|string|in:NEGOTIATED,PUBLISHED,CORPORATE',
            'addOneWayOffers=>nullable|string|in:TRUE,FALSE'
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
