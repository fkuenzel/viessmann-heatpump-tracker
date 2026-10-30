<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="color: #047857;">Zusammenfassung: {{ $periodLabel }}</h2>
    <p style="color: #6b7280;">Zeitraum: {{ $periodStart->format('d.m.Y') }} – {{ $periodEnd->format('d.m.Y') }}</p>
    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Stromverbrauch gesamt</td>
            <td style="border-bottom: 1px solid #e5e7eb;"><strong>{{ $summary['power_total'] !== null ? number_format($summary['power_total'], 1, ',', '.') . ' kWh' : '–' }}</strong></td>
        </tr>
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">&nbsp;&nbsp;davon Heizen</td>
            <td style="border-bottom: 1px solid #e5e7eb;">{{ $summary['power_heating'] !== null ? number_format($summary['power_heating'], 1, ',', '.') . ' kWh' : '–' }}</td>
        </tr>
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">&nbsp;&nbsp;davon Warmwasser</td>
            <td style="border-bottom: 1px solid #e5e7eb;">{{ $summary['power_dhw'] !== null ? number_format($summary['power_dhw'], 1, ',', '.') . ' kWh' : '–' }}</td>
        </tr>
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Thermische Energie gesamt</td>
            <td style="border-bottom: 1px solid #e5e7eb;">{{ $summary['thermal_total'] !== null ? number_format($summary['thermal_total'], 1, ',', '.') . ' kWh' : '–' }}</td>
        </tr>
        <tr>
            <td style="border-bottom: 1px solid #e5e7eb;">Durchschnittlicher COP</td>
            <td style="border-bottom: 1px solid #e5e7eb;">{{ $summary['avg_cop'] !== null ? number_format($summary['avg_cop'], 2, ',', '.') : '–' }}</td>
        </tr>
        <tr>
            <td>Durchschnittliche Außentemperatur</td>
            <td>{{ $summary['avg_outdoor_temp'] !== null ? number_format($summary['avg_outdoor_temp'], 1, ',', '.') . ' °C' : '–' }}</td>
        </tr>
    </table>
    <p style="margin-top: 16px; color: #6b7280; font-size: 13px;">
        Kostenangaben sind hier bewusst nicht enthalten – die Kostenberechnung basiert auf echten Zählerständen, die nicht
        zwangsläufig an diesem Zeitraumende liegen. Details dazu in der App unter "Zählerstände".
    </p>
</body>
</html>
