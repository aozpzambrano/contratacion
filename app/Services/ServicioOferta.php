<?php

namespace App\Services;

use App\Models\Proceso;
use App\Models\Proveedor;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ofertas recibidas tras una publicacion: proveedor (RUC/cedula y razon social) y precio unitario por cada item.
 * Cada oferta cubre todos los items del proceso. El total del item (cantidad x precio) y el del proceso se calculan.
 */
class ServicioOferta
{
    public const MAX_OFERTAS = 20;

    public function __construct(private readonly ServicioProceso $procesos) {}

    /** Numero minimo de ofertas (parametro minimo_proformas). */
    public function minimo(): int
    {
        return (int) (DB::selectOne("select parametro_entero('minimo_proformas') as v")->v ?? 3);
    }

    /** Ofertas del ciclo actual, de todas las publicaciones. */
    public function cantidad(Proceso $proceso): int
    {
        return $proceso->ofertas()->count();
    }

    /** Ultima publicacion del ciclo actual (a la que se asocian las ofertas nuevas). */
    public function publicacionActual(Proceso $proceso): ?object
    {
        return DB::table('publicaciones')->where('proceso_id', $proceso->id)->where('ciclo', $proceso->ciclo)->orderByDesc('secuencia')->first();
    }

    /** El analista asignado registra las ofertas de la ultima publicacion (una sola vez por publicacion). */
    public function puedeRegistrar(Proceso $proceso, User $usuario): bool
    {
        $publicacion = $this->publicacionActual($proceso);

        return $usuario->activo
            && $usuario->tienePermiso('ofertas.registrar')
            && $proceso->estadoProceso?->codigo === 'PUBLICADO'
            && $publicacion !== null
            && $this->procesos->estaAsignado($proceso, $usuario)
            && $publicacion->ofertas_registradas_en === null;
    }

    /** Ya se registraron las ofertas (aunque fueran 0) de la ultima publicacion. */
    public function ofertasRegistradas(Proceso $proceso): bool
    {
        return $this->publicacionActual($proceso)?->ofertas_registradas_en !== null;
    }

    /**
     * Decision del flujo tras registrar las ofertas de la ultima publicacion (la calcula la base de datos):
     * 'remitir', 'republicar', 'devolver' o null si aun no se registraron las ofertas.
     */
    public function decision(Proceso $proceso): ?string
    {
        if ($proceso->estadoProceso?->codigo !== 'PUBLICADO') {
            return null;
        }

        return DB::selectOne('select decision_ofertas_proceso(?) as d', [$proceso->id])->d;
    }

    /** "Remitir ofertas": total de ofertas del ciclo >= minimo, o publicaciones agotadas con al menos una oferta. */
    public function puedeRemitir(Proceso $proceso): bool
    {
        return $this->decision($proceso) === 'remitir';
    }

    /** Publicaciones agotadas y 0 ofertas: el analista devuelve el expediente (memorando e informe). */
    public function puedeDevolver(Proceso $proceso): bool
    {
        return $this->decision($proceso) === 'devolver';
    }

