<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class Enquiries extends Mailable
{
    use Queueable, SerializesModels;

    public $enquiryData;  // Declare the property for enquiry data

    /**
     * Create a new message instance.
     */
    public function __construct($enquiryData)
    {
        $this->enquiryData = $enquiryData;  // Assign the data correctly
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Enquiries',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.enquiries',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->from(env('MAIL_FROM_ADDRESS'))
                    ->view('emails.enquiries')  // Reference the email template
                    ->with('data', $this->enquiryData)  // Pass the correct data
                    ->subject('New Enquiry');
    }
}
