<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('emp_codigo')->unique();
            $table->string('emp_nombre');
            $table->string('emp_director');
            $table->string('emp_cargo_director');
            $table->string('emp_tipo_proceso');
            $table->string('emp_prefijo');
            $table->string('emp_anio');
            $table->integer('emp_numerico');
            $table->timestamps();
        });
    }
}
