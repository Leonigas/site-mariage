<?php
/**
 * Traitement du formulaire RSVP :
 *  1. Enregistre la réponse en base de données (source de vérité, pour export Excel/CSV)
 *  2. Envoie un e-mail de notification via SMTP authentifié (PHPMailer), en best-effort :
 *     si l'e-mail échoue mais que la BDD a bien enregistré la réponse, on ne fait pas
 *     échouer la requête (la réponse n'est pas perdue).
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

// Fichiers de config volontairement hors du dépôt Git (voir *.example.php)
// ET hors du dossier public (webroot), pour ne jamais être accessibles par une URL.
// Placement attendu sur le serveur : un dossier au-dessus de celui qui contient rsvp.php,
// ex. si rsvp.php est dans /home/xxx/public/rsvp.php, les fichiers vont dans
// /home/xxx/mail_config.php et /home/xxx/db_config.php
$mailConfigPath = dirname(__DIR__) . '/mail_config.php';
$dbConfigPath   = dirname(__DIR__) . '/db_config.php';

if (!file_exists($dbConfigPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing_db_config']);
    exit;
}
$dbConfig = require $dbConfigPath;

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

// 1) Enregistrement en base — c'est la partie critique : si ça échoue, on renvoie
// une erreur pour que le site retombe sur le mailto (pour ne pas perdre la réponse).
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname'],
        $dbConfig['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Crée la table si elle n'existe pas encore (aucune étape manuelle nécessaire)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rsvp_responses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            name VARCHAR(200) NOT NULL,
            email VARCHAR(200) NOT NULL,
            attending VARCHAR(100) NOT NULL,
            plus_one VARCHAR(200) NOT NULL DEFAULT \'\',
            meal VARCHAR(100) NOT NULL DEFAULT \'\',
            message TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $stmt = $pdo->prepare(
        'INSERT INTO rsvp_responses (name, email, attending, plus_one, meal, message)
         VALUES (:name, :email, :attending, :plus_one, :meal, :message)'
    );
    $stmt->execute([
        ':name'      => $name,
        ':email'     => $email,
        ':attending' => $attending,
        ':plus_one'  => $plusOne,
        ':meal'      => $meal,
        ':message'   => $message,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_failed']);
    exit;
}

// 2) Notification par e-mail — best-effort : la réponse est déjà en sécurité en base,
// donc un échec d'envoi ne fait pas échouer la requête.
$mailSent = false;
if (file_exists($mailConfigPath)) {
    $mailConfig = require $mailConfigPath;
    $to = 'contact@mariage-kim-et-leo.fr';
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
        $mail->Host       = $mailConfig['host'];
        $mail->Port       = $mailConfig['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailConfig['username'];
        $mail->Password   = $mailConfig['password'];
        $mail->SMTPSecure = $mailConfig['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
        $mail->addAddress($to);
        $mail->addReplyTo($email, $name);

        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->isHTML(false);

        $mail->send();
        $mailSent = true;
    } catch (PHPMailerException $e) {
        // On avale l'erreur : la réponse est déjà enregistrée en base, c'est l'essentiel.
        $mailSent = false;
    }
}

echo json_encode(['ok' => true, 'mail_sent' => $mailSent]);
