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

        $pdf = self::generate_pdf($generator_base, $offer, $trace_id, $session_id);
        if (is_wp_error($pdf)) {
            error_log('[topinstal-lead-widget] offer dispatch: ' . $pdf->get_error_message());
            $engagement_id = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);
            Topinstal_Lead_Widget_Os_Event_Client::emit(
                'fastkalk.offer.failed',
                'Lead widget: generacja lub wysyłka oferty nie powiodła się',
                'error',
                $engagement_id,
                array(
                    'trace_id' => $trace_id,
                    'error_code' => (string) $pdf->get_error_code(),
                ),
                array(
                    'trace_id' => $trace_id,
                    'session_id' => $session_id,
                )
            );
            return array('delivered' => false, 'error' => $pdf->get_error_code());
        }

        $mail = self::send_emails($collected, $offer, $trace_id, $client_email, $pdf);
        if (is_wp_error($mail)) {
            error_log('[topinstal-lead-widget] offer mail: ' . $mail->get_error_message());
            $engagement_id = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);
            Topinstal_Lead_Widget_Os_Event_Client::emit(
                'fastkalk.offer.failed',
                'Lead widget: wysyłka maila z ofertą nie powiodła się',
                'error',
                $engagement_id,
                array(
                    'trace_id' => $trace_id,
                    'error_code' => (string) $mail->get_error_code(),
                ),
                array(
                    'trace_id' => $trace_id,
                    'session_id' => $session_id,
                )
            );
            return array(
                'delivered' => false,
                'error' => $mail->get_error_code(),
                'pdf_url' => isset($pdf['download_url']) ? (string) $pdf['download_url'] : '',
            );
        }

        $engagement_id = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);
        $pdf_url = isset($pdf['download_url']) ? (string) $pdf['download_url'] : '';
        $document_id = isset($pdf['document_id']) ? (string) $pdf['document_id'] : '';
        if ($document_id === '' && $pdf_url !== '') {
            $document_id = $pdf_url;
        }
        Topinstal_Lead_Widget_Os_Event_Client::emit(
            'fastkalk.offer.delivered',
            'Lead widget: oferta PDF dostarczona operatorem',
            'ok',
            $engagement_id,
            array(
                'trace_id' => $trace_id,
                'operator_sent' => !empty($mail['operator_sent']),
                'client_sent' => !empty($mail['client_sent']),
                'pdf_url' => $pdf_url,
                'document_id' => $document_id,
                'format' => isset($pdf['format']) ? (string) $pdf['format'] : 'pdf',
            ),
            array(
                'trace_id' => $trace_id,
                'session_id' => $session_id,
            )
        );
        self::register_offer_snapshot_link(
            $session_id,
            $trace_id,
            $engagement_id,
            $pdf_url,
            $document_id,
            isset($pdf['format']) ? (string) $pdf['format'] : 'pdf'
        );

        return array(
            'delivered' => true,
            'pdf_url' => $pdf_url,
            'operator_sent' => !empty($mail['operator_sent']),
            'client_sent' => !empty($mail['client_sent']),
        );
    }

    /**
     * @param string $generator_base
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @return array{download_url:string,filename:string,bytes:string,format:string,readiness_status:string}|WP_Error
     */
    private static function generate_pdf($generator_base, $offer, $trace_id, $session_id = '') {
        $url = $generator_base . '/wp-json/topinstal/v1/offer-documents/generate';
        $engagement_id = $session_id !== '' ? Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id) : '';
        $body = self::build_generator_request($offer, $trace_id, $engagement_id);
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

        $resolved = self::resolve_pdf_document($decoded);
        if (is_wp_error($resolved)) {
            return $resolved;
        }
        $download_url = (string) $resolved['download_url'];
        $filename = (string) $resolved['filename'];

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
            'format' => (string) $resolved['format'],
            'readiness_status' => (string) $resolved['readiness_status'],
            'document_id' => isset($resolved['document_id']) ? (string) $resolved['document_id'] : '',
        );
    }

    /**
     * Best-effort correlation link: offer_snapshot → PDF/artifact ref on existing engagement.
     *
     * @param string $session_id
     * @param string $trace_id
     * @param string $engagement_id
     * @param string $pdf_url
     * @param string $document_id
     * @param string $format
     * @return void
     */
    private static function register_offer_snapshot_link(
        $session_id,
        $trace_id,
        $engagement_id,
        $pdf_url,
        $document_id,
        $format
    ) {
        $target = trim((string) $document_id);
        if ($target === '') {
            $target = trim((string) $pdf_url);
        }
        if ($target === '' || trim((string) $engagement_id) === '') {
            return;
        }

        $base = rtrim(Topinstal_Lead_Widget_Plugin::get_option('node_b_registry_url', 'http://127.0.0.1:8766'), '/');
        if ($base === '') {
            return;
        }

        $email = Topinstal_Lead_Widget_Session_Store::get_registry_email($session_id);
        $payload = array(
            'identity_email' => $email,
            'links' => array(
                array(
                    'link_type' => 'offer_snapshot',
                    'target_id' => $target,
                    'source_repo' => 'topinstal-lead-widget',
                    'confidence' => 1.0,
                    'metadata' => array(
                        'pdf_url' => (string) $pdf_url,
                        'document_id' => (string) $document_id,
                        'format' => (string) $format,
                        'session_id' => (string) $session_id,
                        'trace_id' => (string) $trace_id,
                        'engagement_id' => (string) $engagement_id,
                    ),
                ),
            ),
        );
        if ($trace_id !== '') {
            $payload['links'][] = array(
                'link_type' => 'canonical_trace',
                'target_id' => (string) $trace_id,
                'source_repo' => 'topinstal-lead-widget',
                'confidence' => 1.0,
            );
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        );
        $token = Topinstal_Lead_Widget_Plugin::get_option('node_b_registry_token', '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_post(
            $base . '/internal/registry/links',
            array(
                'timeout' => 5,
                'blocking' => true,
                'headers' => $headers,
                'body' => wp_json_encode($payload),
            )
        );
        if (is_wp_error($response)) {
            error_log('[topinstal-lead-widget] offer_snapshot registry: ' . $response->get_error_message());
        }
    }

    /**
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @return array<string,mixed>
     */
    private static function build_generator_request($offer, $trace_id, $engagement_id = '') {
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
            'engagementId' => trim((string) $engagement_id),
            'mode' => 'from-offer-dto',
            'documentType' => 'offer_document',
            'outputFormat' => 'pdf',
            'offerDto' => $offer,
            'context' => self::build_generator_context($offer, $trace_id, $engagement_id),
            'payload' => $payload,
        );
    }

    /**
     * Keep the lead-widget document request on the same generator path as kalk-top:
     * full OfferDTO plus calculator-style context and machine room snapshot.
     *
     * @param array<string,mixed> $offer
     * @param string $trace_id
     * @param string $engagement_id
     * @return array<string,mixed>
     */
    private static function build_generator_context($offer, $trace_id, $engagement_id = '') {
        $context = array(
            'source' => 'fast-kalk',
            'channel' => 'lead_widget',
            'documentMode' => 'offer',
            'generatedAt' => gmdate('c'),
            'traceId' => $trace_id,
            'machineRoomSnapshot' => self::build_machine_room_snapshot($offer),
        );
        $engagement_id = trim((string) $engagement_id);
        if ($engagement_id !== '') {
            $context['engagementId'] = $engagement_id;
        }
        return $context;
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private static function build_machine_room_snapshot($offer) {
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $selection = isset($engineering['selection']) && is_array($engineering['selection']) ? $engineering['selection'] : array();
        $buffer = isset($engineering['buffer']) && is_array($engineering['buffer']) ? $engineering['buffer'] : array();
        $cwu = isset($engineering['cwu']) && is_array($engineering['cwu']) ? $engineering['cwu'] : array();
        $pricing = isset($offer['pricing']) && is_array($offer['pricing']) ? $offer['pricing'] : array();
        $totals = isset($pricing['totals']) && is_array($pricing['totals']) ? $pricing['totals'] : array();

        $components = array();
        $pump_model = self::first_string(array(
            self::read_path($selection, array('pumpModel')),
            self::read_path($selection, array('pump_model')),
        ));
        $pump_power = self::first_number(array(
            self::read_path($selection, array('capacity_kW')),
            self::read_path($selection, array('capacityKw')),
        ));
        if ($pump_model !== '' || $pump_power !== null) {
            $pump = array();
            if ($pump_model !== '') {
                $pump['model'] = $pump_model;
                $pump['label'] = $pump_power !== null
                    ? $pump_model . ' ' . self::format_decimal($pump_power) . ' kW'
                    : $pump_model;
            }
            if ($pump_power !== null) {
                $pump['power_kw'] = $pump_power;
            }
            $components['pump'] = $pump;
        }

        $cwu_capacity = self::first_number(array(
            self::read_path($cwu, array('recommendedCapacityL')),
            self::read_path($cwu, array('resolvedCapacityL')),
            self::read_path($cwu, array('tankCapacityL')),
        ));
        if ($cwu_capacity !== null && $cwu_capacity > 0) {
            $capacity_l = (int) round($cwu_capacity);
            $components['cwu'] = array(
                'label' => 'Trinnity ' . $capacity_l . ' L',
                'name' => 'Trinnity',
                'capacity_l' => $capacity_l,
            );
        }

        $buffer_capacity = self::first_number(array(
            self::read_path($buffer, array('liters')),
            self::read_path($buffer, array('capacity_liters')),
        ));
        $setup_type = strtoupper(trim((string) self::read_path($buffer, array('setupType'))));
        if ($buffer_capacity !== null && $buffer_capacity > 0) {
            $capacity_l = (int) round($buffer_capacity);
            $components['buffer'] = array(
                'label' => 'Bufor ' . $capacity_l . ' L',
                'capacity_l' => $capacity_l,
            );
        } elseif ($setup_type === 'NONE' || $buffer_capacity === 0.0) {
            $components['buffer'] = array(
                'label' => 'Bufor nie wymagany',
            );
        }

        $snapshot = array(
            'selected_components' => $components,
        );
        $gross = self::first_number(array(
            self::read_path($totals, array('gross')),
            self::read_path($totals, array('totalGross')),
        ));
        if ($gross !== null && $gross > 0) {
            $snapshot['total_brutto_pln'] = (int) round($gross);
        }

        return $snapshot;
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
     * @return array<string,string>
     */
    private static function extract_offer_facts($offer) {
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $selection = isset($engineering['selection']) && is_array($engineering['selection']) ? $engineering['selection'] : array();
        $ozc = isset($engineering['ozc']) && is_array($engineering['ozc']) ? $engineering['ozc'] : array();
        $buffer = isset($engineering['buffer']) && is_array($engineering['buffer']) ? $engineering['buffer'] : array();
        $cwu = isset($engineering['cwu']) && is_array($engineering['cwu']) ? $engineering['cwu'] : array();
        $pricing = isset($offer['pricing']) && is_array($offer['pricing']) ? $offer['pricing'] : array();
        $totals = isset($pricing['totals']) && is_array($pricing['totals']) ? $pricing['totals'] : array();

        $pump_model = self::first_string(array(
            self::read_path($selection, array('pumpModel')),
            self::read_path($selection, array('pump_model')),
        ));
        $capacity_number = self::first_number(array(
            self::read_path($selection, array('capacity_kW')),
            self::read_path($selection, array('capacityKw')),
        ));
        $capacity = $capacity_number !== null ? self::format_decimal($capacity_number) : '';
        $gross_number = self::first_number(array(
            self::read_path($totals, array('gross')),
            self::read_path($totals, array('totalGross')),
        ));
        $gross = $gross_number !== null ? (int) round($gross_number) : 0;
        $heat_loss = self::first_number(array(
            self::read_path($ozc, array('designHeatLoss_kW')),
            self::read_path($offer, array('meta', 'max_heating_power')),
        ));
        $recommended_power = self::first_number(array(
            self::read_path($ozc, array('recommendedPower_kW')),
            self::read_path($offer, array('meta', 'recommended_power_kw')),
            $heat_loss,
        ));
        $heat_loss_kw = $heat_loss !== null ? self::format_decimal($heat_loss) : '—';
        $recommended_power_kw = $recommended_power !== null ? self::format_decimal($recommended_power) : '—';
        $buf_liters_number = self::first_number(array(
            self::read_path($buffer, array('liters')),
            self::read_path($buffer, array('capacity_liters')),
        ));
        $buf_liters = $buf_liters_number !== null && $buf_liters_number > 0
            ? (string) (int) round($buf_liters_number)
            : 'nie wymagany';
        $cwu_liters_number = self::first_number(array(
            self::read_path($cwu, array('recommendedCapacityL')),
            self::read_path($cwu, array('resolvedCapacityL')),
            self::read_path($cwu, array('tankCapacityL')),
        ));
        $cwu_liters = $cwu_liters_number !== null && $cwu_liters_number > 0
            ? (string) (int) round($cwu_liters_number)
            : '—';

        return array(
            'pump_label' => $pump_model !== '' ? $pump_model : 'Panasonic Aquarea',
            'gross_pln' => $gross > 0 ? number_format($gross, 0, ',', ' ') : '—',
            'capacity_kw' => $capacity !== '' ? (string) $capacity : '—',
            'buffer_liters' => $buf_liters,
            'cwu_liters' => $cwu_liters,
            'heat_loss_kw' => $heat_loss_kw,
            'recommended_power_kw' => $recommended_power_kw,
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
        $postal_code = isset($collected['postal_code']) ? (string) $collected['postal_code'] : '—';
        $building_type = isset($collected['typ_budynku']) ? (string) $collected['typ_budynku'] : '—';
        $emitter = isset($collected['emitter_type']) ? (string) $collected['emitter_type'] : '—';
        $buffer_label = self::liters_label($facts['buffer_liters']);
        $cwu_label = self::liters_label($facts['cwu_liters']);
        $text = "Nowy lead z fast-kalk widget.\n\n"
            . "Klient: {$client_email}\n"
            . "Pompa: {$facts['pump_label']} ({$facts['capacity_kw']} kW)\n"
            . "Zapotrzebowanie: {$facts['heat_loss_kw']} kW | moc rekomendowana: {$facts['recommended_power_kw']} kW\n"
            . "Bufor: {$buffer_label} | CWU: {$cwu_label}\n"
            . "Cena brutto: {$facts['gross_pln']} PLN\n"
            . "Budynek: {$building_type} | {$area} | {$postal_code} | {$emitter}\n"
            . "Trace: {$trace_id}\n";
        $html = self::mail_shell(
            'Nowy lead TOP-INSTAL',
            'Oferta PDF została wygenerowana z pełnego OfferDTO przez top-instal-generator i dołączona do tej wiadomości.',
            array(
                'Klient' => $client_email,
                'Dobór pompy' => $facts['pump_label'] . ' (' . $facts['capacity_kw'] . ' kW)',
                'Zapotrzebowanie budynku' => $facts['heat_loss_kw'] . ' kW',
                'Moc rekomendowana' => $facts['recommended_power_kw'] . ' kW',
                'Bufor CO' => self::liters_label($facts['buffer_liters']),
                'Zasobnik CWU' => self::liters_label($facts['cwu_liters']),
                'Cena brutto' => $facts['gross_pln'] . ' PLN',
                'Budynek' => $building_type . ' | ' . $area . ' | ' . $postal_code,
                'Emisja ciepła' => $emitter,
                'Trace' => $trace_id,
            ),
            'Źródło: fast-kalk lead-widget.'
        );

        return array('text' => $text, 'html' => $html);
    }

    /**
     * @param array<string,string> $facts
     * @return array{text:string,html:string}
     */
    private static function build_client_body($facts) {
        $text = "Dzień dobry,\n\n"
            . "W załączeniu przesyłamy orientacyjną ofertę PDF na pompę ciepła Panasonic.\n\n"
            . "Dobór: {$facts['pump_label']} ({$facts['capacity_kw']} kW)\n"
            . "Zapotrzebowanie budynku: {$facts['heat_loss_kw']} kW\n"
            . "Szacunkowa cena brutto: {$facts['gross_pln']} PLN\n\n"
            . "Oferta ma charakter orientacyjny — dokładną wycenę przygotujemy po doprecyzowaniu parametrów.\n\n"
            . "Pozdrawiamy,\nZespół TOP-INSTAL\n";
        $html = self::mail_shell(
            'Twoja oferta TOP-INSTAL',
            'W załączeniu przesyłamy orientacyjną ofertę PDF na pompę ciepła Panasonic.',
            array(
                'Rekomendowana pompa' => $facts['pump_label'] . ' (' . $facts['capacity_kw'] . ' kW)',
                'Zapotrzebowanie budynku' => $facts['heat_loss_kw'] . ' kW',
                'Moc rekomendowana' => $facts['recommended_power_kw'] . ' kW',
                'Bufor CO' => self::liters_label($facts['buffer_liters']),
                'Zasobnik CWU' => self::liters_label($facts['cwu_liters']),
                'Szacunkowa cena brutto' => $facts['gross_pln'] . ' PLN',
            ),
            'Oferta ma charakter orientacyjny. Dokładną wycenę przygotujemy po doprecyzowaniu parametrów.'
        );

        return array('text' => $text, 'html' => $html);
    }

    /**
     * @param string $title
     * @param string $intro
     * @param array<string,string> $rows
     * @param string $footer
     * @return string
     */
    private static function mail_shell($title, $intro, $rows, $footer) {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#17202a;line-height:1.5;max-width:680px">';
        $html .= '<div style="border-bottom:3px solid #e30613;padding-bottom:12px;margin-bottom:18px">';
        $html .= '<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#e30613;font-weight:700">TOP-INSTAL</div>';
        $html .= '<h1 style="font-size:22px;margin:4px 0 0">' . esc_html($title) . '</h1>';
        $html .= '</div>';
        $html .= '<p style="margin:0 0 16px">' . esc_html($intro) . '</p>';
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 16px">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>';
            $html .= '<td style="border-top:1px solid #e5e7eb;padding:9px 10px;color:#64748b;width:42%">' . esc_html($label) . '</td>';
            $html .= '<td style="border-top:1px solid #e5e7eb;padding:9px 10px;font-weight:700">' . esc_html($value) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '<p style="margin:0 0 16px">' . esc_html($footer) . '</p>';
        $html .= '<p style="margin:0;color:#64748b">Pozdrawiamy,<br>Zespół TOP-INSTAL</p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function liters_label($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '—') {
            return '—';
        }
        return is_numeric($value) ? $value . ' L' : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $path
     * @return mixed|null
     */
    private static function read_path($data, $path) {
        if (!is_array($data)) {
            return null;
        }
        $cursor = $data;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    /**
     * @param array<int,mixed> $values
     * @return string
     */
    private static function first_string($values) {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<int,mixed> $values
     * @return float|null
     */
    private static function first_number($values) {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    /**
     * @param float|int $value
     * @return string
     */
    private static function format_decimal($value) {
        $formatted = number_format((float) $value, 1, ',', ' ');
        return rtrim(rtrim($formatted, '0'), ',');
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
     * @param array<string,mixed> $decoded
     * @return array{download_url:string,filename:string,format:string,readiness_status:string}|WP_Error
     */
    private static function resolve_pdf_document($decoded) {
        if (!is_array($decoded)) {
            return new WP_Error('generator_invalid_response', 'Generator response must be an object.');
        }
        $document = isset($decoded['document']) && is_array($decoded['document']) ? $decoded['document'] : array();
        $readiness = self::infer_document_readiness($decoded, $document);
        $download_url = isset($document['downloadUrl']) ? (string) $document['downloadUrl'] : '';
        $filename = isset($document['filename']) ? (string) $document['filename'] : 'oferta-pompy-ciepla.pdf';

        if ($readiness['status'] !== 'READY') {
            return new WP_Error(
                'generator_pdf_degraded',
                'Generator did not return a verified PDF artifact.',
                array(
                    'status' => 409,
                    'readiness_status' => $readiness['status'],
                    'requested_format' => $readiness['requested_format'],
                    'actual_format' => $readiness['actual_format'],
                    'degraded_code' => $readiness['degraded_code'],
                )
            );
        }
        if ($readiness['actual_format'] !== 'pdf') {
            return new WP_Error(
                'generator_unexpected_format',
                'Generator returned non-PDF document for PDF-required workflow.',
                array(
                    'status' => 409,
                    'actual_format' => $readiness['actual_format'],
                )
            );
        }
        if ($download_url === '') {
            return new WP_Error('generator_no_url', 'Generator response missing downloadUrl.');
        }
        if (empty($readiness['artifact_verified'])) {
            return new WP_Error('generator_pdf_unverified', 'Generator PDF artifact is not verified.');
        }

        return array(
            'download_url' => $download_url,
            'filename' => $filename,
            'format' => $readiness['actual_format'],
            'readiness_status' => $readiness['status'],
            'document_id' => isset($document['id'])
                ? (string) $document['id']
                : (isset($document['documentId']) ? (string) $document['documentId'] : ''),
        );
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $document
     * @return array{status:string,requested_format:string,actual_format:string,artifact_verified:bool,degraded_code:string}
     */
    private static function infer_document_readiness($decoded, $document) {
        $readiness = isset($decoded['readiness']) && is_array($decoded['readiness']) ? $decoded['readiness'] : array();
        $requested_format = isset($readiness['requestedFormat'])
            ? strtolower((string) $readiness['requestedFormat'])
            : 'pdf';
        $actual_format = isset($readiness['actualFormat'])
            ? strtolower((string) $readiness['actualFormat'])
            : strtolower((string) ($document['format'] ?? ''));
        if ($actual_format === '') {
            $actual_format = 'pdf';
        }
        $has_explicit_artifact_verification = array_key_exists('artifactVerified', $readiness)
            || array_key_exists('verified', $document);
        $artifact_verified = array_key_exists('artifactVerified', $readiness)
            ? !empty($readiness['artifactVerified'])
            : !empty($document['verified']);
        if (
            !$has_explicit_artifact_verification
            && !$artifact_verified
            && !empty($document['downloadUrl'])
            && $actual_format === 'pdf'
        ) {
            $artifact_verified = true;
        }
        $status = isset($readiness['status']) ? strtoupper((string) $readiness['status']) : '';
        if ($status === '') {
            $status = ($requested_format === 'pdf' && $actual_format !== 'pdf') ? 'DEGRADED' : 'READY';
        }
        $degraded_code = isset($readiness['degradedCode']) ? (string) $readiness['degradedCode'] : '';

        return array(
            'status' => $status,
            'requested_format' => $requested_format,
            'actual_format' => $actual_format,
            'artifact_verified' => (bool) $artifact_verified,
            'degraded_code' => $degraded_code,
        );
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
