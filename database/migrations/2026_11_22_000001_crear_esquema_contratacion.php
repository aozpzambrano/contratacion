<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El script SQL trae su propio BEGIN/COMMIT, por eso esta migracion
     * no se envuelve en la transaccion de Laravel.
     */
    public bool $withinTransaction = false;

    /** Tablas del framework que down() no debe tocar. */
    private array $conservar = [
        'migrations', 'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    public function up(): void
    {
        // migrate:fresh borra las tablas pero no las funciones: se limpian para poder recrearlas.
        $this->eliminarFunciones();
        DB::unprepared(file_get_contents(database_path('sql/01_esquema_contratacion.sql')));
    }

    public function down(): void
    {
        $tablas = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))
            ->pluck('tablename')
            ->reject(fn ($t) => in_array($t, $this->conservar, true));

        foreach ($tablas as $tabla) {
            DB::statement('DROP TABLE IF EXISTS "'.$tabla.'" CASCADE');
        }

        $this->eliminarFunciones();
    }

    private function eliminarFunciones(): void
    {
        $funciones = DB::select(
            "select p.oid::regprocedure::text as firma from pg_proc p
              join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = 'public' and p.prokind in ('f', 'p')
               and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e')"
        );
        foreach ($funciones as $f) {
            DB::statement('DROP FUNCTION IF EXISTS '.$f->firma.' CASCADE');
        }
    }
};
