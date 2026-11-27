# Changelog

## 2.0.1 (unveröffentlicht)

### Performance
- Behebt die starke Verlangsamung von Dashboard und Auswertungen in 2.0.0: Die Tageswerte wurden je Tag und Kennzahl einzeln aus `api_log` abgefragt (30-Tage-Bericht mit Vergleich: über 1.190 Abfragen, rund 6 Sekunden). Die Daten werden jetzt einmal pro Request geladen, nach Referenzdatum indiziert und im Speicher aufgelöst (59 Abfragen, rund 22 Millisekunden). Der Index wird beim Speichern oder Löschen eines Eintrags verworfen.
- Tarife (`price_history`) und Wetter-Tagesmittel (`weather_log`) werden pro Request einmal geladen und im Speicher aufgelöst, statt je Tag bzw. Ablese-Intervall abgefragt zu werden. Kostenprognose: 358 auf 10 Abfragen, Periodenvergleich: 118 auf 4, KPI-Widgets: 59 auf 2. Beide Bestände werden beim Speichern oder Löschen verworfen.
- Bei identischem Zeitstempel gewinnt deterministisch der Eintrag mit der höheren ID.
- Neue Regressionstests begrenzen die Zahl der `api_log`-Abfragen und prüfen Invalidierung sowie gemeinsame Nutzung des Index.

## 2.0.0 (2026-11)

Großes Release in sechs Phasen. Alle Datenbank-Migrationen sind rein ergänzend, es werden keine bestehenden Daten verändert oder gelöscht.

### Datenbasis
- Gesamtverbrauch: Als Tageswert gilt der größere Wert aus API-Gesamtwert und Heizen plus Warmwasser (die API liefert den Gesamtwert gelegentlich zu niedrig). Rohwerte bleiben unverändert gespeichert.
- Wärmemengen werden an Intervallrändern wie der Strom tagesanteilig gewichtet. Der App-COP pro Ablese-Intervall war dadurch zuvor teils deutlich zu hoch.
- Laufender Tag wird nach Datenabdeckung bis zum Abrufzeitpunkt gewichtet.
- Heizstab: Energie (Heizen, Warmwasser), Starts, Laufzeit und Status werden mit erfasst, in Anlagenwerten pflegbar und im MCP-Status sichtbar.

### Zählerstände
- Neues Feld "Grund" (u. a. Regelablesung, Endablesung, Zählertausch, Startwert, Schätzwert, Sonstiges), Quelle und Notiz.
- Zählertausch und Startwert beginnen eine neue Messreihe, Schätzwerte machen angrenzende Intervalle als unzuverlässig kenntlich.
- Plausibilitätsprüfung (Zählerstand darf nicht sinken) und Warnung bei auffälligem Korrekturfaktor, Aktion "Jetzt ablesen", Filter nach Grund.

### Dashboard
- Globaler Zeitraumfilter (7/30/90 Tage, Monat, Vormonat, Jahr, Vorjahr, gesamt, benutzerdefiniert) mit Vergleich (Vorperiode, Vorjahr, keiner). Von/Bis erscheinen nur bei "Benutzerdefiniert".
- Neue Kennzahlen-Kacheln für Zähler- und App-Werte, Heizgradtage und witterungsbereinigter Verbrauch. Zählerwerte kommen nur aus vollständig enthaltenen Intervallen, mit Abdeckungsanzeige. Vergleiche nur bei ausreichender Abdeckung.

### Auswertungen (neue Navigationsgruppe)
Periodenvergleich, Wirkung von Einstellungsänderungen, Effizienz nach Außentemperatur, Warmwasser, Kosten und Jahresprognose, Kompressor und Takten, Datenqualität. Neue Einstellungen: Beginn Abrechnungsjahr, geplanter Jahresverbrauch, monatlicher Abschlag.

### MCP
- Lese-Endpoint `/mcp`: neue Tools `get_period_report`, `get_temperature_efficiency`, `get_change_impact`, `get_data_quality`, `get_cost_outlook`, `get_meter_readings`, `get_tariffs`, `get_settings`.
- Neuer schreibender Endpoint `/mcp/write` mit eigenem Token (`MCP_WRITE_TOKEN`), Rate-Limit und Audit-Log (`mcp_audit_log`): `add_meter_reading`, `add_annotation`, `add_tariff`, `update_setting`, `add_plant_values`, `trigger_sync`, `get_audit_log`. Keine Steuerung der Wärmepumpe, kein Zugriff auf Zugangsdaten, Löschen nur in der Oberfläche.

### Sicherheit und Wartung
- Abhängigkeiten mit Sicherheitshinweisen (Laravel 12.74.0, Symfony 7.4.24 u. a.) aktualisiert, `composer audit` ohne Treffer.

### Update von 1.2.1
`git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, Caches leeren, MCP-Connector neu verbinden. Optional `MCP_WRITE_TOKEN` setzen. Ausführliche Anleitung im README.
