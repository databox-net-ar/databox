<?php
// api/lib/bancos_interpretes/_tabular.php
// Esqueleto para los bancos que exportan una tabla plana: unas lineas de
// titulo, una fila de encabezado y una fila por movimiento.
//
// Los 5 bancos actuales entran en esta forma, asi que el archivo de cada uno
// termina siendo declarativo: que palabras identifican su encabezado, como se
// llaman sus columnas y con que formato de fecha y separador decimal escribe.
// Lo que NO entra se resuelve sobrescribiendo: `ajustar()` para derivar campos
// (contraparte a partir de la descripcion, por ejemplo) o directamente
// `interpretar()` para tomar el control completo del parseo.
//
// Este archivo arranca con `_` a proposito: el registro de dcbInterpretes()
// saltea los `_*` al descubrir bancos, y _base.php lo carga explicitamente.

require_once __DIR__ . '/_base.php';

abstract class InterpreteTabular extends InterpreteBanco {

    // ------------------------------------------------------------------
    // A declarar por cada banco
    // ------------------------------------------------------------------

    /**
     * Grupos de palabras que identifican la fila de encabezado. Alcanza con que
     * un grupo matchee entero. Varios grupos = varias versiones del export.
     * @return string[][]
     */
    abstract protected function firmasEncabezado(): array;

    /**
     * Patrones de busqueda por campo destino, en orden de prioridad.
     * Claves posibles: fecha, fecha_valor, descripcion, referencia, contraparte,
     * debito, credito, importe, saldo, cuit.
     * @return array<string, string[]>
     */
    abstract protected function patronesColumnas(): array;

    /**
     * Palabras que, si aparecen en cualquier parte del arranque del archivo,
     * confirman el banco (razon social, CUIT, marca). Suben el puntaje de
     * deteccion pero no son obligatorias.
     * @return string[]
     */
    protected function firmasBanco(): array { return []; }

    protected function formatoFecha(): string { return 'dmy'; }
    protected function decimal(): string      { return 'auto'; }
    protected function invertirSigno(): bool  { return false; }

    /** Medio con el que se cargan los movimientos si el archivo no lo dice. */
    protected function medioPorDefecto(): ?string { return null; }

    /**
     * True para los bancos que SIEMPRE exportan el importe en una sola columna
     * firmada. Si el archivo trae debito y credito separados, no puede ser de
     * ellos y detectar() devuelve 0.
     *
     * Existe porque sin esto Brubank y Naranja X reclamaban con 70 puntos un
     * export del Banco San Juan: sus firmas incluyen ['fecha','detalle',
     * 'importe'] y ese archivo tiene las tres columnas, aunque ademas tenga
     * Debito y Credito — que ellos no usan nunca.
     */
    protected function excluyeDebitoCredito(): bool { return false; }

    /** Cuantas filas del arranque se miran buscando encabezado y firmas. */
    protected function maxScan(): int { return 25; }

    /**
     * Gancho para ajustar un movimiento ya armado: derivar la contraparte de la
     * descripcion, clasificar el medio segun el concepto, limpiar prefijos.
     * `$cols` trae los indices resueltos por si hace falta mirar otra columna.
     */
    protected function ajustar(MovimientoBancario $m, array $fila, array $cols): MovimientoBancario {
        return $m;
    }

    // ------------------------------------------------------------------
    // Implementacion comun
    // ------------------------------------------------------------------

    public function detectar(array $filas, string $formato): int {
        if (!$filas) return 0;

        $idx = $this->buscarEncabezado($filas, $this->firmasEncabezado(), $this->maxScan());
        if ($idx < 0) return 0;

        // Descarte temprano: este banco solo exporta importe firmado y el
        // archivo trae debito y credito separados.
        //
        // Se mira el ENCABEZADO CRUDO y no las columnas resueltas: los bancos
        // que ponen excluyeDebitoCredito() en true justamente no declaran
        // patrones de debito/credito, asi que resolverColumnas() se los
        // devolveria siempre en null y el descarte no dispararia nunca.
        if ($this->excluyeDebitoCredito()) {
            $cabecera = $this->norm(implode(' | ', $filas[$idx]));
            $hayDeb = str_contains($cabecera, 'debito') || str_contains($cabecera, 'debe');
            $hayCre = str_contains($cabecera, 'credito') || str_contains($cabecera, 'haber');
            if ($hayDeb && $hayCre) return 0;
        }

        $cols = $this->resolverColumnas($filas[$idx]);

        // Encabezado reconocido: base solida pero todavia no inequivoca — otro
        // banco puede usar los mismos nombres de columna.
        $puntaje = 55;

        // Firma propia del banco en el arranque del archivo: eso si desempata.
        $firmas = $this->firmasBanco();
        if ($firmas) {
            $arranque = '';
            foreach (array_slice($filas, 0, $this->maxScan()) as $f) {
                $arranque .= ' ' . $this->norm(implode(' ', $f));
            }
            foreach ($firmas as $f) {
                if (str_contains($arranque, $this->norm($f))) { $puntaje += 30; break; }
            }
        }

        // Que ademas resuelva fecha e importe confirma que el mapeo va a andar.
        if ($cols['fecha'] !== null) $puntaje += 8;
        if ($cols['debito'] !== null || $cols['credito'] !== null || $cols['importe'] !== null) $puntaje += 7;

        return min(100, $puntaje);
    }

