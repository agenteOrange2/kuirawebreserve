<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * La cortesía pasa el tope mensual del hotel. No se aplica hasta que el
 * propietario confirme por segunda vez que sí la quiere dar.
 */
class CourtesyNeedsConfirmation extends InvalidArgumentException {}
