<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate offer PDF via top-instal-generator and send lead emails (wp_mail).
 */
final class Topinstal_Lead_Widget_Offer_Dispatch {
    /**
     * @param array<string,mixed> $collected
     * @param string $session_id
     * @param string $trace_id
     * @return array<string,mixed>
     */
    public static function maybe_dispatch($collected, $session_id, $trace_id) {
        $client_email = isset($collected['contact_email']) ? sanitize_email((string) $collected['contact_email']) : '';
        if ($client_email === '' || !is_email($client_email)) {
            return array('delivered' => false, 'error' => 'no_email');
        }

        $fingerprint = Topinstal_Lead_Widget_Session_Store::fingerprint_collected($collected);
        $offer = Topinstal_Lead_Widget_Session_Store::get_cached_offer($session_id, $fingerprint);
        if (!is_array($offer)) {
            error_log('[topinstal-lead-widget] offer dispatch: missing cached OfferDTO for session ' . $session_id);
            return array('delivered' => false, 'error' => 'offer_not_cached');
        }

        $generator_base = rtrim(Topinstal_Lead_Widget_Plugin::get_option('generator_url', ''), '/');
        if ($generator_base === '') {
            return array('delivered' => false, 'error' => 'generator_not_configured');
        }

        $pdf = self::generate_pdf($generator_base, $offer, $trace_id);
        if (is_wp_error($pdf)) {
            error_log('[topinstal-lead-widget] offer dispatch: ' . $pdf->get_error_message());
            return array('delivered' => false, 'error' => $pdf->get_error_code());
        }

        $mail = self::send_emails($collected, $offer, $trace_id, $client_email, $pdf);
        if (is_wp_error($mail)) {
            error_log('[topinstal-lead-widget] offer mail: ' . $mail->get_error_message());
            return array(
                'delivered' => false,
                'error' => $mail->get_error_code(),
                'pdf_url' => isset($pdf['download_url']) ? (string) $pdf['download_url'] : '',
            );
        }

        return array(
            'delivered' => true,
            'pdf_url' => isset($pdf['download_url']) ? (string) $pdf['download_url'] : '',
            'operator_sent' => !empty($mail['operator_sent']),
            'client_sent' => !empty($mail['client_sent']),
        );
    }

    /**
     * @param string $generator_base
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @return array{download_url:string,filename:string,bytes:string}|WP_Error
     */
    private static function generate_pdf($generator_base, $offer, $trace_id) {
        $url = $generator_base . '/wp-json/topinstal/v1/offer-documents/generate';
        $body = self::build_generator_request($offer, $trace_id);
        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
        $agent_key = self::resolve_generator_agent_key();
        if ($agent_key !== '') {
            $headers['X-Top-Instal-Agent-Key'] = $agent_key;
        }

        if (self::should_use_internal_generator_dispatch($url)) {
            $decoded = self::call_generator_internal($body, $headers);
            if (is_wp_error($decoded)) {
                return $decoded;
            }
        } else {
            $response = wp_remote_post(
                $url,
                array(
                    'timeout' => 90,
                    'headers' => $headers,
                    'body' => wp_json_encode($body),
                )
            );
            if (is_wp_error($response)) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($raw, true);
            if ($code < 200 || $code >= 300 || !is_array($decoded)) {
                return new WP_Error('generator_http_' . $code, 'Generator HTTP ' . $code, array('status' => $code));
            }
        }

        $document = isset($decoded['document']) && is_array($decoded['document']) ? $decoded['document'] : array();
        $download_url = isset($document['downloadUrl']) ? (string) $document['downloadUrl'] : '';
        $filename = isset($document['filename']) ? (string) $document['filename'] : 'oferta-pompy-ciepla.pdf';
        if ($download_url === '') {
            return new WP_Error('generator_no_url', 'Generator response missing downloadUrl.');
        }

        $pdf_response = wp_remote_get($download_url, array('timeout' => 60));
        if (is_wp_error($pdf_response)) {
            return $pdf_response;
        }
        $pdf_code = (int) wp_remote_retrieve_response_code($pdf_response);
        $pdf_bytes = (string) wp_remote_retrieve_body($pdf_response);
        if ($pdf_code < 200 || $pdf_code >= 300 || $pdf_bytes === '') {
            return new WP_Error('generator_pdf_fetch', 'Failed to download generated PDF.');
        }

        return array(
            'download_url' => $download_url,
            'filename' => $filename,
            'bytes' => $pdf_bytes,
        );
    }

