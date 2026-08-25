<?php
// api/lib/bancos_interpretes/_base.php
// Contrato y registro de los interpretes de extractos bancarios.
//
// POR QUE UN INTERPRETE POR BANCO
// -------------------------------
// Cada banco exporta distinto: cambian el encabezado, la cantidad de lineas de
// titulo, si el importe viene en una columna firmada o en dos (debito/credito),
// el formato de fecha, si hay saldo por fila, y hasta si una operacion ocupa
// una fila o dos. Un mapeo de columnas generico cubre los casos simples pero se
// queda corto en los torcidos, y ademas obliga al usuario a reconfigurarlo.
//
// Con un interprete por banco, importar es: elegir cuenta, subir archivo, listo.
// El interprete sabe donde esta cada cosa.
//
// COMO SE ELIGE EL INTERPRETE
// ---------------------------
// 1. La cuenta tiene `banco_id`; la tabla `datacount_bancos_interpretes` dice
//    que interprete le corresponde a ese banco.
// 2. Igual se corre `detectar()` de TODOS los interpretes sobre el archivo y se
//    usa el puntaje para confirmar. Si el interprete del banco no reconoce el
//    archivo pero otro si, se avisa (tipico: alguien sube el resumen de otra
//    cuenta) en vez de importar mal en silencio.
// 3. Si ninguno reconoce el archivo, el importador cae al mapeo manual de
//    columnas, que sigue existiendo para bancos sin interprete.
//
// COMO SUMAR UN BANCO
// -------------------
// Crear `<clave>.php` en esta carpeta con una clase que extienda
// InterpreteBanco, y agregar la fila en `datacount_bancos_interpretes`. El
// registro descubre los archivos solo — no hay lista que actualizar.

require_once __DIR__ . '/../planilla.php';

/**
 * Un movimiento normalizado, tal como lo devuelve todo interprete.
 * El importador no sabe nada del banco: consume siempre esta forma.
 *
 * - `fecha`       'YYYY-MM-DD'  (obligatoria; sin fecha la fila se descarta)
 * - `tipo`        'ingreso' | 'egreso'
 * - `importe`     float > 0 SIEMPRE positivo — el signo lo lleva `tipo`
 * - `saldo`       float|null    saldo que informa el extracto DESPUES del movimiento
 * - el resto      string|null
 */
final class MovimientoBancario {
    public function __construct(
        public string  $fecha,
        public string  $tipo,
        public float   $importe,
        public ?string $descripcion = null,
        public ?string $referencia  = null,
        public ?float  $saldo       = null,
        public ?string $contraparte = null,
        public ?string $fechaValor  = null,
        public ?string $medio       = null,
        public ?string $cuit        = null,
    ) {}

    public function aArray(): array {
        return [
            'fecha'       => $this->fecha,
            'fecha_valor' => $this->fechaValor,
            'tipo'        => $this->tipo,
            'importe'     => $this->importe,
            'descripcion' => $this->descripcion,
            'referencia'  => $this->referencia,
            'saldo'       => $this->saldo,
            'contraparte' => $this->contraparte,
            'medio'       => $this->medio,
            'cuit'        => $this->cuit,
        ];
    }
}

/** Resultado de interpretar un archivo entero. */
final class ResultadoInterpretacion {
    /** @param MovimientoBancario[] $movimientos @param string[] $avisos */
    public function __construct(
        public array $movimientos = [],
        public array $avisos      = [],
        public int   $descartadas = 0,
    ) {}
}

abstract class InterpreteBanco {

    /** Clave estable; es la que guarda `datacount_bancos_interpretes.interprete`. */
    abstract public function clave(): string;

    /** Nombre para mostrar en la UI ("Banco San Juan — Consulta de movimientos"). */
    abstract public function nombre(): string;

    /**
     * Puntaje 0..100 de confianza en que este archivo sea de este banco.
     *
     * Convencion de puntajes, para que sean comparables entre interpretes:
     *   0      no es de este banco
     *   40-59  el encabezado pinta pero falta alguna columna caracteristica
     *   60-79  encabezado reconocido (umbral de uso: DCB_UMBRAL_DETECCION)
     *   80-100 encabezado + alguna firma inequivoca del banco
     */
    abstract public function detectar(array $filas, string $formato): int;

    /** Convierte las filas crudas de la planilla en movimientos normalizados. */
    abstract public function interpretar(array $filas, string $formato): ResultadoInterpretacion;

    /**
     * Version del formato contra el que se calibro este interprete, y si se
     * verifico contra un export real. Se muestra en la UI para que quede claro
     * cuando un interprete todavia no vio un archivo de verdad.
     */
    public function calibracion(): array {
        return ['verificado' => false, 'nota' => 'Sin calibrar contra un export real.'];
    }

