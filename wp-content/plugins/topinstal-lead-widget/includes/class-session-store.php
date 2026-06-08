<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-widget session state: calculate cache, engagement_id, registry idempotency.
 */
final class Topinstal_Lead_Widget_Session_Store {
    const TTL_SECONDS = 86400;

    /**
     * @param string $session_id
     * @return string
     */
    private static function transient_key($session_id) {
        return 'tilw_sess_' . md5((string) $session_id);
    }

    /**
     * @param array<string,mixed> $collected
     * @return string
     */
    public static function fingerprint_collected($collected) {
        $normalized = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
        unset($normalized['contact_email']);
        $json = wp_json_encode($normalized);
        return is_string($json) ? md5($json) : md5((string) time());
    }

    /**
     * @param string $session_id
     * @return array<string,mixed>
     */
    public static function get($session_id) {
        $session_id = trim((string) $session_id);
        if ($session_id === '') {
            return array();
        }
        $data = get_transient(self::transient_key($session_id));
        return is_array($data) ? $data : array();
    }

    /**
     * @param string $session_id
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function merge($session_id, $patch) {
        $current = self::get($session_id);
        foreach ($patch as $key => $value) {
            $current[$key] = $value;
        }
        set_transient(self::transient_key($session_id), $current, self::TTL_SECONDS);
        return $current;
    }

    /**
     * @param string $session_id
     * @param string $fingerprint
     * @return array<string,mixed>|null
     */
    public static function get_cached_calculate($session_id, $fingerprint) {
        $data = self::get($session_id);
        if (
            isset($data['calculate_fingerprint'], $data['calculate_result'])
            && $data['calculate_fingerprint'] === $fingerprint
            && is_array($data['calculate_result'])
        ) {
            return $data['calculate_result'];
        }
        return null;
    }

    /**
     * @param string $session_id
     * @param string $fingerprint
     * @param array<string,mixed> $result
     * @return void
     */
    public static function put_cached_calculate($session_id, $fingerprint, $result) {
        self::merge(
            $session_id,
            array(
                'calculate_fingerprint' => $fingerprint,
                'calculate_result' => $result,
                'calculated_at' => time(),
            )
        );
    }

    /**
     * @param string $session_id
     * @param string $fingerprint
     * @param array<string,mixed> $offer
     * @return void
     */
    public static function put_cached_offer($session_id, $fingerprint, $offer) {
        self::merge(
            $session_id,
            array(
                'offer_fingerprint' => $fingerprint,
                'offer_dto' => $offer,
            )
        );
    }

    /**
     * @param string $session_id
     * @param string $fingerprint
     * @return array<string,mixed>|null
     */
    public static function get_cached_offer($session_id, $fingerprint) {
        $data = self::get($session_id);
        if (
            isset($data['offer_fingerprint'], $data['offer_dto'])
            && $data['offer_fingerprint'] === $fingerprint
            && is_array($data['offer_dto'])
        ) {
            return $data['offer_dto'];
        }
        return null;
    }

    /**
     * @param string $session_id
     * @return string
     */
    public static function get_engagement_id($session_id) {
        $data = self::get($session_id);
        return isset($data['engagement_id']) ? (string) $data['engagement_id'] : '';
    }

    /**
     * @param string $session_id
     * @return bool
     */
    public static function is_registry_done($session_id) {
        $data = self::get($session_id);
        return !empty($data['registry_done']);
    }

    /**
     * @param string $session_id
     * @return string
     */
    public static function get_registry_email($session_id) {
        $data = self::get($session_id);
        return isset($data['registry_email']) ? (string) $data['registry_email'] : '';
    }
}
