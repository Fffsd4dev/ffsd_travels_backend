<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerFlightBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $bookingData,
        public string $customerName
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $data = $this->bookingData;

        // Booking reference and Amadeus order ID.
        $pnr = data_get($data, 'associatedRecords.0.reference');
        $orderId = data_get($data, 'id');

        // Passenger details.
        $passengers = [];

        foreach (data_get($data, 'travelers', []) as $traveler) {
            $name = trim(implode(' ', array_filter([
                data_get($traveler, 'name.firstName'),
                data_get($traveler, 'name.middleName'),
                data_get($traveler, 'name.lastName'),
            ])));

            $passengers[] = [
                'name' => $name !== '' ? $name : 'Passenger',
            ];
        }

        // Flight segments.
        $segments = [];

        foreach (data_get($data, 'flightOffers', []) as $offer) {
            foreach (data_get($offer, 'itineraries', []) as $itinerary) {
                foreach (data_get($itinerary, 'segments', []) as $segment) {
                    $segments[] = [
                        'departure' => [
                            'iataCode' => data_get(
                                $segment,
                                'departure.iataCode',
                                'N/A'
                            ),
                            'at' => data_get($segment, 'departure.at'),
                        ],
                        'arrival' => [
                            'iataCode' => data_get(
                                $segment,
                                'arrival.iataCode',
                                'N/A'
                            ),
                            'at' => data_get($segment, 'arrival.at'),
                        ],
                        'carrierCode' => data_get(
                            $segment,
                            'carrierCode',
                            ''
                        ),
                        'number' => data_get($segment, 'number', ''),
                    ];
                }
            }
        }

        // Fare details from the first flight offer.
        $price = data_get($data, 'flightOffers.0.price', []);

        return (new MailMessage)
            ->subject('Your FFSD Travels Flight Booking Confirmation')
            ->view('emails.flights.booking-receipt', [
                'customerName' => $this->customerName,
                'bookingId' => $orderId,
                'pnrs' => $pnr,
                'bookingStatus' => 'Booking created',
                'passengers' => $passengers,
                'segments' => $segments,
                'totalPayable' => data_get($price, 'grandTotal'),
                'currency' => data_get($price, 'currency'),
            ]);
    }
}
