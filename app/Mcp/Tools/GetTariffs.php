<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use App\Models\PriceHistory;

#[Name('get_tariffs')]
#[Title('Stromtarife')]
#[Description('Hinterlegte Stromtarife mit Gültigkeitsbeginn, Arbeitspreisen HT/NT (EUR je kWh) und Grundpreis, neueste zuerst.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetTariffs extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured(['tariffs' => PriceHistory::query()->orderByDesc('valid_from')->get()->map(fn (PriceHistory $t): array => [
            'id' => $t->id,
            'valid_from' => $t->valid_from->toDateString(),
            'provider_name' => $t->provider_name,
            'tariff_name' => $t->tariff_name,
            'ht_price' => (float) $t->ht_price,
            'nt_price' => (float) $t->nt_price,
            'base_fee' => (float) $t->base_fee,
            'base_fee_period' => $t->base_fee_period,
        ])->all()]);
    }
}