    // ------------------------------------------------------------------
    // Helpers compartidos
    // ------------------------------------------------------------------

    /**
     * Busca la fila de encabezado: la primera de las `$maxScan` que contenga
     * TODAS las palabras de alguno de los grupos de `$firmas`.
     *
     * Se pide "todas las de un grupo" y no "alguna" porque los bancos meten
     * lineas sueltas arriba ("Movimientos", "Fecha de emision: ...") que
     * contienen una de las palabras y desviarian la deteccion.
     *
     * @param string[][] $firmas
     * @return int indice 0-based de la fila, o -1
     */
    protected function buscarEncabezado(array $filas, array $firmas, int $maxScan = 25): int {
        $tope = min(count($filas), $maxScan);
        for ($i = 0; $i < $tope; $i++) {
            $celdas = array_map([$this, 'norm'], $filas[$i]);
            $linea  = implode(' | ', $celdas);
            foreach ($firmas as $grupo) {
                $todas = true;
                foreach ($grupo as $palabra) {
                    if (!$this->contiene($linea, $palabra)) { $todas = false; break; }
                }
                if ($todas) return $i;
            }
        }
        return -1;
    }

    /**
     * Indice de la primera columna cuyo encabezado contenga alguno de los
     * `$patrones`. Devuelve null si ninguna matchea.
     *
     * `$excluir` saca de la busqueda columnas ya asignadas, para que "fecha" no
     * se lleve la columna "fecha valor" cuando ya se busco esa primero.
     */
    protected function col(array $encabezados, array $patrones, array $excluir = []): ?int {
        $norm = array_map([$this, 'norm'], $encabezados);
        foreach ($patrones as $p) {
            foreach ($norm as $i => $texto) {
                if ($texto === '' || in_array($i, $excluir, true)) continue;
                if ($this->contiene($texto, $p)) return $i;
            }
        }
        return null;
    }

    /** Celda por indice, trimmeada; '' si el indice es null o no existe. */
    protected function celda(array $fila, ?int $idx): string {
        if ($idx === null) return '';
        return trim((string) ($fila[$idx] ?? ''));
    }

