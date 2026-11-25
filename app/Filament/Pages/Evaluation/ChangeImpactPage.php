<?php

namespace App\Filament\Pages\Evaluation;

use App\Filament\Support\EvaluationPage;
use App\Models\Annotation;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChangeImpactPage extends EvaluationPage
{
    protected static string $routePath = 'auswertungen/wirkung-einstellungen';

    protected static ?string $slug = 'auswertungen/wirkung-einstellungen';

    protected static ?string $title = 'Wirkung von Einstellungsänderungen';

    protected static ?string $navigationLabel = 'Wirkung von Änderungen';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 2;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->compact()
                ->columnSpanFull()
                ->extraAttributes(['class' => 'w-full'])
                ->schema([
                    Select::make('annotation')
                        ->label('Änderung (Notiz)')
                        ->options(fn (): array => Annotation::query()
                            ->orderByDesc('timestamp')
                            ->limit(60)
                            ->get()
                            ->mapWithKeys(fn (Annotation $a): array => [
                                $a->id => $a->timestamp->format('d.m.Y H:i') . ' – ' . \Illuminate\Support\Str::limit($a->note, 90),
                            ])
                            ->all())
                        ->default(fn () => Annotation::query()->where('category', 'setting')->orderByDesc('timestamp')->value('id')
                            ?? Annotation::query()->orderByDesc('timestamp')->value('id'))
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->columnSpan(['default' => 1, 'md' => 3]),
                    Select::make('days')
                        ->label('Tage davor und danach')
                        ->options(['3' => '3 Tage', '5' => '5 Tage', '7' => '7 Tage', '14' => '14 Tage'])
                        ->default('7')
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live(),
                ])
                ->columns(['default' => 1, 'md' => 4]),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\Evaluation\ChangeImpactSummary::class,
            \App\Filament\Widgets\Evaluation\ChangeImpactTable::class,
        ];
    }
}
