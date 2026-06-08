<?php
/**
 * Local smoke: lead-widget REST without HTTP self-call deadlock (PHP built-in server).
 * Run from repo root: php scripts/local-rest-smoke.php
 */

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$collected = array(
    'session_id' => 'smoke-' . gmdate('YmdHis'),
    'typ_budynku' => 'wolnostojacy',
    'standard' => 'sredni',
    'emitter_type' => 'podlogowka',
    'powierzchnia' => 140,
    'postal_code' => '30-001',
    'insulation_level' => 'good',
    'dhw_persons' => 4,
    'dhw_usage' => 'normalnie',
    'ventilation_type' => 'natural',
);

echo "Plugin version: " . TOPINSTAL_LEAD_WIDGET_VERSION . "\n";

// --- Chat (fallback bez Anthropic gdy brak klucza) ---
$nonce = wp_create_nonce('wp_rest');
$chatReq = new WP_REST_Request('POST', '/topinstal-lead/v1/chat');
$chatReq->set_header('Content-Type', 'application/json');
$chatReq->set_header('X-WP-Nonce', $nonce);
$chatReq->set_body(wp_json_encode(array(
    'messages' => array(
        array('role' => 'assistant', 'content' => 'Test'),
    ),
    'collected' => array(
        'session_id' => $collected['session_id'],
        'typ_budynku' => $collected['typ_budynku'],
        'standard' => $collected['standard'],
        'emitter_type' => $collected['emitter_type'],
    ),
)));
$chatRes = rest_get_server()->dispatch($chatReq);
echo "CHAT status: " . $chatRes->get_status() . "\n";
if ($chatRes->is_error()) {
    echo "CHAT error: " . $chatRes->as_error()->get_error_message() . "\n";
} else {
    $chatData = $chatRes->get_data();
    echo "CHAT done=" . (empty($chatData['done']) ? 'false' : 'true') . " message_len=" . strlen((string) ($chatData['message'] ?? '')) . "\n";
}

// --- Calculate-offer (internal dispatch — omija deadlock PHP -S) ---
$calcRequest = Topinstal_Lead_Widget_Defaults::to_calc_request($collected);
$offerReq = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
$offerReq->set_header('Content-Type', 'application/json');
$agentKey = Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '');
if ($agentKey !== '') {
    $offerReq->set_header('X-Top-Instal-Agent-Key', Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($agentKey));
}
$offerReq->set_body(wp_json_encode($calcRequest));
$offerRes = rest_get_server()->dispatch($offerReq);
echo "CALC-OFFER status: " . $offerRes->get_status() . "\n";
if ($offerRes->is_error()) {
    echo "CALC-OFFER error: " . $offerRes->as_error()->get_error_message() . "\n";
    exit(1);
}

// Map offer jak class-calculator.php
$offerData = $offerRes->get_data();
$ref = new ReflectionClass('Topinstal_Lead_Widget_Calculator');
$map = $ref->getMethod('map_offer_to_summary');
$map->setAccessible(true);
$summary = $map->invoke(null, $offerData);
echo "MAPPED model=" . $summary['model'] . " cena_min=" . $summary['cena_min'] . " cena_max=" . $summary['cena_max'] . "\n";

// --- Lead widget /calculate przez HTTP (PHP -S: często timeout — osobny proces) ---
$leadReq = new WP_REST_Request('POST', '/topinstal-lead/v1/calculate');
$leadReq->set_header('Content-Type', 'application/json');
$leadReq->set_body(wp_json_encode(array('collected' => $collected)));
$leadRes = Topinstal_Lead_Widget_Calculator::handle($leadReq);
if (is_wp_error($leadRes)) {
    echo "LEAD /calculate WP_Error: " . $leadRes->get_error_message() . " (typowe na PHP built-in server — self-HTTP)\n";
} else {
    $leadSummary = $leadRes->get_data();
    echo "LEAD /calculate OK model=" . ($leadSummary['model'] ?? '?') . "\n";
}

echo "SMOKE OK\n";
