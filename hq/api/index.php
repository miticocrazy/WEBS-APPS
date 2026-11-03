<?php
// Crazy Estudio HQ · API (PHP + MySQL). Almacén de documentos + acceso con nombre y PIN.
// Dos espacios: Crazy Estudio (todo) y Vitoreando (proyecto = "Vitoreando"). Los usuarios con rol
// "vitoreando" solo leen y escriben lo de Vitoreando; el filtro va aquí, no en la pantalla.
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/config.php'; // DB_NAME, DB_USER, DB_PASS, SETUP_KEY, ADMIN_NAME, VAPID_*
require __DIR__ . '/push.php';
require __DIR__ . '/fact.php';

const COLS = ['eventos', 'tareas', 'contenido', 'clientes', 'material', 'enlaces', 'votaciones', 'config', 'mio', 'presupuestos', 'facturas', 'emisores', 'cobros', 'trabajos'];
const SOLO_CRAZY = ['clientes', 'material', 'config', 'presupuestos', 'facturas', 'emisores', 'cobros'];
const SOLO_ACCIONES = ['facturas', 'emisores', 'cobros']; // se escriben solo con las acciones f_* (fact.php), que comprueban permisos y numeración
const MAPAS = ['asistencia', 'votos']; // se fusionan clave a clave para que dos personas a la vez no se pisen
const LISTAS = ['puntos', 'acuerdos']; // "+puntos" añade al final en vez de reemplazar la lista
const MAX_DOC = 200000;
// Un presupuesto aceptado es el acuerdo con el cliente: solo se puede enlazar a su trabajo o marcar como sustituido por otra versión.
const ACEPTADO_LIBRES = ['trabajoId', 'actualizado', 'actualizadoPor', 'sustituidoPor'];
const SESSION_DAYS = 365;

function pdo(): PDO {
  static $p = null;
  if (!$p) {
    $p = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
  }
  return $p;
}
function out($d, int $c = 200): void { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): void { out(['error' => $m], $c); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($j) ? $j : []; }
function ms(): int { return (int) round(microtime(true) * 1000); }
function cleanName($n): string { $n = trim((string) $n); return mb_substr(preg_replace('/[\x00-\x1F<>]/u', '', $n), 0, 40); }
function cleanId($id): string { $id = (string) $id; return preg_match('/^[A-Za-z0-9_\-.]{1,80}$/', $id) ? $id : ''; }
function newId(): string { return bin2hex(random_bytes(8)); }

