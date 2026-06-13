<?php
/**
 * app/helpers/FormatoHelper.php
 * Formato numérico configurable desde Admin > Apariencia (tabla configuracion_app,
 * claves num_decimales/num_sep_miles/num_sep_decimal/num_sep_millones —
 * migraciones 040 y 041).
 *
 * USO:
 *   fmt_cantidad(1234.5)        -> "1.234,50"   (decimales y separadores configurables)
 *   fmt_cantidad(1234.567,3)    -> "1.234,567"  (decimales explícitos, ignora config)
 *   fmt_moneda(1234.5)          -> "1.235"      (siempre 0 decimales, separadores configurables)
 *
 * Separador de millones (num_sep_millones, migración 041): si es igual al
 * separador de miles, el formato es uniforme (ej. "1.234.567"). Si es distinto,
 * solo el grupo más cercano al decimal usa num_sep_miles y todos los grupos a
 * la izquierda (millones, miles de millones, ...) usan num_sep_millones
 * (ej. con miles="." y millones="'": "1'234.567", "1'234'567.890").
 */

/**
 * Lee la configuración de formato numérico (cacheada por request).
 * Defaults si la tabla/claves no existen: 2 decimales, punto=miles=millones,
 * coma=decimal (es-CO).
 */
function config_numeros(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $cfg = ['decimales' => 2, 'sep_miles' => '.', 'sep_decimal' => ',', 'sep_millones' => '.'];
    try {
        $rows = db()->query(
            "SELECT clave, valor FROM configuracion_app
             WHERE clave IN ('num_decimales','num_sep_miles','num_sep_decimal','num_sep_millones')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($rows['num_decimales']))   $cfg['decimales']   = max(0, min(4, (int)$rows['num_decimales']));
        if (isset($rows['num_sep_miles']))   $cfg['sep_miles']   = $rows['num_sep_miles'];
        if (isset($rows['num_sep_decimal'])) $cfg['sep_decimal'] = $rows['num_sep_decimal'];
        if (isset($rows['num_sep_millones'])) $cfg['sep_millones'] = $rows['num_sep_millones'];
    } catch (Exception $e) { /* valores por defecto si la tabla no existe aún */ }

    return $cfg;
}

/**
 * Formatea un número agrupando la parte entera en bloques de 3 dígitos: el
 * grupo junto al separador decimal usa $cfg['sep_miles'], y todos los grupos
 * a la izquierda de ese usan $cfg['sep_millones'] (migración 041). Si ambos
 * separadores son iguales, el resultado es el formato "uniforme" tradicional.
 *
 * @param float $n   Valor a formatear
 * @param int   $dec Número de decimales
 * @param array $cfg Configuración de config_numeros()
 */
function fmt_agrupar(float $n, int $dec, array $cfg): string
{
    $raw = number_format($n, $dec, '.', '');

    $neg = $raw[0] === '-';
    if ($neg) $raw = substr($raw, 1);

    $partes  = explode('.', $raw, 2);
    $entero  = $partes[0];
    $decimal = $partes[1] ?? '';

    $grupos = [];
    while (strlen($entero) > 3) {
        array_unshift($grupos, substr($entero, -3));
        $entero = substr($entero, 0, -3);
    }
    array_unshift($grupos, $entero);

    $out    = $grupos[0];
    $ultimo = count($grupos) - 1;
    for ($i = 1; $i <= $ultimo; $i++) {
        $out .= ($i === $ultimo ? $cfg['sep_miles'] : $cfg['sep_millones']) . $grupos[$i];
    }

    if ($dec > 0) $out .= $cfg['sep_decimal'] . $decimal;

    return ($neg ? '-' : '') . $out;
}

/**
 * Formatea una cantidad (stock, presentaciones, equivalencias, costo por unidad).
 * Decimales configurables vía Admin > Apariencia; $dec sobreescribe si se indica.
 *
 * @param float    $n   Valor a formatear
 * @param int|null $dec Número de decimales (null = usar configuración global)
 */
function fmt_cantidad(float $n, ?int $dec = null): string
{
    $cfg = config_numeros();
    $dec = $dec ?? $cfg['decimales'];
    return fmt_agrupar($n, $dec, $cfg);
}

/**
 * Formatea un monto en pesos. Siempre 0 decimales (invariante de precios);
 * separadores configurables vía Admin > Apariencia.
 */
function fmt_moneda(float $n): string
{
    $cfg = config_numeros();
    return fmt_agrupar($n, 0, $cfg);
}