    /**
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @return array<string,mixed>
     */
    private static function build_generator_request($offer, $trace_id) {
        $payload = array();
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $cwu = isset($engineering['cwu']) && is_array($engineering['cwu']) ? $engineering['cwu'] : array();
        $capacity = 0;
        if (isset($cwu['recommendedCapacityL']) && is_numeric($cwu['recommendedCapacityL'])) {
            $capacity = (int) round((float) $cwu['recommendedCapacityL']);
        } elseif (isset($cwu['resolvedCapacityL']) && is_numeric($cwu['resolvedCapacityL'])) {
            $capacity = (int) round((float) $cwu['resolvedCapacityL']);
        }
        if ($capacity > 0) {
            $payload['tank'] = array(
                'enabled' => true,
                'capacity' => (string) $capacity,
                'manufacturer' => 'Trinnity',
            );
        }

        if ($trace_id === '' && isset($offer['traceId'])) {
            $trace_id = (string) $offer['traceId'];
        }
        if ($trace_id === '') {
            $trace_id = 'lead-widget-' . substr(md5(wp_json_encode($offer)), 0, 12);
        }

        return array(
            'schemaVersion' => '1.0',
            'traceId' => $trace_id,
            'mode' => 'from-offer-dto',
            'documentType' => 'offer_document',
            'outputFormat' => 'pdf',
            'offerDto' => $offer,
            'payload' => $payload,
        );
    }

    /**
     * @param array<string,mixed> $collected
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @param string $client_email
     * @param array{download_url:string,filename:string,bytes:string} $pdf
     * @return array{operator_sent:bool,client_sent:bool}|WP_Error
     */
    private static function send_emails($collected, $offer, $trace_id, $client_email, $pdf) {
        $operator_email = sanitize_email(Topinstal_Lead_Widget_Plugin::get_option('operator_email', ''));
        if ($operator_email === '' || !is_email($operator_email)) {
            return new WP_Error('operator_email_missing', 'Operator email not configured.');
        }

        $tmp = self::create_temp_attachment_path($pdf['filename']);
        if ($tmp === '') {
            return new WP_Error('temp_file', 'Cannot create temp file for PDF attachment.');
        }
        file_put_contents($tmp, $pdf['bytes']);

        $facts = self::extract_offer_facts($offer);
        $operator_subject = sprintf(
            '[NOWY LEAD] Pompa Ciepła: %s (%s PLN) | %s',
            $facts['pump_label'],
            $facts['gross_pln'],
            $client_email
        );
        $operator_body = self::build_operator_body($client_email, $facts, $trace_id, $collected);
        $operator_sent = wp_mail(
            $operator_email,
            $operator_subject,
            $operator_body['html'],
            self::mail_headers(),
            array($tmp)
        );

        $client_sent = false;
        $test_only = Topinstal_Lead_Widget_Plugin::get_option('offer_test_mode', '1') === '1';
        if (!$test_only) {
            $lead_to = sanitize_email(Topinstal_Lead_Widget_Plugin::get_option('lead_email_override', ''));
            if ($lead_to === '' || !is_email($lead_to)) {
                $lead_to = $client_email;
            }
            $client_subject = 'TOP-INSTAL – Twoja oferta na pompę ciepła Panasonic';
            $client_body = self::build_client_body($facts);
            $client_sent = wp_mail(
                $lead_to,
                $client_subject,
                $client_body['html'],
                self::mail_headers(),
                array($tmp)
            );
        }

        if (is_file($tmp)) {
            unlink($tmp);
        }

        if (!$operator_sent) {
            return new WP_Error('operator_mail_failed', 'Failed to send operator notification.');
        }

        return array(
            'operator_sent' => $operator_sent,
            'client_sent' => $client_sent,
        );
    }

