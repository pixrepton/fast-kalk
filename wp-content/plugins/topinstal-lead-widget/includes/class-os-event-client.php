<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Best-effort POST to gmail-agent Node B unified_os_events (channel A′).
 */
final class Topinstal_Lead_Widget_Os_Event_Client {
    const SOURCE_REPO = 'fast-kalk';

    /**
     * @param string $event_type
     * @param string $summary_pl
     * @param string $status ok|warning|error
     * @param string $engagement_id
     * @param array<string,mixed> $payload_extra
     * @param array<string,mixed> $correlation
     * @return void
     */
    public static function emit(
        $event_type,
        $summary_pl,
        $status = 'ok',
        $engagement_id = '',
        $payload_extra = array(),
        $correlation = array()
    ) {
        $base = self::base_url();
        if ($base === '') {
            return;
        }

        $et = trim((string) $event_type);
        if ($et === '') {
            return;
        }

        $payload = array(
            'schema_version' => 'topinstal.os_event.v1',
            'summary_pl' => trim((string) $summary_pl) !== '' ? trim((string) $summary_pl) : $et,
            'status' => trim((string) $status) !== '' ? trim((string) $status) : 'ok',
        );
        if (is_array($payload_extra)) {
            foreach ($payload_extra as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $payload[(string) $key] = $value;
            }
        }

        $body = array(
            'event_type' => $et,
            'source_repo' => self::SOURCE_REPO,
            'engagement_id' => trim((string) $engagement_id),
            'payload' => $payload,
            'correlation' => is_array($correlation) ? $correlation : array(),
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
        $token = self::registry_token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_post(
            $base . '/internal/os-events',
            array(
                'timeout' => 8,
                'blocking' => true,
                'headers' => $headers,
                'body' => wp_json_encode($body),
            )
        );

        if (is_wp_error($response)) {
            error_log('[topinstal-lead-widget] os_event emit transport: ' . $response->get_error_message());
        }
    }

    /**
     * @return string
     */
    private static function base_url() {
        $raw = Topinstal_Lead_Widget_Plugin::get_option('node_b_registry_url', 'http://127.0.0.1:8766');
        return rtrim(trim((string) $raw), '/');
    }

    /**
     * @return string
     */
    private static function registry_token() {
        return trim((string) Topinstal_Lead_Widget_Plugin::get_option('node_b_registry_token', ''));
    }
}