    /** Celda como string o null (para columnas opcionales). */
    protected function texto(array $fila, ?int $idx, int $max = 500): ?string {
        $s = $this->celda($fila, $idx);
        if ($s === '') return null;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    /** Normaliza para comparar encabezados: minusculas, sin acentos, sin dobles espacios. */
    protected function norm(mixed $s): string {
        return planillaNormalizarTexto((string) $s);
    }

    /**
     * ¿`$texto` contiene `$patron`? Tolerando que el export haya perdido los
     * acentos y los haya dejado como '?'.
     *
     * El export del Banco Supervielle es ASCII puro y escribe "D?bito",
     * "Cr?dito", "OPERACI?N", "Autom?tico", "MU?OZ": donde iba una vocal
     * acentuada (o la ñ) quedo un signo de interrogacion. Sin esto el
     * encabezado no matcheaba con ningun patron y el archivo no lo reconocia
     * ningun interprete.
     *
     * La tolerancia se aplica SOLO si el texto tiene '?', y se limita a las
     * vocales y la n del patron — que son las unicas letras que pueden llevar
     * tilde o virgulilla en castellano. Asi "debito" matchea "d?bito" sin que
     * el comodin se coma medio diccionario.
     */
    protected function contiene(string $texto, string $patron): bool {
        if (str_contains($texto, $patron)) return true;
        if (!str_contains($texto, '?'))    return false;

        $rx = preg_quote($patron, '/');
        $rx = (string) preg_replace('/(?<!\\\\)([aeioun])/u', '[$1?]', $rx);
        return (bool) preg_match('/' . $rx . '/u', $texto);
    }

    /** True si la fila esta vacia o es un separador. */
    protected function filaVacia(array $fila): bool {
        return trim(implode('', $fila)) === '';
    }

    /**
     * True si la fila es un pie de tabla (TOTALES, SALDO FINAL, etc.).
     * Se chequea sobre las primeras celdas porque el texto siempre va ahi.
     */
    protected function esPie(array $fila): bool {
        $inicio = $this->norm(implode(' ', array_slice($fila, 0, 3)));
        foreach (['total', 'saldo final', 'saldo al', 'subtotal', 'resumen', 'ultimos movimientos'] as $p) {
            if (str_contains($inicio, $p)) return true;
        }
        return false;
    }

    /**
     * Resuelve tipo + importe a partir de dos columnas debito/credito.
     * @return array{0:string,1:float}|null
     */
    protected function debitoCredito(array $fila, ?int $colDeb, ?int $colCre, string $decimal = 'auto'): ?array {
        $deb = $colDeb !== null ? planillaNumero($this->celda($fila, $colDeb), $decimal) : null;
        $cre = $colCre !== null ? planillaNumero($this->celda($fila, $colCre), $decimal) : null;
        $deb = ($deb !== null && abs($deb) > 0) ? abs($deb) : null;
        $cre = ($cre !== null && abs($cre) > 0) ? abs($cre) : null;
        if ($cre !== null && $deb === null) return ['ingreso', round($cre, 2)];
        if ($deb !== null && $cre === null) return ['egreso',  round($deb, 2)];
        return null;
    }

    /**
     * Resuelve tipo + importe a partir de una columna firmada.
     * @return array{0:string,1:float}|null
     */
    protected function importeFirmado(array $fila, ?int $col, string $decimal = 'auto', bool $invertir = false): ?array {
        $n = planillaNumero($this->celda($fila, $col), $decimal);
        if ($n === null || $n == 0.0) return null;
        if ($invertir) $n = -$n;
        return $n > 0 ? ['ingreso', round($n, 2)] : ['egreso', round(abs($n), 2)];
    }
}

// ----------------------------------------------------------------------------
// Registro
// ----------------------------------------------------------------------------

// Puntaje minimo para que un interprete se considere aplicable. Por debajo, el
// importador cae al mapeo manual en vez de arriesgarse a leer mal el archivo.
const DCB_UMBRAL_DETECCION = 60;

// Ventaja a partir de la cual se considera que OTRO interprete reconoce el
// archivo mejor que el del banco de la cuenta, y por lo tanto que el archivo
// probablemente sea de otro banco.
//
// 15 sale de los datos: un archivo propio puntua 100 (encabezado + firma
// exclusiva) y uno ajeno que comparte los nombres de columna se queda en 70,
// porque le falta la firma. Cualquier corte entre 1 y 30 separa esos dos casos;
// 15 deja margen para que una variante nueva del propio export baje algunos
// puntos sin que se lo confunda con el de otro banco.
const DCB_MARGEN_DETECCION = 15;

/**
 * Carga y devuelve una instancia de cada interprete de la carpeta.
 * Se descubren por filesystem: sumar un banco es crear el archivo, no editar
 * una lista.
 *
 * @return InterpreteBanco[]  clave => instancia
 */
function dcbInterpretes(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // El esqueleto tabular va explicito: los `_*` no se descubren solos, y los
    // interpretes concretos lo extienden.
    require_once __DIR__ . '/_tabular.php';

    $cache = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $ruta) {
        $base = basename($ruta);
        if (str_starts_with($base, '_')) continue;   // _base.php, _tabular.php
        require_once $ruta;
    }

    // Toda subclase concreta de InterpreteBanco que haya quedado declarada.
    foreach (get_declared_classes() as $clase) {
        if (!is_subclass_of($clase, InterpreteBanco::class)) continue;
        $rc = new ReflectionClass($clase);
        if ($rc->isAbstract()) continue;
        /** @var InterpreteBanco $inst */
        $inst = $rc->newInstance();
        $cache[$inst->clave()] = $inst;
    }
    return $cache;
}

/** Un interprete por clave, o null. */
function dcbInterprete(?string $clave): ?InterpreteBanco {
    if ($clave === null || $clave === '') return null;
    return dcbInterpretes()[$clave] ?? null;
}

/**
 * Corre `detectar()` de todos los interpretes y devuelve los puntajes
 * ordenados de mayor a menor.
 *
 * @return array<int, array{clave:string, nombre:string, puntaje:int}>
 */
function dcbDetectarTodos(array $filas, string $formato): array {
    $out = [];
    foreach (dcbInterpretes() as $clave => $i) {
        $p = 0;
        try {
            $p = max(0, min(100, $i->detectar($filas, $formato)));
        } catch (Throwable) {
            // Un interprete roto no puede tumbar la deteccion de los demas.
            $p = 0;
        }
        if ($p > 0) $out[] = ['clave' => $clave, 'nombre' => $i->nombre(), 'puntaje' => $p];
    }
    usort($out, fn($a, $b) => $b['puntaje'] <=> $a['puntaje']);
    return $out;
}

/** Clave del interprete configurado para un banco, o null. */
function dcbInterpreteDeBanco(PDO $pdo, ?int $bancoId): ?string {
    if (!$bancoId) return null;
    $st = $pdo->prepare(
        'SELECT interprete FROM datacount_bancos_interpretes
          WHERE banco_id = :b AND activo = 1 LIMIT 1'
    );
    $st->execute([':b' => $bancoId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null || $v === '' ? null : (string) $v;
}
