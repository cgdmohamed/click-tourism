<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invitation a new user receives: a link to choose their own password (ADR-0032). Written in the language the administrator chose for them. */
class UserInvitation extends Notification
{
    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?invite=1&token='.$this->token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        $days = (int) round((int) config('auth.passwords.invites.expire') / 1440);
        $company = (string) config('app.name');

        if (($notifiable->locale ?? 'ar') === 'ar') {
            return (new MailMessage)
                ->subject('دعوة للانضمام إلى '.$company)
                ->greeting('مرحباً '.$notifiable->name)
                ->line('تمت دعوتك لاستخدام '.$company.'. اضغط الزر لاختيار كلمة المرور الخاصة بك. الرابط صالح لمدة '.$days.' أيام ويُستخدم مرة واحدة.')
                ->action('اختيار كلمة المرور', $url)
                ->line('إن لم تتوقع هذه الدعوة فتجاهل الرسالة.');
        }

        return (new MailMessage)
            ->subject('You are invited to '.$company)
            ->greeting('Hello '.$notifiable->name)
            ->line('You have been invited to use '.$company.'. Press the button to choose your own password. The link is valid for '.$days.' days and works once.')
            ->action('Choose your password', $url)
            ->line('If you did not expect this invitation, ignore this message.');
    }
}
