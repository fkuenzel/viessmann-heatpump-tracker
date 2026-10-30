<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="color: #b91c1c;">Kein Sync seit {{ $gapHours }} Stunden</h2>
    <p>Der letzte erfolgreiche Datenabruf von der Viessmann-API liegt mehr als {{ $gapHours }} Stunden zurück:</p>
    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Letzter Sync</td>
            <td style="border-bottom: 1px solid #e5e7eb;"><strong>{{ $lastSyncAt->format('d.m.Y H:i') }}</strong></td>
        </tr>
        <tr>
            <td>Jetzt</td>
            <td>{{ $detectedAt->format('d.m.Y H:i') }}</td>
        </tr>
    </table>
    <p style="margin-top: 16px;">
        Typischer Grund: Der Rechner mit der laufenden App war länger ausgeschaltet. Die Viessmann-API erlaubt rückwirkendes
        Nachholen von Stromverbrauchswerten für bis zu 8 Tage – Thermik-/Außentemperatur-/Kompressor-Werte lassen sich nur
        manuell über "Anlagenwerte" nachtragen.
    </p>
</body>
</html>
