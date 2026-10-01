<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordPtBr extends Notification
{
    use Queueable;

    public function __construct(
        protected string $token,
        protected string $email
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function resetUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:8081')), '/');

        return $base.'/redefinir-senha?token='.urlencode($this->token).'&email='.urlencode($this->email);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Redefina sua senha — SportsF')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no SportsF.')
            ->action('Redefinir senha', $this->resetUrl())
            ->line('Este link expira em 60 minutos e só pode ser usado uma vez.')
            ->line('Se você não pediu isso, pode ignorar este e-mail com segurança.');
    }
}
