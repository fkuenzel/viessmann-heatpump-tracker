<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ViessmannApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Interaktiver Install-Wizard fuer die Erstinstallation durch andere Nutzer
 * nach der geplanten GitHub-Veroeffentlichung (Github-Fahrplan Abschnitt 2).
 *
 * Deckt den Teil ab, der sich sinnvoll automatisieren laesst:
 * Migrationen, Admin-Benutzer, Hinweis zum Viessmann-OAuth-Flow (der Flow
 * selbst passiert im Browser und kann von einem CLI-Command nicht
 * uebernommen werden) und anschliessend die automatische Ermittlung von
 * Installation-ID/Gateway-Serial/Device-ID ueber die API, sobald die
 * Verbindung autorisiert ist - diese drei Werte stehen nirgendwo sichtbar
 * im Viessmann-Portal, ein manueller API-Request waere fuer Laien eine zu
 * hohe Huerde.
 *
 * Bewusst NICHT Teil dieses Wizards (siehe Fahrplan Abschnitt 2): Wetter-
 * Standort und Stromtarif. Beides ist jederzeit nachtraeglich ueber die
 * bestehende Filament-Oberflaeche ("Einstellungen"/"Stromtarife") pflegbar
 * und keine Voraussetzung fuer den ersten lauffaehigen Zustand.
 *
 * Kann jederzeit erneut ausgefuehrt werden (z. B. falls die OAuth-
 * Verbindung beim ersten Durchlauf noch nicht abgeschlossen war) - jeder
 * Schritt prueft den bereits vorhandenen Zustand, statt ihn stur zu
 * wiederholen.
 */
class InstallApp extends Command
{
    protected $signature = 'app:install';

    protected $description = 'Interaktiver Install-Wizard: Migrationen, Admin-Benutzer, Viessmann-Verbindung';

    public function handle(ViessmannApiClient $client): int
    {
        $this->info('Viessmann-Wärmepumpen-Dashboard: Erstinstallation');
        $this->newLine();

        $this->runMigrations();
        $this->createAdminUser();

        if (! $this->ensureViessmannConnection($client)) {
            $this->warn('Installation ohne abgeschlossene Viessmann-Verbindung beendet. "php artisan app:install" kann jederzeit erneut ausgeführt werden, um fortzufahren.');

            return self::SUCCESS;
        }

        $this->detectInstallation($client);
        $this->printSchedulerHint();

        $this->newLine();
        $this->info('Installation abgeschlossen.');
        $this->line('Wetter-Standort und Stromtarif können anschließend unter "Einstellungen" bzw. "Stromtarife" in der Oberfläche hinterlegt werden.');

        return self::SUCCESS;
    }

    private function runMigrations(): void
    {
        $this->components->task('Datenbank-Migrationen', function () {
            $this->call('migrate', ['--force' => true]);

            return true;
        });
        $this->newLine();
    }

    private function createAdminUser(): void
    {
        $this->info('Admin-Benutzer');

        if (User::query()->exists()) {
            if (! $this->confirm('Es existiert bereits mindestens ein Benutzer. Trotzdem einen weiteren anlegen?', false)) {
                $this->newLine();

                return;
            }
        }

        $name = $this->ask('Name');
        $email = $this->ask('E-Mail-Adresse');
        $password = $this->secret('Passwort');

        while (blank($name) || blank($email) || blank($password)) {
            $this->warn('Name, E-Mail-Adresse und Passwort dürfen nicht leer sein.');
            $name = $name ?: $this->ask('Name');
            $email = $email ?: $this->ask('E-Mail-Adresse');
            $password = $password ?: $this->secret('Passwort');
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Admin-Benutzer \"{$email}\" angelegt.");
        $this->newLine();
    }

    /**
     * Fuehrt durch den (zwangsweise manuellen, browserbasierten) OAuth-
     * Authorization-Code+PKCE-Flow und wartet interaktiv, bis er
     * abgeschlossen ist - ein CLI-Command kann den Browser-Redirect nicht
     * selbst ausfuehren.
     */
    private function ensureViessmannConnection(ViessmannApiClient $client): bool
    {
        $this->info('Viessmann-API-Verbindung');

        if ($client->isConnected()) {
            $this->line('Viessmann-Verbindung bereits autorisiert.');
            $this->newLine();

            return true;
        }

        if (blank(config('viessmann.client_id'))) {
            $this->line('Noch keine Client-ID hinterlegt. Im Viessmann-Developer-Portal (https://app.developer.viessmann-climatesolutions.com/) einen OAuth-Client anlegen (siehe README für die genauen Schritte).');

            $redirectUri = config('viessmann.redirect_uri');
            $this->line("Als Redirect-URI im Portal eintragen: {$redirectUri}");

            $clientId = $this->ask('Client-ID');

            while (blank($clientId)) {
                $clientId = $this->ask('Client-ID');
            }

            $this->writeEnv('VIESSMANN_CLIENT_ID', $clientId);
            config(['viessmann.client_id' => $clientId]);

            $this->warn('Client-ID in .env gespeichert. Falls die Konfiguration gecacht ist (php artisan config:cache), vorher "php artisan config:clear" ausführen.');
        }

        $connectUrl = url('/viessmann/connect');

        $this->newLine();
        $this->line('Jetzt im Browser als der eben angelegte (oder ein bestehender) Admin-Benutzer anmelden und anschließend folgende URL aufrufen, um die Viessmann-Verbindung zu autorisieren:');
        $this->line($connectUrl);
        $this->newLine();

        while (! $client->isConnected()) {
            if (! $this->confirm('Verbindung im Browser abgeschlossen?', true)) {
                return false;
            }
        }

        $this->info('Viessmann-Verbindung erfolgreich autorisiert.');
        $this->newLine();

        return true;
    }

