<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookedMailable extends Mailable
{
    use Queueable, SerializesModels;

    public $flightId;
    public $pnrs;

    /**
     * Create a new message instance.
     */
    public function __construct(string $flightId, array $pnrs)
    {
        $this->flightId = $flightId;
        $this->pnrs = $pnrs;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Flight Booked', // Subject for the email
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Pass variables to the email view
        return new Content(
            view: 'emails.booked', // Specify the view for the email
            data: [
                'flightId' => $this->flightId,
                'pnrs' => $this->pnrs,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return []; // Add attachments if needed
    }
}
