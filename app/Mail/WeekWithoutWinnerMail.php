<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ConfigSource;
use App\Models\Week;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeekWithoutWinnerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Week $week, public ConfigSource $source) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.week.subject', ['date' => $this->date()]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.week-without-winner',
            with: [
                'date' => $this->date(),
                'outcome' => __($this->week->track_id !== null ? 'mail.week.default' : 'mail.week.fallback'),
            ],
        );
    }

    private function date(): string
    {
        return $this->week->starts_at
            ->copy()
            ->setTimezone((string) config('mvp.timezone'))
            ->isoFormat('D MMMM YYYY');
    }
}