    /**
     * @param array<string,mixed> $offer
     * @return array{pump_label:string,gross_pln:string,capacity_kw:string,buffer_liters:string,cwu_liters:string}
     */
    private static function extract_offer_facts($offer) {
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $selection = isset($engineering['selection']) && is_array($engineering['selection']) ? $engineering['selection'] : array();
        $buffer = isset($engineering['buffer']) && is_array($engineering['buffer']) ? $engineering['buffer'] : array();
        $cwu = isset($engineering['cwu']) && is_array($engineering['cwu']) ? $engineering['cwu'] : array();
        $pricing = isset($offer['pricing']) && is_array($offer['pricing']) ? $offer['pricing'] : array();
        $totals = isset($pricing['totals']) && is_array($pricing['totals']) ? $pricing['totals'] : array();

        $pump_model = isset($selection['pumpModel']) ? (string) $selection['pumpModel'] : '';
        $capacity = isset($selection['capacity_kW']) ? $selection['capacity_kW'] : '';
        $gross = isset($totals['gross']) && is_numeric($totals['gross']) ? (int) round((float) $totals['gross']) : 0;
        $buf_liters = isset($buffer['liters']) ? (string) $buffer['liters'] : '—';
        $cwu_liters = isset($cwu['recommendedCapacityL']) ? (string) $cwu['recommendedCapacityL'] : '—';

        return array(
            'pump_label' => $pump_model !== '' ? $pump_model : 'Panasonic Aquarea',
            'gross_pln' => $gross > 0 ? number_format($gross, 0, ',', ' ') : '—',
            'capacity_kw' => $capacity !== '' ? (string) $capacity : '—',
            'buffer_liters' => $buf_liters,
            'cwu_liters' => $cwu_liters,
        );
    }

    /**
     * @param string $client_email
     * @param array<string,string> $facts
     * @param string $trace_id
     * @param array<string,mixed> $collected
     * @return array{text:string,html:string}
     */
    private static function build_operator_body($client_email, $facts, $trace_id, $collected) {
        $area = isset($collected['powierzchnia']) ? (string) $collected['powierzchnia'] . ' m²' : '—';
        $text = "Nowy lead z fast-kalk widget.\n\n"
            . "Klient: {$client_email}\n"
            . "Pompa: {$facts['pump_label']} ({$facts['capacity_kw']} kW)\n"
            . "Bufor: {$facts['buffer_liters']} L | CWU: {$facts['cwu_liters']} L\n"
            . "Cena brutto: {$facts['gross_pln']} PLN\n"
            . "Powierzchnia: {$area}\n"
            . "Trace: {$trace_id}\n";
        $html = '<p>Nowy lead z <strong>fast-kalk</strong> widget.</p>'
            . '<p><strong>Klient:</strong> ' . esc_html($client_email) . '<br>'
            . '<strong>Pompa:</strong> ' . esc_html($facts['pump_label']) . ' (' . esc_html($facts['capacity_kw']) . ' kW)<br>'
            . '<strong>Bufor:</strong> ' . esc_html($facts['buffer_liters']) . ' L | <strong>CWU:</strong> ' . esc_html($facts['cwu_liters']) . ' L<br>'
            . '<strong>Cena brutto:</strong> ' . esc_html($facts['gross_pln']) . ' PLN<br>'
            . '<strong>Powierzchnia:</strong> ' . esc_html($area) . '<br>'
            . '<strong>Trace:</strong> ' . esc_html($trace_id) . '</p>';

        return array('text' => $text, 'html' => $html);
    }

