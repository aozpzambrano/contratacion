<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Publicacion extends Model
{
public function up(): void
    {    
        Schema::create('publicacions', function (Blueprint $table) {
            $table->id();
            $table->string('pub_nc_publicacion');
            $table->string('pub_link_publicacion');
            $table->string('pub_fecha_publicacion');
            $table->string('pub_fecha_limite');
            $table->string('pub_numero_ofertas');
            $table->timestamps();
        });
    }
}
