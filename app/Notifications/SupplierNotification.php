<?php

namespace App\Notifications;

use App\Utils\NotificationUtil;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;

class SupplierNotification extends Notification
{
    use Queueable;

    protected $notificationInfo;

    protected $cc;

    protected $bcc;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($notificationInfo)
    {
        $this->notificationInfo = $notificationInfo;

        $notificationUtil = new NotificationUtil();
        $notificationUtil->configureEmail($notificationInfo);
        $this->cc = ! empty($notificationInfo['cc']) ? $notificationInfo['cc'] : null;
        $this->bcc = ! empty($notificationInfo['bcc']) ? $notificationInfo['bcc'] : null;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Mail\Mailable
     */
    public function toMail($notifiable)
    {
        $data = $this->notificationInfo;

        $html = view('emails.plain_html', ['content' => $data['email_body']])->render();

        $mail = (new Mailable)
            ->subject($data['subject'])
            ->html($html);

        $this->addressMailableRecipients($mail, $notifiable);

        if (! empty($this->cc)) {
            $mail->cc($this->cc);
        }
        if (! empty($this->bcc)) {
            $mail->bcc($this->bcc);
        }

        if (! empty($data['pdf']) && ! empty($data['pdf_name'])) {
            $mail->attachData($data['pdf']->Output($data['pdf_name'], 'S'), $data['pdf_name'], [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }

    /**
     * @param  \Illuminate\Mail\Mailable  $mail
     * @param  mixed  $notifiable
     */
    protected function addressMailableRecipients(Mailable $mail, $notifiable): void
    {
        $to = $notifiable->routeNotificationFor('mail', $this);

        if (is_string($to)) {
            $mail->to($to);

            return;
        }

        if (! is_array($to)) {
            return;
        }

        foreach ($to as $email => $name) {
            if (is_numeric($email)) {
                $mail->to(is_string($name) ? $name : ($name->email ?? $name));
            } else {
                $mail->to($email, is_string($name) ? $name : null);
            }
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
