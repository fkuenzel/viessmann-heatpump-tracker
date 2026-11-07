<?php

namespace App\Mcp\Tools;

use App\Filament\Resources\Annotations\AnnotationResource;
use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Models\Annotation;
use App\Services\ConfigChangeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
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

/**
 * Liest die Notizen aus der Tabelle annotations (manuell erfasste Notizen
 * sowie die automatischen Einstellungsnotizen des Konfigurationsverlaufs ab
 * 1.2.0). So lassen sich Auffälligkeiten im Verbrauch oder COP mit
 * Einstellungsänderungen, Wartungen oder Beobachtungen abgleichen.
 */
#[Name('get_annotations')]
#[Title('Notizen')]
#[Description('Notizen im Zeitraum, neueste zuerst: manuell erfasste Notizen (Einstellung, Wartung, Beobachtung, Sonstiges) und automatisch erfasste Einstellungsänderungen (z. B. Heizkurve, Zeitprogramme, Solltemperaturen, Ferienprogramm). Hilfreich, um Veränderungen bei Verbrauch oder COP mit Änderungen an der Anlage zu erklären. Optional nach Kategorie filterbar.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetAnnotations extends Tool
{
    use InteractsWithToolInput;

    /**
     * Höchstanzahl zurückgegebener Notizen, damit die Antwort klein bleibt.
     */
    private const MAX_RESULTS = 200;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')
                ->description('Erster Tag des Zeitraums (JJJJ-MM-TT). Standard: 30 Tage bis einschließlich "to".'),
            'to' => $schema->string()->format('date')
                ->description('Letzter Tag des Zeitraums (JJJJ-MM-TT). Standard: heute. Höchstens 366 Tage Zeitraum.'),
            'category' => $schema->string()
                ->enum(array_keys(AnnotationResource::CATEGORIES))
                ->description('Nur Notizen dieser Kategorie. Automatisch erfasste Einstellungsänderungen haben die Kategorie "einstellung".'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        [$from, $to] = $this->resolveDateRange($request, defaultDays: 30, maxDays: 366);

        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(array_keys(AnnotationResource::CATEGORIES))],
        ]);

        $query = Annotation::query()
            ->whereBetween('timestamp', [$from, $to->copy()->endOfDay()])
            ->when($validated['category'] ?? null, fn ($query, string $category) => $query->where('category', $category));

        $total = (clone $query)->count();

        $annotations = $query
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (Annotation $annotation): array => [
                'timestamp' => $this->iso($annotation->timestamp),
                'date' => $annotation->timestamp->toDateString(),
                'category' => $annotation->category,
                'category_label' => AnnotationResource::CATEGORIES[$annotation->category] ?? $annotation->category,
                'note' => $annotation->note,
                'automatic' => $annotation->category === ConfigChangeService::ANNOTATION_CATEGORY
                    && str_ends_with((string) $annotation->note, '(automatisch erfasst)'),
            ])
            ->all();

        return Response::structured([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => $total,
            'returned' => count($annotations),
            'truncated' => $total > count($annotations),
            'annotations' => $annotations,
            'note' => 'Automatische Notizen entstehen beim Sync, wenn sich eine Einstellung geändert hat; der Zeitpunkt ist daher nur auf das Sync-Intervall (15 Minuten) genau.',
        ]);
    }
}
