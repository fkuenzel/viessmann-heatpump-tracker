<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Wie get(), aber fuer Werte, die ueber setEncrypted() verschluesselt
     * abgelegt wurden (z. B. Viessmann-OAuth-Tokens) - Sicherheits-Fix vor dem
     * 1.1.0-Release, siehe github-fahrplan.md Abschnitt 6.1: ohne das laegen
     * die Tokens im Klartext in database.sqlite.
     *
     * Faengt DecryptException ab und liefert den Rohwert zurueck, damit ein
     * Bestandswert aus einer Installation vor diesem Fix (noch unverschluesselt
     * gespeichert) nicht hart fehlschlaegt, sondern beim naechsten
     * setEncrypted()-Aufruf automatisch migriert wird.
     */
    public static function getEncrypted(string $key, mixed $default = null): mixed
    {
        $value = static::get($key);

        if ($value === null) {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public static function setEncrypted(string $key, mixed $value): void
    {
        static::set($key, Crypt::encryptString((string) $value));
    }
}
