<?php
/**
 * COPIE ce fichier en "mail_config.php" (même dossier) et remplis les vraies
 * valeurs. "mail_config.php" est volontairement exclu du dépôt Git
 * (voir .gitignore) pour ne jamais publier le mot de passe SMTP sur GitHub :
 * dépose-le manuellement sur le serveur (SFTP / gestionnaire de fichiers),
 * il ne doit JAMAIS être commité.
 */

return [
    'host'       => 'smtp.ionos.fr',
    'port'       => 587,
    'encryption' => 'tls', // 'tls' (port 587, STARTTLS) ou 'ssl' (port 465)
    'username'   => 'contact@mariage-kim-et-leo.fr',
    'password'   => 'REMPLACE_MOI',
    'from_email' => 'contact@mariage-kim-et-leo.fr',
    'from_name'  => 'Mariage Kim & Léopold',
];
