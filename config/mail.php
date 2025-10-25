<?php
// Mail configuration for sending 2FA codes
// IMPORTANT: For Gmail, you must use an App Password (not your regular password)
// Generate at: https://myaccount.google.com/apppasswords
return [
    'smtp' => [
        'enabled'  => true,
        'host'     => 'smtp.gmail.com',
        'port'     => 587,           // TLS
        'encryption' => 'tls',       // 'tls' or 'ssl'
        'username' => 'jeanmarcaguilar829@gmail.com',
        'password' => 'zosr dzsq ggtl nrbi',            // <-- PUT YOUR 16-char Gmail App Password here
        'from_email' => 'jeanmarcaguilar829@gmail.com',
        'from_name'  => 'San Agustin Portal',
        'timeout'    => 15           // seconds
    ]
];
