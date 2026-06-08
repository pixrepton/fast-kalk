<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proxies to kalk-top POST /wp-json/topinstal/v1/calculate-offer.
 */
final class Topinstal_Lead_Widget_Calculator {
    const PRICE_FACTOR_MIN = 0.93;
    const PRICE_FACTOR_MAX = 1.16;

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle($request) {
        $rate = Topinstal_Lead_Widget_Rate_Limit::check($request);
        if (is_wp_error($rate)) {
            return $rate;
        }

        $body = $request->get_json_params();
        $collected = is_array($body) && isset($body['collected']) && is_array($body['collected'])
            ? Topinstal_Lead_Widget_Defaults::sanitize_collected($body['collected'])
            : array();

        $session_id = isset($collected['session_id']) ? trim((string) $collected['session_id']) : '';
        $fingerprint = Topinstal_Lead_Widget_Session_Store::fingerprint_collected($collected);

        if ($session_id !== '') {
            $cached = Topinstal_Lead_Widget_Session_Store::get_cached_calculate($session_id, $fingerprint);
            if (is_array($cached)) {
                $cached['cached'] = true;
                $cached['idempotent'] = true;
                $param_score = Topinstal_Lead_Widget_Defaults::count_satisfied_parameters($collected);
                $cached['parameters_filled'] = $param_score['filled'];
                $cached['parameters_total'] = $param_score['total'];
                $cached['parameters_assumed'] = $param_score['assumed'];
                $cached['pending_labels'] = $param_score['pending_labels'];
                $cached['refinement_complete'] = !empty($collected['refinement_complete']);
                if (!isset($cached['engagement_id']) || $cached['engagement_id'] === '') {
                    $cached['engagement_id'] = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);
                }
                return new WP_REST_Response($cached, 200);
            }
        }

        $kalk_ready = self::ensure_kalk_top_available();
        if (is_wp_error($kalk_ready)) {
            return $kalk_ready;
        }

        $calc_request = Topinstal_Lead_Widget_Defaults::to_calc_request($collected);
        $offer = self::call_kalk_top($calc_request);
        if (is_wp_error($offer)) {
            return $offer;
        }

        $summary = self::map_offer_to_summary($offer);
        $trace_id = isset($calc_request['traceId']) ? (string) $calc_request['traceId'] : '';
        $summary['traceId'] = $trace_id;
        $summary['sessionId'] = isset($calc_request['sessionId']) ? (string) $calc_request['sessionId'] : $session_id;
        $param_score = Topinstal_Lead_Widget_Defaults::count_satisfied_parameters($collected);
        $summary['parameters_filled'] = $param_score['filled'];
        $summary['parameters_total'] = $param_score['total'];
        $summary['parameters_assumed'] = $param_score['assumed'];
        $summary['pending_labels'] = $param_score['pending_labels'];
        $summary['refinement_complete'] = !empty($collected['refinement_complete']);
        $summary['cached'] = false;
        $summary['idempotent'] = false;

