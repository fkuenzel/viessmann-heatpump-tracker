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

## Bekannte Einschränkungen

- Die Viessmann-API liefert Thermik-/Außentemperatur-/Kompressorwerte nur als aktuellen Tages-Snapshot (keine Nachhol-Funktion wie beim Stromverbrauch). Lücken durch Ausfallzeiten lassen sich über **Anlagenwerte** manuell nachtragen, sofern die entsprechenden Werte noch in der Viessmann-App einsehbar sind.
- Eine feinere zeitliche Auflösung pro Kompressor sowie die API-eigene COP-Berechnung sind nur im kostenpflichtigen Advanced-Plan verfügbar, der aktuell nicht buchbar ist.

## Lizenz

MIT – siehe [LICENSE](LICENSE). Nutzung, Veränderung und Weitergabe (auch kommerziell) sind erlaubt, solange der Copyright-Hinweis erhalten bleibt. Haftung ist ausgeschlossen.
