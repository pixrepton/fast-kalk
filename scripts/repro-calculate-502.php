<?php
$wpRoot = dirname(__DIR__) . '/../kalk-top/.runtime-wp/wordpress';
define('WP_USE_THEMES', false);
require $wpRoot . '/wp-load.php';

$collected = array(
    'session_id' => 'prod-repro-' . gmdate('YmdHis'),
    'typ_budynku' => 'blizniak',
    'standard' => '2000_2010',
    'emitter_type' => 'grzejniki',
    'powierzchnia' => 200,
    'postal_code' => '43-600',
    'insulation_level' => '200',
    'insulation_confirmed' => true,
    'dhw_persons' => 5,
    'dhw_usage' => 'normalnie',
    'radiators_is_ht' => false,
    'hydraulics_confirmed' => true,
    'ventilation_type' => 'naturalna',
    'obecne_ogrzewanie' => 'inne',
);

$san = Topinstal_Lead_Widget_Defaults::sanitize_collected($collected);
echo 'sanitized insulation_level=' . ($san['insulation_level'] ?? '(none)') . "\n";
echo 'insulation_confirmed=' . (!empty($san['insulation_confirmed']) ? '1' : '0') . "\n";

$req = new WP_REST_Request('POST', '/topinstal-lead/v1/calculate');
$req->set_header('Content-Type', 'application/json');
$req->set_body(wp_json_encode(array('collected' => $collected)));
$res = Topinstal_Lead_Widget_Calculator::handle($req);
if (is_wp_error($res)) {
    echo 'ERROR: ' . $res->get_error_message() . "\n";
    echo 'data: ' . wp_json_encode($res->get_error_data()) . "\n";
    exit(1);
}
$d = $res->get_data();
echo 'OK model=' . ($d['model'] ?? '?') . "\n";
