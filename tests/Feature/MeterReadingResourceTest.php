<?php

namespace Tests\Feature;

use App\Enums\MeterReadingReason;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Models\MeterReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeterReadingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        MeterReading::create([
            'timestamp' => now()->subDays(5),
            'counter_ht' => 1000,
            'counter_nt' => 500,
            'reason' => MeterReadingReason::StartValue,
        ]);
    }

    public function test_list_page_renders_with_reason_column(): void
    {
        Livewire::test(ListMeterReadings::class)
            ->assertSuccessful()
            ->assertSee('Inbetriebnahme / Startwert');
    }

    public function test_create_rejects_a_decreasing_counter(): void
    {
        Livewire::test(CreateMeterReading::class)
            ->fillForm([
                'timestamp' => now()->toDateTimeString(),
                'reason' => 'regular',
                'counter_ht' => 900,
                'counter_nt' => 510,
            ])
            ->call('create')
            ->assertHasFormErrors(['counter_ht']);
    }

    public function test_create_accepts_a_valid_reading_with_reason(): void
    {
        Livewire::test(CreateMeterReading::class)
            ->fillForm([
                'timestamp' => now()->toDateTimeString(),
                'reason' => 'check',
                'counter_ht' => 1010,
                'counter_nt' => 505,
                'note' => 'Kontrolle',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('meter_readings', ['reason' => 'check', 'note' => 'Kontrolle']);
    }

    public function test_other_reason_requires_a_note(): void
    {
        Livewire::test(CreateMeterReading::class)
            ->fillForm([
                'timestamp' => now()->toDateTimeString(),
                'reason' => 'other',
                'counter_ht' => 1010,
                'counter_nt' => 505,
            ])
            ->call('create')
            ->assertHasFormErrors(['note']);
    }

    public function test_read_now_action_creates_a_reading(): void
    {
        Livewire::test(ListMeterReadings::class)
            ->callAction('readNow', ['counter_ht' => 1020, 'counter_nt' => 510, 'reason' => 'regular'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('meter_readings', ['counter_ht' => 1020, 'source' => 'manual']);
    }
}
