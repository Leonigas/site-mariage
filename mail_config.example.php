<?php
/**
 * COPIE ce fichier en "mail_config.php" et remplis les vraies valeurs.
 * "mail_config.php" est volontairement exclu du dépôt Git (voir .gitignore)
 * pour ne jamais publier le mot de passe SMTP sur GitHub : dépose-le
 * manuellement sur le serveur (SFTP / gestionnaire de fichiers), il ne doit
 * JAMAIS être commité.
 *
 * IMPORTANT : place-le UN DOSSIER AU-DESSUS du webroot (le dossier qui
 * contient rsvp.php), pas dedans. Ex. si rsvp.php est dans
 * /home/xxx/public/rsvp.php, mets ce fichier dans /home/xxx/mail_config.php.
 * Ainsi il n'est jamais accessible par une URL, même en cas de souci de
 * configuration Apache. Pense aussi à restreindre ses permissions
 * (ex : chmod 600 mail_config.php) une fois déposé.
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
