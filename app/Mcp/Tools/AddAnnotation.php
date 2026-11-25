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
use App\Filament\Resources\Annotations\AnnotationResource;
use App\Models\Annotation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

#[Name('add_annotation')]
#[Title('Notiz anlegen')]
#[Description('Legt eine Notiz an (Einstellung, Wartung, Beobachtung, Sonstiges), z. B. eine Änderung an der Heizkurve, damit sie in den Auswertungen (Wirkung von Änderungen) auswählbar ist. Ändert nichts an der Wärmepumpe.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class AddAnnotation extends Tool
{
    use AuditsWrites;
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->description('Text der Notiz.')->required(),
            'category' => $schema->string()->enum(array_keys(AnnotationResource::CATEGORIES))->description('Kategorie. Standard: sonstiges.'),
            'timestamp' => $schema->string()->description('Zeitpunkt (JJJJ-MM-TT HH:MM, Europe/Berlin). Standard: jetzt.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'category' => ['nullable', Rule::in(array_keys(AnnotationResource::CATEGORIES))],
            'timestamp' => ['nullable', 'date_format:Y-m-d H:i', 'before_or_equal:now'],
        ]);

        $annotation = Annotation::create([
            'timestamp' => isset($data['timestamp']) ? Carbon::createFromFormat('Y-m-d H:i', $data['timestamp']) : now(),
            'note' => $data['note'],
            'category' => $data['category'] ?? 'sonstiges',
        ]);

        $this->audit('add_annotation', $data, null, $annotation->only(['id', 'timestamp', 'category', 'note']));

        return Response::structured(['saved' => true, 'id' => $annotation->id, 'timestamp' => $this->iso($annotation->timestamp)]);
    }
}
