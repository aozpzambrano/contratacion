<?php

namespace App\Services;

use DomainException;
use ZipArchive;

/**
 * Rellena una plantilla Word (.docx) reemplazando marcadores {{nombre}}.
 * Word suele partir un marcador en varios fragmentos de texto ("runs"); aqui se reconstruye el texto de cada
 * parrafo, se reemplaza y se conserva el formato del fragmento donde empieza el marcador.
 */
class PlantillaWord
{
    public const MARCADORES = ['objeto_contratacion', 'numero_proceso'];

    /** Partes del paquete que contienen texto visible. */
    private const PARTES = '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#';

    /**
     * Marcadores {{...}} presentes en el archivo (aunque Word los haya partido en fragmentos).
     *
     * @return array<int, string>
     */
    public function marcadoresPresentes(string $rutaDocx): array
    {
        $zip = new ZipArchive;
        if ($zip->open($rutaDocx) !== true) {
            throw new DomainException('El archivo no es un documento Word (.docx) valido.');
        }

        $encontrados = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (! preg_match(self::PARTES, $nombre)) {
                continue;
            }
            foreach ($this->parrafos((string) $zip->getFromIndex($i)) as $parrafo) {
                if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', implode('', $parrafo['textos']), $m)) {
                    $encontrados = array_merge($encontrados, $m[1]);
                }
            }
        }
        $zip->close();

        return array_values(array_unique($encontrados));
    }

    /**
     * Copia la plantilla a un archivo temporal con los marcadores reemplazados y devuelve su ruta.
     *
     * @param  array<string, string>  $valores  nombre del marcador => valor
     */
    public function rellenar(string $rutaDocx, array $valores): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'plantilla_');
        if (! copy($rutaDocx, $destino)) {
            throw new DomainException('No se pudo preparar la plantilla.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destino) !== true) {
            @unlink($destino);
            throw new DomainException('La plantilla no es un documento Word (.docx) valido.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if (! preg_match(self::PARTES, $nombre)) {
                continue;
            }
            $xml = (string) $zip->getFromIndex($i);
            $nuevo = $this->reemplazar($xml, $valores);
            if ($nuevo !== $xml) {
                $zip->addFromString($nombre, $nuevo);
            }
        }
        $zip->close();

        return $destino;
    }

    /** @param array<string, string> $valores */
    public function reemplazar(string $xml, array $valores): string
    {
        $partes = $this->parrafos($xml);
        // De atras hacia adelante para no invalidar las posiciones.
        foreach (array_reverse($partes) as $parrafo) {
            $textos = $parrafo['textos'];
            $unido = implode('', $textos);
            if (! str_contains($unido, '{{')) {
                continue;
            }

            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $unido, $coincidencias, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            if ($coincidencias === []) {
                continue;
            }

            // Inicio de cada fragmento dentro del texto unido (en bytes, igual que PREG_OFFSET_CAPTURE).
            $inicios = [];
            $acumulado = 0;
            foreach ($textos as $i => $t) {
                $inicios[$i] = $acumulado;
                $acumulado += strlen($t);
            }

            foreach (array_reverse($coincidencias) as $c) {
                [$completo, $desde] = $c[0];
                $nombre = $c[1][0];
                if (! array_key_exists($nombre, $valores)) {
                    continue;
                }
                $hasta = $desde + strlen($completo);
                $valor = htmlspecialchars(preg_replace('/\s+/u', ' ', trim($valores[$nombre])) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');

                $primero = true;
                foreach ($textos as $i => $t) {
                    $ini = $inicios[$i];
                    $fin = $ini + strlen($t);
                    if ($fin <= $desde || $ini >= $hasta) {
                        continue;
                    }
                    $recorteIni = max($desde, $ini) - $ini;
                    $recorteFin = min($hasta, $fin) - $ini;
                    $textos[$i] = substr($t, 0, $recorteIni).($primero ? $valor : '').substr($t, $recorteFin);
                    $primero = false;
                }
            }

            $xml = $this->reescribir($xml, $parrafo, $textos);
        }

        return $xml;
    }

    /**
     * Parrafos con su posicion en el XML y el texto de cada <w:t>.
     *
     * @return array<int, array{inicio: int, largo: int, textos: array<int, string>}>
     */
    private function parrafos(string $xml): array
    {
        $salida = [];
        if (! preg_match_all('#<w:p[ >].*?</w:p>#s', $xml, $m, PREG_OFFSET_CAPTURE)) {
            return $salida;
        }
        foreach ($m[0] as [$texto, $inicio]) {
            preg_match_all('#<w:t(?: [^>]*)?>(.*?)</w:t>#s', $texto, $t);
            $salida[] = ['inicio' => $inicio, 'largo' => strlen($texto), 'textos' => $t[1]];
        }

        return $salida;
    }

    /** Sustituye el contenido de los <w:t> del parrafo por los textos nuevos. */
    private function reescribir(string $xml, array $parrafo, array $textos): string
    {
        $original = substr($xml, $parrafo['inicio'], $parrafo['largo']);
        $i = 0;
        $nuevo = preg_replace_callback('#<w:t((?: [^>]*)?)>(.*?)</w:t>#s', function ($m) use (&$i, $textos) {
            $atributos = $m[1];
            if (! str_contains($atributos, 'xml:space')) {
                $atributos .= ' xml:space="preserve"';
            }

            return '<w:t'.$atributos.'>'.$textos[$i++].'</w:t>';
        }, $original);

        return substr($xml, 0, $parrafo['inicio']).$nuevo.substr($xml, $parrafo['inicio'] + $parrafo['largo']);
    }
}
