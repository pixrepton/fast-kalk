<?php
/**
 * In-process W2 trigger (avoids PHP built-in server self-HTTP hangs).
 *
 * Usage: php scripts/os-event-w2-harness.php
 * Stdout: JSON with trace ids on success.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$kalk_trace = 'os-w2-kalk-' . $suffix;
$fk_session = 'os-w2-session-' . $suffix;
$gen_trace = 'os-w2-gen-' . $suffix;

$fixture_path = dirname(__DIR__) . '/../kalk-top/core/application/harness/fixtures/baseline-floor-heating.json';
if (!is_readable($fixture_path)) {
    fwrite(STDERR, "Missing fixture: {$fixture_path}\n");
    exit(1);
}

$calc_body = json_decode((string) file_get_contents($fixture_path), true);
if (!is_array($calc_body)) {
    fwrite(STDERR, "Invalid fixture JSON\n");
    exit(1);
}
$calc_body['traceId'] = $kalk_trace;
if (!isset($calc_body['lead']) || !is_array($calc_body['lead'])) {
    $calc_body['lead'] = array();
}
$calc_body['lead']['sessionId'] = 'sess-kalk-' . $suffix;

$kalk_req = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
$kalk_req->set_header('Content-Type', 'application/json');
$kalk_req->set_header('X-Top-Instal-Agent-Key', (string) get_option('topinstal_calc_agent_api_key', ''));
$kalk_req->set_body(wp_json_encode($calc_body));
$kalk_res = rest_get_server()->dispatch($kalk_req);
if ($kalk_res->is_error()) {
    $err = $kalk_res->as_error();
    fwrite(STDERR, 'kalk-top dispatch failed: ' . $err->get_error_message() . "\n");
    exit(1);
}
$offer = $kalk_res->get_data();
if (!is_array($offer)) {
    fwrite(STDERR, "kalk-top returned non-array\n");
    exit(1);
}

$collected = array(
    'session_id' => $fk_session,
    'typ_budynku' => 'wolnostojacy',
    'standard' => 'sredni',
    'emitter_type' => 'podlogowka',
    'powierzchnia' => 135,
    'postal_code' => '30-001',
    'insulation_level' => 'good',
    'refinement_complete' => true,
);
$lead_req = new WP_REST_Request('POST', '/topinstal-lead/v1/calculate');
$lead_req->set_header('Content-Type', 'application/json');
$lead_req->set_body(wp_json_encode(array('collected' => $collected)));
$lead_res = Topinstal_Lead_Widget_Calculator::handle($lead_req);
if (is_wp_error($lead_res)) {
    fwrite(STDERR, 'fast-kalk calculate failed: ' . $lead_res->get_error_message() . "\n");
    exit(1);
}
$lead_summary = $lead_res->get_data();
$fk_trace = is_array($lead_summary) && isset($lead_summary['traceId']) ? (string) $lead_summary['traceId'] : '';

$gen_body = array(
    'schemaVersion' => '1.0',
    'traceId' => $gen_trace,
    'mode' => 'from-offer-dto',
    'documentType' => 'offer_document',
    'outputFormat' => 'pdf',
    'offerDto' => $offer,
    'payload' => array(),
);
$gen_req = new WP_REST_Request('POST', '/topinstal/v1/offer-documents/generate');
$gen_req->set_header('Content-Type', 'application/json');
$gen_req->set_header('X-Top-Instal-Agent-Key', (string) get_option('top_instal_agent_api_key', ''));
$gen_req->set_body(wp_json_encode($gen_body));
$gen_res = rest_get_server()->dispatch($gen_req);
if ($gen_res->is_error()) {
    $err = $gen_res->as_error();
    fwrite(STDERR, 'generator dispatch failed: ' . $err->get_error_message() . "\n");
    exit(1);
}

echo wp_json_encode(array(
    'ok' => true,
    'suffix' => $suffix,
    'traces' => array(
        'kalk' => $kalk_trace,
        'fast_kalk' => $fk_trace,
        'generator' => $gen_trace,
    ),
), JSON_UNESCAPED_UNICODE) . "\n";
