<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Proveedor identificado por RUC (13 digitos) o cedula (10). */
class Proveedor extends Model
{
    use SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = ['ruc', 'razon_social', 'activo'];
}
