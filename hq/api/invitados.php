<?php
// vitoreandoeh · Casting de participantes (producido por Crazy Estudio).
// Público (crazyestudio.es/invitados):        ?a=prods (GET) · ?a=alta (POST)
// Equipo  (crazyestudio.es/invitados/equipo): ?a=lista · ?a=guardar · ?a=borrar · ?a=prod_guardar  (sesión del HQ: nombre + PIN)
// Usa la base de datos y las sesiones del HQ, pero no toca index.php ni sus datos: tiene sus propias tablas.
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/config.php'; // DB_NAME, DB_USER, DB_PASS

const SESSION_DAYS = 365;
const ESTADOS = ['nuevo', 'contactado', 'confirmado', 'descartado'];
const TIPOS = ['citas', 'general'];
const MAX_POR_HORA = 5; // envíos por IP y hora (antispam)

// Preguntas cerradas del formulario de citas: solo se aceptan estas respuestas.
const C_GENERO = ['chico', 'chica', 'otro'];
const C_BUSCA = ['chicos', 'chicas', 'ambos'];
const C_FUMA = ['no', 'a veces', 'sí'];
const C_GUSTOS = ['Música', 'Fiesta', 'Deporte', 'Gimnasio', 'Viajar', 'Series y pelis', 'Gaming', 'Comer bien', 'Cocinar', 'Monte y naturaleza',
  'Moda', 'Arte', 'Leer', 'Animales', 'Fútbol', 'Conciertos', 'Pintxos', 'Playa', 'Motor', 'Baile'];
const C_DISPO = ['Entre semana mañana', 'Entre semana tarde', 'Fin de semana'];

