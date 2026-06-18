<?php



if (!defined('ABSPATH')) {

    exit;

}



/**

 * Best-effort registration in gmail-agent Node B correlation registry.

 */

final class Topinstal_Lead_Widget_Lead_Registry {

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

        if (!is_array($body)) {

            return new WP_Error('tilw_invalid_body', 'Invalid JSON body.', array('status' => 400));

        }



        $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected(

            isset($body['collected']) && is_array($body['collected']) ? $body['collected'] : array()

        );

        $result_summary = isset($body['result_summary']) && is_array($body['result_summary'])

            ? $body['result_summary']

            : array();



        $trace_id = isset($body['traceId']) ? sanitize_text_field((string) $body['traceId']) : '';

        $session_id = isset($collected['session_id']) ? (string) $collected['session_id'] : '';

        if ($session_id === '') {

            $session_id = 'lw-' . wp_generate_uuid4();

            $collected['session_id'] = $session_id;

        }



        $incoming_email = self::resolve_contact_email($collected);

        $existing = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);

        $registry_done = Topinstal_Lead_Widget_Session_Store::is_registry_done($session_id);

        $stored_email = Topinstal_Lead_Widget_Session_Store::get_registry_email($session_id);



        if (($existing !== '' || $registry_done) && self::should_enrich_registry_email($incoming_email, $stored_email)) {

            $sync = self::register_sync(

                $collected,

                $result_summary,

                $trace_id,

                $session_id,

                array('enrich_identity' => true)

            );

            $engagement_id = isset($sync['engagement_id']) ? (string) $sync['engagement_id'] : $existing;

            if ($engagement_id === '') {

                $engagement_id = $existing;

            }



            return self::respond(

                array(

                    'ok' => !empty($sync['ok']),

                    'queued' => false,

                    'engagement_id' => $engagement_id,

                    'deduplicated' => false,

                    'identity_enriched' => true,

                ),

                $collected,

                $session_id,

                $trace_id

            );

        }



        if ($existing !== '' || $registry_done) {

            return self::respond(

                array(

                    'ok' => true,

                    'queued' => false,

                    'engagement_id' => $existing,

                    'deduplicated' => true,

                ),

                $collected,

                $session_id,

                $trace_id

            );

        }



        $sync = self::register_sync($collected, $result_summary, $trace_id, $session_id);

        if (!empty($sync['engagement_id'])) {

            Topinstal_Lead_Widget_Session_Store::merge(

                $session_id,

                array(

                    'engagement_id' => (string) $sync['engagement_id'],

                    'registry_done' => true,

                    'registry_email' => self::registry_email_for_collected($collected),

                )

            );

        } else {

            self::register_async($collected, $result_summary, $trace_id, $session_id);

        }



        return self::respond(

            array(

                'ok' => true,

                'queued' => empty($sync['engagement_id']),

                'engagement_id' => isset($sync['engagement_id']) ? (string) $sync['engagement_id'] : '',

            ),

            $collected,

            $session_id,

            $trace_id

        );

    }



    /**

     * Blocking registry call (short timeout) — returns engagement_id when Node B responds.

     *

     * @param array<string,mixed> $collected

     * @param array<string,mixed> $result_summary

     * @param string $trace_id

     * @param string $session_id

     * @param array<string,mixed> $options

     * @return array<string,mixed>

     */

    public static function register_sync($collected, $result_summary, $trace_id, $session_id, $options = array()) {

        $enrich_identity = !empty($options['enrich_identity']);

        $session_id = trim((string) $session_id);

        if ($session_id !== '' && !$enrich_identity) {

            $existing = Topinstal_Lead_Widget_Session_Store::get_engagement_id($session_id);

            if ($existing !== '' || Topinstal_Lead_Widget_Session_Store::is_registry_done($session_id)) {

                $incoming_email = self::resolve_contact_email($collected);

                $stored_email = Topinstal_Lead_Widget_Session_Store::get_registry_email($session_id);

                if (self::should_enrich_registry_email($incoming_email, $stored_email)) {

                    $enrich_identity = true;

                } else {

                    return array(

                        'ok' => true,

                        'engagement_id' => $existing,

                        'deduplicated' => true,

                    );

                }

            }

        }



        $built = self::build_registry_request($collected, $result_summary, $trace_id, $session_id);

        if (is_wp_error($built)) {

            return array('ok' => false, 'error' => $built->get_error_message());

        }



        $response = wp_remote_post(

            $built['url'],

            array(

                'timeout' => 8,

                'blocking' => true,

                'headers' => $built['headers'],

                'body' => wp_json_encode($built['payload']),

            )

        );



        if (is_wp_error($response)) {

            error_log('[topinstal-lead-widget] registry sync error: ' . $response->get_error_message());

            return array('ok' => false, 'error' => $response->get_error_message());

        }



        $code = (int) wp_remote_retrieve_response_code($response);

        $raw = (string) wp_remote_retrieve_body($response);

        $decoded = json_decode($raw, true);



        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            Topinstal_Lead_Widget_Os_Event_Client::emit(
                'fastkalk.registry.failed',
                'Lead widget: rejestracja engagement w Node B nie powiodła się',
                'error',
                '',
                array(
                    'trace_id' => $trace_id,
                    'error_code' => 'registry_http_' . $code,
                ),
                array(
                    'trace_id' => $trace_id,
                    'session_id' => $session_id,
                )
            );

            return array('ok' => false, 'error' => 'registry_http_' . $code);

        }



        $engagement_id = isset($decoded['engagement_id']) ? (string) $decoded['engagement_id'] : '';

        if ($session_id !== '' && $engagement_id !== '') {

            Topinstal_Lead_Widget_Session_Store::merge(

                $session_id,

                array(

                    'engagement_id' => $engagement_id,

                    'registry_done' => true,

                    'registry_email' => isset($built['payload']['identity_email'])

                        ? (string) $built['payload']['identity_email']

                        : self::registry_email_for_collected($collected),

                )

            );

        }



        return array(

            'ok' => true,

            'engagement_id' => $engagement_id,

            'identity_id' => isset($decoded['identity_id']) ? (string) $decoded['identity_id'] : '',

            'identity_enriched' => $enrich_identity,

        );

    }



    /**

     * @param array<string,mixed> $collected

     * @param array<string,mixed> $result_summary

     * @param string $trace_id

     * @param string $session_id

     * @return void

     */

    private static function register_async($collected, $result_summary, $trace_id, $session_id) {

        $built = self::build_registry_request($collected, $result_summary, $trace_id, $session_id);

        if (is_wp_error($built)) {

            error_log('[topinstal-lead-widget] registry skipped: ' . $built->get_error_message());

            return;

        }



        $response = wp_remote_post(

            $built['url'],

            array(

                'timeout' => 5,

                'blocking' => false,

                'headers' => $built['headers'],

                'body' => wp_json_encode($built['payload']),

            )

        );



        if (is_wp_error($response)) {

            error_log('[topinstal-lead-widget] registry transport error: ' . $response->get_error_message());

        }

    }



    /**

     * @param array<string,mixed> $collected

     * @return string

     */

    private static function resolve_contact_email($collected) {

        if (!isset($collected['contact_email'])) {

            return '';

        }

        $candidate = sanitize_email((string) $collected['contact_email']);

        if ($candidate !== '' && is_email($candidate)) {

            return $candidate;

        }

        return '';

    }



    /**

     * @param array<string,mixed> $collected

     * @return string

     */

    private static function registry_email_for_collected($collected) {

        $email = self::resolve_contact_email($collected);

        if ($email !== '') {

            return $email;

        }

        $session_id = isset($collected['session_id']) ? (string) $collected['session_id'] : '';

        if ($session_id === '') {

            return '';

        }

        return 'lead-widget+' . sanitize_key($session_id) . '@widget.topinstal.local';

    }



    /**

     * @param string $incoming_email

     * @param string $stored_email

     * @return bool

     */

    public static function should_enrich_registry_email($incoming_email, $stored_email) {

        $incoming_email = strtolower(trim($incoming_email));

        if ($incoming_email === '' || !is_email($incoming_email)) {

            return false;

        }

        if (self::is_widget_placeholder_email($incoming_email)) {

            return false;

        }

        $stored_email = strtolower(trim($stored_email));

        if ($stored_email === $incoming_email) {

            return false;

        }

        return $stored_email === '' || self::is_widget_placeholder_email($stored_email);

    }



    /**

     * @param string $email

     * @return bool

     */

    public static function is_widget_placeholder_email($email) {

        $email = strtolower(trim($email));

        return (bool) preg_match('/^lead-widget\+.+@widget\.topinstal\.local$/', $email);

    }



    /**

     * @param array<string,mixed> $collected

     * @param array<string,mixed> $result_summary

     * @param string $trace_id

     * @param string $session_id

     * @return array{url:string,headers:array<string,string>,payload:array<string,mixed>}|WP_Error

     */

    private static function build_registry_request($collected, $result_summary, $trace_id, $session_id) {

        $base = rtrim(Topinstal_Lead_Widget_Plugin::get_option('node_b_registry_url', 'http://127.0.0.1:8766'), '/');

        if ($base === '') {

            return new WP_Error('tilw_registry_url', 'empty NODE_B_REGISTRY_URL');

        }



        $email = self::registry_email_for_collected($collected);



        $links = array(

            array(

                'link_type' => 'calc_request_snapshot',

                'target_id' => $session_id,

                'source_repo' => 'topinstal-lead-widget',

                'confidence' => 0.9,

            ),

        );

        if ($trace_id !== '') {

            $links[] = array(

                'link_type' => 'canonical_trace',

                'target_id' => $trace_id,

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



        return array(

            'url' => $base . '/internal/registry/links',

            'headers' => $headers,

            'payload' => array(

                'identity_email' => $email,

                'display_name' => self::build_display_name($collected, $result_summary),

                'links' => $links,

            ),

        );

    }



    /**

     * @param array<string,mixed> $collected

     * @param array<string,mixed> $result_summary

     * @return string

     */

    private static function build_display_name($collected, $result_summary) {

        $snippet = array(

            'source' => 'lead_widget',

            'collected' => $collected,

            'result' => $result_summary,

        );

        $json = wp_json_encode($snippet, JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {

            return 'lead_widget';

        }

        if (strlen($json) > 240) {

            $json = substr($json, 0, 237) . '...';

        }

        return $json;

    }



    /**

     * @param array<string,mixed> $payload

     * @param array<string,mixed> $collected

     * @param string $session_id

     * @param string $trace_id

     * @return array<string,mixed>

     */

    private static function attach_offer_dispatch(array $payload, $collected, $session_id, $trace_id) {

        if (self::resolve_contact_email($collected) === '') {

            return $payload;

        }

        $dispatch = Topinstal_Lead_Widget_Offer_Dispatch::maybe_dispatch($collected, $session_id, $trace_id);

        $payload['offer_delivered'] = !empty($dispatch['delivered']);

        if (!empty($dispatch['pdf_url'])) {

            $payload['pdf_url'] = (string) $dispatch['pdf_url'];

        }

        if (!empty($dispatch['error'])) {

            $payload['offer_error'] = (string) $dispatch['error'];

        }

        return $payload;

    }



    /**

     * @param array<string,mixed> $payload

     * @param array<string,mixed> $collected

     * @param string $session_id

     * @param string $trace_id

     * @return WP_REST_Response

     */

    private static function respond(array $payload, $collected, $session_id, $trace_id) {

        return new WP_REST_Response(

            self::attach_offer_dispatch($payload, $collected, $session_id, $trace_id),

            200

        );

    }

}
