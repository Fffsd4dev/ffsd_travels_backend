<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookedNotification extends Notification
{
    use Queueable;

    public $message;
    public $subject;
    public $fromEmail;
    public $mailer;
    public $pnrs;
    public $flightId;
    public $fullName;  // Add this property to hold the full name

    public function __construct(string $flightId, array $pnrs, string $fullName)
    {
        $this->message = 'A flight has been booked on FFSD travels. Kindly process the ticketing as soon as possible';
        $this->subject = 'Booked Flight Notification';
        $this->fromEmail = env('MAIL_FROM_NAME');
        $this->mailer = env('MAIL_MAILER');
        $this->flightId = $flightId; // Set flightId
        $this->pnrs = $pnrs; // Set pnrs
        $this->fullName = $fullName; // Set fullName
    }

    // Define the via() method to specify the delivery channel(s)
    public function via($notifiable)
    {
        return ['mail'];  // Specifies that the notification will be sent via email
    }

    public function toMail($notifiable): MailMessage
    {
        // Use the full name passed in the constructor
        $name = $this->fullName;

        // Format the PNRs into a string
        $pnrString = '';
        foreach ($this->pnrs as $pnr) {
            $pnrString .= "PNR: $pnr\n";
        }

        return (new MailMessage)
            ->mailer($this->mailer)
            ->subject($this->subject)
            ->greeting('Hello, ' . ucwords($name))  // Use the name here
            ->line($this->message)
            ->line("Flight ID: {$this->flightId}")
            ->line('This is the id and their respective PNRs:')
            ->line($pnrString);
    }
}
