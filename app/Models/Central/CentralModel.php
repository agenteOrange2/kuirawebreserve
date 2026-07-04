<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

/**
 * Base para modelos de la DB CENTRAL: siguen apuntando a la conexión
 * central aunque haya un tenant inicializado (donde la conexión por
 * defecto es la del tenant).
 */
abstract class CentralModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
