<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcesoContratacion extends Model
{
    public function up(): void
    {
        Schema::create('procesocontratacions', function (Blueprint $table) {
            $table->id();
            $table->string('pco_codigo_numero')->unique();
            $table->string('pco_codigo_cpc')->nullable()->comment('Código CPC SERCOP');
            $table->string('pco_descripcion');
            $table->text('pco_detalle');
            $table->string('pco_cum');
            $table->integer('pco_cantidad');
            //usuario responsable del proceso
            //usuario asignado de la revision
            //usuario asignado para la publicacion
            //usuario asignado para la comision
            //usuario asignado para la administracion
            $table->timestamps();
        });
    }
}
