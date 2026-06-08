<?php
/**
 * Plugin Name: TOP-INSTAL WP SMTP (local)
 * Description: Configures PHPMailer for wp_mail() using TOPINSTAL_SMTP_* constants or config/local-secrets.env.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string,string>
 */
function topinstal_wp_smtp_load_env_file() {
    $candidates = array(
        dirname(__FILE__, 3) . '/config/local-secrets.env',
        dirname(__DIR__, 3) . '/fast-kalk/config/local-secrets.env',
    );
    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $parsed = array();
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }
        return $parsed;
    }
    return array();
}

/**
 * @param string $key
 * @param string $default
 * @return string
 */
function topinstal_wp_smtp_env($key, $default = '') {
    static $file_env = null;
    if ($file_env === null) {
        $file_env = topinstal_wp_smtp_load_env_file();
    }
    $const = 'TOPINSTAL_' . $key;
    if (defined($const)) {
        $value = constant($const);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }
    if (isset($file_env[$key]) && trim((string) $file_env[$key]) !== '') {
        return trim((string) $file_env[$key]);
    }
    $raw = getenv($key);
    if (is_string($raw) && trim($raw) !== '') {
        return trim($raw);
    }
    return $default;
}

/**
 * @return bool
 */
function topinstal_wp_smtp_truthy($value) {
    $value = strtolower(trim((string) $value));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

add_action('phpmailer_init', static function ($phpmailer) {
    $host = topinstal_wp_smtp_env('SMTP_HOST', '');
    if ($host === '') {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = (int) topinstal_wp_smtp_env('SMTP_PORT', '587');
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = topinstal_wp_smtp_env('SMTP_USER', '');
    $phpmailer->Password = topinstal_wp_smtp_env('SMTP_PASSWORD', '');

    $use_ssl = topinstal_wp_smtp_env('SMTP_USE_SSL', 'false');
    $use_tls = topinstal_wp_smtp_env('SMTP_USE_TLS', 'true');
    if (topinstal_wp_smtp_truthy($use_ssl) || $phpmailer->Port === 465) {
        $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif (topinstal_wp_smtp_truthy($use_tls)) {
        $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $phpmailer->SMTPSecure = '';
        $phpmailer->SMTPAutoTLS = false;
    }

    $from = topinstal_wp_smtp_env('SMTP_FROM', '');
    if ($from !== '' && is_email($from)) {
        $phpmailer->setFrom($from, 'TOP-INSTAL', false);
    }
});

add_filter('wp_mail_from', static function ($from) {
    $override = topinstal_wp_smtp_env('SMTP_FROM', '');
    return ($override !== '' && is_email($override)) ? $override : $from;
});