function bearer(): string {
  $h = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  return preg_match('/([a-f0-9]{64})$/i', trim($h), $m) ? strtolower($m[1]) : '';
}
function me(): array {
  $t = bearer();
  if (!$t) fail('login', 401);
  $s = pdo()->prepare('SELECT u.nombre, u.admin, u.rol FROM sessions s JOIN users u ON u.nombre = s.nombre WHERE s.token_hash = ? AND s.creado > ?');
  $s->execute([hash('sha256', $t), ms() - SESSION_DAYS * 86400000]);
  $u = $s->fetch();
  if (!$u) fail('login', 401);
  return ['nombre' => $u['nombre'], 'admin' => (bool) $u['admin'], 'rol' => $u['rol']];
}
function requireAdmin(): array { $u = me(); if (!$u['admin']) fail('Solo el administrador puede hacer esto', 403); return $u; }
function vito(array $u): bool { return $u['rol'] === 'vitoreando'; }
function ownerFor(string $col, array $u): string { return $col === 'mio' ? $u['nombre'] : ''; }
function checkCol($c, array $u): string {
  $c = (string) $c;
  if (!in_array($c, COLS, true)) fail('Colección no válida');
  if ($c === 'config' && !$u['admin']) fail('Solo el administrador cambia los ajustes', 403);
  if (vito($u) && in_array($c, SOLO_CRAZY, true)) fail('Esto es solo de Crazy Estudio', 403);
  if (in_array($c, SOLO_ACCIONES, true)) fail('Esto se guarda desde Facturación', 403);
  return $c;
}
function presu_aceptado(?array $d): bool { return $d !== null && (($d['estado'] ?? '') === 'aceptado' || !empty($d['aceptada'])); }
function presu_cambia(?array $prev, array $data): bool {
  if (!presu_aceptado($prev)) return false;
  $quita = array_flip(ACEPTADO_LIBRES);
  return array_diff_key($prev, $quita) != array_diff_key($data, $quita);
}
function espacio(string $col, array $data): string {
  return !in_array($col, SOLO_CRAZY, true) && ($data['proyecto'] ?? '') === 'Vitoreando' ? 'vitoreando' : 'crazy';
}
// Fila actual; un usuario de Vitoreando no "ve" filas de Crazy, ni siquiera para sobrescribirlas.
function current_row(string $col, string $id, string $owner, array $u, bool $lock = false): ?array {
  $s = pdo()->prepare('SELECT data, espacio FROM docs WHERE col = ? AND id = ? AND owner = ? AND deleted = 0' . ($lock ? ' FOR UPDATE' : ''));
  $s->execute([$col, $id, $owner]);
  $r = $s->fetch();
  if ($r && vito($u) && $col !== 'mio' && $r['espacio'] !== 'vitoreando') fail('Esto es solo de Crazy Estudio', 403);
  return $r ?: null;
}
function saveDoc(string $col, string $id, string $owner, array $data, array $u): int {
  if (vito($u) && $col !== 'mio') $data['proyecto'] = 'Vitoreando';
  $j = json_encode($data, JSON_UNESCAPED_UNICODE);
  if (strlen($j) > MAX_DOC) fail('Documento demasiado grande');
  $t = ms();
  pdo()->prepare('INSERT INTO docs (col, id, owner, data, updated, deleted, espacio) VALUES (?,?,?,?,?,0,?)
    ON DUPLICATE KEY UPDATE data = VALUES(data), updated = VALUES(updated), deleted = 0, espacio = VALUES(espacio)')
    ->execute([$col, $id, $owner, $j, $t, espacio($col, $data)]);
  return $t;
}
function hasColumn(string $t, string $c): bool {
  $s = pdo()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
  $s->execute([$t, $c]);
  return (bool) $s->fetchColumn();
}
function ics(string $s): string { return str_replace(["\\", ";", ",", "\r", "\n"], ["\\\\", "\\;", "\\,", '', "\\n"], $s); }
// shortcut: pliega por caracteres (36), no por bytes; vale para texto normal, revisar si hay textos muy largos con emojis.
function icsFold(string $line): string { return implode("\r\n ", mb_str_split($line, 36)); }

/* ---------- avisos (notificaciones push) ---------- */
const DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
function madrid(): DateTime { return new DateTime('now', new DateTimeZone('Europe/Madrid')); }
function fechaTxt(string $f, string $h = ''): string {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return 'sin fecha';
  $hoy = madrid()->format('Y-m-d'); $man = madrid()->modify('+1 day')->format('Y-m-d'); $d = new DateTime($f);
  return ($f === $hoy ? 'hoy' : ($f === $man ? 'mañana' : DIAS[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . MESES[(int) $d->format('n') - 1])) . ($h ? ' ' . $h : '');
}
function usuarios(): array { static $u = null; return $u ??= pdo()->query('SELECT nombre, rol FROM users')->fetchAll(PDO::FETCH_KEY_PAIR); }
// ¿Puede ver esa persona algo de ese espacio? Lo de Crazy, solo el equipo de Crazy; lo de Vitoreando, todos.
function ve(string $n, string $esp): bool { $r = usuarios()[$n] ?? null; return $r !== null && ($r === 'crazy' || $esp === 'vitoreando'); }
// Fuera temporalmente (ajustes 'fuera' = {nombre: {desde, hasta, por}}): no se le espera ni se le recuerda nada. Vuelve solo al pasar "hasta".
function fuera(): array {
  static $f = null; if ($f !== null) return $f;
  $j = json_decode((string) (aj('fuera') ?? '{}'), true) ?: []; $hoy = madrid()->format('Y-m-d');
  return $f = array_filter($j, fn($x) => empty($x['hasta']) || $x['hasta'] >= $hoy);
}
// En una grabación con convocatoria (equipo), solo se espera y se avisa a quien va.
function esperados_ev(array $d, string $esp): array {
  $L = array_values(array_filter(array_map(fn($p) => is_array($p) ? (string) ($p['n'] ?? '') : '', (array) ($d['equipo'] ?? []))));
  $base = esperados($esp);
  return $L ? array_values(array_intersect($L, $base)) : $base;
}
function esperados(string $esp): array { return array_values(array_filter(array_keys(usuarios()), fn($n) => ve($n, $esp) && !isset(fuera()[$n]))); }

$QUEUE = [];
// Apunta el aviso (con $clave nunca se repite) y lo envía al terminar la petición, sin hacer esperar a quien guarda.
function avisar(string $para, string $titulo, string $cuerpo, string $url, string $de = '', string $tipo = '', ?string $clave = null): bool {
  global $QUEUE;
  $s = pdo()->prepare('INSERT IGNORE INTO avisos (clave, para, de, tipo, titulo, creado) VALUES (?,?,?,?,?,?)');
  $s->execute([$clave, $para, $de, $tipo, mb_substr($titulo, 0, 190), ms()]);
  if (!$s->rowCount()) return false;
  $QUEUE[] = [$para, ['title' => $titulo, 'body' => $cuerpo, 'url' => $url, 'tag' => $tipo ?: null]];
  return true;
}
$LATER = []; // otros trabajos para después de responder (p. ej. actualizar Google Calendar)
register_shutdown_function(function () {
  global $QUEUE, $LATER;
  if (!$QUEUE && !$LATER) return;
  if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  foreach ($LATER as $fn) { try { $fn(); } catch (Throwable $e) { error_log('crazyhq later: ' . $e->getMessage()); } }
  if (!$QUEUE) return;
  try {
    $subs = pdo()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subs WHERE nombre = ?');
    $mark = pdo()->prepare('UPDATE push_subs SET ultimo = ?, ultimo_at = ? WHERE id = ?');
    $del = pdo()->prepare('DELETE FROM push_subs WHERE id = ?');
    foreach ($QUEUE as [$para, $msg]) {
      $subs->execute([$para]);
      foreach ($subs->fetchAll() as $s) {
        try { $c = wp_send($s['endpoint'], $s['p256dh'], $s['auth'], $msg); } catch (Throwable $e) { $c = -1; error_log('crazyhq push: ' . $e->getMessage()); }
        if ($c === 404 || $c === 410) $del->execute([$s['id']]); else $mark->execute([$c, ms(), $s['id']]); // 404/410 = el móvil ya no existe
      }
    }
  } catch (Throwable $e) { error_log('crazyhq push: ' . $e->getMessage()); }
});
// Avisos que salen solos al guardar.
function con_historial(string $col, ?array $prev, array $data, array $u): array {
  if ($col !== 'contenido') return $data;
  $h = $prev['historial'] ?? [];
  if ($prev === null || ($prev['etapa'] ?? '') !== ($data['etapa'] ?? '')) $h[] = ['etapa' => (string) ($data['etapa'] ?? 'Idea'), 't' => ms(), 'por' => $u['nombre']];
  $data['historial'] = array_slice($h, -40);
  return $data;
}
function after_save(string $col, ?array $prev, array $data, array $u): void {
  $me = $u['nombre']; $esp = espacio($col, $data);
  $gid = (string) ($data['gcal_id'] ?? '');
  if ($col === 'eventos' && $gid !== '' && $prev !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['fecha'] ?? ''))) {
    $campos = fn($x) => [$x['titulo'] ?? '', $x['fecha'] ?? '', $x['hora'] ?? '', $x['lugar'] ?? '']; // no al confirmar: mandaría un correo a todos cada vez
    if ($campos($prev) !== $campos($data)) { global $LATER; $LATER[] = fn() => g_api('PATCH', '/' . rawurlencode($gid) . '?sendUpdates=all', g_evento($data, $esp, false, '')); }
  }
  if ($col === 'tareas') {
    $r = (string) ($data['responsable'] ?? '');
    if ($r !== '' && $r !== $me && empty($data['hecho']) && ($prev === null || ($prev['responsable'] ?? '') !== $r) && ve($r, $esp))
      avisar($r, $me . ' te ha puesto una tarea', ($data['titulo'] ?? '') . (!empty($data['fecha']) ? ' · para ' . fechaTxt($data['fecha']) : ''), './#inicio', $me, 'tarea');
  }
  $cli = (string) ($data['cliente'] ?? '');
  if ($cli !== '' && $col === 'contenido' && ($prev === null || ($prev['etapa'] ?? '') !== ($data['etapa'] ?? ''))) {
    $e = (string) ($data['etapa'] ?? 'Idea');
    avisar('c:' . $cli, $e === 'Publicado' ? '¡Publicado! ' . ($data['titulo'] ?? '') : ($data['titulo'] ?? 'Tu contenido') . ' → ' . $e, $e === 'Publicado' ? 'Ya está en redes. Entra para verlo.' : 'Tu contenido ha pasado a ' . mb_strtolower($e) . '.', './', $me, 'cliente');
  }
  if ($cli !== '' && $col === 'eventos' && $prev === null && ($data['fecha'] ?? '') >= madrid()->format('Y-m-d'))
    avisar('c:' . $cli, (($data['tipo'] ?? '') === 'Reunión' ? 'Reunión' : 'Grabación') . ' programada', ($data['titulo'] ?? '') . ' · ' . fechaTxt($data['fecha'], (string) ($data['hora'] ?? '')) . (!empty($data['lugar']) ? ' · ' . $data['lugar'] : ''), './', $me, 'cliente');
  if ($col === 'eventos' && $prev === null && ($data['fecha'] ?? '') >= madrid()->format('Y-m-d')) {
    $tit = ['Grabación' => 'Nueva grabación', 'Reunión' => 'Nueva reunión'][$data['tipo'] ?? ''] ?? 'Nuevo en la agenda';
    foreach (esperados_ev($data, $esp) as $n) if ($n !== $me)
      avisar($n, $tit . ': ' . ($data['titulo'] ?? ''), fechaTxt($data['fecha'], (string) ($data['hora'] ?? '')) . (!empty($data['lugar']) ? ' · ' . $data['lugar'] : '') . ' · ¿Vas?', './#agenda', $me, 'evento');
  }
}
// Lo que tiene pendiente una persona (solo lo que puede ver).
function pendiente(string $n): array {
  $hoy = madrid()->format('Y-m-d'); $p = ['tareas' => 0, 'atrasadas' => 0, 'sinConf' => 0, 'hoy' => []];
  foreach (pdo()->query("SELECT col, data, espacio FROM docs WHERE deleted = 0 AND owner = '' AND col IN ('eventos','tareas')") as $r) {
    $d = json_decode($r['data'], true) ?: [];
    if (!ve($n, $r['espacio'])) continue;
    if ($r['col'] === 'tareas') {
      if (($d['responsable'] ?? '') !== $n || !empty($d['hecho'])) continue;
      $p['tareas']++; if (($d['fecha'] ?? '') !== '' && $d['fecha'] < $hoy) $p['atrasadas']++;
    } else {
      $f = (string) ($d['fecha'] ?? ''); $mia = ($d['asistencia'] ?? [])[$n] ?? null;
      if ($f >= $hoy && $mia === null) $p['sinConf']++;
      if ($f === $hoy && $mia !== 'no') $p['hoy'][] = ($d['tipo'] ?? 'Evento') . (!empty($d['hora']) ? ' ' . $d['hora'] : '') . ': ' . ($d['titulo'] ?? '');
    }
  }
  return $p;
}
function pendienteTxt(array $p): array {
  $x = $p['hoy'];
  if ($p['tareas']) $x[] = $p['tareas'] . ' tarea' . ($p['tareas'] > 1 ? 's' : '') . ($p['atrasadas'] ? ' (' . $p['atrasadas'] . ' atrasada' . ($p['atrasadas'] > 1 ? 's' : '') . ')' : '');
  if ($p['sinConf']) $x[] = $p['sinConf'] . ' sin confirmar';
  return $x;
}

