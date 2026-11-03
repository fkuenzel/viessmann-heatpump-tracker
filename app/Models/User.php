<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Implementiert FilamentUser/canAccessPanel() (Fahrplan-Fix 03.11.2026):
 * Ohne dieses Interface verweigert Filament in APP_ENV=production jedem
 * - auch korrekt eingeloggten - Nutzer den Panel-Zugriff mit 403 Forbidden
 * (Sicherheitsdefault, der nur in production greift - lokal mit
 * APP_ENV=local blieb das deshalb unbemerkt). Einzelner Admin-Account
 * (Install-Wizard legt genau einen an, Single-User-Betrieb, siehe
 * Fahrplan Abschnitt 0) - jeder erfolgreich authentifizierte Nutzer darf
 * daher das Panel sehen, keine weitere Rollen-/Rechte-Logik noetig.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