        $engagement_id = '';
        if ($session_id !== '') {
            $engagement_id = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);
            if ($engagement_id === '' && !Topinstal_Lead_Widget_Session_Store::is_registry_done($session_id)) {
                $reg = Topinstal_Lead_Widget_Lead_Registry::register_sync(
                    $collected,
                    $summary,
                    $trace_id,
                    $session_id
                );
                if (!empty($reg['engagement_id'])) {
                    $engagement_id = (string) $reg['engagement_id'];
                }
            }
        }
        $summary['engagement_id'] = $engagement_id;

        if ($session_id !== '') {
            Topinstal_Lead_Widget_Session_Store::put_cached_calculate($session_id, $fingerprint, $summary);
            Topinstal_Lead_Widget_Session_Store::put_cached_offer($session_id, $fingerprint, $offer);
        }

        return new WP_REST_Response($summary, 200);
    }

    /**
     * @param array<string,mixed> $calc_request
     * @return array<string,mixed>|WP_Error
     */
    private static function call_kalk_top($calc_request) {
        $url = Topinstal_Lead_Widget_Plugin::get_option(
            'calc_rest_url',
            function_exists('rest_url') ? rest_url('topinstal/v1/calculate-offer') : ''
        );
        if ($url === '') {
            return new WP_Error('tilw_calc_url', 'Brak URL calculate-offer.', array('status' => 500));
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );

        $agent_key = self::resolve_calc_agent_key();
        if ($agent_key !== '') {
            $headers['X-Top-Instal-Agent-Key'] = $agent_key;
        } else {
            $headers['X-WP-Nonce'] = wp_create_nonce('wp_rest');
        }

        if (self::should_use_internal_calc_dispatch($url)) {
            return self::call_kalk_top_internal($calc_request, $headers);
        }

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 45,
                'headers' => $headers,
                'body' => wp_json_encode($calc_request),
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('tilw_calc_transport', $response->get_error_message(), array('status' => 502));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = self::extract_calc_error_message($decoded, $code, $raw);
            return new WP_Error('tilw_calc_failed', $message, array('status' => $code >= 400 ? $code : 502));
        }

        if (!is_array($decoded)) {
            return new WP_Error('tilw_calc_parse', 'Nieprawidłowa odpowiedź silnika.', array('status' => 502));
        }

        return $decoded;
    }

    /**
     * PHP built-in server (runtime :8090) cannot HTTP-call itself — use in-process REST.
     *
     * @param string $url
     * @return bool
     */
    private static function should_use_internal_calc_dispatch($url) {
        $target = wp_parse_url($url);
        $site = wp_parse_url(home_url('/'));
        if (!is_array($target) || !is_array($site)) {
            return false;
        }
        $target_host = isset($target['host']) ? strtolower((string) $target['host']) : '';
        $site_host = isset($site['host']) ? strtolower((string) $site['host']) : '';
        if ($target_host === '' || $site_host === '') {
            return false;
        }
        if ($target_host === $site_host) {
            return true;
        }
        return in_array($target_host, array('127.0.0.1', 'localhost'), true)
            && in_array($site_host, array('127.0.0.1', 'localhost'), true);
    }

    /**
     * @return true|WP_Error
     */
    private static function ensure_kalk_top_available() {
        if (!function_exists('rest_get_server')) {
            return new WP_Error('tilw_calc_rest', 'REST API WordPress niedostępne.', array('status' => 500));
        }
        if (!class_exists('TopInstal_CalculateOffer_Controller')) {
            return new WP_Error(
                'tilw_kalk_plugin_missing',
                'Silnik kalkulacji (wtyczka kalk-top) nie jest aktywny na tej instalacji WordPress.',
                array('status' => 503)
            );
        }

        return true;
    }

    /**
     * @return string
     */
    private static function resolve_calc_agent_key() {
        $key = Topinstal_Lead_Widget_Plugin::sanitize_ascii_value(
            Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '')
        );
        if ($key !== '') {
            return $key;
        }

        $wp_option = get_option('topinstal_calc_agent_api_key', '');
        if (is_string($wp_option) && trim($wp_option) !== '') {
            return Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($wp_option);
        }

        return '';
    }

    /**
     * @param mixed $decoded
     * @param int $code
     * @param string $raw
     * @return string
     */
    private static function extract_calc_error_message($decoded, $code, $raw) {
        if (is_array($decoded)) {
            if (!empty($decoded['message']) && is_string($decoded['message'])) {
                return (string) $decoded['message'];
            }
            if (isset($decoded['data']) && is_array($decoded['data']) && !empty($decoded['data']['message'])) {
                return (string) $decoded['data']['message'];
            }
            if (!empty($decoded['errorCode']) && is_string($decoded['errorCode'])) {
                $hint = (string) $decoded['errorCode'];
                if (!empty($decoded['details']) && is_array($decoded['details'])) {
                    $errors = isset($decoded['details']['errors']) && is_array($decoded['details']['errors'])
                        ? $decoded['details']['errors']
                        : array();
                    if (!empty($errors[0]['message'])) {
                        return (string) $errors[0]['message'] . ' (' . $hint . ')';
                    }
                }
                return 'Kalkulacja nie powiodła się (' . $hint . ').';
            }
        }

        if ($code === 401) {
            return 'Brak autoryzacji silnika — ustaw kalk-top Agent Key (TOPINSTAL_CALC_AGENT_API_KEY).';
        }
        if ($code === 403) {
            return 'Nieprawidłowy klucz agenta kalk-top.';
        }
        if ($code === 404) {
            return 'Endpoint calculate-offer nie istnieje — sprawdź, czy wtyczka kalkulatora jest aktywna.';
        }
        if ($code >= 500) {
            return 'Błąd serwera kalkulacji (HTTP ' . $code . ').';
        }
        if ($raw !== '' && strlen($raw) < 200 && strpos($raw, '<') === false) {
            return $raw;
        }

        return 'Kalkulacja nie powiodła się (HTTP ' . $code . ').';
    }

    /**
     * @param array<string,mixed> $calc_request
     * @param array<string,string> $headers
     * @return array<string,mixed>|WP_Error
     */
    private static function call_kalk_top_internal($calc_request, $headers) {
        if (!function_exists('rest_get_server')) {
            return new WP_Error('tilw_calc_internal', 'REST API niedostępne.', array('status' => 500));
        }

        $request = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
        $request->set_header('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            if ($name !== 'Content-Type' && $name !== 'Accept') {
                $request->set_header($name, $value);
            }
        }
        $request->set_body(wp_json_encode($calc_request));

        $response = rest_get_server()->dispatch($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            $status = 502;
            $data = $error->get_error_data();
            if (is_array($data) && isset($data['status'])) {
                $status = (int) $data['status'];
            }
            return new WP_Error(
                $error->get_error_code(),
                $error->get_error_message(),
                array('status' => $status)
            );
        }

        $decoded = $response->get_data();
        if (!is_array($decoded)) {
            return new WP_Error('tilw_calc_parse', 'Nieprawidłowa odpowiedź silnika.', array('status' => 502));
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private static function map_offer_to_summary($offer) {
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $selection = isset($engineering['selection']) && is_array($engineering['selection']) ? $engineering['selection'] : array();
        $buffer = isset($engineering['buffer']) && is_array($engineering['buffer']) ? $engineering['buffer'] : array();
        $cwu = isset($engineering['cwu']) && is_array($engineering['cwu']) ? $engineering['cwu'] : array();
        $pricing = isset($offer['pricing']) && is_array($offer['pricing']) ? $offer['pricing'] : array();
        $totals = isset($pricing['totals']) && is_array($pricing['totals']) ? $pricing['totals'] : array();

        $gross = null;
        if (isset($totals['gross']) && is_numeric($totals['gross'])) {
            $gross = (float) $totals['gross'];
        } elseif (isset($totals['totalGross']) && is_numeric($totals['totalGross'])) {
            $gross = (float) $totals['totalGross'];
        }

        $cena_min = 0;
        $cena_max = 0;
        if ($gross !== null && $gross > 0) {
            $cena_min = (int) round($gross * self::PRICE_FACTOR_MIN);
            $cena_max = (int) round($gross * self::PRICE_FACTOR_MAX);
        }

        $buf_liters = isset($buffer['liters']) ? $buffer['liters'] : (isset($buffer['capacity_liters']) ? $buffer['capacity_liters'] : '');
        $setup = isset($buffer['setupType']) ? (string) $buffer['setupType'] : '';
        $buf_config = $setup !== '' && strtoupper($setup) !== 'NONE' ? $setup : 'standard';

        $cwu_l = isset($cwu['recommendedCapacityL']) ? $cwu['recommendedCapacityL'] : '';
        if ($cwu_l === '' && isset($cwu['tankCapacityL'])) {
            $cwu_l = $cwu['tankCapacityL'];
        }

        $model = '';
        if (isset($selection['pumpModel'])) {
            $model = (string) $selection['pumpModel'];
        } elseif (isset($selection['pump_model'])) {
            $model = (string) $selection['pump_model'];
        }

        $capacity = isset($selection['capacity_kW']) ? $selection['capacity_kW'] : (isset($selection['capacityKw']) ? $selection['capacityKw'] : null);
        if ($model !== '' && $capacity !== null && is_numeric($capacity)) {
            $model .= ' (' . round((float) $capacity, 1) . ' kW)';
        }

        $buf_liters_int = self::parse_liters_value($buf_liters);
        $cwu_liters_int = self::parse_liters_value($cwu_l);
        $setup_upper = strtoupper(trim($setup));
        $buffer_not_required = (
            $buf_liters_int <= 0
            || $setup_upper === ''
            || $setup_upper === 'NONE'
        );

        $assumptions = array();
        $warnings = array();
        if (isset($engineering['assumptions']) && is_array($engineering['assumptions'])) {
            foreach ($engineering['assumptions'] as $item) {
                if (is_string($item) && $item !== '') {
                    $assumptions[] = $item;
                }
            }
        }
        if (isset($engineering['warnings']) && is_array($engineering['warnings'])) {
            foreach ($engineering['warnings'] as $item) {
                if (is_string($item) && $item !== '') {
                    $warnings[] = $item;
                }
            }
        }
        $assumptions = array_slice($assumptions, 0, 2);
        $warnings = array_slice($warnings, 0, 2);

        return array(
            'model' => self::format_public_model_label($model),
            'model_raw' => $model,
            'bufor_display' => $buffer_not_required
                ? 'NIE WYMAGANY'
                : 'DOPASOWANA POJEMNOŚĆ ' . $buf_liters_int . ' LITRÓW',
            'bufor_pojemnosc' => $buf_liters_int > 0 ? (string) $buf_liters_int . ' L' : '—',
            'bufor_konfiguracja' => $buf_config,
            'cwu_display' => $cwu_liters_int > 0
                ? 'STAL NIERDZEWNA. DOPASOWANA POJEMNOŚĆ ' . $cwu_liters_int . ' LITRÓW'
                : '—',
            'cwu_pojemnosc' => $cwu_liters_int > 0 ? (string) $cwu_liters_int . ' L' : '—',
            'cena_min' => $cena_min,
            'cena_max' => $cena_max,
            'orientacyjny' => true,
            'assumptions' => $assumptions,
            'warnings' => $warnings,
        );
    }

    /**
     * @param mixed $value
     * @return int
     */
    private static function parse_liters_value($value) {
        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }
        if (!is_string($value) || $value === '') {
            return 0;
        }
        if (preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @param string $model
     * @return string
     */
    private static function format_public_model_label($model) {
        $model = trim($model);
        if ($model === '') {
            return 'PANASONIC Aquarea K';
        }
        if (stripos($model, 'panasonic') !== false) {
            return $model;
        }

        return 'PANASONIC ' . $model;
    }
}