    public function interpretar(array $filas, string $formato): ResultadoInterpretacion {
        $res = new ResultadoInterpretacion();

        $idx = $this->buscarEncabezado($filas, $this->firmasEncabezado(), $this->maxScan());
        if ($idx < 0) {
            $res->avisos[] = 'No se encontró la fila de encabezado esperada para ' . $this->nombre() . '.';
            return $res;
        }

        $cols = $this->resolverColumnas($filas[$idx]);
        if ($cols['fecha'] === null) {
            $res->avisos[] = 'El archivo no tiene una columna de fecha reconocible.';
            return $res;
        }
        $hayImporte = $cols['debito'] !== null || $cols['credito'] !== null || $cols['importe'] !== null;
        if (!$hayImporte) {
            $res->avisos[] = 'El archivo no tiene columnas de importe reconocibles.';
            return $res;
        }

        $fmt  = $this->formatoFecha();
        $dec  = $this->decimal();
        $tope = count($filas);

        for ($i = $idx + 1; $i < $tope; $i++) {
            $fila = $filas[$i];

            if ($this->filaVacia($fila)) continue;

            $fecha = planillaFecha($this->celda($fila, $cols['fecha']), $fmt);

            // El chequeo de pie va DESPUES de la fecha y solo sobre filas que no
            // la tienen. Antes iba primero y miraba las 3 primeras columnas, con
            // lo que un movimiento real cuya columna 'Detalle' arrastraba el
            // marcador "SUBTOTAL <n>" de la paginacion del PDF se tomaba por pie
            // y cortaba la lectura: en el export 2016-2019 del Banco San Juan
            // eso dejaba 20 movimientos de 10.414.
            //
            // Un pie de verdad ("TOTALES", "SALDO FINAL AL ...") nunca trae una
            // fecha parseable en la columna de fecha, asi que exigirlo no pierde
            // ningun corte legitimo.
            if ($fecha === null) {
                if ($this->esPie($fila)) break;   // fin de la tabla
                $res->descartadas++;
                continue;
            }

            $par = ($cols['debito'] !== null || $cols['credito'] !== null)
                ? $this->debitoCredito($fila, $cols['debito'], $cols['credito'], $dec)
                : $this->importeFirmado($fila, $cols['importe'], $dec, $this->invertirSigno());

            if ($par === null) { $res->descartadas++; continue; }
            [$tipo, $importe] = $par;
            if ($importe <= 0) { $res->descartadas++; continue; }

            $mov = new MovimientoBancario(
                fecha:       $fecha,
                tipo:        $tipo,
                importe:     $importe,
                descripcion: $this->texto($fila, $cols['descripcion'], 500),
                referencia:  $this->texto($fila, $cols['referencia'], 100),
                saldo:       $cols['saldo'] !== null
                                ? planillaNumero($this->celda($fila, $cols['saldo']), $dec)
                                : null,
                contraparte: $this->texto($fila, $cols['contraparte'], 255),
                fechaValor:  $cols['fecha_valor'] !== null
                                ? planillaFecha($this->celda($fila, $cols['fecha_valor']), $fmt)
                                : null,
                medio:       $this->medioPorDefecto(),
                cuit:        $this->normalizarCuit($this->celda($fila, $cols['cuit'])),
            );

            $res->movimientos[] = $this->ajustar($mov, $fila, $cols);
        }

        if (!$res->movimientos) {
            $res->avisos[] = 'Se reconoció el formato de ' . $this->nombre()
                           . ' pero no se pudo leer ningún movimiento.';
        }
        return $res;
    }

    /** @return array<string, int|null> */
    protected function resolverColumnas(array $encabezados): array {
        $p    = $this->patronesColumnas();
        $out  = [];
        $used = [];

        // El orden importa: los campos mas especificos se resuelven primero
        // para que no se los lleve uno mas generico.
        //   - 'fecha_valor' antes que 'fecha', si no "fecha" matchea "fecha valor".
        //   - debito/credito antes que 'importe'.
        //   - 'saldo' ANTES que 'importe': un patron generico de importe como
        //     'amount' matchea "BALANCE_AMOUNT" de MercadoPago y le robaba la
        //     columna al saldo, que despues quedaba en null.
        //   - 'descripcion' ultima: sus patrones ('detalle', 'movimiento') son
        //     los mas amplios y se quedan con lo que sobre.
        $orden = ['fecha_valor', 'fecha', 'debito', 'credito', 'saldo', 'importe',
                  'referencia', 'contraparte', 'cuit', 'descripcion'];

        foreach ($orden as $campo) {
            $idx = isset($p[$campo]) ? $this->col($encabezados, $p[$campo], $used) : null;
            $out[$campo] = $idx;
            if ($idx !== null) $used[] = $idx;
        }

        // Debito/credito e importe unico son excluyentes: si estan las dos
        // columnas separadas, un "importe" suelto suele ser el bruto o un total.
        if ($out['debito'] !== null && $out['credito'] !== null) $out['importe'] = null;

        return $out;
    }

