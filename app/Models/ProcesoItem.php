<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcesoItem extends Model
{
    protected $table = 'proceso_items';

    protected $fillable = ['numero_linea', 'cum', 'descripcion', 'cantidad'];
}
