<?php
// Notificaciones Web Push sin librerías: cifrado aes128gcm (RFC 8291) y firma VAPID (RFC 8292).
// Lo comprueba prueba-push.php con el ejemplo del propio RFC.

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// Punto P-256 sin comprimir (65 bytes) -> clave pública que entiende OpenSSL.
function p256_pub_pem(string $raw): string {
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function p256_raw_pub($key): string {
  $d = openssl_pkey_get_details($key)['ec'];
  return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
// HKDF-Expand con una sola vuelta (todas las salidas aquí miden <= 32 bytes).
function hkdf1(string $prk, string $info, int $len): string { return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len); }

// Cifra el mensaje para un navegador (su clave p256dh y su secreto auth). $asKey y $salt solo se fijan en la prueba.
function wp_encrypt(string $plain, string $uaPub, string $auth, $asKey = null, ?string $salt = null): string {
  if (strlen($uaPub) !== 65 || strlen($auth) !== 16) throw new InvalidArgumentException('suscripción no válida');
  $asKey = $asKey ?: openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  $salt = $salt ?? random_bytes(16);
  $asPub = p256_raw_pub($asKey);
  $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_pub_pem($uaPub)), $asKey, 32);
  if ($ecdh === false) throw new RuntimeException('ecdh');
  $ikm = hkdf1(hash_hmac('sha256', $ecdh, $auth, true), "WebPush: info\0" . $uaPub . $asPub, 32);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = hkdf1($prk, "Content-Encoding: aes128gcm\0", 16);
  $nonce = hkdf1($prk, "Content-Encoding: nonce\0", 12);
  $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

// Firma ECDSA de OpenSSL (DER) -> r||s de 64 bytes, que es lo que pide JWT.
function der2raw(string $der): string {
  $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl);
  $o += 2 + $rl; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
  return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function vapid_auth(string $endpoint): string {
  $p = parse_url($endpoint);
  $jwt = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 43200, 'sub' => 'https://crazyestudio.es/hq/']));
  if (!openssl_sign($jwt, $der, VAPID_PRIVATE_PEM, OPENSSL_ALGO_SHA256)) throw new RuntimeException('firma');
  return 'vapid t=' . $jwt . '.' . b64u(der2raw($der)) . ', k=' . VAPID_PUBLIC;
}
// Solo se envía a servicios de push conocidos (evita que alguien use el servidor para llamar a otras webs).
function push_host_ok(string $endpoint): bool {
  $p = parse_url($endpoint);
  if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
  return (bool) preg_match('/(^|\.)(push\.apple\.com|fcm\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/i', $p['host']);
}
// Devuelve el código HTTP del servicio de push (201 = entregado al servicio).
function wp_send(string $endpoint, string $p256dh, string $auth, array $msg): int {
  if (!push_host_ok($endpoint)) return 0;
  $body = wp_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE), b64u_dec($p256dh), b64u_dec($auth));
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Authorization: ' . vapid_auth($endpoint), 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high']]);
  curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code;
}
