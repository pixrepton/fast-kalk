<?php
/**
 * Smoke: buffer CO vs radiators_is_ht (hydraulics_inputs → BufferEngine).
 */
require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$base = array(
    'session_id' => 'buffer-hydraulics-smoke',
    'typ_budynku' => 'wolnostojacy',
    'standard' => 'po_2010',
    'emitter_type' => 'grzejniki',
    'powierzchnia' => 100,
    'insulation_level' => 'good',
    'insulation_confirmed' => true,
    'postal_code' => '30-001',
    'dhw_persons' => 4,
    'dhw_usage' => 'normalnie',
    'obecne_ogrzewanie' => 'gaz',
    'ventilation_type' => 'natural',
);

$agentKey = Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '');
$ref = new ReflectionClass('Topinstal_Lead_Widget_Calculator');
$map = $ref->getMethod('map_offer_to_summary');
$map->setAccessible(true);

$fail = 0;

foreach (array(true, false) as $ht) {
    $c = $base;
    $c['hydraulics_confirmed'] = true;
    $c['radiators_is_ht'] = $ht;
    $c['calc_revision'] = $ht ? 1 : 2;

    $req = Topinstal_Lead_Widget_Defaults::to_calc_request($c);
    $hyd = $req['context']['configurator']['hydraulics_inputs'] ?? array();
    echo 'HT=' . ($ht ? 'true' : 'false') . ' hydraulics_inputs: ' . wp_json_encode($hyd) . "\n";

    $offerReq = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
    $offerReq->set_header('Content-Type', 'application/json');
    if ($agentKey !== '') {
        $offerReq->set_header('X-Top-Instal-Agent-Key', Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($agentKey));
    }
    $offerReq->set_body(wp_json_encode($req));
    $res = rest_get_server()->dispatch($offerReq);
    if ($res->is_error()) {
        echo "ERROR: " . $res->as_error()->get_error_message() . "\n";
        $fail++;
        continue;
    }
    $summary = $map->invoke(null, $res->get_data());
    $buf = $summary['bufor_display'] ?? '?';
    echo "HT=" . ($ht ? 'true' : 'false') . " bufor: {$buf} model: " . ($summary['model'] ?? '?') . "\n";

    if ($ht) {
        if (stripos((string) $buf, 'NIE WYMAGANY') !== false) {
            echo "FAIL: HT true should not yield NIE WYMAGANY in typical case\n";
            $fail++;
        }
    }
}

$force = array_merge(
    $base,
    array(
        'last_bufor_display' => 'NIE WYMAGANY',
        'refinement_active' => true,
    )
);
$refine = Topinstal_Lead_Widget_Defaults::refinement_pending_questions($force, 4);
$refine_fields = array_map(static function ($q) {
    return $q['field'];
}, $refine);
echo 'Refinement force fields: ' . implode(', ', $refine_fields) . "\n";
if (!in_array('radiators_is_ht', $refine_fields, true)) {
    echo "FAIL: expected radiators_is_ht in forced refinement\n";
    $fail++;
}

echo $fail === 0 ? "SMOKE OK\n" : "SMOKE FAILED ({$fail})\n";
exit($fail === 0 ? 0 : 1);
