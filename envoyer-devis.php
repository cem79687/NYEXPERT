<?php
/**
 * envoyer-devis.php — traitement du formulaire de contact
 *
 * Sécurité :
 *  - les secrets (Turnstile, SMTP) ne sont JAMAIS dans ce fichier : ils sont lus dans
 *    config-nyexpert.php, placé AU-DESSUS du dossier public (public_html).
 *  - en local (localhost / 127.0.0.1) sans fichier de config : mode « dev », aucun e-mail
 *    n'est envoyé, il est écrit dans un fichier journal.
 *  - en ligne, le mode dev est impossible (le script refuse de fonctionner sans config).
 *
 * Compatible PHP 7.4 → 8.x.
 */

ini_set('display_errors', '0');          // jamais d'erreur PHP affichée au visiteur
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ───────────────────────── Utilitaires ─────────────────────────

function respond($ok, $message, $status = 200)
{
    http_response_code($status);
    echo json_encode(['success' => (bool) $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_local_host()
{
    $host = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
}

function client_ip()
{
    // REMOTE_ADDR uniquement : les en-têtes X-Forwarded-For sont falsifiables par le visiteur.
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/** Dossier d'écriture (journaux, limites) : de préférence hors du dossier public. */
function storage_dir($config)
{
    $candidates = [];
    if (!empty($config['log_dir'])) {
        $candidates[] = $config['log_dir'];
    }
    $candidates[] = dirname(__DIR__) . '/logs-nyexpert';
    $candidates[] = rtrim(sys_get_temp_dir(), '/\\') . '/nyexpert';
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }
    return null;
}

/** Journal technique : jamais de contenu du message, jamais de secret. */
function log_event($config, $level, $message)
{
    $dir = storage_dir($config);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $level . ' ' . $message . "\n";
    if ($dir !== null) {
        @file_put_contents($dir . '/envoyer-devis.log', $line, FILE_APPEND | LOCK_EX);
    } else {
        error_log('envoyer-devis: ' . $level . ' ' . $message);
    }
}

/** Nettoie un champ d'une seule ligne : retire retours à la ligne et caractères de contrôle. */
function clean_line($value, $max)
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
    if ($value === null) {
        return '';
    }
    $value = trim(preg_replace('/\s{2,}/u', ' ', $value));
    return mb_strlen($value, 'UTF-8') > $max ? '' : $value;
}

/** Nettoie un texte multiligne : garde les retours à la ligne, retire le reste des contrôles. */
function clean_text($value, $max)
{
    if (!is_string($value)) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if ($value === null) {
        return '';
    }
    $value = trim(preg_replace("/\n{3,}/", "\n\n", $value));
    return mb_strlen($value, 'UTF-8') > $max ? '' : $value;
}

/** Limite de fréquence par IP : max 5 envois/heure, et 20 s minimum entre deux envois. */
function rate_limit_ok($config)
{
    $dir = storage_dir($config);
    if ($dir === null) {
        return true; // pas de stockage disponible : on ne bloque pas les vrais visiteurs
    }
    $salt = isset($config['rate_salt']) ? $config['rate_salt'] : 'nyexpert';
    $file = $dir . '/rl-' . hash('sha256', client_ip() . $salt) . '.json';
    $now = time();
    $hits = [];

    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return true;
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = $raw ? json_decode($raw, true) : [];
    if (is_array($data)) {
        foreach ($data as $t) {
            if (is_int($t) && $t > $now - 3600) {
                $hits[] = $t;
            }
        }
    }
    $ok = count($hits) < 5 && (empty($hits) || ($now - max($hits)) >= 20);
    if ($ok) {
        $hits[] = $now;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);

    // ménage occasionnel des vieux fichiers
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/rl-*.json') ?: [] as $f) {
            if (@filemtime($f) < $now - 7200) {
                @unlink($f);
            }
        }
    }
    return $ok;
}

/** Vérification Turnstile côté serveur. Retourne [bool succès, string cause]. */
function verify_turnstile($secret, $token, $allowedHosts, $config)
{
    if ($token === '' || strlen($token) > 2048) {
        return [false, 'missing_token'];
    }
    if (!function_exists('curl_init')) {
        log_event($config, 'ERROR', 'extension cURL absente');
        return [false, 'no_curl'];
    }
    $url = !empty($config['turnstile_url']) ? (string) $config['turnstile_url'] : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => client_ip(),
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        log_event($config, 'ERROR', 'Turnstile injoignable : http=' . $code . ' ' . $err);
        return [false, 'network'];
    }
    $res = json_decode($body, true);
    if (!is_array($res) || empty($res['success'])) {
        $codes = isset($res['error-codes']) && is_array($res['error-codes']) ? implode(',', $res['error-codes']) : 'inconnu';
        log_event($config, 'WARN', 'Turnstile refusé : ' . $codes);
        return [false, 'refused'];
    }
    // En ligne : le jeton doit avoir été émis pour NOTRE domaine.
    if (!empty($allowedHosts)) {
        $h = isset($res['hostname']) ? strtolower((string) $res['hostname']) : '';
        if (!in_array($h, array_map('strtolower', $allowedHosts), true)) {
            log_event($config, 'WARN', 'Turnstile : nom d\'hôte inattendu (' . $h . ')');
            return [false, 'hostname'];
        }
    }
    return [true, 'ok'];
}