    /**
     * Installation-ID, Gateway-Serial und Device-ID automatisch ueber die
     * equipment/installations-API ermitteln und in .env schreiben (Fahrplan
     * Abschnitt 2, Punkt 6) - bei mehreren gefundenen Anlagen/Gateways/
     * Geraeten wird der Nutzer gefragt.
     */
    private function detectInstallation(ViessmannApiClient $client): void
    {
        if (filled(config('viessmann.installation_id')) && filled(config('viessmann.gateway_serial'))) {
            $this->line('Installation-ID/Gateway-Serial bereits in .env hinterlegt, Ermittlung übersprungen.');
            $this->newLine();

            return;
        }

        $this->info('Anlage automatisch ermitteln');

        $installations = $client->listInstallations();

        if (empty($installations)) {
            $this->error('Keine Installation im Viessmann-Account gefunden. Installation-ID/Gateway-Serial/Device-ID müssen manuell in der .env eingetragen werden.');

            return;
        }

        $installation = count($installations) === 1
            ? $installations[0]
            : $this->chooseFrom($installations, 'Installation wählen', fn (array $item) => sprintf('%s (ID %s)', $item['description'] ?? $item['address']['street'] ?? 'Installation', $item['id']));

        $installationId = (string) $installation['id'];
        $this->writeEnv('VIESSMANN_INSTALLATION_ID', $installationId);

        $gateways = $client->listGateways($installationId);

        if (empty($gateways)) {
            $this->error('Keine Gateways für diese Installation gefunden.');

            return;
        }

        $gateway = count($gateways) === 1
            ? $gateways[0]
            : $this->chooseFrom($gateways, 'Gateway wählen', fn (array $item) => sprintf('%s (%s)', $item['serial'], $item['description'] ?? $item['model'] ?? ''));

        $gatewaySerial = (string) $gateway['serial'];
        $this->writeEnv('VIESSMANN_GATEWAY_SERIAL', $gatewaySerial);

        $devices = $client->listDevices($installationId, $gatewaySerial);
        $heatingDevice = collect($devices)->firstWhere('deviceType', 'heating');
        $device = $heatingDevice ?? ($devices[0] ?? null);

        if ($device === null) {
            $this->error('Keine Geräte für dieses Gateway gefunden.');

            return;
        }

        $deviceId = (string) $device['id'];
        $this->writeEnv('VIESSMANN_DEVICE_ID', $deviceId);

        config([
            'viessmann.installation_id' => $installationId,
            'viessmann.gateway_serial' => $gatewaySerial,
            'viessmann.device_id' => $deviceId,
        ]);

        $this->info("Installation-ID {$installationId}, Gateway-Serial {$gatewaySerial}, Device-ID {$deviceId} in .env gespeichert.");
        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): string  $label
     * @return array<string, mixed>
     */
    private function chooseFrom(array $items, string $question, callable $label): array
    {
        $options = [];

        foreach ($items as $index => $item) {
            $options[$index] = $label($item);
        }

        $selectedIndex = (int) array_search($this->choice($question, $options), $options, true);

        return $items[$selectedIndex];
    }

    private function printSchedulerHint(): void
    {
        $this->info('Letzter Schritt: Scheduler einrichten');
        $this->line('Für automatischen Datenabruf folgenden Cron-Eintrag hinterlegen (hostingabhängig, außerhalb von Laravel):');
        $this->line('* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('Ohne laufenden Scheduler kann der Sync weiterhin jederzeit manuell über den Button in der Oberfläche ("Sync-Steuerung") angestoßen werden.');
    }

    /**
     * Setzt/ersetzt einen Schlüssel in der .env-Datei. Einfache, aber fürs
     * CLI-geführte Setup ausreichende Implementierung - kein externes
     * Paket nötig für den einen Anwendungsfall (Install-Wizard).
     */
    private function writeEnv(string $key, string $value): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);
        $escaped = str_contains($value, ' ') || str_contains($value, '#') ? '"' . $value . '"' : $value;

        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, "{$key}={$escaped}", $content);
        } else {
            $content = rtrim($content, "\n") . "\n{$key}={$escaped}\n";
        }

        file_put_contents($path, $content);
    }
}
