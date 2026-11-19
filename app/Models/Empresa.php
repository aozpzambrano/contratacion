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
            $table->timestamps();
        });
    }
}