/* ---------- Google Calendar + Meet (una cuenta del estudio organiza; el equipo recibe la invitación) ---------- */
const G_REDIRECT = 'https://crazyestudio.online/hq/api/google.php';
function aj(string $k, ?string $v = null, bool $borrar = false): ?string {
  if ($borrar) { pdo()->prepare('DELETE FROM ajustes WHERE k = ?')->execute([$k]); return null; }
  if ($v !== null) { pdo()->prepare('INSERT INTO ajustes (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]); return $v; }
  $s = pdo()->prepare('SELECT v FROM ajustes WHERE k = ?'); $s->execute([$k]); $r = $s->fetchColumn(); return $r === false ? null : $r;
}
function http_json(string $method, string $url, $body = null, array $headers = []): array {
  $ch = curl_init($url);
  $form = is_array($body) && isset($body['__form']);
  if ($form) unset($body['__form']);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => array_merge($headers, $body === null ? [] : [$form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json'])]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_UNICODE));
  $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, json_decode((string) $r, true) ?: []];
}
function g_token(): string {
  $c = json_decode((string) aj('g_access'), true);
  if ($c && $c['exp'] > time() + 60) return $c['t'];
  $rt = aj('g_refresh'); if (!$rt) throw new RuntimeException('Google no está conectado');
  [$code, $j] = http_json('POST', 'https://oauth2.googleapis.com/token', ['__form' => 1, 'client_id' => aj('g_client_id'), 'client_secret' => aj('g_client_secret'), 'refresh_token' => $rt, 'grant_type' => 'refresh_token']);
  if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('Google ha desconectado la cuenta. Vuelve a conectarla en Ajustes');
  aj('g_access', json_encode(['t' => $j['access_token'], 'exp' => time() + (int) ($j['expires_in'] ?? 3000)]));
  return $j['access_token'];
}
function g_api(string $method, string $path, $body = null): array {
  return http_json($method, 'https://www.googleapis.com/calendar/v3/calendars/primary/events' . $path, $body, ['Authorization: Bearer ' . g_token()]);
}
// Evento de Google a partir de la reunión de la app; invita a quien tiene que ir y tiene Gmail.
function g_evento(array $d, string $esp, bool $nuevo, string $id): array {
  $f = (string) ($d['fecha'] ?? ''); $hr = (string) ($d['hora'] ?? '');
  if (preg_match('/^\d{2}:\d{2}$/', $hr)) {
    $ini = new DateTime("$f $hr", new DateTimeZone('Europe/Madrid')); $fin = (clone $ini)->modify('+1 hour');
    $t = ['start' => ['dateTime' => $ini->format('c'), 'timeZone' => 'Europe/Madrid'], 'end' => ['dateTime' => $fin->format('c'), 'timeZone' => 'Europe/Madrid']];
  } else $t = ['start' => ['date' => $f], 'end' => ['date' => (new DateTime($f))->modify('+1 day')->format('Y-m-d')]];
  $emails = pdo()->query("SELECT nombre, email FROM users WHERE email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
  $inv = [];
  foreach (esperados_ev($d, $esp) as $n) if (!empty($emails[$n]) && ((($d['asistencia'] ?? [])[$n] ?? '') !== 'no')) $inv[] = ['email' => $emails[$n]];
  $ev = $t + ['summary' => (string) ($d['titulo'] ?? 'Reunión'), 'location' => (string) ($d['lugar'] ?? ''), 'attendees' => $inv,
    'description' => 'Creada desde Crazy HQ · https://crazyestudio.es/hq/'];
  if ($nuevo) $ev['conferenceData'] = ['createRequest' => ['requestId' => 'chq-' . $id . '-' . bin2hex(random_bytes(4)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
  return $ev;
}

/* ---------- portal de clientes ---------- */
const ETAPAS = ['Idea', 'Guion', 'Grabación', 'Edición', 'Revisión', 'Programado', 'Publicado'];
const EQUIPO_ROL = ['Paulo' => 'Dirección y contenido', 'Laguntzo' => 'Filmmaker', 'Edu' => 'Técnico y edición', 'Naroa' => 'Periodista', 'Víctor' => 'Vitoreando', 'Kimetz' => 'Vitoreando'];
function cliente_me(): array {
  $t = bearer(); if (!$t) fail('login', 401);
  $s = pdo()->prepare('SELECT p.cliente_id, p.usuario, p.cambiada FROM portal_sessions s JOIN portal_users p ON p.cliente_id = s.cliente_id WHERE s.token_hash = ? AND s.creado > ?');
  $s->execute([hash('sha256', $t), ms() - SESSION_DAYS * 86400000]);
  $c = $s->fetch(); if (!$c) fail('login', 401);
  return $c;
}
function slug(string $s): string {
  $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
  return substr(trim(preg_replace('/[^a-z0-9]+/', '', $s), '-'), 0, 16) ?: 'cliente';
}
function clave_facil(): string { // fácil de dictar: palabra + 4 números
  $p = ['play', 'rec', 'foco', 'plano', 'toma', 'luz', 'reel', 'corte', 'clip', 'zoom'];
  return $p[random_int(0, count($p) - 1)] . '-' . random_int(1000, 9999);
}
// Solo lo que el cliente puede ver: nunca notas internas, importes, tareas ni enlaces internos.
function portal_datos(string $cid): array {
  $s = pdo()->prepare("SELECT data FROM docs WHERE col = 'clientes' AND id = ? AND owner = '' AND deleted = 0"); $s->execute([$cid]);
  $c = json_decode((string) $s->fetchColumn(), true);
  if (!$c) fail('login', 401);
  $hoy = madrid()->format('Y-m-d'); $piezas = []; $grab = []; $gente = [];
  foreach (pdo()->query("SELECT col, id, data, espacio FROM docs WHERE deleted = 0 AND owner = '' AND col IN ('contenido','eventos')") as $r) {
    $d = json_decode($r['data'], true) ?: [];
    if (($d['cliente'] ?? '') !== $cid) continue;
    if ($r['col'] === 'contenido') {
      $resp = (string) ($d['responsable'] ?? ''); if ($resp !== '') $gente[$resp] = true;
      $hist = array_map(fn($x) => ['etapa' => $x['etapa'] ?? '', 't' => $x['t'] ?? 0, 'por' => $x['por'] ?? ''], (array) ($d['historial'] ?? []));
      foreach ($hist as $x) if ($x['por'] !== '') $gente[$x['por']] = true;
      $url = (string) ($d['enlacePublico'] ?? '');
      $piezas[] = ['id' => $r['id'], 'titulo' => (string) ($d['titulo'] ?? ''), 'etapa' => in_array($d['etapa'] ?? '', ETAPAS, true) ? $d['etapa'] : 'Idea',
        'responsable' => $resp, 'fecha' => (string) ($d['fecha'] ?? ''), 'plataformas' => array_values((array) ($d['plataformas'] ?? [])),
        'publicadoEn' => array_values((array) ($d['publicadoEn'] ?? [])), 'enlace' => preg_match('#^https?://#i', $url) ? $url : '',
        'nota' => (string) ($d['notaCliente'] ?? ''), 'vitoreando' => $r['espacio'] === 'vitoreando', 'historial' => $hist];
    } elseif (($d['fecha'] ?? '') >= $hoy) {
      $van = array_keys(array_filter((array) ($d['asistencia'] ?? []), fn($v) => $v === 'si'));
      foreach ($van as $n) $gente[$n] = true;
      $grab[] = ['titulo' => (string) ($d['titulo'] ?? ''), 'tipo' => (string) ($d['tipo'] ?? ''), 'fecha' => (string) $d['fecha'], 'hora' => (string) ($d['hora'] ?? ''), 'lugar' => (string) ($d['lugar'] ?? ''), 'equipo' => $van];
    }
  }
  usort($grab, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
  $equipo = array_map(fn($n) => ['nombre' => $n, 'rol' => EQUIPO_ROL[$n] ?? 'Equipo'], array_values(array_filter(array_keys($gente), fn($n) => isset(usuarios()[$n]))));
  $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($c['color'] ?? '')) && strtolower($c['color']) !== '#000000' ? $c['color'] : '';
  return ['cliente' => ['nombre' => (string) ($c['nombre'] ?? ''), 'estado' => (string) ($c['estado'] ?? ''), 'color' => $color], 'piezas' => $piezas, 'grabaciones' => $grab, 'equipo' => $equipo, 'etapas' => ETAPAS, 'ahora' => ms()];
}

$a = PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['a'] ?? ''); // la tarea programada llama: php index.php cron
try {
  switch ($a) {

    case 'install': {
      if (!hash_equals(SETUP_KEY, (string) ($_GET['key'] ?? ''))) fail('No autorizado', 403);
      $db = pdo();
      $db->exec('CREATE TABLE IF NOT EXISTS users (nombre VARCHAR(40) PRIMARY KEY, pin_hash VARCHAR(255) NULL, admin TINYINT NOT NULL DEFAULT 0, fallos INT NOT NULL DEFAULT 0, bloqueo BIGINT NOT NULL DEFAULT 0, orden INT NOT NULL DEFAULT 0) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      $db->exec('CREATE TABLE IF NOT EXISTS sessions (token_hash CHAR(64) PRIMARY KEY, nombre VARCHAR(40) NOT NULL, creado BIGINT NOT NULL, INDEX (nombre)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      $db->exec('CREATE TABLE IF NOT EXISTS docs (col VARCHAR(20) NOT NULL, id VARCHAR(80) NOT NULL, owner VARCHAR(40) NOT NULL DEFAULT \'\', data MEDIUMTEXT NOT NULL, updated BIGINT NOT NULL, deleted TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (col, id, owner), INDEX (updated)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      // Migración v2: roles, espacios y calendario.
      if (!hasColumn('users', 'rol')) {
        $db->exec("ALTER TABLE users ADD COLUMN rol VARCHAR(12) NOT NULL DEFAULT 'crazy', ADD COLUMN feed_key CHAR(32) NULL");
        $db->exec("UPDATE users SET rol = 'vitoreando' WHERE nombre IN ('Víctor', 'Kimetz')");
      }
      if (!hasColumn('docs', 'espacio')) {
        $db->exec("ALTER TABLE docs ADD COLUMN espacio VARCHAR(12) NOT NULL DEFAULT 'crazy', ADD INDEX (espacio)");
        $up = $db->prepare('UPDATE docs SET espacio = ? WHERE col = ? AND id = ? AND owner = ?');
        foreach ($db->query('SELECT col, id, owner, data FROM docs') as $r)
          $up->execute([espacio($r['col'], json_decode($r['data'], true) ?: []), $r['col'], $r['id'], $r['owner']]);
      }
      // Migración v3: avisos al móvil.
      $db->exec('CREATE TABLE IF NOT EXISTS push_subs (id CHAR(64) PRIMARY KEY, nombre VARCHAR(40) NOT NULL, endpoint TEXT NOT NULL, p256dh VARCHAR(120) NOT NULL, auth VARCHAR(40) NOT NULL, creado BIGINT NOT NULL, ultimo INT NULL, ultimo_at BIGINT NULL, INDEX (nombre)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      $db->exec("CREATE TABLE IF NOT EXISTS avisos (id BIGINT AUTO_INCREMENT PRIMARY KEY, clave VARCHAR(160) NULL UNIQUE, para VARCHAR(40) NOT NULL, de VARCHAR(40) NOT NULL DEFAULT '', tipo VARCHAR(20) NOT NULL DEFAULT '', titulo VARCHAR(200) NOT NULL DEFAULT '', creado BIGINT NOT NULL, INDEX (para, creado)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      // Migración v5: Google (ajustes del estudio y Gmail de cada persona).
      $db->exec('CREATE TABLE IF NOT EXISTS ajustes (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      if (!hasColumn('users', 'email')) $db->exec('ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL');
      // Migración v4: portal de clientes.
      $db->exec('CREATE TABLE IF NOT EXISTS portal_users (cliente_id VARCHAR(80) PRIMARY KEY, usuario VARCHAR(40) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, cambiada TINYINT NOT NULL DEFAULT 0, fallos INT NOT NULL DEFAULT 0, bloqueo BIGINT NOT NULL DEFAULT 0, creado BIGINT NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      $db->exec('CREATE TABLE IF NOT EXISTS portal_sessions (token_hash CHAR(64) PRIMARY KEY, cliente_id VARCHAR(80) NOT NULL, creado BIGINT NOT NULL, INDEX (cliente_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      // Migración v6: facturación (números registrados por emisor y serie; nunca dos iguales).
      $db->exec('CREATE TABLE IF NOT EXISTS fact_numeros (emisor VARCHAR(40) NOT NULL, serie VARCHAR(20) NOT NULL, numero VARCHAR(40) NOT NULL, n INT NULL, factura VARCHAR(80) NOT NULL, creado BIGINT NOT NULL, PRIMARY KEY (emisor, serie, numero), UNIQUE KEY (factura)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      f_semillas(); f_dir();
      if (!(int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
        $ins = $db->prepare('INSERT INTO users (nombre, admin, orden, rol) VALUES (?,?,?,?)');
        foreach (['Paulo', 'Laguntzo', 'Edu', 'Naroa', 'Víctor', 'Kimetz'] as $i => $n)
          $ins->execute([$n, $n === ADMIN_NAME ? 1 : 0, $i, in_array($n, ['Víctor', 'Kimetz'], true) ? 'vitoreando' : 'crazy']);
      }
      out(['ok' => true, 'usuarios' => $db->query('SELECT nombre, rol FROM users ORDER BY orden')->fetchAll(),
        'espacios' => $db->query('SELECT espacio, COUNT(*) n FROM docs WHERE deleted = 0 GROUP BY espacio')->fetchAll()]);
    }

    case 'team': {
      $r = pdo()->query('SELECT nombre, admin, rol, pin_hash IS NOT NULL AS tienePin FROM users ORDER BY orden, nombre')->fetchAll();
      out(['team' => array_map(fn($x) => ['nombre' => $x['nombre'], 'admin' => (bool) $x['admin'], 'rol' => $x['rol'], 'tienePin' => (bool) $x['tienePin'], 'fuera' => isset(fuera()[$x['nombre']]), 'fueraHasta' => (string) (fuera()[$x['nombre']]['hasta'] ?? '')], $r)]);
    }

    case 'login': {
      $b = body(); $nombre = cleanName($b['nombre'] ?? ''); $pin = (string) ($b['pin'] ?? '');
      if (!preg_match('/^\d{4,8}$/', $pin)) fail('El PIN son 4 números');
      $s = pdo()->prepare('SELECT * FROM users WHERE nombre = ?'); $s->execute([$nombre]); $u = $s->fetch();
      if (!$u) fail('No existe ese nombre', 404);
      if ($u['bloqueo'] > ms()) fail('Demasiados intentos. Espera ' . max(1, (int) ceil(($u['bloqueo'] - ms()) / 60000)) . ' min', 429);
      if ($u['pin_hash'] === null) {
        // Solo el administrador estrena su PIN él mismo; al resto se lo pone el administrador.
        if (!$u['admin']) fail('Aún no tienes PIN. Pídeselo a ' . ADMIN_NAME, 403);
        pdo()->prepare('UPDATE users SET pin_hash = ? WHERE nombre = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $nombre]);
      } elseif (!password_verify($pin, $u['pin_hash'])) {
        // Cada 5 fallos seguidos, un bloqueo que se duplica (5, 10, 20 min… hasta 24 h): así 4 cifras no se pueden adivinar.
        $f = $u['fallos'] + 1;
        $lock = $f % 5 === 0 ? ms() + min(86400000, 300000 * 2 ** (intdiv($f, 5) - 1)) : 0;
        pdo()->prepare('UPDATE users SET fallos = ?, bloqueo = ? WHERE nombre = ?')->execute([$f, $lock, $nombre]);
        fail('PIN incorrecto', 401);
      }
      pdo()->prepare('UPDATE users SET fallos = 0, bloqueo = 0 WHERE nombre = ?')->execute([$nombre]);
      $tok = bin2hex(random_bytes(32));
      pdo()->prepare('INSERT INTO sessions (token_hash, nombre, creado) VALUES (?,?,?)')->execute([hash('sha256', $tok), $nombre, ms()]);
      out(['token' => $tok, 'nombre' => $nombre, 'admin' => (bool) $u['admin'], 'rol' => $u['rol']]);
    }

    case 'logout': {
      $t = bearer(); if ($t) pdo()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $t)]);
      out(['ok' => true]);
    }

    case 'sync': {
      $u = me(); $since = max(0, (int) ($_GET['since'] ?? 0));
      $now = ms();
      $s = pdo()->prepare('SELECT col, id, data, deleted, espacio FROM docs WHERE updated > ? AND (owner = \'\' OR (col = \'mio\' AND owner = ?)) ORDER BY updated');
      $s->execute([$since, $u['nombre']]);
      $docs = [];
      foreach ($s as $r) {
        // Para Vitoreando, lo de Crazy llega como "borrado": así también desaparece lo que se pasa de un espacio a otro.
        $hide = $r['deleted'] || (vito($u) && $r['col'] !== 'mio' && $r['espacio'] !== 'vitoreando');
        $docs[] = ['col' => $r['col'], 'id' => $r['id'], 'deleted' => $hide, 'data' => $hide ? null : json_decode($r['data'], true)];
      }
      // Para el administrador: quién ha entrado alguna vez (para avisar a quien falta).
      $extra = $u['admin'] ? ['entrados' => pdo()->query('SELECT DISTINCT nombre FROM sessions')->fetchAll(PDO::FETCH_COLUMN),
        'avisos' => pdo()->query('SELECT nombre, COUNT(*) FROM push_subs GROUP BY nombre')->fetchAll(PDO::FETCH_KEY_PAIR)] : [];
      $s2 = pdo()->prepare('SELECT email FROM users WHERE nombre = ?'); $s2->execute([$u['nombre']]);
      $extra['google'] = (bool) aj('g_refresh'); $extra['email'] = (string) $s2->fetchColumn();
      if ($u['admin']) $extra += ['gconf' => (bool) aj('g_client_id'), 'gcuenta' => (string) aj('g_cuenta'), 'emails' => pdo()->query("SELECT nombre, email FROM users WHERE email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_KEY_PAIR)];
      if (!vito($u)) $extra['portal'] = pdo()->query('SELECT cliente_id, usuario FROM portal_users')->fetchAll(PDO::FETCH_KEY_PAIR);
      out(['now' => $now, 'docs' => $docs, 'me' => $u] + $extra);
    }

    case 'put': {
      $u = me(); $b = body(); $col = checkCol($b['col'] ?? '', $u);
      $id = cleanId($b['id'] ?? '') ?: newId(); $own = ownerFor($col, $u);
      $prev = current_row($col, $id, $own, $u);
      $pd = $prev ? (json_decode($prev['data'], true) ?: []) : null;
      if ($col === 'presupuestos' && presu_cambia($pd, (array) ($b['data'] ?? []))) fail('Este presupuesto está aceptado y no se puede cambiar. Haz una versión nueva.', 409);
      $data = con_historial($col, $pd, (array) ($b['data'] ?? []), $u);
      $t = saveDoc($col, $id, $own, $data, $u);
      after_save($col, $pd, $data, $u);
      out(['id' => $id, 'updated' => $t]);
    }

    case 'patch': {
      $u = me(); $b = body(); $col = checkCol($b['col'] ?? '', $u); $id = cleanId($b['id'] ?? '');
      if (!$id) fail('Falta id');
      $own = ownerFor($col, $u);
      $db = pdo(); $db->beginTransaction();
      $r = current_row($col, $id, $own, $u, true);
      if (!$r) { $db->rollBack(); fail('No existe', 404); }
      $data = json_decode($r['data'], true) ?: []; $old = $data;
      foreach ((array) ($b['patch'] ?? []) as $k => $v) {
        if (is_string($k) && str_starts_with($k, '+') && in_array(substr($k, 1), LISTAS, true) && is_array($v)) { $data[substr($k, 1)][] = $v; continue; }
        $data[$k] = in_array($k, MAPAS, true) && is_array($v)
          ? array_filter(array_merge((array) ($data[$k] ?? []), $v), fn($x) => $x !== null)
          : $v;
      }
      if ($col === 'presupuestos' && presu_cambia($old, $data)) { $db->rollBack(); fail('Este presupuesto está aceptado y no se puede cambiar. Haz una versión nueva.', 409); }
      $data = con_historial($col, $old, $data, $u);
      $t = saveDoc($col, $id, $own, $data, $u);
      $db->commit();
      after_save($col, $old, $data, $u);
      out(['id' => $id, 'updated' => $t]);
    }

    case 'del': {
      $u = me(); $b = body(); $col = checkCol($b['col'] ?? '', $u); $id = cleanId($b['id'] ?? ''); $own = ownerFor($col, $u);
      $r = current_row($col, $id, $own, $u);
      if ($r && $col === 'presupuestos' && presu_aceptado(json_decode($r['data'], true) ?: [])) fail('Un presupuesto aceptado no se borra: es el acuerdo con el cliente.', 409);
      $gid = $r ? (string) ((json_decode($r['data'], true) ?: [])['gcal_id'] ?? '') : '';
      if ($gid !== '' && aj('g_refresh')) { global $LATER; $LATER[] = fn() => g_api('DELETE', '/' . rawurlencode($gid) . '?sendUpdates=all'); } // avisa a todos de que se cancela
      pdo()->prepare('UPDATE docs SET deleted = 1, updated = ?, data = \'{}\' WHERE col = ? AND id = ? AND owner = ?')->execute([ms(), $col, $id, $own]);
      out(['ok' => true]);
    }

    case 'feedkey': { // enlace privado de calendario (solo lectura); ?nuevo=1 lo cambia si se filtra
      $u = me();
      $s = pdo()->prepare('SELECT feed_key FROM users WHERE nombre = ?'); $s->execute([$u['nombre']]);
      $k = $s->fetchColumn();
      if (!$k || ($_GET['nuevo'] ?? '') === '1') {
        $k = bin2hex(random_bytes(16));
        pdo()->prepare('UPDATE users SET feed_key = ? WHERE nombre = ?')->execute([$k, $u['nombre']]);
      }
      out(['key' => $k]);
    }

    case 'feed': {
      $k = (string) ($_GET['k'] ?? '');
      if (!preg_match('/^[a-f0-9]{32}$/', $k)) { http_response_code(404); exit; }
      $s = pdo()->prepare('SELECT nombre, rol FROM users WHERE feed_key = ?'); $s->execute([$k]); $u = $s->fetch();
      if (!$u) { http_response_code(404); exit; }
      $tz = new DateTimeZone('Europe/Madrid'); $utc = new DateTimeZone('UTC');
      $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Crazy Estudio//HQ//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:Crazy HQ · ' . ics($u['nombre']), 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
      $q = pdo()->query("SELECT col, id, data FROM docs WHERE deleted = 0 AND owner = '' AND col IN ('eventos','tareas')" . (vito($u) ? " AND espacio = 'vitoreando'" : ''));
      $stamp = gmdate('Ymd\THis\Z');
      foreach ($q as $r) {
        $d = json_decode($r['data'], true) ?: [];
        $f = (string) ($d['fecha'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) continue;
        if ($r['col'] === 'eventos') {
          if ((($d['asistencia'] ?? [])[$u['nombre']] ?? '') === 'no') continue; // si dice que no va, no le molesta
          $title = (($d['tipo'] ?? '') === 'Grabación' ? '● ' : '') . ($d['titulo'] ?? 'Evento');
          $desc = trim(($d['material'] ?? '') ? 'Llevar: ' . $d['material'] : '');
          $alarm = true;
        } else {
          if (($d['responsable'] ?? '') !== $u['nombre'] || !empty($d['hecho'])) continue; // solo sus tareas pendientes
          $title = '✓ ' . ($d['titulo'] ?? 'Tarea'); $desc = 'Márcala como hecha en la app'; $alarm = false; $d['hora'] = '';
        }
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:' . $r['col'] . '-' . $r['id'] . '@crazyestudio.online';
        $L[] = 'DTSTAMP:' . $stamp;
        if (preg_match('/^\d{2}:\d{2}$/', (string) ($d['hora'] ?? ''))) {
          $start = new DateTime($f . ' ' . $d['hora'], $tz);
          $end = (clone $start)->modify(($d['tipo'] ?? '') === 'Grabación' ? '+2 hours' : '+1 hour');
          $L[] = 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z');
          $L[] = 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z');
        } else {
          $L[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $f);
          $L[] = 'DTEND;VALUE=DATE:' . (new DateTime($f))->modify('+1 day')->format('Ymd');
          $alarm = false;
        }
        $L[] = icsFold('SUMMARY:' . ics($title));
        if (!empty($d['lugar'])) $L[] = icsFold('LOCATION:' . ics($d['lugar']));
        if ($desc !== '') $L[] = icsFold('DESCRIPTION:' . ics($desc));
        $L[] = 'URL:https://crazyestudio.es/hq/';
        if ($alarm) array_push($L, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics($title), 'TRIGGER:-PT1H', 'END:VALARM');
        $L[] = 'END:VEVENT';
      }
      $L[] = 'END:VCALENDAR';
      header('Content-Type: text/calendar; charset=utf-8');
      header('Content-Disposition: inline; filename="crazy-hq.ics"');
      echo implode("\r\n", $L) . "\r\n";
      exit;
    }

    case 'users': {
      requireAdmin();
      out(['users' => pdo()->query('SELECT nombre, admin, rol, pin_hash IS NOT NULL AS tienePin FROM users ORDER BY orden, nombre')->fetchAll()]);
    }

    case 'adduser': {
      requireAdmin(); $b = body(); $n = cleanName($b['nombre'] ?? '');
      if ($n === '') fail('Falta el nombre');
      $rol = ($b['rol'] ?? '') === 'vitoreando' ? 'vitoreando' : 'crazy';
      $o = (int) pdo()->query('SELECT COALESCE(MAX(orden),0)+1 FROM users')->fetchColumn();
      pdo()->prepare('INSERT IGNORE INTO users (nombre, admin, orden, rol) VALUES (?,0,?,?)')->execute([$n, $o, $rol]);
      out(['ok' => true]);
    }

    case 'setrol': {
      $u = requireAdmin(); $b = body(); $n = cleanName($b['nombre'] ?? '');
      if ($n === $u['nombre']) fail('No puedes cambiar tu propio espacio');
      pdo()->prepare('UPDATE users SET rol = ? WHERE nombre = ?')->execute([($b['rol'] ?? '') === 'vitoreando' ? 'vitoreando' : 'crazy', $n]);
      out(['ok' => true]);
    }

    case 'deluser': {
      $u = requireAdmin(); $n = cleanName(body()['nombre'] ?? '');
      if ($n === $u['nombre']) fail('No puedes borrarte a ti mismo');
      pdo()->prepare('DELETE FROM sessions WHERE nombre = ?')->execute([$n]);
      pdo()->prepare('DELETE FROM push_subs WHERE nombre = ?')->execute([$n]);
      pdo()->prepare('DELETE FROM users WHERE nombre = ?')->execute([$n]);
      out(['ok' => true]);
    }

    case 'setfuera': {
      $u = requireAdmin(); $b = body(); $n = cleanName($b['nombre'] ?? ''); $hasta = (string) ($b['hasta'] ?? '');
      if (!isset(usuarios()[$n])) fail('No existe esa persona', 404);
      if ($hasta !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $hasta < madrid()->format('Y-m-d'))) fail('La fecha de vuelta tiene que ser hoy o más adelante');
      $f = json_decode((string) (aj('fuera') ?? '{}'), true) ?: [];
      if (!empty($b['fuera'])) $f[$n] = ['desde' => madrid()->format('Y-m-d'), 'hasta' => $hasta, 'por' => $u['nombre']]; else unset($f[$n]);
      aj('fuera', json_encode($f, JSON_UNESCAPED_UNICODE));
      out(['ok' => true]);
    }

    case 'setpin': {
      $u = me(); $b = body(); $n = cleanName($b['nombre'] ?? $u['nombre']); $pin = (string) ($b['pin'] ?? '');
      if ($n !== $u['nombre'] && !$u['admin']) fail('Solo puedes cambiar tu PIN', 403);
      if (!preg_match('/^\d{4,8}$/', $pin)) fail('El PIN son 4 números');
      pdo()->prepare('UPDATE users SET pin_hash = ?, fallos = 0, bloqueo = 0 WHERE nombre = ?')->execute([password_hash($pin, PASSWORD_DEFAULT), $n]);
      if ($n !== $u['nombre']) pdo()->prepare('DELETE FROM sessions WHERE nombre = ?')->execute([$n]);
      out(['ok' => true]);
    }

    case 'vapid': out(['key' => VAPID_PUBLIC]);

    case 'setemail': { // tu Gmail, para recibir las reuniones en tu calendario (el admin puede poner el de otros)
      $u = me(); $b = body(); $n = cleanName($b['nombre'] ?? $u['nombre']); $e = strtolower(trim((string) ($b['email'] ?? '')));
      if ($n !== $u['nombre'] && !$u['admin']) fail('Solo puedes cambiar tu correo', 403);
      if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Ese correo no parece válido');
      pdo()->prepare('UPDATE users SET email = ? WHERE nombre = ?')->execute([$e ?: null, $n]);
      out(['ok' => true]);
    }

    case 'g_config': {
      requireAdmin(); $b = body(); $id = trim((string) ($b['client_id'] ?? '')); $sec = trim((string) ($b['client_secret'] ?? ''));
      if (!preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $id) || strlen($sec) < 10) fail('Revisa el ID de cliente y el secreto: no tienen el formato de Google');
      aj('g_client_id', $id); aj('g_client_secret', $sec);
      out(['ok' => true]);
    }

    case 'g_conectar': {
      requireAdmin(); if (!aj('g_client_id')) fail('Primero guarda el ID de cliente y el secreto');
      $st = bin2hex(random_bytes(16)); aj('g_state', $st . ':' . time());
      out(['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => aj('g_client_id'), 'redirect_uri' => G_REDIRECT, 'response_type' => 'code',
        'scope' => 'openid email https://www.googleapis.com/auth/calendar.events', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $st])]);
    }

    case 'gcallback': { // Google vuelve aquí (google.php) con el código de autorización
      header('Content-Type: text/html; charset=utf-8');
      [$st, $ts] = array_pad(explode(':', (string) aj('g_state')), 2, '0');
      $okState = $st !== '' && hash_equals($st, (string) ($_GET['state'] ?? '')) && time() - (int) $ts < 900;
      $msg = 'No se pudo conectar. Vuelve a intentarlo desde Ajustes.';
      if ($okState && !empty($_GET['code'])) {
        [$code, $j] = http_json('POST', 'https://oauth2.googleapis.com/token', ['__form' => 1, 'code' => (string) $_GET['code'], 'client_id' => aj('g_client_id'), 'client_secret' => aj('g_client_secret'), 'redirect_uri' => G_REDIRECT, 'grant_type' => 'authorization_code']);
        if ($code === 200 && !empty($j['refresh_token'])) {
          aj('g_refresh', $j['refresh_token']); aj('g_access', json_encode(['t' => $j['access_token'], 'exp' => time() + (int) ($j['expires_in'] ?? 3000)]));
          $claims = json_decode(b64u_dec(explode('.', (string) ($j['id_token'] ?? '..'))[1] ?? ''), true) ?: []; // viene directo de Google por HTTPS
          aj('g_cuenta', (string) ($claims['email'] ?? ''));
          $msg = 'Google conectado ✓ Ya puedes cerrar esta pestaña y volver a la app.';
        }
      }
      aj('g_state', null, true);
      echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Google</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#09090B;color:#F5F5F2;font:600 18px system-ui;text-align:center;padding:24px"><div><p>' . htmlspecialchars($msg) . '</p><p><a style="color:#FF2A2A" href="/hq/#mas">Volver a la app</a></p></div></body>';
      exit;
    }

    case 'g_desconectar': {
      requireAdmin(); $rt = aj('g_refresh');
      if ($rt) http_json('POST', 'https://oauth2.googleapis.com/revoke', ['__form' => 1, 'token' => $rt]);
      foreach (['g_refresh', 'g_access', 'g_cuenta'] as $k) aj($k, null, true);
      out(['ok' => true]);
    }

    case 'meet': { // crea (o actualiza) la reunión en Google con enlace de Meet e invita al equipo
      $u = me(); $id = cleanId(body()['id'] ?? '');
      $r = current_row('eventos', $id, '', $u); if (!$r) fail('No existe', 404);
      if (!aj('g_refresh')) fail('Google no está conectado. Pídeselo a ' . ADMIN_NAME);
      $d = json_decode($r['data'], true) ?: [];
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($d['fecha'] ?? ''))) fail('Pon el día (y la hora) de la reunión para crear el Meet');
      $gid = (string) ($d['gcal_id'] ?? '');
      [$code, $j] = $gid !== '' ? g_api('PATCH', '/' . rawurlencode($gid) . '?sendUpdates=all&conferenceDataVersion=1', g_evento($d, $r['espacio'], false, $id))
                                : g_api('POST', '?sendUpdates=all&conferenceDataVersion=1', g_evento($d, $r['espacio'], true, $id));
      if ($code >= 300 || empty($j['id'])) { error_log('crazyhq meet: ' . $code . ' ' . json_encode($j)); fail('Google no ha dejado crear la reunión (' . $code . ')', 502); }
      $link = (string) ($j['hangoutLink'] ?? ($d['enlace'] ?? ''));
      $d['gcal_id'] = $j['id']; if ($link !== '') $d['enlace'] = $link; $d['meet'] = true;
      saveDoc('eventos', $id, '', $d, $u);
      out(['ok' => true, 'enlace' => $link, 'invitados' => count($j['attendees'] ?? [])]);
    }

    case 'portal_alta': { // el equipo de Crazy da (o renueva) el acceso de un cliente
      $u = me(); if (vito($u)) fail('Esto es solo de Crazy Estudio', 403);
      $cid = cleanId(body()['cliente_id'] ?? '');
      $s = pdo()->prepare("SELECT data FROM docs WHERE col = 'clientes' AND id = ? AND owner = '' AND deleted = 0"); $s->execute([$cid]);
      $c = json_decode((string) $s->fetchColumn(), true); if (!$c) fail('No existe ese cliente', 404);
      $s = pdo()->prepare('SELECT usuario FROM portal_users WHERE cliente_id = ?'); $s->execute([$cid]); $us = $s->fetchColumn();
      if (!$us) { $base = slug((string) ($c['nombre'] ?? '')); $us = $base; $q = pdo()->prepare('SELECT 1 FROM portal_users WHERE usuario = ?');
        for ($i = 2; $q->execute([$us]) && $q->fetchColumn(); $i++) $us = $base . $i; }
      $clave = clave_facil();
      pdo()->prepare('INSERT INTO portal_users (cliente_id, usuario, pass_hash, cambiada, creado) VALUES (?,?,?,0,?) ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash), cambiada = 0, fallos = 0, bloqueo = 0')
        ->execute([$cid, $us, password_hash($clave, PASSWORD_DEFAULT), ms()]);
      pdo()->prepare('DELETE FROM portal_sessions WHERE cliente_id = ?')->execute([$cid]);
      out(['usuario' => $us, 'clave' => $clave, 'url' => 'https://crazyestudio.es/clientes/']);
    }

    case 'portal_baja': {
      $u = me(); if (vito($u)) fail('Esto es solo de Crazy Estudio', 403);
      $cid = cleanId(body()['cliente_id'] ?? '');
      foreach (['DELETE FROM portal_sessions WHERE cliente_id = ?', 'DELETE FROM portal_users WHERE cliente_id = ?', 'DELETE FROM push_subs WHERE nombre = ?'] as $i => $q)
        pdo()->prepare($q)->execute([$i === 2 ? 'c:' . $cid : $cid]);
      out(['ok' => true]);
    }

    case 'p_login': {
      $b = body(); $us = strtolower(trim((string) ($b['usuario'] ?? ''))); $cl = (string) ($b['clave'] ?? '');
      $s = pdo()->prepare('SELECT * FROM portal_users WHERE usuario = ?'); $s->execute([$us]); $p = $s->fetch();
      if (!$p) fail('Usuario o clave incorrectos', 401);
      if ($p['bloqueo'] > ms()) fail('Demasiados intentos. Espera ' . max(1, (int) ceil(($p['bloqueo'] - ms()) / 60000)) . ' min', 429);
      if (!password_verify($cl, $p['pass_hash'])) {
        $f = $p['fallos'] + 1; $lock = $f % 5 === 0 ? ms() + min(86400000, 300000 * 2 ** (intdiv($f, 5) - 1)) : 0;
        pdo()->prepare('UPDATE portal_users SET fallos = ?, bloqueo = ? WHERE cliente_id = ?')->execute([$f, $lock, $p['cliente_id']]);
        fail('Usuario o clave incorrectos', 401);
      }
      pdo()->prepare('UPDATE portal_users SET fallos = 0, bloqueo = 0 WHERE cliente_id = ?')->execute([$p['cliente_id']]);
      $tok = bin2hex(random_bytes(32));
      pdo()->prepare('INSERT INTO portal_sessions (token_hash, cliente_id, creado) VALUES (?,?,?)')->execute([hash('sha256', $tok), $p['cliente_id'], ms()]);
      out(['token' => $tok, 'cambiar' => !$p['cambiada']]);
    }

    case 'p_datos': { $c = cliente_me(); out(portal_datos($c['cliente_id']) + ['cambiar' => !$c['cambiada'], 'usuario' => $c['usuario']]); }

    case 'p_clave': {
      $c = cliente_me(); $n = (string) (body()['nueva'] ?? '');
      if (mb_strlen($n) < 6) fail('La clave tiene que tener al menos 6 caracteres');
      pdo()->prepare('UPDATE portal_users SET pass_hash = ?, cambiada = 1 WHERE cliente_id = ?')->execute([password_hash($n, PASSWORD_DEFAULT), $c['cliente_id']]);
      pdo()->prepare('DELETE FROM portal_sessions WHERE cliente_id = ? AND token_hash <> ?')->execute([$c['cliente_id'], hash('sha256', bearer())]);
      out(['ok' => true]);
    }

    case 'p_pushsub': {
      $c = cliente_me(); $b = body(); $ep = (string) ($b['endpoint'] ?? ''); $k = (array) ($b['keys'] ?? []);
      $p = (string) ($k['p256dh'] ?? ''); $au = (string) ($k['auth'] ?? '');
      if (strlen($ep) > 700 || !push_host_ok($ep) || strlen(b64u_dec($p)) !== 65 || strlen(b64u_dec($au)) !== 16) fail('Este móvil no admite avisos');
      pdo()->prepare('INSERT INTO push_subs (id, nombre, endpoint, p256dh, auth, creado) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), p256dh = VALUES(p256dh), auth = VALUES(auth), creado = VALUES(creado)')
        ->execute([hash('sha256', $ep), 'c:' . $c['cliente_id'], $ep, $p, $au, ms()]);
      avisar('c:' . $c['cliente_id'], 'Avisos activados ✓', 'Te avisaremos cada vez que avance tu proyecto.', './', '', 'cliente', 'ctest:' . $c['cliente_id'] . ':' . intdiv(time(), 60));
      out(['ok' => true]);
    }

    case 'p_logout': { $t = bearer(); if ($t) pdo()->prepare('DELETE FROM portal_sessions WHERE token_hash = ?')->execute([hash('sha256', $t)]); out(['ok' => true]); }

    case 'pushsub': {
      $u = me(); $b = body(); $ep = (string) ($b['endpoint'] ?? ''); $k = (array) ($b['keys'] ?? []);
      $p = (string) ($k['p256dh'] ?? ''); $au = (string) ($k['auth'] ?? '');
      if (strlen($ep) > 700 || !push_host_ok($ep) || strlen(b64u_dec($p)) !== 65 || strlen(b64u_dec($au)) !== 16) fail('Este móvil no admite avisos');
      pdo()->prepare('INSERT INTO push_subs (id, nombre, endpoint, p256dh, auth, creado) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), p256dh = VALUES(p256dh), auth = VALUES(auth), creado = VALUES(creado)')
        ->execute([hash('sha256', $ep), $u['nombre'], $ep, $p, $au, ms()]);
      out(['ok' => true]);
    }

    case 'pushunsub': {
      $u = me();
      pdo()->prepare('DELETE FROM push_subs WHERE id = ? AND nombre = ?')->execute([hash('sha256', (string) (body()['endpoint'] ?? '')), $u['nombre']]);
      out(['ok' => true]);
    }

    case 'pushtest': {
      $u = me();
      $ok = avisar($u['nombre'], 'Avisos activados ✓', 'Así te llegarán las grabaciones, tus tareas y los recordatorios.', './#inicio', '', 'prueba', 'test:' . $u['nombre'] . ':' . intdiv(time(), 60));
      out(['ok' => $ok]);
    }

    case 'nudge': { // "recordar" a alguien: le llega al móvil lo que tiene pendiente
      $u = me(); $n = cleanName(body()['nombre'] ?? '');
      if (!isset(usuarios()[$n]) || $n === $u['nombre']) fail('No se puede avisar a esa persona');
      $s = pdo()->prepare('SELECT COUNT(*) FROM push_subs WHERE nombre = ?'); $s->execute([$n]); $moviles = (int) $s->fetchColumn();
      $x = pendienteTxt(pendiente($n));
      if (!avisar($n, $u['nombre'] . ' te recuerda lo pendiente', $x ? implode(' · ', $x) : 'Échale un ojo a la app cuando puedas.', './#inicio', $u['nombre'], 'recordar', 'nudge:' . $u['nombre'] . ':' . $n . ':' . intdiv(time(), 600)))
        fail('Ya le has avisado hace poco. Espera unos minutos', 429);
      out(['ok' => true, 'moviles' => $moviles]);
    }

    case 'nudgeev': { // avisar a quien no ha contestado a una grabación o reunión
      $u = me(); $id = cleanId(body()['id'] ?? '');
      $r = current_row('eventos', $id, '', $u);
      if (!$r) fail('No existe', 404);
      $d = json_decode($r['data'], true) ?: []; $k = 0;
      foreach (esperados_ev($d, $r['espacio']) as $n) {
        if ($n === $u['nombre'] || isset(($d['asistencia'] ?? [])[$n])) continue;
        if (avisar($n, '¿Vas a ' . ($d['titulo'] ?? 'esto') . '?', fechaTxt((string) ($d['fecha'] ?? ''), (string) ($d['hora'] ?? '')) . ' · contesta con un toque', './#agenda', $u['nombre'], 'evento', 'nev:' . $id . ':' . $n . ':' . intdiv(time(), 600))) $k++;
      }
      out(['ok' => true, 'avisados' => $k]);
    }

    case 'cron': { // cada 15 min: aviso 1 hora antes y resumen de la mañana
      if (PHP_SAPI !== 'cli' && !hash_equals(SETUP_KEY, (string) ($_GET['key'] ?? ''))) fail('No autorizado', 403);
      $now = madrid(); $hoy = $now->format('Y-m-d'); $n1 = 0; $n2 = 0;
      foreach (pdo()->query("SELECT id, data, espacio FROM docs WHERE deleted = 0 AND owner = '' AND col = 'eventos'") as $r) {
        $d = json_decode($r['data'], true) ?: [];
        if (($d['fecha'] ?? '') !== $hoy || !preg_match('/^\d{2}:\d{2}$/', (string) ($d['hora'] ?? ''))) continue;
        $min = ((new DateTime($hoy . ' ' . $d['hora'], $now->getTimezone()))->getTimestamp() - $now->getTimestamp()) / 60;
        if ($min < 40 || $min > 75) continue;
        foreach (esperados_ev($d, $r['espacio']) as $n) {
          if ((($d['asistencia'] ?? [])[$n] ?? '') === 'no') continue;
          if (avisar($n, 'En 1 hora: ' . ($d['titulo'] ?? ''), $d['hora'] . (!empty($d['lugar']) ? ' · ' . $d['lugar'] : '') . (!empty($d['material']) ? ' · Llevar: ' . $d['material'] : ''), './#agenda', '', 'evento', 'h1:' . $r['id'] . ':' . $hoy . $d['hora'] . ':' . $n)) $n1++;
        }
      }
      $hora = (int) $now->format('G');
      if ($hora >= 9 && $hora < 12) foreach (array_keys(usuarios()) as $n) {
        $x = pendienteTxt(pendiente($n));
        if ($x && avisar($n, 'Buenos días, ' . $n, 'Hoy: ' . implode(' · ', $x), './#inicio', '', 'dia', 'dia:' . $n . ':' . $hoy)) $n2++;
      }
      pdo()->exec('DELETE FROM avisos WHERE creado < ' . (ms() - 60 * 86400000));
      out(['ok' => true, 'hora' => $now->format('H:i'), 'una_hora' => $n1, 'buenos_dias' => $n2]);
    }

    default: if (str_starts_with($a, 'f_')) facturacion($a); fail('Acción desconocida', 404);
  }
} catch (Throwable $e) {
  error_log('crazyhq: ' . $e->getMessage());
  fail('Error del servidor', 500);
}