function pdo(): PDO {
  static $p = null;
  if ($p) return $p;
  $p = new PDO('mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  $p->exec("CREATE TABLE IF NOT EXISTS invitados (
    id CHAR(16) PRIMARY KEY, nombre VARCHAR(80) NOT NULL, edad TINYINT UNSIGNED NOT NULL,
    instagram VARCHAR(60) NOT NULL DEFAULT '', telefono VARCHAR(30) NOT NULL DEFAULT '',
    gustos TEXT NOT NULL, extra TEXT NOT NULL, grabacion VARCHAR(40) NOT NULL DEFAULT '',
    estado VARCHAR(12) NOT NULL DEFAULT 'nuevo', nota TEXT NOT NULL, privacidad_ok BIGINT NOT NULL,
    ip_hash CHAR(64) NOT NULL, creado BIGINT NOT NULL, actualizado BIGINT NOT NULL, actualizado_por VARCHAR(40) NOT NULL DEFAULT '',
    INDEX (creado), INDEX (ip_hash, creado), INDEX (grabacion)
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $c = $p->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invitados' AND COLUMN_NAME = 'datos'")->fetchColumn();
  if (!(int) $c) $p->exec("ALTER TABLE invitados ADD COLUMN datos MEDIUMTEXT NULL");
  $p->exec("CREATE TABLE IF NOT EXISTS producciones (
    slug VARCHAR(40) PRIMARY KEY, titulo VARCHAR(80) NOT NULL, descripcion VARCHAR(400) NOT NULL DEFAULT '',
    tipo VARCHAR(12) NOT NULL DEFAULT 'general', edad_min TINYINT UNSIGNED NOT NULL DEFAULT 16,
    activa TINYINT NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0, creado BIGINT NOT NULL
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  if (!(int) $p->query('SELECT COUNT(*) FROM producciones')->fetchColumn())
    $p->prepare('INSERT INTO producciones (slug, titulo, descripcion, tipo, edad_min, activa, orden, creado) VALUES (?,?,?,?,?,1,0,?)')
      ->execute(['citas', 'Citas a ciegas', 'Una cita con alguien que no conoces, estilo First Dates. Te buscamos pareja según lo que nos cuentes.', 'citas', 18, ms()]);
  return $p;
}
function out($d, int $c = 200): void { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): void { out(['error' => $m], $c); }
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($j) ? $j : []; }
function ms(): int { return (int) round(microtime(true) * 1000); }
function txt($v, int $max): string {
  if (is_array($v)) return '';
  $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/u', '', (string) ($v ?? '')) ?? '');
  return mb_substr($v, 0, $max);
}
function uno($v, array $ok): string { $v = (string) (is_scalar($v) ? $v : ''); return in_array($v, $ok, true) ? $v : ''; }
function varios($v, array $ok, int $max): array { return array_slice(array_values(array_unique(array_filter((array) $v, fn($x) => is_string($x) && in_array($x, $ok, true)))), 0, $max); }
function slug($v): string { $v = strtolower((string) $v); return substr(preg_replace('/[^a-z0-9-]/', '', $v) ?? '', 0, 40); }

// Sesión del equipo: el mismo token del HQ (login con nombre + PIN).
function me(): string {
  $h = trim((string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
  if (!preg_match('/^[a-f0-9]{64}$/i', $h)) fail('login', 401);
  $s = pdo()->prepare('SELECT nombre FROM sessions WHERE token_hash = ? AND creado > ?');
  $s->execute([hash('sha256', strtolower($h)), ms() - SESSION_DAYS * 86400000]);
  $n = $s->fetchColumn();
  if (!$n) fail('login', 401);
  return (string) $n;
}
function prod(string $slug): ?array {
  $s = pdo()->prepare('SELECT * FROM producciones WHERE slug = ?'); $s->execute([$slug]);
  return $s->fetch() ?: null;
}

$a = $_GET['a'] ?? '';
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
  switch ($a) {

    case 'prods': {
      $todas = isset($_GET['todas']);
      if ($todas) me();
      $r = pdo()->query('SELECT slug, titulo, descripcion, tipo, edad_min, activa FROM producciones' . ($todas ? '' : ' WHERE activa = 1') . ' ORDER BY orden, creado')->fetchAll();
      foreach ($r as &$x) { $x['edad_min'] = (int) $x['edad_min']; $x['activa'] = (bool) $x['activa']; }
      out(['producciones' => $r, 'opciones' => ['genero' => C_GENERO, 'busca' => C_BUSCA, 'fuma' => C_FUMA, 'gustos' => C_GUSTOS, 'dispo' => C_DISPO]]);
    }

    case 'alta': {
      if ($m !== 'POST') fail('Método no permitido', 405);
      $b = body();
      if (txt($b['web'] ?? '', 10) !== '') out(['ok' => true]); // trampa para bots
      $p = prod(slug($b['produccion'] ?? ''));
      if (!$p || !$p['activa']) fail('Elige en qué quieres participar');
      $nombre = txt($b['nombre'] ?? '', 80);
      $edad = (int) ($b['edad'] ?? 0);
      $ig = ltrim(txt($b['instagram'] ?? '', 60), '@');
      $tel = txt($b['telefono'] ?? '', 30);
      if (mb_strlen($nombre) < 2) fail('Escribe tu nombre');
      if ($edad < 14 || $edad > 99) fail('Revisa la edad');
      if ($edad < (int) $p['edad_min']) fail('Para ' . $p['titulo'] . ' hay que tener ' . (int) $p['edad_min'] . ' años o más');
      if ($ig === '' && $tel === '') fail('Déjanos tu Instagram o tu WhatsApp para poder escribirte');
      if ($tel !== '' && !preg_match('/^[+0-9 ()-]{9,20}$/', $tel)) fail('Revisa el número de WhatsApp');
      if ($ig !== '' && !preg_match('/^[A-Za-z0-9._]{1,30}$/', $ig)) fail('Revisa tu usuario de Instagram');
      if (empty($b['privacidad'])) fail('Tienes que aceptar el aviso de privacidad');
      $d = is_array($b['datos'] ?? null) ? $b['datos'] : [];
      $gustos = txt($b['gustos'] ?? '', 1500);
      $datos = null;
      if ($p['tipo'] === 'citas') {
        $datos = [
          'profesion' => txt($d['profesion'] ?? '', 80), 'zona' => txt($d['zona'] ?? '', 60),
          'genero' => uno($d['genero'] ?? '', C_GENERO), 'busca' => uno($d['busca'] ?? '', C_BUSCA),
          'edadMin' => max(18, min(99, (int) ($d['edadMin'] ?? 18))), 'edadMax' => max(18, min(99, (int) ($d['edadMax'] ?? 99))),
          'fuma' => uno($d['fuma'] ?? '', C_FUMA), 'tags' => varios($d['tags'] ?? [], C_GUSTOS, 12),
          'plan' => txt($d['plan'] ?? '', 500), 'noSoporto' => txt($d['noSoporto'] ?? '', 500),
          'tresPalabras' => txt($d['tresPalabras'] ?? '', 80), 'dispo' => varios($d['dispo'] ?? [], C_DISPO, 3),
        ];
        if ($datos['profesion'] === '') fail('Cuéntanos a qué te dedicas');
        if ($datos['genero'] === '' || $datos['busca'] === '') fail('Dinos quién eres y a quién buscas');
        if ($datos['edadMin'] > $datos['edadMax']) [$datos['edadMin'], $datos['edadMax']] = [$datos['edadMax'], $datos['edadMin']];
        if (!$datos['tags'] && $gustos === '') fail('Elige algunos gustos');
        if (empty($b['sensibles'])) fail('Para las citas tienes que aceptar que usemos tus preferencias para buscarte pareja');
      } elseif ($gustos === '') fail('Cuéntanos un poco qué te gusta');
      $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . DB_NAME);
      $c = pdo()->prepare('SELECT COUNT(*) FROM invitados WHERE ip_hash = ? AND creado > ?');
      $c->execute([$ip, ms() - 3600000]);
      if ((int) $c->fetchColumn() >= MAX_POR_HORA) fail('Demasiados envíos seguidos. Prueba en un rato', 429);
      $t = ms();
      pdo()->prepare('INSERT INTO invitados (id, nombre, edad, instagram, telefono, gustos, extra, grabacion, datos, estado, nota, privacidad_ok, ip_hash, creado, actualizado)
        VALUES (?,?,?,?,?,?,?,?,?,\'nuevo\',\'\',?,?,?,?)')
        ->execute([bin2hex(random_bytes(8)), $nombre, $edad, $ig, $tel, $gustos, txt($b['extra'] ?? '', 1500), $p['slug'],
          $datos ? json_encode($datos, JSON_UNESCAPED_UNICODE) : null, $t, $ip, $t, $t]);
      out(['ok' => true, 'menor' => $edad < 18, 'titulo' => $p['titulo']]);
    }

    case 'lista': {
      me();
      $r = pdo()->query('SELECT id, nombre, edad, instagram, telefono, gustos, extra, grabacion, datos, estado, nota, creado, actualizado, actualizado_por FROM invitados ORDER BY creado DESC')->fetchAll();
      foreach ($r as &$x) {
        $x['edad'] = (int) $x['edad']; $x['creado'] = (int) $x['creado']; $x['actualizado'] = (int) $x['actualizado'];
        $x['datos'] = $x['datos'] ? (json_decode($x['datos'], true) ?: null) : null;
      }
      out(['invitados' => $r]);
    }

    case 'guardar': {
      if ($m !== 'POST') fail('Método no permitido', 405);
      $yo = me(); $b = body();
      $id = (string) ($b['id'] ?? '');
      if (!preg_match('/^[a-f0-9]{16}$/', $id)) fail('Falta el participante');
      $estado = (string) ($b['estado'] ?? '');
      if (!in_array($estado, ESTADOS, true)) fail('Estado no válido');
      $s = pdo()->prepare('UPDATE invitados SET estado = ?, nota = ?, actualizado = ?, actualizado_por = ? WHERE id = ?');
      $s->execute([$estado, txt($b['nota'] ?? '', 1500), ms(), $yo, $id]);
      if (!$s->rowCount()) fail('No existe ese participante', 404);
      out(['ok' => true]);
    }

    case 'borrar': {
      if ($m !== 'POST') fail('Método no permitido', 405);
      me(); $b = body();
      $id = (string) ($b['id'] ?? '');
      if (!preg_match('/^[a-f0-9]{16}$/', $id)) fail('Falta el participante');
      pdo()->prepare('DELETE FROM invitados WHERE id = ?')->execute([$id]);
      out(['ok' => true]);
    }

    case 'prod_guardar': {
      if ($m !== 'POST') fail('Método no permitido', 405);
      me(); $b = body();
      $titulo = txt($b['titulo'] ?? '', 80);
      if (mb_strlen($titulo) < 2) fail('Ponle un nombre a la producción');
      $sl = slug($b['slug'] ?? '') ?: slug(str_replace(' ', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo) ?: $titulo));
      if ($sl === '') fail('Nombre no válido');
      $tipo = uno($b['tipo'] ?? 'general', TIPOS) ?: 'general';
      $edadMin = max(14, min(99, (int) ($b['edad_min'] ?? ($tipo === 'citas' ? 18 : 16))));
      if ($tipo === 'citas') $edadMin = max(18, $edadMin);
      pdo()->prepare('INSERT INTO producciones (slug, titulo, descripcion, tipo, edad_min, activa, orden, creado) VALUES (?,?,?,?,?,?,0,?)
        ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), descripcion = VALUES(descripcion), tipo = VALUES(tipo), edad_min = VALUES(edad_min), activa = VALUES(activa)')
        ->execute([$sl, $titulo, txt($b['descripcion'] ?? '', 400), $tipo, $edadMin, empty($b['activa']) ? 0 : 1, ms()]);
      out(['ok' => true, 'slug' => $sl]);
    }

    default: fail('Acción no válida', 404);
  }
} catch (Throwable $e) {
  error_log('vitoreandoeh casting: ' . $e->getMessage());
  fail('Error del servidor. Prueba de nuevo en un momento', 500);
}