    protected function normalizarCuit(string $raw): ?string {
        $d = preg_replace('/[^0-9]/', '', $raw);
        return ($d !== null && strlen($d) === 11) ? $d : null;
    }

    /**
     * Cuenta los puntos donde la cadena de saldos no cierra.
     * Se usa despues de las reparaciones: lo que sobrevive ya no es un problema
     * de formato del archivo sino, casi siempre, un movimiento que falta.
     *
     * @param MovimientoBancario[] $movs
     */
    protected function contarQuiebresDeSaldo(array $movs): int {
        $n = count($movs);
        if ($n < 2) return 0;

        $asc  = $movs[0]->fecha <= $movs[$n - 1]->fecha;
        $paso = $asc ? -1 : 1;

        $rotos = 0;
        for ($k = 0; $k < $n; $k++) {
            $iPrev = $k + $paso;
            if ($iPrev < 0 || $iPrev >= $n) continue;
            $prev = $movs[$iPrev];
            $cur  = $movs[$k];
            if ($prev->saldo === null || $cur->saldo === null) continue;
            $signo = $cur->tipo === 'ingreso' ? $cur->importe : -$cur->importe;
            if (abs(round($prev->saldo + $signo, 2) - $cur->saldo) > 0.01) $rotos++;
        }
        return $rotos;
    }

    /**
     * Repara la columna de saldo cuando el export viene paginado y el banco
     * REINICIA el saldo corrido en cada pagina.
     *
     * El sintoma (visto en el export real del Banco San Juan, 969 filas): la
     * cadena `saldo[i] == saldo[i+1] + signo[i]` se rompe a intervalos
     * exactamente regulares, y la fila que sigue a cada quiebre repite siempre
     * el mismo valor — el saldo ACTUAL de la cuenta. O sea: el banco arranca
     * cada pagina desde el saldo de hoy en vez de continuar el corrido, con lo
     * que solo la primera pagina queda bien. En ese archivo eran 48 quiebres
     * cada 20 filas y el 97,9% de los saldos estaba mal.
     *
     * La reparacion es reconstruir el corrido desde la fila mas reciente, cuyo
     * saldo SI es correcto (es el saldo actual, y es el movimiento mas nuevo).
     *
     * NO se toca nada salvo que se cumpla la firma completa del artefacto:
     * quiebres regularmente espaciados Y todas las filas post-quiebre con el
     * mismo valor que la fila 0 Y el archivo en orden de fecha descendente.
     * Un hueco real en el extracto (movimientos faltantes) rompe la cadena de
     * forma irregular: ahi los saldos del banco son los buenos y recalcular
     * seria justamente lo contrario de lo que hay que hacer.
     *
     * @param MovimientoBancario[] $movs
     * @return int cantidad de saldos corregidos (0 = no se toco nada)
     */
    protected function repararSaldosPaginados(array $movs): int {
        $n = count($movs);
        if ($n < 3) return 0;

        $ancla = $movs[0]->saldo;
        if ($ancla === null) return 0;

        // Solo tiene sentido si el archivo va del mas nuevo al mas viejo: el
        // ancla es la fila 0 justamente porque es el movimiento mas reciente.
        for ($k = 0; $k < $n - 1; $k++) {
            if ($movs[$k]->fecha < $movs[$k + 1]->fecha) return 0;
        }

        $quiebres = [];
        for ($k = 0; $k < $n - 1; $k++) {
            if ($movs[$k]->saldo === null || $movs[$k + 1]->saldo === null) continue;
            $signo = $movs[$k]->tipo === 'ingreso' ? $movs[$k]->importe : -$movs[$k]->importe;
            if (abs(round($movs[$k + 1]->saldo + $signo, 2) - $movs[$k]->saldo) > 0.01) {
                $quiebres[] = $k;
            }
        }
        if (!$quiebres) return 0;   // cadena sana, nada que hacer

        // Firma 1: toda fila post-quiebre repite el saldo de la fila 0.
        foreach ($quiebres as $k) {
            $s = $movs[$k + 1]->saldo;
            if ($s === null || abs($s - $ancla) > 0.01) return 0;
        }

        // Firma 2: los quiebres estan regularmente espaciados (tamaño de pagina).
        if (count($quiebres) >= 2) {
            $paso = $quiebres[1] - $quiebres[0];
            if ($paso < 2) return 0;
            for ($i = 1, $c = count($quiebres); $i < $c; $i++) {
                if ($quiebres[$i] - $quiebres[$i - 1] !== $paso) return 0;
            }
        }

        // Reconstruir el corrido hacia atras desde el ancla.
        $reparados = 0;
        $saldo     = $ancla;
        foreach ($movs as $mov) {
            if ($mov->saldo !== null && abs($mov->saldo - $saldo) > 0.01) $reparados++;
            $mov->saldo = $saldo;
            $signo = $mov->tipo === 'ingreso' ? $mov->importe : -$mov->importe;
            $saldo = round($saldo - $signo, 2);
        }
        return $reparados;
    }
}
