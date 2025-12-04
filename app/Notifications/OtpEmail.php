<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
class OtpEmail extends Notification
{
    use Queueable;
    public $message;
    public $subject;
    public $fromEmail;
    public $mailer;
    public $otp;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
        $this->message = 'Use the code below to validate your email';
        $this->subject='Email Verification';
        $this->fromEmail=env('MAIL_FROM_NAME');
        $this->mailer= env('mailer');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
       //$token = $this->otp->generate($notifiable->email,'numeric');

        return (new MailMessage)
            ->mailer(env('MAIL_MAILER'))
            ->subject($this->subject)
            ->greeting('Hello,'.$notifiable->firstName,' '.$notifiable->lastName)
            ->line($this->message)
            ->line('code : '.$notifiable->otp);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
