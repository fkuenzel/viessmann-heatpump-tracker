<?php

namespace App\Filament\Resources\Annotations;

use App\Filament\Resources\Annotations\Pages\CreateAnnotation;
use App\Filament\Resources\Annotations\Pages\EditAnnotation;
use App\Filament\Resources\Annotations\Pages\ListAnnotations;
use App\Models\Annotation;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnotationResource extends Resource
{
    protected static ?string $model = Annotation::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Notizen';

    protected static \UnitEnum | string | null $navigationGroup = 'Daten erfassen';

    protected static ?string $modelLabel = 'Notiz';

    protected static ?string $pluralModelLabel = 'Notizen';

    public const CATEGORIES = [
        'einstellung' => 'Einstellung',
        'wartung' => 'Wartung',
        'beobachtung' => 'Beobachtung',
        'sonstiges' => 'Sonstiges',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('timestamp')
                ->label('Zeitpunkt')
                ->required()
                ->seconds(false)
                ->default(now())
                ->native(false),
            Select::make('category')
                ->label('Kategorie')
                ->options(self::CATEGORIES)
                ->native(false),
            Textarea::make('note')
                ->label('Notiz')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Zeitpunkt')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Kategorie')
                    ->formatStateUsing(fn (?string $state) => self::CATEGORIES[$state] ?? $state)
                    ->badge()
                    ->toggleable(),
                TextColumn::make('note')
                    ->label('Notiz')
                    ->limit(80)
                    ->wrap(),
            ])
            ->defaultSort('timestamp', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnotations::route('/'),
            'create' => CreateAnnotation::route('/create'),
            'edit' => EditAnnotation::route('/{record}/edit'),
        ];
    }
}
