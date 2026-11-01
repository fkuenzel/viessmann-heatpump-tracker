@php
    $deviceName = \App\Models\Setting::get('viessmann_device_name');
    $deviceType = \App\Models\Setting::get('viessmann_device_type');
    $deviceSerial = \App\Models\Setting::get('viessmann_device_serial');
@endphp

@if ($deviceName || $deviceType || $deviceSerial)
    <style>
        .fi-sidebar-device-info {
            margin: 0.75rem;
            padding: 0.75rem 0.875rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(228 228 231);
            background-color: rgb(255 255 255);
            font-size: 0.75rem;
            line-height: 1.1rem;
        }
        .dark .fi-sidebar-device-info {
            border-color: rgb(39 39 42);
            background-color: rgb(39 39 42);
        }
        .fi-sidebar-device-info-row + .fi-sidebar-device-info-row {
            margin-top: 0.375rem;
        }
        .fi-sidebar-device-info-label {
            color: rgb(161 161 170);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            font-size: 0.625rem;
        }
        .fi-sidebar-device-info-value {
            color: rgb(63 63 70);
            font-weight: 600;
            word-break: break-word;
        }
        .dark .fi-sidebar-device-info-value {
            color: rgb(212 212 216);
        }
    </style>
    <div class="fi-sidebar-device-info">
        @if ($deviceName)
            <div class="fi-sidebar-device-info-row">
                <div class="fi-sidebar-device-info-label">Produktname</div>
                <div class="fi-sidebar-device-info-value">{{ $deviceName }}</div>
            </div>
        @endif
        @if ($deviceType)
            <div class="fi-sidebar-device-info-row">
                <div class="fi-sidebar-device-info-label">Produkttyp</div>
                <div class="fi-sidebar-device-info-value">{{ $deviceType }}</div>
            </div>
        @endif
        @if ($deviceSerial)
            <div class="fi-sidebar-device-info-row">
                <div class="fi-sidebar-device-info-label">Seriennummer</div>
                <div class="fi-sidebar-device-info-value">{{ $deviceSerial }}</div>
            </div>
        @endif
    </div>
@endif
