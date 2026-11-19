<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoExpediente extends Model
{
    public function up(): void
    {
        // Los estados son:
        // Inicio, En revisión, En corrección, Para legalizar, Para publicar,
        // Publicado, Para Comisión, En comisión, Aprobado, Generación de Expediente,
        // Generación de Orden de Compra, Administración de Contrato, Cierre
        // Para reapertura
        // Devolución 
        Schema::create('estadoexpedientes', function (Blueprint $table) {
            $table->id();
            $table->string('eex_nombre');
            $table->boolean('eex_estado')->default(1);
            $table->timestamps();
        });
    }
}
