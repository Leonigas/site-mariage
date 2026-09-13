<?php
/**
 * Traitement du formulaire RSVP - envoie un e-mail via SMTP authentifié
 * (PHPMailer) plutôt que la fonction mail() native, pour une bien meilleure
 * délivrabilité (moins de risque de finir en spam).
 */

header('Content-Type: application/json; charset=utf-8');

// N'accepte que les requêtes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require __DIR__ . '/vendor/PHPMailer/src/Exception.php';
require __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Config SMTP : fichier volontairement hors du dépôt Git (voir mail_config.example.php).
// Il doit être déposé manuellement sur le serveur, à côté de ce script.
$configPath = __DIR__ . '/mail_config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing_config']);
    exit;
}
$config = require $configPath;

// Lecture du corps (JSON envoyé par le site) avec repli sur $_POST classique
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Petite fonction de nettoyage : enlève les retours à la ligne (anti header-injection)
// et limite la longueur des champs.
function clean_field($value, $maxLength = 2000) {
    $value = (string) ($value ?? '');
    $value = str_replace(["\r", "\n"], ' ', $value);
    $value = trim($value);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength);
    } else {
        $value = substr($value, 0, $maxLength);
    }
    return $value;
}

$name        = clean_field($data['name'] ?? '', 200);
$email       = clean_field($data['email'] ?? '', 200);
$attending   = clean_field($data['attending'] ?? '', 100);
$plusOne     = clean_field($data['plusOne'] ?? '', 200);
$meal        = clean_field($data['meal'] ?? '', 100);
// Le message peut contenir des retours à la ligne, donc on le nettoie séparément (sans les retirer)
$message = (string) ($data['message'] ?? '');
$message = trim($message);
if (function_exists('mb_substr')) {
    $message = mb_substr($message, 0, 5000);
} else {
    $message = substr($message, 0, 5000);
}

// Validation minimale
$errors = [];
if ($name === '') {
    $errors[] = 'name';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation', 'fields' => $errors]);
    exit;
}

// Destinataire final (fixe, on ne le laisse jamais venir du formulaire)
$to = 'leopold.guerin@gmail.com';
$subject = 'RSVP - ' . $name;

$bodyLines = [
    "Nouvelle réponse RSVP reçue depuis le site du mariage :",
    "",
    "Nom : " . $name,
    "E-mail : " . $email,
    "Présence : " . ($attending !== '' ? $attending : '—'),
    "Accompagné·e : " . ($plusOne !== '' ? $plusOne : 'non'),
    "Menu : " . ($meal !== '' ? $meal : '—'),
    "Message : " . ($message !== '' ? $message : '—'),
];
$body = implode("\n", $bodyLines);

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $config['host'];
    $mail->Port       = $config['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['username'];
    $mail->Password   = $config['password'];
    $mail->SMTPSecure = $config['encryption'] === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($to);
    // Pour pouvoir répondre directement à la personne qui a rempli le formulaire
    $mail->addReplyTo($email, $name);

    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->isHTML(false);

    $mail->send();
    echo json_encode(['ok' => true]);
} catch (PHPMailerException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mail_failed', 'detail' => $mail->ErrorInfo]);
}
