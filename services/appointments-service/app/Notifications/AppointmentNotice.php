<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AppointmentNotice extends Notification
{
    public function __construct(private string $subject, private string $html, private ?string $senderName, private ?string $logoPath) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)
            ->view('emails.appointment-notification', ['htmlBody' => $this->html]);
        if ($this->senderName && config('mail.from.address')) {
            $mail->from(config('mail.from.address'), $this->senderName);
        }
        if ($this->logoPath && is_file($this->logoPath)) {
            $mail->attach($this->logoPath, [
                'as' => 'company-logo.'.pathinfo($this->logoPath, PATHINFO_EXTENSION),
                'mime' => mime_content_type($this->logoPath) ?: 'application/octet-stream',
            ]);
        }

        return $mail;
    }
}
