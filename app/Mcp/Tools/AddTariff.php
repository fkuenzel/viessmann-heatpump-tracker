<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AuditsWrites;
use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Models\PriceHistory;
use Illuminate\Validation\Rule;

#[Name('add_tariff')]
#[Title('Stromtarif anlegen')]
#[Description('Legt einen Stromtarif mit Gültigkeitsbeginn an (Arbeitspreise HT/NT in EUR je kWh, Grundpreis in EUR je Jahr oder Monat). Bei Ein-Zähler-Tarifen HT und NT gleich setzen. Vorhandene Tarife werden nicht verändert; zwei Tarife mit gleichem Gültigkeitsbeginn sind nicht erlaubt. Löschen ist nur in der Oberfläche möglich.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AddTariff extends Tool
{
    use AuditsWrites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'valid_from' => $schema->string()->format('date')->description('Gültig ab (JJJJ-MM-TT).')->required(),
            'ht_price' => $schema->number()->description('Arbeitspreis HT in EUR je kWh (z. B. 0.2403).')->required(),
            'nt_price' => $schema->number()->description('Arbeitspreis NT in EUR je kWh.')->required(),
            'base_fee' => $schema->number()->description('Grundpreis in EUR (je base_fee_period).')->required(),
            'base_fee_period' => $schema->string()->enum(['yearly', 'monthly'])->description('Bezugszeitraum des Grundpreises. Standard: yearly.'),
            'provider_name' => $schema->string()->description('Anbieter.')->required(),
            'tariff_name' => $schema->string()->description('Tarifname.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'ht_price' => ['required', 'numeric', 'between:0.01,2'],
            'nt_price' => ['required', 'numeric', 'between:0.01,2'],
            'base_fee' => ['required', 'numeric', 'between:0,1000'],
            'base_fee_period' => ['nullable', Rule::in(['yearly', 'monthly'])],
            'provider_name' => ['required', 'string', 'max:255'],
            'tariff_name' => ['required', 'string', 'max:255'],
        ]);

        if (PriceHistory::query()->whereDate('valid_from', $data['valid_from'])->exists()) {
            return Response::error('Für diesen Gültigkeitsbeginn existiert bereits ein Tarif. Bitte in der Oberfläche unter "Stromtarife" ändern.');
        }

        $tariff = PriceHistory::create([...$data, 'base_fee_period' => $data['base_fee_period'] ?? 'yearly']);

        $this->audit('add_tariff', $data, null, $tariff->only(['id', 'valid_from', 'ht_price', 'nt_price', 'base_fee', 'base_fee_period', 'provider_name', 'tariff_name']));

        return Response::structured(['saved' => true, 'id' => $tariff->id]);
    }
}