    /**
     * @param array<string,string> $facts
     * @return array{text:string,html:string}
     */
    private static function build_client_body($facts) {
        $text = "Dzień dobry,\n\n"
            . "W załączeniu przesyłamy orientacyjną ofertę na pompę ciepła Panasonic.\n\n"
            . "Dobór: {$facts['pump_label']} ({$facts['capacity_kw']} kW)\n"
            . "Szacunkowa cena brutto: {$facts['gross_pln']} PLN\n\n"
            . "Oferta ma charakter orientacyjny — dokładną wycenę przygotujemy po doprecyzowaniu parametrów.\n\n"
            . "Pozdrawiamy,\nZespół TOP-INSTAL\n";
        $html = '<p>Dzień dobry,</p>'
            . '<p>W załączeniu przesyłamy orientacyjną ofertę na pompę ciepła Panasonic.</p>'
            . '<p><strong>Dobór:</strong> ' . esc_html($facts['pump_label']) . ' (' . esc_html($facts['capacity_kw']) . ' kW)<br>'
            . '<strong>Szacunkowa cena brutto:</strong> ' . esc_html($facts['gross_pln']) . ' PLN</p>'
            . '<p>Oferta ma charakter orientacyjny — dokładną wycenę przygotujemy po doprecyzowaniu parametrów.</p>'
            . '<p>Pozdrawiamy,<br>Zespół TOP-INSTAL</p>';

        return array('text' => $text, 'html' => $html);
    }

    /**
     * @return array<string,string>
     */
    private static function mail_headers() {
        $from = sanitize_email(Topinstal_Lead_Widget_Plugin::get_option('mail_from', ''));
        if ($from === '' || !is_email($from)) {
            $from = get_option('admin_email');
        }
        return array(
            'Content-Type: text/html; charset=UTF-8',
            'From: TOP-INSTAL <' . $from . '>',
        );
    }

    /**
     * @param string $filename
     * @return string
     */
    private static function create_temp_attachment_path($filename) {
        if (!function_exists('wp_tempnam')) {
            $admin_file = ABSPATH . 'wp-admin/includes/file.php';
            if (is_readable($admin_file)) {
                require_once $admin_file;
            }
        }
        if (function_exists('wp_tempnam')) {
            $tmp = wp_tempnam($filename);
            return is_string($tmp) && $tmp !== '' ? $tmp : '';
        }
        $suffix = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $filename);
        $tmp = tempnam(sys_get_temp_dir(), 'tilw-');
        if ($tmp === false) {
            return '';
        }
        $target = $tmp . (is_string($suffix) && $suffix !== '' ? '-' . $suffix : '');
        if ($target !== $tmp && !@rename($tmp, $target)) {
            return $tmp;
        }
        return $target;
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function should_use_internal_generator_dispatch($url) {
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
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @return array<string,mixed>|WP_Error
     */
    private static function call_generator_internal($body, $headers) {
        if (!function_exists('rest_get_server')) {
            return new WP_Error('generator_internal', 'REST API niedostępne.');
        }

        $request = new WP_REST_Request('POST', '/topinstal/v1/offer-documents/generate');
        $request->set_header('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            if ($name !== 'Content-Type' && $name !== 'Accept') {
                $request->set_header($name, $value);
            }
        }
        $request->set_body(wp_json_encode($body));

        $response = rest_get_server()->dispatch($request);
        if ($response->is_error()) {
            $error = $response->as_error();
            $status = 502;
            $data = $error->get_error_data();
            if (is_array($data) && isset($data['status'])) {
                $status = (int) $data['status'];
            }
            return new WP_Error($error->get_error_code(), $error->get_error_message(), array('status' => $status));
        }

        $decoded = $response->get_data();
        return is_array($decoded) ? $decoded : new WP_Error('generator_internal_parse', 'Invalid generator response.');
    }

    /**
     * @return string
     */
    private static function resolve_generator_agent_key() {
        $key = Topinstal_Lead_Widget_Plugin::sanitize_ascii_value(
            Topinstal_Lead_Widget_Plugin::get_option('generator_agent_key', '')
        );
        if ($key !== '') {
            return $key;
        }
        return Topinstal_Lead_Widget_Plugin::sanitize_ascii_value(
            Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '')
        );
    }
}
