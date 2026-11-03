<?php
// Crazy HQ · Facturación. Prepara borradores y proformas y REGISTRA las facturas que cada emisor emite con su programa.
// No emite ante Hacienda ni inventa números, QR, certificados ni respuestas: eso lo hace el programa del emisor
// (sistema "externo"). Las facturas, emisores y cobros solo se escriben con estas acciones, nunca con put/patch/del.
declare(strict_types=1);

const F_TIPOS = ['factura', 'proforma', 'rectificativa'];
const F_ALCANCE = ['completa', 'anticipo', 'parcial', 'resto', 'libre', 'rectificacion'];
const F_TERR = ['comun', 'alava', 'bizkaia', 'gipuzkoa', 'navarra', 'canarias', 'ceuta', 'pendiente'];
const F_MPAGO = ['transferencia', 'bizum', 'efectivo', 'tarjeta'];
const F_PDF_MAX = 10 * 1024 * 1024;

function f_arr($v): array { return is_array($v) ? $v : []; }
function f_txt($v, int $max = 200): string { $s = trim(is_scalar($v) ? (string) $v : ''); return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '', 0, $max); }
function f_fecha($v): string { $s = is_scalar($v) ? (string) $v : ''; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)) ? $s : ''; }
function f_num($v, float $min, float $max): float { return max($min, min($max, round(is_numeric($v) ? (float) $v : 0.0, 2))); }
function f_int($v): int { return is_numeric($v) ? (int) round((float) $v) : 0; }
function f_nifclean($v): string { return strtoupper(preg_replace('/[\s.\-]/', '', f_txt($v, 24)) ?? ''); }
function eur2(int $c): string { return number_format($c / 100, 2, ',', '.') . ' €'; }
function f_hoy(): string { return madrid()->format('Y-m-d'); }
function f_yo(): array { $u = me(); if (vito($u)) fail('Esto es solo de Crazy Estudio', 403); return $u; }
function f_no(string $m, int $c = 400): void { if (pdo()->inTransaction()) pdo()->rollBack(); fail($m, $c); }

