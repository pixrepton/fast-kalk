<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-IP rate limiting for lead widget REST endpoints.
 */
final class Topinstal_Lead_Widget_Rate_Limit {
    const WINDOW_SECONDS = 60;
    const MAX_REQUESTS = 10;
    const TRANSIENT_PREFIX = 'tilw_rate_';

    /**
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check($request) {
        $ip = self::client_ip($request);
        $key = self::TRANSIENT_PREFIX . md5($ip);
        $bucket = get_transient($key);
        if (!is_array($bucket) || !isset($bucket['resetAt'])) {
            $bucket = array(
                'count' => 0,
                'resetAt' => time() + self::WINDOW_SECONDS,
            );
        }

        $now = time();
        if ($now >= (int) $bucket['resetAt']) {
            $bucket['count'] = 0;
            $bucket['resetAt'] = $now + self::WINDOW_SECONDS;
        }

        $count = isset($bucket['count']) ? (int) $bucket['count'] : 0;
        if ($count >= self::MAX_REQUESTS) {
            $retry = max(1, (int) $bucket['resetAt'] - $now);
            return new WP_Error(
                'tilw_rate_limited',
                'Too many requests. Spróbuj za chwilę.',
                array('status' => 429, 'retry_after' => $retry)
            );
        }

        $bucket['count'] = $count + 1;
        set_transient($key, $bucket, max(1, (int) $bucket['resetAt'] - $now));

        return true;
    }

    /**
     * @param WP_REST_Request $request
     * @return string
     */
    private static function client_ip($request) {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $remote = trim($remote);
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        return '0.0.0.0';
    }
}
