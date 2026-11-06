<?php

namespace Tests\Feature;

use App\Mail\AnomalyWarningMail;
use App\Mail\SyncOutageWarningMail;
use App\Models\ApiLog;
use App\Models\Setting;
use App\Services\MailReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Absicherung des Refactorings: Die Auswertung liegt jetzt in
 * evaluateAnomaly()/evaluateSyncOutage() (auch vom MCP-Server genutzt),
 * Mailversand und Debounce müssen sich unverändert verhalten.
 */
class MailReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-06 12:00:00', 'Europe/Berlin');
        Carbon::setTestNow($this->now);

        Mail::fake();
        Setting::set('mail_recipient', 'fabian@example.test');
        // Periodische Zusammenfassung ausblenden, getestet werden nur die Warnungen
        Setting::set('last_summary_period_end', $this->now->copy()->subDay()->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function logDay(string $date, float $kwh, string $time = '23:45'): ApiLog
    {
        $timestamp = Carbon::parse($date.' '.$time, 'Europe/Berlin');

        return ApiLog::create([
            'timestamp' => $timestamp,
            'measured_at' => $timestamp,
            'day_index' => 0,
            'power_total_kwh' => $kwh,
            'source' => 'scheduled',
        ]);
    }

    private function seedAnomaly(): void
    {
        foreach (['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $this->logDay($date, 20);
        }

        $this->logDay('2026-11-05', 40);
        $this->logDay('2026-11-06', 4, '11:45');
    }

    public function test_sends_anomaly_mail_once_per_day(): void
    {
        $this->seedAnomaly();
        $service = app(MailReportingService::class);
        $latest = Carbon::parse('2026-11-06 11:45:00', 'Europe/Berlin');

        $service->checkAndNotify($this->now, $latest);
        $service->checkAndNotify($this->now, $latest);

        Mail::assertSent(AnomalyWarningMail::class, 1);
        $this->assertSame('2026-11-06', Setting::get('last_anomaly_warning_date'));
    }

    public function test_sends_no_anomaly_mail_for_a_normal_day(): void
    {
        $this->seedAnomaly();
        ApiLog::query()->where('timestamp', 'like', '2026-11-05%')->update(['power_total_kwh' => 21]);

        app(MailReportingService::class)->checkAndNotify($this->now, Carbon::parse('2026-11-06 11:45:00', 'Europe/Berlin'));

        Mail::assertNotSent(AnomalyWarningMail::class);
    }

    public function test_sends_outage_mail_once_per_day(): void
    {
        $service = app(MailReportingService::class);
        $latest = Carbon::parse('2026-11-03 08:00:00', 'Europe/Berlin');

        $service->checkAndNotify($this->now, $latest);
        $service->checkAndNotify($this->now, $latest);

        Mail::assertSent(SyncOutageWarningMail::class, 1);
    }

    public function test_sends_nothing_without_a_recipient(): void
    {
        Setting::set('mail_recipient', '');
        $this->seedAnomaly();

        app(MailReportingService::class)->checkAndNotify($this->now, Carbon::parse('2026-11-03 08:00:00', 'Europe/Berlin'));

        Mail::assertNothingSent();
    }

    public function test_evaluate_methods_are_side_effect_free(): void
    {
        $this->seedAnomaly();
        $service = app(MailReportingService::class);

        $anomaly = $service->evaluateAnomaly($this->now);
        $outage = $service->evaluateSyncOutage($this->now, Carbon::parse('2026-11-03 08:00:00', 'Europe/Berlin'));

        $this->assertSame('anomaly', $anomaly['status']);
        $this->assertSame('outage', $outage['status']);
        $this->assertSame(76, $outage['gap_hours']);
        Mail::assertNothingSent();
        $this->assertNull(Setting::get('last_anomaly_warning_date'));
    }
}
