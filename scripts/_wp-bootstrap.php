<?php
/**
 * Shared bootstrap for fast-kalk CLI scripts against kalk-top local WP runtime.
 */

$wpRoot = dirname(__DIR__) . '/../kalk-top/.runtime-wp/wordpress';
if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "WP runtime not found at {$wpRoot}\n");
    exit(1);
}

define('WP_USE_THEMES', false);
require $wpRoot . '/wp-load.php';
