<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Warnung bei Sync-Ausfall (MailReportingService::checkSyncOutage(),
 * Fahrplan Phase 6, Fabian 29.10.2026).
 */
class SyncOutageWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Carbon $lastSyncAt,
        public readonly Carbon $detectedAt,
        public readonly int $gapHours,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wärmepumpe – Kein Sync seit ' . $this->gapHours . ' Stunden',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.sync-outage-warning',
        );
    }
}
