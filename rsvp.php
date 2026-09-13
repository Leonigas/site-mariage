<?php
/**
 * Traitement du formulaire RSVP - envoie un e-mail directement au marié
 * sans passer par mailto: (qui dépend du logiciel de messagerie du visiteur).
 */

header('Content-Type: application/json; charset=utf-8');

// N'accepte que les requêtes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

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

// Destinataire (fixe, on ne le laisse jamais venir du formulaire)
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

// En-têtes de l'e-mail. On envoie "From" avec un domaine du site (beaucoup
// d'hébergeurs rejettent un From avec un domaine externe type gmail.com),
// et on met l'adresse du répondant en "Reply-To" pour pouvoir lui répondre
// directement depuis la boîte mail.
$hostForFrom = $_SERVER['SERVER_NAME'] ?? 'mariage-kim-et-leo.fr';
$fromAddress = 'rsvp@' . $hostForFrom;

$headers = [];
$headers[] = 'From: Site Mariage <' . $fromAddress . '>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$success = mail($to, $subject, $body, implode("\r\n", $headers));

if ($success) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mail_failed']);
}
