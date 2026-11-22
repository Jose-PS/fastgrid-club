<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\IntegrationEventType;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IntegrationFailingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Server $server,
        public IntegrationEventType $type,
        public string $summary,
        public int $failures,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.integration.subject', ['server' => $this->server->name]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.integration-failing',
            with: [
                'server' => $this->server->name,
                'typeLabel' => __("mail.integration.type.{$this->type->value}"),
                'summary' => $this->summary,
                'failures' => $this->failures,
            ],
        );
    }
}
