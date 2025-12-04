<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdvertisementMail extends Mailable
{
    use Queueable, SerializesModels;

    public $advertisementData;

    /**
     * Create a new message instance.
     *
     * @param array $advertisementData
     */
    public function __construct($advertisementData)
    {
        $this->advertisementData = $advertisementData;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Interest on Installmental Payment',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.installment',  // The actual email template
        );
    }
    

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->view('emails.advertisement')  // Reference the email template
                    ->with('data', $this->advertisementData)
                    ->subject('New Interest on Installmental Payment');
    }
}
