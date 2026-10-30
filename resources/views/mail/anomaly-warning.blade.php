<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="color: #b45309;">Auffälliger Tagesverbrauch</h2>
    <p>Der Stromverbrauch am <strong>{{ $date->format('d.m.Y') }}</strong> lag deutlich über dem bisherigen Durchschnitt:</p>
    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Tageswert</td>
            <td style="border-bottom: 1px solid #e5e7eb;"><strong>{{ number_format($value, 1, ',', '.') }} kWh</strong></td>
        </tr>
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Durchschnitt (Vergleichszeitraum)</td>
            <td style="border-bottom: 1px solid #e5e7eb;">{{ number_format($average, 1, ',', '.') }} kWh</td>
        </tr>
        <tr>
            <td>Abweichung</td>
            <td><strong>+{{ number_format($deviationPercent, 0, ',', '.') }} %</strong></td>
        </tr>
    </table>
    <p style="margin-top: 16px; color: #6b7280; font-size: 13px;">
        Schwellenwert und Vergleichszeitraum sind in den Einstellungen der Wärmepumpen-App konfigurierbar.
    </p>
</body>
</html>