// Las mismas cuentas que la app: céntimos enteros, mitades lejos del cero.
function rdiv(int $a, int $b): int { return ($a < 0 ? -1 : 1) * intdiv(2 * abs($a) + $b, 2 * $b); }
function centi($x): int { return (int) round((is_numeric($x) ? (float) $x : 0.0) * 100); }
function f_totales(array $f): array {
  $tl = [];
  foreach (f_arr($f['lineas'] ?? null) as $l) $tl[] = rdiv(centi($l['qty'] ?? 0) * (int) ($l['unit'] ?? 0), 100);
  $suma = array_sum($tl);
  $desc = $suma > 0 ? min($suma, max(0, (int) ($f['descuento']['importe'] ?? 0))) : 0;
  $base = $suma - $desc; $cuota = rdiv($base * centi($f['ivaPct'] ?? 0), 10000); $ret = rdiv($base * centi($f['irpfPct'] ?? 0), 10000);
  return ['lineas' => $tl, 'suma' => $suma, 'descuento' => $desc, 'base' => $base, 'cuota' => $cuota, 'retencion' => $ret, 'total' => $base + $cuota, 'aCobrar' => $base + $cuota - $ret];
}
function f_nif(string $x): bool {
  $raw = f_nifclean($x); $es = str_starts_with($raw, 'ES') && strlen($raw) > 9; $s = $es ? substr($raw, 2) : $raw; $L = 'TRWAGMYFPDXBNJZSQVHLCKE';
  if (preg_match('/^(\d{8})([A-Z])$/', $s, $m)) return $L[(int) $m[1] % 23] === $m[2];
  if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $s, $m)) return $L[(int) (strpos('XYZ', $m[1]) . $m[2]) % 23] === $m[3];
  if (preg_match('/^([ABCDEFGHJKLMNPQRSUVW])(\d{7})([0-9A-J])$/', $s, $m)) {
    $t = 0; for ($i = 0; $i < 7; $i++) { $d = (int) $m[2][$i]; if ($i % 2 === 0) { $d *= 2; $d = intdiv($d, 10) + $d % 10; } $t += $d; }
    $c = (10 - $t % 10) % 10; return $m[3] === (string) $c || $m[3] === 'JABCDEFGHI'[$c];
  }
  return !$es && !str_starts_with($raw, 'ES') && (bool) preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $s); // NIF-IVA de otro país
}
function f_iban(string $x): bool {
  $s = strtoupper(preg_replace('/\s/', '', $x) ?? '');
  if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $s) || (str_starts_with($s, 'ES') && strlen($s) !== 24)) return false;
  $m = 0; foreach (str_split(substr($s, 4) . substr($s, 0, 4)) as $ch) { $v = ctype_alpha($ch) ? ord($ch) - 55 : (int) $ch; $m = ($v > 9 ? $m * 100 + $v : $m * 10 + $v) % 97; }
  return $m === 1;
}
// Mientras falte algo, ese emisor solo hace borradores y proformas.
function f_faltan(array $e): array {
  $f = []; $d = f_arr($e['domicilio'] ?? null); $mp = f_arr($e['metodosPago'] ?? null); $ser = f_arr($e['series'] ?? null);
  if (trim((string) ($e['razonSocial'] ?? '')) === '') $f[] = 'nombre o razón social';
  if (!f_nif((string) ($e['nif'] ?? ''))) $f[] = 'NIF válido';
  if (empty($d['calle']) || empty($d['cp']) || empty($d['ciudad']) || empty($d['provincia'])) $f[] = 'domicilio fiscal completo';
  if (($e['tipo'] ?? 'pendiente') === 'pendiente') $f[] = 'si es autónomo o sociedad';
  if (($e['territorio'] ?? 'pendiente') === 'pendiente') $f[] = 'territorio fiscal';
  if (!$mp) $f[] = 'forma de cobro';
  if (in_array('transferencia', $mp, true) && !f_iban((string) ($e['iban'] ?? ''))) $f[] = 'IBAN válido';
  if (in_array('bizum', $mp, true) && trim((string) ($e['bizum'] ?? '')) === '') $f[] = 'teléfono de Bizum';
  if (!isset($e['irpfPct']) || $e['irpfPct'] === '') $f[] = 'si sus facturas llevan retención de IRPF';
  if (trim((string) ($e['sistema']['programa'] ?? '')) === '') $f[] = 'programa con el que emite';
  if (!array_filter($ser, fn($s) => is_array($s) && empty($s['rect']) && !empty($s['confirmado']))) $f[] = 'serie con el último número confirmado';
  if (!array_filter($ser, fn($s) => is_array($s) && !empty($s['rect']) && !empty($s['confirmado']))) $f[] = 'serie de rectificativas confirmada';
  return $f;
}
// Preparar un borrador para alguien y emitir en su nombre son permisos distintos.
function f_puede(array $e, string $acc, array $u): bool {
  if (vito($u)) return false;
  if (in_array($u['nombre'], f_arr($e['permisos'][$acc] ?? null), true)) return true;
  return $acc === 'preparar' && ($u['admin'] || $u['nombre'] === ($e['usuario'] ?? ''));
}
function f_leer(string $col, string $id, bool $lock = false): ?array {
  if ($id === '') return null;
  $s = pdo()->prepare("SELECT data FROM docs WHERE col = ? AND id = ? AND owner = '' AND deleted = 0" . ($lock ? ' FOR UPDATE' : ''));
  $s->execute([$col, $id]); $r = $s->fetchColumn();
  return $r === false ? null : (json_decode((string) $r, true) ?: []);
}
function f_todas(string $col): array {
  $s = pdo()->prepare("SELECT id, data FROM docs WHERE col = ? AND owner = '' AND deleted = 0"); $s->execute([$col]);
  $o = []; foreach ($s as $r) $o[$r['id']] = json_decode($r['data'], true) ?: []; return $o;
}
function f_dir(): string {
  static $d = null; if ($d) return $d;
  $d = dirname(__DIR__, 3) . '/hq-archivos'; // fuera de public_html: ningún enlace llega a los PDF
  if (!is_dir($d) && !@mkdir($d, 0700, true)) {
    $d = __DIR__ . '/archivos';
    if (!is_dir($d)) mkdir($d, 0700, true);
    if (!is_file($d . '/.htaccess')) file_put_contents($d . '/.htaccess', "Require all denied\n");
  }
  return $d;
}
function f_numero_n(array $se, string $num): ?int {
  $p = (string) ($se['prefijo'] ?? ''); if (!str_starts_with($num, $p)) return null;
  $r = substr($num, strlen($p)); return preg_match('/^\d{1,9}$/', $r) ? (int) $r : null;
}
function f_max_registrado(string $eid, string $serie): int {
  $s = pdo()->prepare('SELECT MAX(n) FROM fact_numeros WHERE emisor = ? AND serie = ?'); $s->execute([$eid, $serie]); return (int) $s->fetchColumn();
}
// Copia de los datos del emisor en la factura emitida: si luego cambian, la factura no cambia.
function f_snap(array $e): array {
  return array_intersect_key($e, array_flip(['nombre', 'razonSocial', 'nif', 'nombreComercial', 'logo', 'email', 'telefono', 'domicilio', 'tipo', 'territorio', 'iban', 'metodosPago', 'bizum', 'ivaMencion']));
}
function f_emisor_limpio(array $in, ?array $prev, array $u, string $id): array {
  $p = $prev ?? []; $equipo = array_keys(array_filter(usuarios(), fn($r) => $r === 'crazy'));
  $lista = fn($v) => array_values(array_intersect($equipo, array_map('strval', f_arr($v))));
  $d = f_arr($in['domicilio'] ?? null); $sis = f_arr($in['sistema'] ?? null); $per = f_arr($in['permisos'] ?? null);
  $usuario = $u['admin'] && in_array($in['usuario'] ?? '', $equipo, true) ? (string) $in['usuario'] : (string) ($p['usuario'] ?? '');
  $e = [
    'nombre' => f_txt($in['nombre'] ?? '', 40) ?: (string) ($p['nombre'] ?? $id),
    'usuario' => $usuario,
    'razonSocial' => f_txt($in['razonSocial'] ?? '', 120),
    'nif' => f_nifclean($in['nif'] ?? ''),
    'nombreComercial' => f_txt($in['nombreComercial'] ?? '', 80) ?: 'Crazy Estudio',
    'logo' => ($in['logo'] ?? '') === 'ninguno' ? 'ninguno' : 'crazy',
    'email' => filter_var((string) ($in['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? strtolower((string) $in['email']) : '',
    'telefono' => f_txt($in['telefono'] ?? '', 30),
    'domicilio' => ['calle' => f_txt($d['calle'] ?? '', 120), 'cp' => f_txt($d['cp'] ?? '', 10), 'ciudad' => f_txt($d['ciudad'] ?? '', 60), 'provincia' => f_txt($d['provincia'] ?? '', 60), 'pais' => f_txt($d['pais'] ?? '', 40) ?: 'España'],
    'tipo' => in_array($in['tipo'] ?? '', ['autonomo', 'sociedad', 'pendiente'], true) ? (string) $in['tipo'] : 'pendiente',
    'territorio' => in_array($in['territorio'] ?? '', F_TERR, true) ? (string) $in['territorio'] : 'pendiente',
    'iban' => strtoupper(preg_replace('/\s/', '', f_txt($in['iban'] ?? '', 42)) ?? ''),
    'metodosPago' => array_values(array_intersect(F_MPAGO, array_map('strval', f_arr($in['metodosPago'] ?? null)))),
    'bizum' => f_txt($in['bizum'] ?? '', 20),
    'irpfPct' => isset($in['irpfPct']) && is_numeric($in['irpfPct']) ? f_num($in['irpfPct'], 0, 47) : null,
    'ivaMencion' => f_txt($in['ivaMencion'] ?? '', 300),
    'sistema' => ['modo' => 'externo', 'programa' => f_txt($sis['programa'] ?? '', 80), 'nota' => f_txt($sis['nota'] ?? '', 300)],
    'nota' => f_txt($in['nota'] ?? '', 500),
    'proformaUltimo' => (int) ($p['proformaUltimo'] ?? 0),
  ];
  $series = [];
  foreach (array_slice(f_arr($in['series'] ?? null), 0, 8, true) as $k => $s) {
    $k = (string) $k; if (!preg_match('/^[A-Z0-9]{1,6}$/', $k) || $k === 'PRO' || !is_array($s)) continue;
    $ult = max(0, f_int($s['ultimo'] ?? 0)); $min = f_max_registrado($id, $k);
    if ($ult < $min) f_no("La serie $k ya tiene registrada la factura número $min: el último número no puede ser menor", 409);
    $series[$k] = ['nombre' => f_txt($s['nombre'] ?? '', 40) ?: $k, 'prefijo' => f_txt($s['prefijo'] ?? '', 20), 'digitos' => max(0, min(8, f_int($s['digitos'] ?? 0))), 'ultimo' => $ult, 'confirmado' => !empty($s['confirmado']), 'rect' => !empty($s['rect'])];
  }
  foreach (array_keys(f_arr($p['series'] ?? null)) as $k) if (!isset($series[$k]) && f_max_registrado($id, (string) $k) > 0) f_no("La serie $k tiene facturas registradas: no se puede quitar", 409);
  $e['series'] = $series;
  // Quién puede emitir o rectificar en su nombre lo decide solo el propio emisor (al crearlo, solo él).
  $pp = f_arr($p['permisos'] ?? null); $own = $u['nombre'] === $usuario;
  $e['permisos'] = [
    'preparar' => $lista($per['preparar'] ?? []),
    'emitir' => $prev === null ? ($usuario ? [$usuario] : []) : ($own ? $lista($per['emitir'] ?? []) : $lista($pp['emitir'] ?? [])),
    'rectificar' => $prev === null ? ($usuario ? [$usuario] : []) : ($own ? $lista($per['rectificar'] ?? []) : $lista($pp['rectificar'] ?? [])),
  ];
  return $e;
}
function f_factura_limpia(array $in, array $em, string $eid): array {
  $tipo = in_array($in['tipo'] ?? '', F_TIPOS, true) ? (string) $in['tipo'] : 'factura';
  $c = f_arr($in['cliente'] ?? null); $lin = [];
  foreach (array_slice(f_arr($in['lineas'] ?? null), 0, 150) as $l) {
    if (!is_array($l)) continue; $con = f_txt($l['concepto'] ?? '', 300); if ($con === '') continue;
    $lin[] = ['concepto' => $con, 'qty' => f_num($l['qty'] ?? 1, 0, 100000), 'unit' => max(-100000000, min(100000000, f_int($l['unit'] ?? 0)))];
  }
  $di = f_arr($in['descuento'] ?? null);
  $ser = (string) ($in['serie'] ?? '');
  $f = [
    'tipo' => $tipo, 'alcance' => in_array($in['alcance'] ?? '', F_ALCANCE, true) ? (string) $in['alcance'] : 'libre',
    'emisorId' => $eid, 'serie' => $tipo === 'proforma' ? 'PRO' : (isset($em['series'][$ser]) ? $ser : ''),
    'presupuestoId' => cleanId($in['presupuestoId'] ?? ''), 'presupuestoRef' => f_txt($in['presupuestoRef'] ?? '', 120),
    'clienteId' => cleanId($in['clienteId'] ?? ''), 'saludo' => f_txt($in['saludo'] ?? '', 60),
    'cliente' => ['nombre' => f_txt($c['nombre'] ?? '', 120), 'nif' => f_nifclean($c['nif'] ?? ''), 'domicilio' => f_txt($c['domicilio'] ?? '', 200), 'email' => f_txt($c['email'] ?? '', 120), 'tipo' => f_txt($c['tipo'] ?? '', 30)],
    'lineas' => $lin,
    'descuento' => f_int($di['importe'] ?? 0) > 0 ? ['etiqueta' => f_txt($di['etiqueta'] ?? '', 120) ?: 'Descuento', 'importe' => f_int($di['importe'])] : null,
    'ivaPct' => f_num($in['ivaPct'] ?? 21, 0, 30), 'ivaMencion' => f_txt($in['ivaMencion'] ?? '', 300), 'irpfPct' => f_num($in['irpfPct'] ?? 0, 0, 47),
    'fechaOperacion' => f_fecha($in['fechaOperacion'] ?? ''), 'vencimiento' => f_fecha($in['vencimiento'] ?? ''),
    'metodoPago' => in_array($in['metodoPago'] ?? '', F_MPAGO, true) ? (string) $in['metodoPago'] : '',
    'condiciones' => f_txt($in['condiciones'] ?? '', 1000), 'notaCliente' => f_txt($in['notaCliente'] ?? '', 1000), 'notaInterna' => f_txt($in['notaInterna'] ?? '', 1000),
    'origenProforma' => cleanId($in['origenProforma'] ?? ''),
  ];
  $f['totales'] = f_totales($f);
  return $f;
}
// Un presupuesto no se factura por encima de su base (cuentan emitidas y borradores; las rectificativas restan).
function f_tope(array $f, string $id): void {
  if ($f['presupuestoId'] === '' || !in_array($f['tipo'], ['factura', 'rectificativa'], true)) return;
  $p = f_leer('presupuestos', $f['presupuestoId'], true); // bloquea: dos personas a la vez no pasan las dos
  if (!$p) f_no('El presupuesto vinculado ya no existe: desvincúlalo para seguir', 409);
  if (!is_numeric($p['calc']['base'] ?? null) || ($p['calc']['total'] ?? null) === null) f_no('Ese presupuesto no tiene total cerrado: termínalo y guárdalo antes de facturarlo', 409);
  $B = (int) $p['calc']['base']; $ya = 0;
  foreach (f_todas('facturas') as $fid => $x)
    if ($fid !== $id && ($x['presupuestoId'] ?? '') === $f['presupuestoId'] && in_array($x['tipo'] ?? '', ['factura', 'rectificativa'], true) && ($x['estado'] ?? '') !== 'anulada')
      $ya += (int) ($x['totales']['base'] ?? 0);
  if ($ya + (int) $f['totales']['base'] > $B)
    f_no('Así se facturaría más que el presupuesto: base ' . eur2($B) . ', ya facturado o en borrador ' . eur2($ya) . ', queda ' . eur2(max(0, $B - $ya)) . '.', 409);
}
function f_hist(array $f, array $u, string $que): array { return array_slice([...f_arr($f['historial'] ?? null), ['t' => ms(), 'por' => $u['nombre'], 'que' => $que]], -80); }
function f_semillas(): void {
  $base = fn($n, $usu, $tipo, $terr, $prov, $nota) => ['nombre' => $n, 'usuario' => $usu, 'razonSocial' => '', 'nif' => '', 'nombreComercial' => 'Crazy Estudio', 'logo' => 'crazy', 'email' => '', 'telefono' => '',
    'domicilio' => ['calle' => '', 'cp' => '', 'ciudad' => '', 'provincia' => $prov, 'pais' => 'España'], 'tipo' => $tipo, 'territorio' => $terr, 'iban' => '', 'metodosPago' => ['transferencia'], 'bizum' => '',
    'irpfPct' => null, 'ivaMencion' => '', 'sistema' => ['modo' => 'externo', 'programa' => '', 'nota' => ''], 'nota' => $nota, 'proformaUltimo' => 0,
    'series' => ['F' => ['nombre' => 'Facturas', 'prefijo' => '', 'digitos' => 0, 'ultimo' => 0, 'confirmado' => false, 'rect' => false], 'R' => ['nombre' => 'Rectificativas', 'prefijo' => '', 'digitos' => 0, 'ultimo' => 0, 'confirmado' => false, 'rect' => true]],
    'permisos' => ['preparar' => ['Paulo', 'Edu'], 'emitir' => [$usu], 'rectificar' => [$usu]], 'cambios' => []];
  $sis = ['rol' => 'crazy', 'nombre' => 'sistema', 'admin' => true];
  if (!f_leer('emisores', 'edu')) saveDoc('emisores', 'edu', '', $base('Edu', 'Edu', 'autonomo', 'comun', 'León', 'Autónomo con domicilio fiscal en León (territorio común): sin TicketBAI, aunque trabaje con un estudio de otra provincia.'), $sis);
  if (!f_leer('emisores', 'paulo')) saveDoc('emisores', 'paulo', '', $base('Paulo', 'Paulo', 'pendiente', 'pendiente', '', 'Datos, territorio y configuración fiscal pendientes.'), $sis);
}

function facturacion(string $a): void {
  $db = pdo();
  switch ($a) {
    case 'f_emisor': { // datos de un emisor: el administrador o el propio emisor
      $u = f_yo(); $b = body(); $id = cleanId($b['id'] ?? ''); $in = f_arr($b['data'] ?? null);
      $db->beginTransaction(); $prev = $id ? f_leer('emisores', $id, true) : null;
      if (!$prev) { if (!$u['admin']) f_no('Solo el administrador crea emisores', 403); if (!preg_match('/^[a-z0-9]{2,20}$/', $id)) f_no('Identificador no válido'); }
      elseif (!$u['admin'] && $u['nombre'] !== ($prev['usuario'] ?? '')) f_no('Estos datos solo los cambian ' . (($prev['usuario'] ?? '') ?: 'su dueño') . ' o el administrador', 403);
      $e = f_emisor_limpio($in, $prev, $u, $id);
      $cambiado = array_keys(array_filter($e, fn($v, $k) => json_encode($v) !== json_encode($prev[$k] ?? null), ARRAY_FILTER_USE_BOTH));
      $e['cambios'] = array_slice([...f_arr($prev['cambios'] ?? null), ['t' => ms(), 'por' => $u['nombre'], 'que' => $cambiado ? implode(', ', $cambiado) : 'sin cambios']], -80);
      saveDoc('emisores', $id, '', $e, $u); $db->commit();
      out(['id' => $id, 'data' => $e, 'faltan' => f_faltan($e)]);
    }

    case 'f_guardar': { // borrador de factura, proforma o rectificativa (el id lo da la app: repetir no duplica)
      $u = f_yo(); $b = body(); $in = f_arr($b['data'] ?? null);
      $id = cleanId($b['id'] ?? '') ?: 'f' . newId(); $eid = cleanId($in['emisorId'] ?? '');
      $db->beginTransaction(); $prev = f_leer('facturas', $id, true);
      if ($prev && ($prev['estado'] ?? '') !== 'borrador') f_no('Ya no es un borrador: no se cambia. Duplícala como borrador o prepara una rectificativa.', 409);
      $em = f_leer('emisores', $eid); if (!$em) f_no('Elige quién emite');
      if (!f_puede($em, 'preparar', $u)) f_no('No puedes preparar documentos de ' . $em['nombre'], 403);
      if ($prev && ($prev['emisorId'] ?? '') !== $eid && !f_puede(f_leer('emisores', (string) $prev['emisorId']) ?? [], 'preparar', $u)) f_no('Este borrador es de otro emisor', 403);
      $f = f_factura_limpia($in, $em, $eid);
      if ($f['tipo'] === 'rectificativa') {
        if (!f_puede($em, 'rectificar', $u)) f_no('Las rectificativas de ' . $em['nombre'] . ' solo las preparan: ' . (implode(', ', f_arr($em['permisos']['rectificar'] ?? null)) ?: 'nadie aún'), 403);
        $r = f_arr($in['rectifica'] ?? null); $oid = cleanId($r['id'] ?? ''); $o = f_leer('facturas', $oid);
        if (!$o || ($o['estado'] ?? '') !== 'emitida' || ($o['tipo'] ?? '') === 'proforma' || ($o['emisorId'] ?? '') !== $eid) f_no('La rectificativa va sobre una factura emitida del mismo emisor', 409);
        $mot = f_txt($r['motivo'] ?? '', 300); if ($mot === '') f_no('Escribe el motivo de la rectificación');
        $f['rectifica'] = ['id' => $oid, 'numero' => (string) ($o['numero'] ?? ''), 'fecha' => (string) ($o['fechaExpedicion'] ?? ''), 'motivo' => $mot];
      }
      f_tope($f, $id);
      $f += ['estado' => 'borrador', 'creado' => $prev['creado'] ?? ms(), 'creadoPor' => $prev['creadoPor'] ?? $u['nombre'], 'actualizado' => ms(), 'actualizadoPor' => $u['nombre']];
      $f['historial'] = f_hist($prev ?? [], $u, $prev ? 'Borrador guardado' : 'Borrador creado');
      saveDoc('facturas', $id, '', $f, $u); $db->commit();
      out(['id' => $id, 'data' => $f]);
    }

    case 'f_borrar': { // solo borradores; lo emitido se rectifica
      $u = f_yo(); $id = cleanId(body()['id'] ?? '');
      $db->beginTransaction(); $f = f_leer('facturas', $id, true); if (!$f) f_no('No existe', 404);
      if (($f['estado'] ?? '') !== 'borrador') f_no('Solo se borran borradores. Una factura emitida se rectifica.', 409);
      if (!f_puede(f_leer('emisores', (string) ($f['emisorId'] ?? '')) ?? [], 'preparar', $u)) f_no('No puedes borrar documentos de ese emisor', 403);
      $db->prepare("UPDATE docs SET deleted = 1, updated = ?, data = '{}' WHERE col = 'facturas' AND id = ? AND owner = ''")->execute([ms(), $id]);
      $db->commit(); out(['ok' => true]);
    }

    case 'f_proforma': { // numeración interna PRO-…: no es una factura ni se comunica a Hacienda
      $u = f_yo(); $id = cleanId(body()['id'] ?? '');
      $db->beginTransaction(); $f = f_leer('facturas', $id, true); if (!$f) f_no('No existe', 404);
      if (($f['tipo'] ?? '') !== 'proforma') f_no('Esto no es una proforma');
      if (($f['estado'] ?? '') === 'emitida') { $db->commit(); out(['ok' => true, 'repetida' => true, 'data' => $f]); }
      if (($f['estado'] ?? '') !== 'borrador') f_no('Esta proforma ya no es un borrador', 409);
      $eid = (string) $f['emisorId']; $em = f_leer('emisores', $eid, true) ?? [];
      if (!f_puede($em, 'preparar', $u)) f_no('No puedes preparar documentos de ' . ($em['nombre'] ?? 'ese emisor'), 403);
      if (!$f['lineas']) f_no('La proforma no tiene líneas'); if (($f['cliente']['nombre'] ?? '') === '') f_no('Falta el nombre del cliente');
      $n = (int) ($em['proformaUltimo'] ?? 0) + 1; $num = 'PRO-' . strtoupper(substr($eid, 0, 4)) . '-' . madrid()->format('Y') . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
      $db->prepare('INSERT INTO fact_numeros (emisor, serie, numero, n, factura, creado) VALUES (?,?,?,?,?,?)')->execute([$eid, 'PRO', $num, $n, $id, ms()]);
      $em['proformaUltimo'] = $n; saveDoc('emisores', $eid, '', $em, $u);
      $f = array_merge($f, ['totales' => f_totales($f), 'emisor' => f_snap($em), 'numero' => $num, 'fechaExpedicion' => f_hoy(), 'estado' => 'emitida',
        'emision' => ['modo' => 'proforma', 'por' => $u['nombre'], 'en' => ms()], 'fiscal' => ['estado' => 'no_aplica', 'texto' => 'Proforma: documento previo. No es una factura ni se comunica a Hacienda.']]);
      $f['historial'] = f_hist($f, $u, 'Proforma generada (' . $num . ')');
      saveDoc('facturas', $id, '', $f, $u); $db->commit(); out(['ok' => true, 'data' => $f]);
    }

    case 'f_emitir': { // registra la factura que el emisor YA ha emitido con su programa, con el número que le dio
      $u = f_yo(); $b = body(); $id = cleanId($b['id'] ?? ''); $num = f_txt($b['numero'] ?? '', 40); $fecha = f_fecha($b['fecha'] ?? '');
      $pdf = preg_match('/^[a-f0-9]{16}$/', (string) ($b['pdf'] ?? '')) ? (string) $b['pdf'] : '';
      $db->beginTransaction(); $f = f_leer('facturas', $id, true); if (!$f) f_no('No existe', 404);
      if (($f['estado'] ?? '') === 'emitida') { $db->commit(); out(['ok' => true, 'repetida' => true, 'data' => $f]); } // repetir nunca crea otra
      if (($f['estado'] ?? '') !== 'borrador' || !in_array($f['tipo'] ?? '', ['factura', 'rectificativa'], true)) f_no('Esto no se puede registrar como factura', 409);
      $eid = (string) $f['emisorId']; $em = f_leer('emisores', $eid, true) ?? [];
      if (!f_puede($em, 'emitir', $u)) f_no('Registrar facturas en nombre de ' . ($em['nombre'] ?? '') . ' solo pueden: ' . (implode(', ', f_arr($em['permisos']['emitir'] ?? null)) ?: 'nadie aún') . '. Tú puedes dejarla preparada.', 403);
      $falt = f_faltan($em); if ($falt) f_no('Faltan datos de ' . $em['nombre'] . ': ' . implode(', ', $falt) . '. Mientras tanto, solo borradores y proformas.', 409);
      $se = f_arr($em['series'][$f['serie']] ?? null);
      if (!$se) f_no('Elige la serie', 409);
      if (empty($se['confirmado'])) f_no('Confirma el último número de la serie ' . $f['serie'] . ' antes de registrar', 409);
      if (($f['tipo'] === 'rectificativa') !== !empty($se['rect'])) f_no('Las rectificativas van en su serie, y las facturas normales en la suya', 409);
      $c = $f['cliente'];
      if ($c['nombre'] === '' || $c['domicilio'] === '') f_no('Faltan el nombre y el domicilio fiscal del cliente', 409);
      if (($c['tipo'] !== 'Particular' || $c['nif'] !== '') && !f_nif($c['nif'])) f_no('Falta el NIF del cliente o no es válido', 409);
      if (!$f['lineas']) f_no('No hay líneas', 409);
      $tot = f_totales($f);
      if ($tot['aCobrar'] > 0 && ($f['vencimiento'] === '' || $f['metodoPago'] === '')) f_no('Pon el vencimiento y la forma de pago', 409);
      if ($f['metodoPago'] !== '' && !in_array($f['metodoPago'], f_arr($em['metodosPago'] ?? null), true)) f_no($em['nombre'] . ' no tiene configurada esa forma de cobro', 409);
      if ($num === '') f_no('Escribe el número que te ha dado ' . $em['sistema']['programa'], 409);
      if ($fecha === '' || $fecha > f_hoy()) f_no('La fecha de expedición no puede estar vacía ni ser futura', 409);
      if ($f['tipo'] === 'rectificativa' && (f_leer('facturas', (string) ($f['rectifica']['id'] ?? ''))['estado'] ?? '') !== 'emitida') f_no('La factura que se rectifica no está emitida', 409);
      f_tope($f, $id);
      $n = f_numero_n($se, $num);
      try { $db->prepare('INSERT INTO fact_numeros (emisor, serie, numero, n, factura, creado) VALUES (?,?,?,?,?,?)')->execute([$eid, $f['serie'], $num, $n, $id, ms()]); }
      catch (PDOException $e) { if ((int) ($e->errorInfo[1] ?? 0) === 1062) f_no('El número ' . $num . ' ya está registrado en la serie ' . $f['serie'] . '. Revisa el que te ha dado el programa.', 409); throw $e; }
      if ($n !== null && $n > (int) ($se['ultimo'] ?? 0)) { $em['series'][$f['serie']]['ultimo'] = $n; saveDoc('emisores', $eid, '', $em, $u); }
      $prog = (string) $em['sistema']['programa'];
      $f = array_merge($f, ['totales' => $tot, 'emisor' => f_snap($em), 'numero' => $num, 'fechaExpedicion' => $fecha, 'estado' => 'emitida',
        'emision' => ['modo' => 'externo', 'programa' => $prog, 'por' => $u['nombre'], 'en' => ms()],
        'fiscal' => ['estado' => 'externa', 'texto' => 'Emitida con ' . $prog . '. Crazy HQ solo la registra: no envía nada a Hacienda.'],
        'adjuntos' => $pdf !== '' && is_file(f_dir() . '/' . $pdf . '.pdf') ? [['id' => $pdf, 'nombre' => f_txt($b['pdfNombre'] ?? '', 120) ?: 'factura.pdf', 'por' => $u['nombre'], 'en' => ms()]] : []]);
      $f['historial'] = f_hist($f, $u, 'Registrada como emitida: ' . $num . ' (' . $prog . ')');
      saveDoc('facturas', $id, '', $f, $u); $db->commit(); out(['ok' => true, 'data' => $f]);
    }

    case 'f_anular': { // solo si el registro fue un error; si la factura llegó al cliente, se rectifica
      $u = f_yo(); $b = body(); $id = cleanId($b['id'] ?? ''); $mot = f_txt($b['motivo'] ?? '', 300); if ($mot === '') fail('Escribe el motivo');
      $db->beginTransaction(); $f = f_leer('facturas', $id, true); if (!$f) f_no('No existe', 404);
      if (($f['estado'] ?? '') === 'anulada') { $db->commit(); out(['ok' => true, 'repetida' => true, 'data' => $f]); }
      if (($f['estado'] ?? '') !== 'emitida') f_no('Un borrador se borra, no se anula', 409);
      if (!f_puede(f_leer('emisores', (string) $f['emisorId']) ?? [], ($f['tipo'] ?? '') === 'proforma' ? 'preparar' : 'emitir', $u)) f_no('No puedes anular documentos de ese emisor', 403);
      foreach (f_todas('cobros') as $c) if (($c['facturaId'] ?? '') === $id && empty($c['anulado'])) f_no('Tiene cobros apuntados: anúlalos antes', 409);
      foreach (f_todas('facturas') as $x) if (($x['rectifica']['id'] ?? '') === $id && ($x['estado'] ?? '') !== 'anulada') f_no('Tiene una rectificativa: no se puede anular', 409);
      $db->prepare('DELETE FROM fact_numeros WHERE factura = ?')->execute([$id]);
      $f['estado'] = 'anulada'; $f['anulacion'] = ['motivo' => $mot, 'por' => $u['nombre'], 'en' => ms(), 'numeroLiberado' => (string) ($f['numero'] ?? '')];
      $f['historial'] = f_hist($f, $u, 'Registro anulado: ' . $mot);
      saveDoc('facturas', $id, '', $f, $u); $db->commit(); out(['ok' => true, 'data' => $f]);
    }

    case 'f_cobro': { // el id lo da la app: pulsar dos veces no apunta dos cobros
      $u = f_yo(); $b = body(); $cid = cleanId($b['id'] ?? '') ?: 'c' . newId(); $fid = cleanId($b['facturaId'] ?? '');
      $imp = f_int($b['importe'] ?? 0); $fecha = f_fecha($b['fecha'] ?? ''); $met = in_array($b['metodo'] ?? '', F_MPAGO, true) ? (string) $b['metodo'] : 'transferencia';
      if ($imp <= 0) fail('Pon el importe cobrado'); if ($fecha === '' || $fecha > f_hoy()) fail('La fecha del cobro no puede estar vacía ni ser futura');
      $db->beginTransaction(); $f = f_leer('facturas', $fid, true);
      if (!$f || ($f['estado'] ?? '') !== 'emitida' || ($f['tipo'] ?? '') !== 'factura') f_no('Los cobros se apuntan en facturas emitidas', 409);
      if ($x = f_leer('cobros', $cid)) { $db->commit(); out(['id' => $cid, 'repetido' => true, 'data' => $x]); }
      $em = f_leer('emisores', (string) $f['emisorId']) ?? [];
      if (!f_puede($em, 'preparar', $u) && !f_puede($em, 'emitir', $u)) f_no('No puedes apuntar cobros de ese emisor', 403);
      $ya = 0; foreach (f_todas('cobros') as $c) if (($c['facturaId'] ?? '') === $fid && empty($c['anulado'])) $ya += (int) ($c['importe'] ?? 0);
      $pend = (int) $f['totales']['aCobrar'] - $ya; if ($imp > $pend) f_no('Es más de lo pendiente (' . eur2(max(0, $pend)) . ')', 409);
      $c = ['facturaId' => $fid, 'emisorId' => (string) $f['emisorId'], 'numero' => (string) $f['numero'], 'fecha' => $fecha, 'importe' => $imp, 'metodo' => $met, 'nota' => f_txt($b['nota'] ?? '', 300), 'por' => $u['nombre'], 'en' => ms(), 'anulado' => false];
      saveDoc('cobros', $cid, '', $c, $u); $db->commit(); out(['id' => $cid, 'data' => $c]);
    }

    case 'f_cobro_anular': {
      $u = f_yo(); $b = body(); $cid = cleanId($b['id'] ?? ''); $mot = f_txt($b['motivo'] ?? '', 300); if ($mot === '') fail('Escribe el motivo');
      $db->beginTransaction(); $c = f_leer('cobros', $cid, true); if (!$c) f_no('No existe', 404);
      if (!empty($c['anulado'])) { $db->commit(); out(['ok' => true, 'repetido' => true]); }
      $em = f_leer('emisores', (string) ($c['emisorId'] ?? '')) ?? [];
      if (!f_puede($em, 'preparar', $u) && !f_puede($em, 'emitir', $u)) f_no('No puedes cambiar cobros de ese emisor', 403);
      $c = array_merge($c, ['anulado' => true, 'anuladoPor' => $u['nombre'], 'anuladoEn' => ms(), 'motivoAnulacion' => $mot]);
      saveDoc('cobros', $cid, '', $c, $u); $db->commit(); out(['ok' => true, 'data' => $c]);
    }

    case 'f_subir': { // PDF de la factura emitida con el programa del emisor; se guarda fuera de la web
      f_yo(); $raw = file_get_contents('php://input', false, null, 0, F_PDF_MAX + 1);
      if ($raw === false || $raw === '') fail('No ha llegado el archivo');
      if (strlen($raw) > F_PDF_MAX) fail('El PDF pasa de 10 MB');
      if (!str_starts_with($raw, '%PDF-')) fail('Tiene que ser un PDF');
      $fid = newId(); if (file_put_contents(f_dir() . '/' . $fid . '.pdf', $raw) === false) fail('No se pudo guardar el archivo', 500);
      out(['id' => $fid, 'bytes' => strlen($raw)]);
    }

    case 'f_adjuntar': {
      $u = f_yo(); $b = body(); $id = cleanId($b['id'] ?? ''); $pdf = (string) ($b['pdf'] ?? '');
      if (!preg_match('/^[a-f0-9]{16}$/', $pdf) || !is_file(f_dir() . '/' . $pdf . '.pdf')) fail('Sube primero el PDF');
      $db->beginTransaction(); $f = f_leer('facturas', $id, true); if (!$f || ($f['estado'] ?? '') !== 'emitida') f_no('Solo se adjunta a documentos emitidos', 409);
      $em = f_leer('emisores', (string) $f['emisorId']) ?? [];
      if (!f_puede($em, 'preparar', $u) && !f_puede($em, 'emitir', $u)) f_no('No puedes cambiar documentos de ese emisor', 403);
      $f['adjuntos'] = [...f_arr($f['adjuntos'] ?? null), ['id' => $pdf, 'nombre' => f_txt($b['nombre'] ?? '', 120) ?: 'factura.pdf', 'por' => $u['nombre'], 'en' => ms()]];
      $f['historial'] = f_hist($f, $u, 'PDF adjuntado');
      saveDoc('facturas', $id, '', $f, $u); $db->commit(); out(['ok' => true, 'data' => $f]);
    }

    case 'f_archivo': {
      f_yo(); $fid = (string) ($_GET['id'] ?? '');
      if (!preg_match('/^[a-f0-9]{16}$/', $fid) || !is_file($p = f_dir() . '/' . $fid . '.pdf')) fail('No existe', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="factura-' . $fid . '.pdf"'); header('Content-Length: ' . filesize($p));
      readfile($p); exit;
    }
  }
  fail('Acción desconocida', 404);
}
