<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Basis-Exception fuer Fehler bei externen API-Aufrufen (Viessmann,
 * Open-Meteo, ...).
 */
class ExternalApiException extends RuntimeException
{
}
