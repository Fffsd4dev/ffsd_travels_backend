
{{-- resources/views/emails/flights/booking-receipt.blade.php --}}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flight Booking Confirmation | FFSD Travels</title>
</head>

<body style="margin:0; padding:0; background-color:#F7F7F7; font-family:Arial,Helvetica,sans-serif; color:#333333;">

    <div style="max-width:650px; margin:30px auto; background-color:#FFFFFF; border-radius:10px; overflow:hidden; border:1px solid #EEEEEE;">

        {{-- Brand Header --}}
        <div style="background-color:#D71920; padding:30px 20px; text-align:center;">

            <a href="https://ffsdtravels.com"
               style="color:#FFFFFF; text-decoration:none; font-size:28px; font-weight:bold; letter-spacing:1px;">
                FFSD TRAVELS
            </a>

            <p style="margin:10px 0 0; color:#FFFFFF; font-size:15px;">
                Your Flight Booking Confirmation
            </p>
        </div>

        {{-- Main Content --}}
        <div style="padding:30px 25px;">

            <h2 style="margin:0 0 18px; color:#D71920; font-size:23px;">
                Hello {{ $customerName ?? 'Traveller' }},
            </h2>

            <p style="font-size:15px; line-height:1.8; margin:0 0 24px;">
                Thank you for booking with FFSD Travels. Your flight booking
                details are provided below. Please keep this email for your records.
            </p>

            {{-- Booking Summary --}}
            <div style="background-color:#FFF5F5; border:1px solid #F3D0D0; border-left:5px solid #D71920; border-radius:6px; padding:20px; margin-bottom:28px;">

                <h3 style="margin:0 0 18px; color:#D71920; font-size:18px;">
                    Booking Summary
                </h3>

                <p style="margin:0 0 14px; font-size:14px; line-height:1.7;">
                    <span style="color:#666666;">Booking Reference (PNR)</span><br>
                    <strong style="color:#D71920; font-size:20px;">
                        {{ $pnrs ?: 'Pending confirmation' }}
                    </strong>
                </p>

                <p style="margin:0 0 14px; font-size:14px; line-height:1.7;">
                    <span style="color:#666666;">Booking Order ID</span><br>
                    <strong style="color:#333333;">
                        {{ $bookingId ?? 'N/A' }}
                    </strong>
                </p>

                <p style="margin:0; font-size:14px; line-height:1.7;">
                    <span style="color:#666666;">Booking Status</span><br>
                    <strong style="color:#D71920;">
                        {{ $bookingStatus ?? 'Booking created' }}
                    </strong>
                </p>
            </div>

            {{-- Passenger Details --}}
            @if(!empty($passengers))
                <div style="margin-bottom:28px;">

                    <h3 style="color:#D71920; font-size:18px; margin:0 0 15px; padding-bottom:10px; border-bottom:2px solid #D71920;">
                        Passenger Details
                    </h3>

                    @foreach($passengers as $passenger)
                        <div style="padding:13px 15px; margin-bottom:8px; background-color:#FFFFFF; border:1px solid #EEEEEE; border-radius:5px;">
                            <strong style="color:#333333; font-size:14px;">
                                {{ $passenger['name'] ?? 'Passenger' }}
                            </strong>
                        </div>
                    @endforeach

                </div>
            @endif

            {{-- Flight Itinerary --}}
            @if(!empty($segments))
                <div style="margin-bottom:28px;">

                    <h3 style="color:#D71920; font-size:18px; margin:0 0 15px; padding-bottom:10px; border-bottom:2px solid #D71920;">
                        Flight Itinerary
                    </h3>

                    @foreach($segments as $segment)
                        <div style="border:1px solid #EEEEEE; border-radius:7px; margin-bottom:14px; overflow:hidden;">

                            <div style="background-color:#D71920; padding:14px 16px; color:#FFFFFF;">

                                <span style="font-size:18px; font-weight:bold;">
                                    {{ data_get($segment, 'departure.iataCode', 'N/A') }}
                                </span>

                                <span style="padding:0 12px; font-size:18px;">
                                    &rarr;
                                </span>

                                <span style="font-size:18px; font-weight:bold;">
                                    {{ data_get($segment, 'arrival.iataCode', 'N/A') }}
                                </span>

                            </div>

                            <div style="padding:17px; background-color:#FFFFFF;">

                                <p style="margin:0 0 12px; font-size:14px; line-height:1.7;">
                                    <strong style="color:#666666;">Airline / Flight:</strong><br>
                                    {{ data_get($segment, 'carrierCode', '') }}
                                    {{ data_get($segment, 'number', '') }}
                                </p>

                                <p style="margin:0 0 12px; font-size:14px; line-height:1.7;">
                                    <strong style="color:#666666;">Departure:</strong><br>
                                    {{ data_get($segment, 'departure.at') ?? 'To be confirmed' }}
                                </p>

                                <p style="margin:0; font-size:14px; line-height:1.7;">
                                    <strong style="color:#666666;">Arrival:</strong><br>
                                    {{ data_get($segment, 'arrival.at') ?? 'To be confirmed' }}
                                </p>

                            </div>
                        </div>
                    @endforeach

                </div>
            @endif

            {{-- Payment Summary --}}
            @if(isset($totalPayable) && $totalPayable !== '')
                <div style="background-color:#D71920; color:#FFFFFF; padding:22px; border-radius:7px; margin-bottom:28px;">

                    <p style="margin:0 0 10px; font-size:14px; color:#FFFFFF;">
                        Total Payable
                    </p>

                    <p style="margin:0; font-size:27px; font-weight:bold; color:#FFFFFF;">
                        {{ $currency ?? '' }}
                        {{ number_format((float) $totalPayable, 2) }}
                    </p>

                </div>
            @endif

            {{-- Important Information --}}
            <div style="background-color:#FFF5F5; border:1px solid #F3D0D0; border-left:4px solid #D71920; padding:17px; border-radius:5px; margin-bottom:25px;">

                <p style="margin:0 0 8px; color:#D71920; font-weight:bold; font-size:14px;">
                    Important Travel Information
                </p>

                <p style="margin:0; color:#444444; font-size:13px; line-height:1.8;">
                    Booking confirmation does not necessarily mean your
                    e-ticket has been issued. Your e-ticket will be sent
                    once ticket issuance is completed. Please verify your
                    ticket status before travelling.
                </p>

            </div>

            <p style="font-size:14px; line-height:1.8; margin:0 0 10px;">
                Thank you for trusting FFSD Travels with your journey.
            </p>

            <p style="font-size:14px; line-height:1.8; margin:0;">
                We wish you a safe and pleasant trip.
            </p>

            <p style="font-size:14px; line-height:1.8; margin:22px 0 0; color:#D71920; font-weight:bold;">
                The FFSD Travels Team
            </p>

        </div>

        {{-- Footer --}}
        <div style="background-color:#FFFFFF; border-top:3px solid #D71920; padding:24px 20px; text-align:center;">

            <p style="margin:0 0 10px; color:#D71920; font-size:16px; font-weight:bold;">
                FFSD TRAVELS
            </p>

            <p style="margin:0 0 14px; color:#666666; font-size:12px; line-height:1.8;">
                Your trusted travel partner.
            </p>

            <p style="margin:0 0 14px; font-size:13px;">
                <a href="https://ffsdtravels.com"
                   style="color:#D71920; text-decoration:none; font-weight:bold;">
                    Visit ffsdtravels.com
                </a>
            </p>

            <p style="margin:0; color:#888888; font-size:11px; line-height:1.8;">
                &copy; {{ date('Y') }} FFSD Travels. All rights reserved.<br>
                This is an automated booking notification.
            </p>

        </div>

    </div>

</body>
</html>
