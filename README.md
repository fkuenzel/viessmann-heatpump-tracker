# Viessmann Heatpump Tracker

Laravel-/Filament-Anwendung zur Nachverfolgung einer Viessmann-Wärmepumpe (getestet mit der Vitocal 250A, E3-Generation) über die offizielle Viessmann-API. Erfasst Stromverbrauch, thermische Energieabgabe, COP, echte Zählerstände samt Kostenberechnung und externe Wetterdaten – als Ersatz für eine manuell gepflegte Excel-Tabelle.

Kein Dauer-Server nötig: Die Anwendung ist für lokalen Betrieb ausgelegt, der Datenabruf funktioniert sowohl automatisch über den Scheduler als auch jederzeit manuell per Button in der Oberfläche.

## Voraussetzungen

- PHP 8.2 oder neuer, Composer
- Ein kostenloser Viessmann-Developer-Account mit eigener Wärmepumpe (Basic-Plan reicht für alle in dieser App genutzten Werte)

## Installation

```bash
git clone <repo-url>
cd <projektordner>
composer install
cp .env.example .env
php artisan key:generate
```

`APP_URL` in der `.env` auf die tatsächliche Adresse setzen, unter der die Anwendung später erreichbar ist (lokal z. B. über [Laravel Herd](https://herd.laravel.com/) oder `php artisan serve`) – das ist wichtig, da die Viessmann-Redirect-URI im nächsten Schritt darauf aufbaut.

### Viessmann-Developer-Zugang einrichten

1. Account anlegen unter [app.developer.viessmann-climatesolutions.com](https://app.developer.viessmann-climatesolutions.com/).
2. Dort einen neuen OAuth2-Client erstellen (Flow: Authorization Code + PKCE).
3. Als Redirect-URI eintragen: `<APP_URL>/viessmann/callback` (also z. B. `http://meine-app.test/viessmann/callback`) – der Install-Wizard im nächsten Schritt zeigt diese URL noch einmal exakt an.
4. Die vom Portal vergebene **Client-ID** griffbereit halten (ein Client-Secret wird für den PKCE-Flow nicht benötigt).

### Install-Wizard ausführen

```bash
php artisan app:install
```

Führt interaktiv durch:

1. Datenbank-Migrationen
2. Anlage eines Admin-Benutzers für die Filament-Oberfläche
3. Abfrage der Viessmann-Client-ID (wird automatisch in die `.env` geschrieben)
4. Ausgabe der Autorisierungs-URL – im Browser öffnen, mit dem Viessmann-Account anmelden und die Verbindung bestätigen
5. Nach erfolgreicher Autorisierung: automatische Ermittlung von Installation-ID, Gateway-Serial und Device-ID über die Viessmann-API (bei mehreren Anlagen/Gateways/Geräten erfolgt eine Auswahl)
6. Hinweis zur Einrichtung des Schedulers

Der Befehl kann jederzeit erneut ausgeführt werden, falls ein Schritt (insbesondere die Browser-Autorisierung) beim ersten Durchlauf nicht abgeschlossen wurde – bereits erledigte Schritte werden übersprungen.

### Scheduler einrichten (für automatischen Datenabruf)

```
* * * * * cd /pfad/zur/anwendung && php artisan schedule:run >> /dev/null 2>&1
```

Ohne laufenden Scheduler lässt sich der Datenabruf weiterhin jederzeit manuell über den Button in der Oberfläche ("Sync-Steuerung") anstoßen.

## Nach der Installation: weitere Einstellungen

Diese Punkte sind keine Voraussetzung für den ersten lauffähigen Zustand und werden deshalb nicht vom Install-Wizard abgefragt, sondern direkt in der Oberfläche unter **Einstellungen** bzw. **Stromtarife** gepflegt:

- **Wetter-Standort**: Ortsname eingeben, die Koordinaten werden automatisch über Open-Meteo ermittelt. Ohne hinterlegten Standort bleibt die Seite "Wetterdaten" ausgeblendet.
- **Stromtarif**: Anbieter, Tarifname, Grundgebühr, HT-/NT-Preis. Ohne hinterlegten Tarif werden nur die davon abhängigen Kosten-Metriken ausgeblendet – der reine Verbrauchsvergleich Zähler ↔ App bleibt unabhängig davon sichtbar.
- **Zählernummer**, **Wetterprognose-Horizont**
- **Mail-Reporting** (periodische Zusammenfassung, Anomalie- und Sync-Ausfall-Warnungen) – optional, ohne hinterlegte Empfänger-Adresse komplett inaktiv. Für den tatsächlichen Mailversand zusätzlich `MAIL_MAILER`/`MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS` in der `.env` auf einen echten SMTP-Zugang setzen (Standard ist `log`, Mails landen dann nur im Laravel-Log).

Zählerstände werden anschließend über **Zählerstände** mit frei wählbarem Zeitpunkt erfasst.

## Konfigurationsverlauf (ab Version 1.2.0)

Bei jedem Sync prüft die Anwendung Heizkurve, Vorlauftemperatur-Grenzen, Soll-Raumtemperaturen, Zeitprogramme (Heizen und Warmwasser), Warmwasser-Einstellungen, Sommerbetrieb, Betriebsarten, Standby, Ferienprogramm, die §14a-Leistungsbegrenzung und die Parameter-Version. Dafür sind keine zusätzlichen API-Anfragen nötig, die Werte stammen aus der ohnehin abgerufenen Feature-Liste.

- Nur tatsächliche Änderungen werden gespeichert (Tabelle `config_log`). Beim ersten Sync entsteht lediglich ein Ausgangswert ohne Notiz.
- Zu jeder Änderung legt die Anwendung automatisch eine Notiz der Kategorie "Einstellung" an, die im Dashboard bei den Notizen erscheint.
- Die Seite **Konfigurationsverlauf** zeigt Zeitpunkt, Einstellung sowie alten und neuen Wert.
- Der Zeitpunkt ist nur auf das Sync-Intervall (15 Minuten) genau, da die API keinen echten Änderungszeitpunkt liefert.

Beim Update auf 1.2.0 muss einmalig `php artisan migrate` ausgeführt werden.

## Bekannte Einschränkungen

- Die Viessmann-API liefert Thermik-/Außentemperatur-/Kompressorwerte nur als aktuellen Tages-Snapshot (keine Nachhol-Funktion wie beim Stromverbrauch). Lücken durch Ausfallzeiten lassen sich über **Anlagenwerte** manuell nachtragen, sofern die entsprechenden Werte noch in der Viessmann-App einsehbar sind.
- Eine feinere zeitliche Auflösung pro Kompressor sowie die API-eigene COP-Berechnung sind nur im kostenpflichtigen Advanced-Plan verfügbar, der aktuell nicht buchbar ist.

## Sicherheit beim produktiven Betrieb

Diese Anwendung wird mit echten Zugangsdaten (Viessmann-OAuth-Tokens, ggf. SMTP-Zugang) und personenbezogenen Zählerdaten betrieben und ist für den Einsatz auf gewöhnlichem Webspace gedacht. Vor der öffentlichen Erreichbarkeit unbedingt beachten:

- **`APP_ENV=production` und `APP_DEBUG=false`** in der `.env` setzen. Bei `APP_DEBUG=true` zeigt Laravel bei Fehlern Stacktraces inklusive `.env`-Werten öffentlich an. `php artisan app:install` prüft das automatisch und warnt, falls `APP_ENV=production` gesetzt ist, aber `APP_DEBUG` noch `true` steht.
- **HTTPS durchgängig verwenden**: `APP_URL` mit `https://` und `SESSION_SECURE_COOKIE=true` setzen, sonst werden Session-Cookies auch unverschlüsselt übertragen. Wird ebenfalls vom Install-Wizard geprüft.
- **Document Root muss auf `/public` zeigen**, nicht auf das Projekt-Root – bei Shared-Hosting ohne eigenen vHost ein häufiger Fehler. Eine `.htaccess` im Projekt-Root verweigert als zusätzliches Sicherheitsnetz den direkten Zugriff auf `.env`, `storage/`, `database/` und `vendor/`, falls die Document-Root-Konfiguration dennoch falsch ist – ersetzt aber nicht die korrekte Konfiguration.
- **`database.sqlite` enthält personenbezogene Zählerdaten** und gehört entsprechend ins Backup-/Zugriffskonzept. Die Viessmann-OAuth-Tokens darin werden verschlüsselt abgelegt (`Setting::setEncrypted()`), sind also bei einem reinen Dateizugriff nicht direkt verwertbar.
- Vor jedem Release `composer audit` laufen lassen und Dependabot (oder vergleichbar) im GitHub-Repo aktivieren.

## Deployment auf Webspace ohne SSH (z. B. ALL-INKL)

Einige Webspace-Anbieter (z. B. ALL-INKL-Tarife ohne SSH-Zugang) bieten weder eine Shell noch Composer, sondern nur FTP und Cronjobs als reinen HTTPS-URL-Aufruf. Composer-Install und Artisan-Befehle laufen in diesem Fall lokal, der fertige Stand (inklusive `vendor/`) wird per FTP hochgeladen.

### Einmalige Einrichtung

1. Lokal `composer install --no-dev --optimize-autoloader` ausführen.
2. Projekt per FTP hochladen, Document Root der (Sub-)Domain im Hoster-Kontrollzentrum auf `public/` setzen, der restliche Code bleibt oberhalb davon und ist damit nicht direkt web-erreichbar.
3. `.env` direkt auf dem Server anlegen (nicht aus dem lokalen `.env.example` per FTP überschreiben lassen) mit mindestens: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, echtem `ARTISAN_RUNNER_TOKEN` (siehe `.env.example`).
4. Einmalig die lokale `database/database.sqlite` per FTP hochladen (initialer Umzug des bisherigen Standes) – danach ist die Server-Version die alleinige Quelle der Wahrheit, siehe "Datenbank-Workflow" unten.
5. `https://<domain>/system/run/storage-link?token=<ARTISAN_RUNNER_TOKEN>` einmal im Browser aufrufen.

### Artisan-Runner statt CLI

Ohne Shell gibt es keinen `php artisan`-Zugriff auf dem Server. Stattdessen führt `GET /system/run/{command}?token=...` (`App\Http\Controllers\ArtisanRunnerController`) einen von wenigen fest whitelisteten Befehlen aus:

| URL-Segment | Artisan-Befehl |
|---|---|
| `schedule-run` | `schedule:run` (siehe unten) |
| `db-backup` | `db:backup` |
| `migrate` | `migrate --force` |
| `config-clear` | `config:clear` |
| `cache-clear` | `cache:clear` |
| `storage-link` | `storage:link` |
| `weather-backfill-archive` | `weather:backfill-archive` |

Ohne gültigen `token`-Parameter (muss exakt `ARTISAN_RUNNER_TOKEN` aus der `.env` entsprechen) antwortet die Route mit 401, ohne gesetztes `ARTISAN_RUNNER_TOKEN` mit 404 – der Endpoint ist dann vollständig deaktiviert. Zusätzlich empfiehlt sich, beim Hoster für die Cronjob-URL HTTP-Basic-Auth zu hinterlegen (bei ALL-INKL direkt in der Cronjob-Verwaltung möglich), damit selbst ein durchgesickertes Token allein nicht reicht.

### Scheduler-Ersatz per Webcron

Da es keinen System-Cron gibt, übernimmt ein Webcron-Aufruf auf `schedule-run` die Rolle von `php artisan schedule:run`:

```
https://<domain>/system/run/schedule-run?token=<ARTISAN_RUNNER_TOKEN>
```

Beim Hoster als Cronjob **alle 5 Minuten, exakt auf volle 5-Minuten-Marken** (:00, :05, :10, ...) einrichten – kleiner oder gleich dem kleinsten in `routes/console.php` definierten Intervall (aktuell 15 Minuten für `viessmann:sync`). Laravel entscheidet bei jedem Aufruf selbst, welche der in `routes/console.php` hinterlegten Jobs gerade fällig sind.

Ein tägliches Backup der SQLite-Datenbank läuft über denselben Mechanismus als eigener Cronjob (empfohlen: einmal täglich, außerhalb der Hauptnutzungszeit):

```
https://<domain>/system/run/db-backup?token=<ARTISAN_RUNNER_TOKEN>
```

Legt eine Kopie unter `storage/app/backups/` ab und entfernt automatisch Kopien älter als 30 Tage (`--keep-days` anpassbar).

### Datenbank-Workflow (lokal ↔ Server)

- Die Server-`database.sqlite` ist nach dem initialen Upload die alleinige Quelle der Wahrheit. **Nicht** wiederholt zum Testen herunterladen und unverändert wieder hochladen – das überschreibt zwischenzeitlich gesammelte Live-Daten.
- Neue Migrationen: lokal entwickeln und testen (idealerweise gegen eine zuvor heruntergeladene, rein lesend genutzte Kopie der Live-Datenbank), per FTP hochladen, anschließend `.../system/run/migrate?token=...` aufrufen.
- Frischer Stand zum lokalen Debuggen: Server-`database.sqlite` per FTP herunterladen, nur lesend lokal verwenden.
- Viessmann-OAuth: Redirect-URI im Developer-Portal auf `https://<domain>/viessmann/callback` umstellen bzw. ergänzen und einmalig `https://<domain>/viessmann/connect` aufrufen, um die Tokens für die neue Domain neu auszustellen.

## MCP-Anbindung (ab Version 1.1.0)

Die Anwendung stellt ihre Daten über einen MCP-Server bereit, sodass Claude (oder jeder andere MCP-Client) Heizungsdaten direkt abfragen kann. Der Zugriff ist ausschließlich lesend; es gibt keine Tools, die Daten schreiben, löschen oder einen Sync anstoßen.

### Einrichtung

1. Token erzeugen (mindestens 32 Zeichen, nicht identisch mit `ARTISAN_RUNNER_TOKEN`):

   ```
   php artisan tinker --execute="echo bin2hex(random_bytes(32));"
   ```

2. In der `.env` des jeweiligen Systems eintragen: `MCP_API_TOKEN=<token>`. Ohne Token (oder bei weniger als 32 Zeichen) ist der Endpunkt vollständig deaktiviert und antwortet mit 404.
3. Nach dem Ändern der `.env` auf dem Server gegebenenfalls `.../system/run/config-clear?token=...` aufrufen.

Der Endpunkt lautet `POST https://<domain>/mcp` (Streamable HTTP). Authentifiziert wird ausschließlich über den Header `Authorization: Bearer <token>`; ein Token in der URL wird bewusst nicht akzeptiert, weil URLs in Server- und Proxy-Logs landen.

### Verfügbare Tools

| Tool | Inhalt |
|---|---|
| `get_current_status` | Letzter Sync, bisherige Tageswerte, Außentemperatur, Gerätezustand, Kompressor-Zähler |
| `get_daily_summary` | Strom, Thermik, COP, Temperaturen und Kompressor für einen Tag, inklusive zählerbasierter Schätzung |
| `get_interval_breakdown` | Zählerintervalle mit Verbrauch (Zähler und App), Korrekturfaktor, Heizen/Warmwasser, echtem COP und Kosten |
| `get_cop_trend` | COP-Verlauf der letzten Tage (App-Wert und zählerbasierte Schätzung) |
| `check_anomalies` | Auffälliger Tagesverbrauch und Sync-Ausfall (gleiche Regeln wie das Mail-Reporting, ohne Mailversand) |
| `get_compressor_stats` | Tägliche Starts und Laufzeit des Kompressors, Durchschnitte |
| `get_weather_comparison` | Externe Temperatur gegenüber Anlagensensor sowie Kurzfrist-Prognose |
| `get_annotations` | Notizen im Zeitraum (manuell und automatisch erfasste Einstellungsänderungen), optional nach Kategorie |

Die Ausgaben unterscheiden durchgehend zwischen App-Werten (Viessmann-API) und zählerbasierten Werten. Fehlende Daten werden als `null` geliefert, nie als 0. Zeiträume sind begrenzt (maximal 90 bzw. 92 Tage).

### Client einrichten

- **Claude Code:**

  ```
  claude mcp add --transport http heatpump https://<domain>/mcp --header "Authorization: Bearer <token>"
  ```

- **Claude Desktop:** über den Brückenprozess `mcp-remote` (benötigt Node.js), der den Header mitsendet:

  ```json
  {
    "mcpServers": {
      "heatpump": {
        "command": "npx",
        "args": ["-y", "mcp-remote", "https://<domain>/mcp", "--header", "Authorization: Bearer ${MCP_TOKEN}"],
        "env": { "MCP_TOKEN": "<token>" }
      }
    }
  }
  ```

- **claude.ai (Web) als Connector:** Benutzerdefinierte Connectoren verwenden OAuth und können keinen statischen Bearer-Header senden. Für diesen Weg wäre ein OAuth-Flow (z. B. mit Laravel Passport) nötig; das ist aktuell nicht umgesetzt.

### Sicherheit

- Vergleich des Tokens zeitkonstant (`hash_equals`), fehlgeschlagene Versuche werden ohne Token-Inhalt protokolliert.
- In `APP_ENV=production` werden Aufrufe ohne HTTPS mit 403 abgewiesen.
- Rate-Limit: 120 Anfragen pro Minute und IP.
- Auf Servern mit Apache/CGI muss der `Authorization`-Header an PHP durchgereicht werden. Das mitgelieferte `public/.htaccess` von Laravel übernimmt das; bei Problemen (401 trotz korrektem Token) die entsprechende Rewrite-Regel prüfen.
- Das Token gewährt Lesezugriff auf sämtliche Verbrauchs- und Gerätedaten. Bei Verdacht auf Weitergabe in der `.env` austauschen.
- Da `laravel/mcp` ab 1.1.0 unter `require` steht, muss beim Deployment ohne SSH das lokale `composer install --no-dev --optimize-autoloader` nach dem Update erneut ausgeführt und `vendor/` neu hochgeladen werden.

## Verwendete Technologien

- **[Laravel 12](https://laravel.com/)** – PHP-Framework (Eloquent ORM, Scheduler, Mail)
- **[Filament 5](https://filamentphp.com/)** – komplette Admin-/Reporting-Oberfläche (Resources, Pages, Widgets), kein eigenes Frontend
- **SQLite** – Standard-Datenbank für den lokalen Single-User-Betrieb; dank Eloquent-Migrationen jederzeit auf MySQL/MariaDB umstellbar
- **[Viessmann API](https://developer.viessmann-climatesolutions.com/)** – OAuth2 (Authorization Code + PKCE) für Zugriff auf die Wärmepumpen-Messwerte
- **[Open-Meteo](https://open-meteo.com/)** – kostenlose Wetter-, Prognose-, Archiv- und Geocoding-API, kein API-Key nötig
- **Chart.js** (über Filaments Chart-Widgets) – Diagramme für Verbrauch, COP und Temperaturvergleich
- **Tailwind CSS** – Styling-Grundlage von Filament
- **Guzzle** – HTTP-Client für die Viessmann-API-Anbindung
- **[laravel/mcp](https://github.com/laravel/mcp)** – MCP-Server für den lesenden Zugriff durch Claude

## Lizenz

MIT – siehe [LICENSE](LICENSE). Nutzung, Veränderung und Weitergabe (auch kommerziell) sind erlaubt, solange der Copyright-Hinweis erhalten bleibt. Haftung ist ausgeschlossen.
