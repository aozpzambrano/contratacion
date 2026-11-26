<?php

namespace App\Services;

use App\Models\Archivo;
use App\Models\Proceso;
use App\Models\Proveedor;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Ofertas recibidas tras una publicacion: proveedor (RUC/cedula y razon social) y precio unitario por cada item.
 * Cada oferta cubre todos los items del proceso. El total del item (cantidad x precio) y el del proceso se calculan.
 */
class ServicioOferta
{
    public const MAX_OFERTAS = 20;

    /** Archivos que se pueden cargar por oferta (una carpeta del proveedor se sube como varios archivos o como ZIP). */
    public const MAX_ARCHIVOS_POR_OFERTA = 50;

    public const EXTENSIONES_OFERTA = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'jpg', 'jpeg', 'png'];

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
     * @param  array<int, array{identificacion: string, razon_social: string, precios: array<int|string, mixed>, archivos: array<int, array{0: string, 1: string}>}>  $ofertas
     *                                                                                                                         precios: id del item del proceso => precio unitario;
     *                                                                                                                         archivos: lista de [ruta temporal, nombre original] de la oferta del proveedor (obligatorio)
     */
    public function registrar(Proceso $proceso, User $usuario, array $ofertas): void
    {
        $temporales = collect($ofertas)->flatMap(fn ($o) => array_column((array) ($o['archivos'] ?? []), 0))->filter()->all();

        try {
            $this->registrarOfertas($proceso, $usuario, $ofertas);
        } catch (Throwable $e) {
            // la carga se revirtio: no quedan archivos temporales ni definitivos huerfanos
            Storage::disk(ServicioDocumento::DISCO)->delete($temporales);
            Storage::disk(ServicioDocumento::DISCO)->delete($this->rutasNuevas);
            $this->rutasNuevas = [];

            throw $e;
        }
    }

    /** @var array<int, string> archivos definitivos creados en la operacion en curso */
    private array $rutasNuevas = [];

    private function registrarOfertas(Proceso $proceso, User $usuario, array $ofertas): void
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
            $archivos = $this->validarArchivos($n, (array) ($o['archivos'] ?? []));

            $normalizadas[] = ['identificacion' => $id, 'razon_social' => $razon, 'precios' => $precios, 'archivos' => $archivos];
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
                    foreach ($o['archivos'] as [$rutaTemporal, $nombre, $extension]) {
                        $archivo = $this->almacenar($proceso, $ofertaId, $rutaTemporal, $nombre, $extension, $usuario);
                        DB::table('oferta_archivos')->insert(['proceso_id' => $proceso->id, 'oferta_id' => $ofertaId, 'archivo_id' => $archivo->id]);
                    }
                }

                DB::table('publicaciones')->where('id', $publicacion->id)->update(['ofertas_registradas_en' => DB::raw('now()')]);

                DB::table('auditoria')->insert([
                    'user_id' => $usuario->id,
                    'evento' => 'proceso.ofertas_registradas',
                    'entidad' => 'procesos',
                    'entidad_id' => $proceso->id,
                    'valores_nuevos' => json_encode([
                        'publicacion_id' => $publicacion->id,
                        'ofertas' => count($normalizadas),
                        'archivos' => array_sum(array_map(fn ($o) => count($o['archivos']), $normalizadas)),
                    ]),
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
                'archivos' => $this->archivosDeOferta($oferta->id),
            ];
        });
    }

    /** @return array<int, array{id: int, nombre: string, tamano: int}> */
    public function archivosDeOferta(int $ofertaId): array
    {
        return DB::table('oferta_archivos as oa')->join('archivos as a', 'a.id', '=', 'oa.archivo_id')
            ->where('oa.oferta_id', $ofertaId)->orderBy('oa.id')
            ->get(['a.id', 'a.nombre_original', 'a.tamano_bytes'])
            ->map(fn ($f) => ['id' => (int) $f->id, 'nombre' => $f->nombre_original, 'tamano' => (int) $f->tamano_bytes])->all();
    }

    /**
     * Comprueba los archivos de una oferta: al menos uno, formatos permitidos, no vacios y dentro del tamano maximo.
     *
     * @param  array<int, array{0: string, 1: string}>  $archivos
     * @return array<int, array{0: string, 1: string, 2: string}> [ruta temporal, nombre original, extension]
     */
    private function validarArchivos(int $n, array $archivos): array
    {
        $archivos = array_values(array_filter($archivos, fn ($a) => ! empty($a[0])));
        if ($archivos === []) {
            throw new DomainException("Oferta {$n}: cargue los archivos de la oferta del proveedor.");
        }
        if (count($archivos) > self::MAX_ARCHIVOS_POR_OFERTA) {
            throw new DomainException("Oferta {$n}: máximo ".self::MAX_ARCHIVOS_POR_OFERTA.' archivos por oferta (si son más, súbalos en un ZIP).');
        }

        $disco = Storage::disk(ServicioDocumento::DISCO);
        $limiteMb = (int) (DB::selectOne('select parametro_entero(?) as valor', ['tamano_maximo_documento_mb'])->valor ?: 20);
        $validados = [];
        foreach ($archivos as $a) {
            $nombre = basename(str_replace('\\', '/', (string) ($a[1] ?? '')));
            $nombre = $nombre !== '' && mb_check_encoding($nombre, 'UTF-8') ? $nombre : basename((string) $a[0]);
            $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

            if (! in_array($extension, self::EXTENSIONES_OFERTA, true)) {
                throw new DomainException("Oferta {$n}: el archivo \"{$nombre}\" no es de un tipo permitido (".implode(', ', self::EXTENSIONES_OFERTA).').');
            }
            if (! $disco->exists($a[0]) || $disco->size($a[0]) === 0) {
                throw new DomainException("Oferta {$n}: el archivo \"{$nombre}\" está vacío o no se cargó correctamente.");
            }
            if ($disco->size($a[0]) > $limiteMb * 1024 * 1024) {
                throw new DomainException("Oferta {$n}: el archivo \"{$nombre}\" supera el tamaño máximo permitido ({$limiteMb} MB).");
            }
            $validados[] = [$a[0], $nombre, $extension];
        }

        return $validados;
    }

    private function almacenar(Proceso $proceso, int $ofertaId, string $rutaTemporal, string $nombre, string $extension, User $usuario): Archivo
    {
        $disco = Storage::disk(ServicioDocumento::DISCO);
        $interno = Str::uuid()->toString().'.'.$extension;
        $ruta = "procesos/{$proceso->id}/ofertas/{$ofertaId}/{$interno}";
        $disco->move($rutaTemporal, $ruta);
        $this->rutasNuevas[] = $ruta;

        $archivo = new Archivo;
        $archivo->forceFill([
            'disco' => ServicioDocumento::DISCO,
            'ruta' => $ruta,
            'nombre_original' => Str::limit($nombre, 250, ''),
            'nombre_interno' => $interno,
            'extension' => $extension,
            'mime' => $disco->mimeType($ruta) ?: 'application/octet-stream',
            'tamano_bytes' => $disco->size($ruta),
            'hash_sha256' => hash('sha256', (string) $disco->get($ruta)),
            'user_id' => $usuario->id,
        ])->save();

        return $archivo;
    }

    private function puedeDescargar(Proceso $proceso, User $usuario): bool
    {
        return $usuario->activo
            && ($usuario->tienePermiso('ofertas.ver') || $usuario->tienePermiso('ofertas.registrar'))
            && app(ServicioDocumento::class)->puedeVerProceso($proceso, $usuario);
    }

    /** Descarga todos los archivos de una oferta en un solo ZIP (cada archivo con su nombre original). */
    public function descargarOfertaZip(int $ofertaId, User $usuario): StreamedResponse
    {
        $oferta = DB::table('ofertas as o')->join('proveedores as p', 'p.id', '=', 'o.proveedor_id')
            ->where('o.id', $ofertaId)->first(['o.id', 'o.proceso_id', 'p.ruc', 'p.razon_social']);
        abort_if($oferta === null, 404, 'La oferta no existe.');
        abort_unless($this->puedeDescargar(Proceso::findOrFail($oferta->proceso_id), $usuario), 403, 'No tiene permiso para descargar esta oferta.');

        $archivos = Archivo::query()
            ->whereIn('id', DB::table('oferta_archivos')->where('oferta_id', $ofertaId)->pluck('archivo_id'))
            ->orderBy('id')->get();
        abort_if($archivos->isEmpty(), 404, 'La oferta no tiene archivos.');

        $zipRuta = tempnam(sys_get_temp_dir(), 'oferta_');
        $zip = new \ZipArchive;
        abort_unless($zip->open($zipRuta, \ZipArchive::OVERWRITE) === true, 500, 'No se pudo crear el ZIP.');
        $usados = [];
        $temporales = [];
        foreach ($archivos as $a) {
            $disco = Storage::disk($a->disco);
            if (! $disco->exists($a->ruta)) {
                continue;
            }
            $local = tempnam(sys_get_temp_dir(), 'ofa_');
            file_put_contents($local, $disco->get($a->ruta));
            $temporales[] = $local;

            $nombre = $a->nombre_original;
            for ($n = 2; in_array($nombre, $usados, true); $n++) {
                $nombre = pathinfo($a->nombre_original, PATHINFO_FILENAME).'_'.$n.'.'.$a->extension;
            }
            $usados[] = $nombre;
            $zip->addFile($local, $nombre);
        }
        $zip->close();
        abort_if($usados === [], 404, 'Los archivos de la oferta ya no están disponibles.');

        DB::table('auditoria')->insert([
            'user_id' => $usuario->id,
            'evento' => 'oferta.zip_descargado',
            'entidad' => 'ofertas',
            'entidad_id' => $ofertaId,
            'valores_nuevos' => json_encode(['archivos' => count($usados)]),
            'ip' => request()->ip(),
        ]);

        $nombreZip = 'oferta_'.$oferta->ruc.'_'.Str::slug($oferta->razon_social, '_').'.zip';

        return response()->streamDownload(function () use ($zipRuta, $temporales) {
            readfile($zipRuta);
            @unlink($zipRuta);
            array_map('unlink', array_filter($temporales, 'file_exists'));
        }, $nombreZip, ['Content-Type' => 'application/zip']);
    }
}
