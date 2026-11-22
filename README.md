# Sistema de Contratación por Fases — AIL E.P.

Gestión de procesos de contratación pública en las fases Preparatoria, Precontractual y Contractual
(más Finalizado y reapertura). Laravel 13 · Filament 4 · PostgreSQL 16 · Docker.

## Puesta en marcha

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
```

Panel: http://localhost:8000/admin (usuario y clave en `ADMIN_EMAIL` / `ADMIN_PASSWORD` del `.env`).

## Pruebas

```bash
docker compose exec db psql -U contratacion -d postgres -c "CREATE DATABASE contratacion_test"
docker compose exec app php artisan test
```

Las pruebas usan PostgreSQL (`contratacion_test`), no SQLite: el esquema usa funciones y triggers de PostgreSQL.

## Documentación

- `docs/01_arquitectura_datos.md` — modelo de datos, estados, transiciones y matriz de permisos.
- `database/sql/` — script del esquema (`01_`) y pruebas SQL (`02_`). El esquema se aplica desde una migración.