// ───────────────────────── 1. Requête ─────────────────────────

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(false, 'Méthode non autorisée.', 405);
}

// Le formulaire doit être posté depuis notre propre site.
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = strtolower((string) parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST));
    $ownHost = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
    if ($originHost !== '' && $originHost !== $ownHost) {
        respond(false, 'Requête refusée.', 403);
    }
}

// ───────────────────────── 2. Configuration ─────────────────────────

$config = [];
$configFile = dirname(__DIR__) . '/config-nyexpert.php';   // AU-DESSUS de public_html
if (is_file($configFile)) {
    $loaded = include $configFile;
    if (is_array($loaded)) {
        $config = $loaded;
    }
}

$isDev = false;
if (empty($config)) {
    if (is_local_host()) {
        $isDev = true;                                       // mode développement local
        $config = [
            'env' => 'dev',
            'turnstile_secret' => '1x0000000000000000000000000000000AA',   // clé de TEST Cloudflare
        ];
    } else {
        log_event([], 'ERROR', 'config-nyexpert.php introuvable (hors mode dev)');
        respond(false, 'Le service est momentanément indisponible. Merci d\'appeler directement.', 503);
    }
}
if ((isset($config['env']) ? $config['env'] : 'production') === 'dev') {
    $isDev = true;
}
// Garde-fou : le mode dev et les clés de test sont interdits en ligne.
if ($isDev && !is_local_host()) {
    log_event($config, 'ERROR', 'mode dev refusé : hôte non local');
    respond(false, 'Le service est momentanément indisponible. Merci d\'appeler directement.', 503);
}
$secret = isset($config['turnstile_secret']) ? (string) $config['turnstile_secret'] : '';
if (!$isDev && (preg_match('/^[123]x0{5,}/', $secret) || $secret === '')) {
    log_event($config, 'ERROR', 'clé Turnstile de test ou vide en production');
    respond(false, 'Le service est momentanément indisponible. Merci d\'appeler directement.', 503);
}

// ───────────────────────── 3. Limite de fréquence ─────────────────────────

if (!rate_limit_ok($config)) {
    respond(false, 'Trop de demandes envoyées. Merci de patienter quelques minutes ou d\'appeler directement.', 429);
}

// ───────────────────────── 4. Validation des champs ─────────────────────────

$TYPES = [
    'Malfaçons / Désordres',
    'Fissures / Pathologies',
    'Réception de chantier / VEFA',
    'Sinistre / Contre-expertise',
    'Avant achat immobilier',
    'Suivi de travaux',
    'Expertise judiciaire',
    'Autre',
];

$OFFRES = [                                  // offres des cartes de tarifs (liste fermée)
    'diagnostic' => 'Diagnostic Terrain',
    'expertise'  => 'Expertise & Rapport',
    'suivi'      => 'Suivi de Chantier',
];

$nom        = clean_line(isset($_POST['nom']) ? $_POST['nom'] : '', 100);
$telephone  = clean_line(isset($_POST['telephone']) ? $_POST['telephone'] : '', 30);
$email      = clean_line(isset($_POST['email']) ? $_POST['email'] : '', 254);      // facultatif
$type       = clean_line(isset($_POST['type_expertise']) ? $_POST['type_expertise'] : '', 60);   // facultatif
$offreKey   = clean_line(isset($_POST['offre']) ? $_POST['offre'] : '', 20);        // facultatif (vient des tarifs)
$adresse    = clean_line(isset($_POST['adresse']) ? $_POST['adresse'] : '', 200);   // facultatif : commune du bien
$message    = clean_text(isset($_POST['message']) ? $_POST['message'] : '', 3000);
$consent    = !empty($_POST['consentement']);

