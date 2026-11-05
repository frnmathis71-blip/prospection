<?php

namespace App\Mail;

use App\Services\RenderedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Email de prospection déjà rendu (M8) : HTML + texte brut, en-têtes de désinscription en un
 * clic (List-Unsubscribe / List-Unsubscribe-Post). L'envoi réel passe par la file, les quotas
 * et la liste d'opposition (M9).
 */
class ProspectMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RenderedEmail $email, public bool $isTest = false, public ?string $messageId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->isTest ? '[TEST] ' : '').$this->email->subject);
    }

    public function headers(): Headers
    {
        // Message-ID connu à l'avance : il relie les réponses à cet email (M10).
        return new Headers(messageId: $this->messageId, text: $this->email->unsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->email->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->email->html, text: 'emails.prospect-text', with: ['text' => $this->email->text]);
    }
}
