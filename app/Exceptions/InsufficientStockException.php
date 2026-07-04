<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    public static function for(string $name, float $available, string $unit): self
    {
        return new self("Stock insuficiente de \"{$name}\": quedan {$available} {$unit}.");
    }
}
