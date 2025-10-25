<?php

class Mailer {
    public static function send2FACode(string $toEmail, string $username, string $code): bool {
        $subject = 'Your San Agustin Portal 2FA Code';
        $message = "Hello {$username},\n\nYour one-time verification code is: {$code}\nThis code will expire in 10 minutes.\n\nIf you did not request this, you can ignore this email.";

        // Try SMTP using config/mail.php
        $smtpCfg = self::loadSmtpConfig();
        if ($smtpCfg && !empty($smtpCfg['enabled']) && !empty($smtpCfg['username']) && !empty($smtpCfg['password'])) {
            $fromEmail = $smtpCfg['from_email'] ?: $smtpCfg['username'];
            $fromName  = $smtpCfg['from_name'] ?: 'San Agustin Portal';
            $ok = self::sendViaSmtp(
                $smtpCfg['host'],
                (int)($smtpCfg['port'] ?? 587),
                $smtpCfg['encryption'] ?? 'tls',
                $smtpCfg['username'],
                $smtpCfg['password'],
                $fromEmail,
                $fromName,
                $toEmail,
                $subject,
                $message,
                (int)($smtpCfg['timeout'] ?? 15)
            );
            if ($ok) return true;
        }

        // Fallback: PHP mail()
        $headers = "From: no-reply@sanagustines.local\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";
        if (@mail($toEmail, $subject, $message, $headers)) {
            return true;
        }

        // Final fallback: log to file
        self::logMail($toEmail, $subject, $code);
        return true;
    }

    private static function loadSmtpConfig(): ?array {
        $cfgPath = __DIR__ . '/../config/mail.php';
        if (file_exists($cfgPath)) {
            $cfg = include $cfgPath;
            return $cfg['smtp'] ?? null;
        }
        return null;
    }

    private static function sendViaSmtp(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $body,
        int $timeout
    ): bool {
        $contextOptions = [];
        $transport = '';
        if (strtolower($encryption) === 'ssl') {
            $transport = 'ssl://';
            $contextOptions['ssl'] = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ];
        }
        $remote = ($transport ? $transport : '') . $host . ':' . $port;

        $errno = 0; $errstr = '';
        $context = stream_context_create($contextOptions);
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            self::logMail($toEmail, $subject, 'SMTP connect failed: ' . $errstr);
            return false;
        }
        stream_set_timeout($fp, $timeout);

        $read = function() use ($fp) {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 515);
                if ($line === false) break;
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break; // end of response
            }
            return $data;
        };

        $write = function($cmd) use ($fp) {
            fwrite($fp, $cmd);
        };

        $expect = function($prefixes) use ($read) {
            $resp = $read();
            foreach ((array)$prefixes as $p) {
                if (strpos($resp, $p) === 0) return $resp;
            }
            return $resp; // caller can decide
        };

        // Greet
        $expect('220');
        $write("EHLO sanagustines.local\r\n");
        $resp = $expect(['250']);

        // STARTTLS if requested on 587
        if (strtolower($encryption) === 'tls' && $port == 587) {
            $write("STARTTLS\r\n");
            $resp = $expect(['220']);
            if (strpos($resp, '220') !== 0) {
                fclose($fp);
                self::logMail($toEmail, $subject, 'STARTTLS not available');
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                self::logMail($toEmail, $subject, 'TLS negotiation failed');
                return false;
            }
            // Re-issue EHLO after TLS
            $write("EHLO sanagustines.local\r\n");
            $expect(['250']);
        }

        // AUTH LOGIN
        $write("AUTH LOGIN\r\n");
        $expect(['334']);
        $write(base64_encode($username) . "\r\n");
        $expect(['334']);
        $write(base64_encode($password) . "\r\n");
        $resp = $expect(['235']);
        if (strpos($resp, '235') !== 0) {
            fclose($fp);
            self::logMail($toEmail, $subject, 'Authentication failed');
            return false;
        }

        // MAIL FROM / RCPT TO
        $write("MAIL FROM:<{$fromEmail}>\r\n");
        $expect(['250']);
        $write("RCPT TO:<{$toEmail}>\r\n");
        $resp = $expect(['250', '251']);
        if (strpos($resp, '250') !== 0 && strpos($resp, '251') !== 0) {
            fclose($fp);
            self::logMail($toEmail, $subject, 'RCPT rejected: ' . $resp);
            return false;
        }

        // DATA
        $write("DATA\r\n");
        $expect(['354']);

        $headers = [];
        $headers[] = 'From: ' . self::encodeHeader($fromName) . " <{$fromEmail}>";
        $headers[] = 'To: <' . $toEmail . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n." . "\r\n";
        $write($data);
        $expect(['250']);

        // QUIT
        $write("QUIT\r\n");
        fclose($fp);
        return true;
    }

    private static function encodeHeader(string $str): string {
        // RFC 2047 encoded-word
        if (preg_match('/[\x80-\xFF]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }

    private static function logMail(string $toEmail, string $subject, string $note): void {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/mail.log';
        $line = date('c') . " | TO: {$toEmail} | SUBJECT: {$subject} | NOTE: {$note}\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
