<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\LoginLinkPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public int $ttlMinutes, public LoginLinkPurpose $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __($this->group().'.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-link', with: ['group' => $this->group()]);
    }

    private function group(): string
    {
        return $this->purpose === LoginLinkPurpose::EmailChange ? 'mail.emailChange' : 'mail.confirm';
    }
}
