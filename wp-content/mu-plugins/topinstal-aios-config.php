<?php
/**
 * Plugin Name: TOP-INSTAL AI-OS Config
 * Description: Defines plugin constants from environment variables (.env).
 * Loads before all other plugins. Handles 1 primary key + 3 fallback keys.
 */

// ── Load .env from plugin root ───────────────────────────────────────────────
$env_files = array(
    __DIR__ . '/../plugins/TopInstalLeadWidget/.env',
    __DIR__ . '/../plugins/HvacRagChat/.env',
);
foreach ($env_files as $env_path) {
    if (!file_exists($env_path)) {
        continue;
    }
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        continue;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        // Handle quotes
        if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && $value !== '') {
            putenv("$key=$value");
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}
