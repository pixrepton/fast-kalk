<?php
define('WP_USE_THEMES', false);
$wpRoot = dirname(__DIR__) . '/../kalk-top/.runtime-wp/wordpress';
require $wpRoot . '/wp-load.php';

$base = array(
    'session_id' => 'insulation-test',
    'typ_budynku' => 'wolnostojacy',
    'standard' => 'przed_2000',
    'emitter_type' => 'podlogowka',
    'powierzchnia' => 100,
);

$pending = Topinstal_Lead_Widget_Defaults::pending_chat_questions($base);
$fields = array_map(static function ($q) {
    return $q['field'];
}, $pending);

echo "Pending after 100 m2 (no insulation): " . implode(', ', $fields) . "\n";
echo "insulation in pending: " . (in_array('insulation_level', $fields, true) ? 'yes' : 'no') . "\n";

$collected = array_merge(
    $base,
    array(
        'insulation_level' => 'good',
        'insulation_confirmed' => true,
        'postal_code' => '30-001',
        'dhw_persons' => 4,
        'dhw_usage' => 'normalnie',
        'obecne_ogrzewanie' => 'gaz',
        'ventilation_type' => 'natural',
    )
);

$agentKey = Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '');
$ref = new ReflectionClass('Topinstal_Lead_Widget_Calculator');
$map = $ref->getMethod('map_offer_to_summary');
$map->setAccessible(true);

$radiator = array_merge(
    $collected,
    array(
        'emitter_type' => 'grzejniki',
        'hydraulics_confirmed' => true,
    )
);

foreach (array(true, false) as $ht) {
    $c = $radiator;
    $c['radiators_is_ht'] = $ht;
    $req = Topinstal_Lead_Widget_Defaults::to_calc_request($c);
    $offerReq = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
    $offerReq->set_header('Content-Type', 'application/json');
    if ($agentKey !== '') {
        $offerReq->set_header('X-Top-Instal-Agent-Key', Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($agentKey));
    }
    $offerReq->set_body(wp_json_encode($req));
    $res = rest_get_server()->dispatch($offerReq);
    if ($res->is_error()) {
        echo 'grzejniki HT=' . ($ht ? 'true' : 'false') . ': ERROR ' . $res->as_error()->get_error_message() . "\n";
        continue;
    }
    $summary = $map->invoke(null, $res->get_data());
    echo '100 m2 grzejniki HT=' . ($ht ? 'true' : 'false') . ': ' . ($summary['bufor_display'] ?? '?') . ' | ' . ($summary['model'] ?? '?') . "\n";
}

foreach (array('good' => 'good', 'poor' => 'poor') as $label => $level) {
    $c = $collected;
    $c['insulation_level'] = $level;
    $req = Topinstal_Lead_Widget_Defaults::to_calc_request($c);
    $offerReq = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
    $offerReq->set_header('Content-Type', 'application/json');
    if ($agentKey !== '') {
        $offerReq->set_header('X-Top-Instal-Agent-Key', Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($agentKey));
    }
    $offerReq->set_body(wp_json_encode($req));
    $res = rest_get_server()->dispatch($offerReq);
    if ($res->is_error()) {
        echo "{$label}: ERROR " . $res->as_error()->get_error_message() . "\n";
        continue;
    }
    $summary = $map->invoke(null, $res->get_data());
    echo "100 m2, insulation {$level}: " . ($summary['model'] ?? '?') . "\n";
}
