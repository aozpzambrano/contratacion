<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstadoFase extends Model
{
    public function up(): void
    {
        Schema::create('estadofases', function (Blueprint $table) {
            $table->id();
            $table->string('efa_nombre');
            $table->boolean('efa_estado')->default(1);
            $table->timestamps();
        });
    }
}
