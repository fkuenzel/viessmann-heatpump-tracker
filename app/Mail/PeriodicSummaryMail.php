<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Periodische Zusammenfassung (MailReportingService::checkPeriodicSummary(),
 * Fahrplan Phase 6, Fabian 29.10.2026).
 */
class PeriodicSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{power_total: ?float, power_heating: ?float, power_dhw: ?float, thermal_total: ?float, avg_cop: ?float, avg_outdoor_temp: ?float}  $summary
     */
    public function __construct(
        public readonly string $periodLabel,
        public readonly Carbon $periodStart,
        public readonly Carbon $periodEnd,
        public readonly array $summary,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wärmepumpe – Zusammenfassung: ' . $this->periodLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.periodic-summary',
        );
    }
}
