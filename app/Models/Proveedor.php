<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    public function up(): void
    {
        //Los estados pueden ser:
        // Preparatoria, Precontractual, Contractual, Finalizado
        Schema::create('proveedors', function (Blueprint $table) {
            $table->id();
            $table->string('pro_razon_social');
            $table->string('pro_ruc');
            $table->timestamps();
        });
    }
}