    /**
     * @param  array<int, array{identificacion: string, razon_social: string, precios: array<int|string, mixed>}>  $ofertas
     *                                                                                                                         precios: id del item del proceso => precio unitario
     */
    public function registrar(Proceso $proceso, User $usuario, array $ofertas): void
    {
        if (! $this->puedeRegistrar($proceso, $usuario)) {
            throw new DomainException('No puede registrar ofertas en este momento.');
        }

        $ofertas = array_values($ofertas);
        if (count($ofertas) > self::MAX_OFERTAS) {
            throw new DomainException('El número de ofertas debe estar entre 0 y '.self::MAX_OFERTAS.'.');
        }

        $items = $proceso->items()->get();
        $vistos = [];
        $normalizadas = [];
        foreach ($ofertas as $i => $o) {
            $n = $i + 1;
            $id = preg_replace('/\s+/', '', (string) ($o['identificacion'] ?? ''));
            if (! preg_match('/^\d{10}(\d{3})?$/', $id)) {
                throw new DomainException("Oferta {$n}: el RUC debe tener 13 dígitos o la cédula 10 dígitos.");
            }
            if (isset($vistos[$id])) {
                throw new DomainException("Oferta {$n}: el proveedor {$id} está repetido.");
            }
            $vistos[$id] = true;

            $razon = trim((string) ($o['razon_social'] ?? ''));
            if ($razon === '' || mb_strlen($razon) > 250) {
                throw new DomainException("Oferta {$n}: ingrese la razón social (máximo 250 caracteres).");
            }

            $precios = [];
            foreach ($items as $item) {
                $valor = $o['precios'][$item->id] ?? null;
                if (! is_numeric($valor) || (float) $valor < 0 || (float) $valor >= 1e10) {
                    throw new DomainException("Oferta {$n}: ingrese el precio unitario del ítem {$item->numero_linea}.");
                }
                $precios[$item->id] = round((float) $valor, 4);
            }
            $normalizadas[] = ['identificacion' => $id, 'razon_social' => $razon, 'precios' => $precios];
        }

        try {
            DB::transaction(function () use ($proceso, $usuario, $normalizadas) {
                $publicacion = $this->publicacionActual($proceso);
                $estado = DB::table('estados_oferta')->where('codigo', 'RECIBIDA')->value('id');

                foreach ($normalizadas as $o) {
                    $proveedor = Proveedor::withTrashed()->where('ruc', $o['identificacion'])->first()
                        ?? Proveedor::create(['ruc' => $o['identificacion'], 'razon_social' => $o['razon_social']]);

                    $ofertaId = DB::table('ofertas')->insertGetId([
                        'proceso_id' => $proceso->id,
                        'publicacion_id' => $publicacion->id,
                        'proveedor_id' => $proveedor->id,
                        'estado_oferta_id' => $estado,
                        'fecha_presentacion' => DB::raw('now()'),
                        'creado_por' => $usuario->id,
                    ]);
                    foreach ($o['precios'] as $itemId => $precio) {
                        DB::table('oferta_items')->insert([
                            'proceso_id' => $proceso->id, 'oferta_id' => $ofertaId, 'proceso_item_id' => $itemId, 'precio_unitario' => $precio,
                        ]);
                    }
                }

                DB::table('publicaciones')->where('id', $publicacion->id)->update(['ofertas_registradas_en' => DB::raw('now()')]);

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.ofertas_registradas',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode(['publicacion_id' => $publicacion->id, 'ofertas' => count($normalizadas)]),
                ]);
            });
        } catch (QueryException $e) {
            $mensaje = ($e->getPrevious() ?? $e)->getMessage();

            throw new DomainException(preg_match('/ERROR:\s+(.+?)(?:\R|$)/s', $mensaje, $m) ? trim($m[1]) : 'No se pudieron registrar las ofertas.', 0, $e);
        }
    }

    /**
     * Resumen de las ofertas del ciclo actual, con totales por item y por proceso.
     *
     * @return Collection<int, array{id: int, publicacion: int, identificacion: string, razon_social: string, total: float, items: array<int, array{linea: int, descripcion: string, cantidad: int, precio: float, total: float}>}>
     */
    public function resumen(Proceso $proceso): Collection
    {
        return $proceso->ofertas()->with(['proveedor', 'publicacion', 'items.procesoItem'])->get()->map(function ($oferta) {
            $items = $oferta->items->sortBy(fn ($i) => $i->procesoItem->numero_linea)->map(function ($i) {
                $precio = (float) $i->precio_unitario;
                $cantidad = (int) $i->procesoItem->cantidad;

                return [
                    'linea' => (int) $i->procesoItem->numero_linea,
                    'descripcion' => $i->procesoItem->descripcion,
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                    'total' => round($cantidad * $precio, 4),
                ];
            })->values()->all();

            return [
                'id' => $oferta->id,
                'publicacion' => (int) $oferta->publicacion->secuencia,
                'identificacion' => $oferta->proveedor->ruc,
                'razon_social' => $oferta->proveedor->razon_social,
                'total' => round(array_sum(array_column($items, 'total')), 4),
                'items' => $items,
            ];
        });
    }
}
