<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SocialPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SocialPostReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SocialPost $post) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.social.subject', ['type' => $this->typeLabel()]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.social-post-ready',
            with: [
                'type' => $this->typeLabel(),
                'text' => $this->post->text,
                'url' => route('admin.social.index', ['tab' => 'pendientes']).'#post-'.$this->post->id,
            ],
        );
    }

    private function typeLabel(): string
    {
        return __('mail.social.type.'.$this->post->type->value);
    }
}