if (!preg_match('/^[\p{L}\p{M}\' .\-]{2,100}$/u', $nom)) {
    respond(false, 'Merci de vérifier votre nom.', 400);
}
$digits = preg_replace('/\D/', '', $telephone);
if (!preg_match('/^[0-9+().\s\-]{6,30}$/', $telephone) || strlen($digits) < 9 || strlen($digits) > 15) {
    respond(false, 'Merci de vérifier votre numéro de téléphone.', 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Merci de vérifier votre adresse e-mail (ou laissez ce champ vide).', 400);
}
if ($type !== '' && !in_array($type, $TYPES, true)) {
    respond(false, 'Type d\'expertise non reconnu.', 400);
}
if (!isset($OFFRES[$offreKey])) {
    $offreKey = '';                              // valeur inconnue : ignorée, jamais recopiée dans le message
}
if ($message === '' || mb_strlen($message, 'UTF-8') < 10) {
    respond(false, 'Merci de décrire brièvement votre situation (3000 caractères maximum).', 400);
}
if (!$consent) {
    respond(false, 'Merci d\'accepter le traitement de vos données pour envoyer la demande.', 400);
}

// ───────────────────────── 5. Anti-robot (Cloudflare Turnstile) ─────────────────────────

if ($isDev) {
    $turnstileOk = true;   // dev local : pas d'appel réseau
} else {
    $token = isset($_POST['cf-turnstile-response']) && is_string($_POST['cf-turnstile-response']) ? trim($_POST['cf-turnstile-response']) : '';
    $allowedHosts = isset($config['allowed_hostnames']) && is_array($config['allowed_hostnames']) ? $config['allowed_hostnames'] : [];
    list($turnstileOk, $why) = verify_turnstile($secret, $token, $allowedHosts, $config);
    if (!$turnstileOk) {
        if ($why === 'network' || $why === 'no_curl') {
            respond(false, 'Vérification momentanément indisponible. Merci de réessayer ou d\'appeler directement.', 503);
        }
        respond(false, 'La vérification anti-robot a échoué. Merci de réessayer.', 400);
    }
}

// ───────────────────────── 6. Construction du message ─────────────────────────

$subject = 'Demande de devis — ' . ($type !== '' ? $type : 'type non précisé') . ' — ' . $nom;   // champs déjà nettoyés : une seule ligne
$lines = [
    'Nouvelle demande reçue depuis le site.',
    '',
    'Nom : ' . $nom,
    'Téléphone : ' . $telephone,
    'E-mail : ' . ($email !== '' ? $email : 'non renseigné (rappeler par téléphone)'),
    'Type d\'expertise : ' . ($type !== '' ? $type : 'non précisé'),
    'Offre consultée sur le site : ' . ($offreKey !== '' ? $OFFRES[$offreKey] : 'aucune'),
    'Commune du bien : ' . ($adresse !== '' ? $adresse : 'non précisée'),
    '',
    'Description :',
    $message,
    '',
    '— Reçu le ' . date('d/m/Y à H:i') . ' (IP ' . client_ip() . ')',
];
$body = implode("\n", $lines);

// ───────────────────────── 7. Envoi ─────────────────────────

if ($isDev) {
    // Aucun e-mail réel : on écrit le message dans un fichier pour pouvoir le relire.
    $dir = storage_dir($config);
    if ($dir !== null) {
        @file_put_contents($dir . '/mail-dev.log', "=== " . date('c') . " ===\nSujet : $subject\nReply-To : " . ($email !== '' ? $email : '(aucun)') . "\n\n$body\n\n", FILE_APPEND | LOCK_EX);
    }
    log_event($config, 'INFO', 'DEV : message enregistré dans mail-dev.log');
    respond(true, 'Votre demande a bien été envoyée (mode test local). Réponse sous 24h.');
}

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

$smtp = isset($config['smtp']) && is_array($config['smtp']) ? $config['smtp'] : [];
$mailTo = isset($config['mail_to']) ? (string) $config['mail_to'] : '';
$mailFrom = isset($config['mail_from']) ? (string) $config['mail_from'] : '';
if ($mailTo === '' || $mailFrom === '' || empty($smtp['host'])) {
    log_event($config, 'ERROR', 'configuration SMTP incomplète');
    respond(false, 'Le service est momentanément indisponible. Merci d\'appeler directement.', 503);
}

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) $smtp['host'];
    $mail->Port = isset($smtp['port']) ? (int) $smtp['port'] : 465;
    $mail->SMTPAuth = !empty($smtp['user']);
    if ($mail->SMTPAuth) {
        $mail->Username = (string) $smtp['user'];
        $mail->Password = isset($smtp['pass']) ? (string) $smtp['pass'] : '';
    }
    $secure = isset($smtp['secure']) ? (string) $smtp['secure'] : 'ssl';
    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->Timeout = 10;

    $mail->setFrom($mailFrom, 'Site Nurset Yazgören');   // expéditeur = vraie boîte du domaine
    $mail->addAddress($mailTo);
    if ($email !== '') {
        $mail->addReplyTo($email, $nom);                 // répondre = écrire au visiteur
    }
    $mail->Subject = $subject;
    $mail->isHTML(false);
    $mail->Body = $body;
    $mail->send();
} catch (Exception $e) {
    log_event($config, 'ERROR', 'envoi SMTP échoué : ' . $e->getMessage());
    respond(false, 'L\'envoi a échoué. Merci de réessayer ou d\'appeler directement.', 500);
}

log_event($config, 'INFO', 'demande envoyée (' . $type . ')');
respond(true, 'Votre demande a bien été envoyée. Réponse sous 24h.');
