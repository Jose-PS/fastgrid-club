<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public int $ttlMinutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.login.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.login-link');
    }
}
