<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoProceso extends Model
{
    public function up(): void
    {
    //Los estados del proceso son:
    //En trámite, Publicado, Adjudicado, Desierto, Cancelado    
    Schema::create('estadoprocesos', function (Blueprint $table) {
            $table->id();
            $table->string('epr_nombre');
            $table->boolean('epr_estado')->default(1);
            $table->timestamps();
        });
    }
}
