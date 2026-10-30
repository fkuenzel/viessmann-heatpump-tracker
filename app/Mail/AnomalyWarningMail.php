<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Warnung bei auffaelligem Tagesverbrauch (MailReportingService::checkAnomaly(),
 * Fahrplan Phase 6, Fabian 29.10.2026).
 */
class AnomalyWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Carbon $date,
        public readonly float $value,
        public readonly float $average,
        public readonly float $deviationPercent,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wärmepumpe – Auffälliger Tagesverbrauch am ' . $this->date->format('d.m.Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.anomaly-warning',
        );
    }
}
