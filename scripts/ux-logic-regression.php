<?php
/**
 * Targeted regression for UX/logic fixes:
 * - public building options
 * - unsupported stale building payload
 * - current heat source vs bivalence decision split
 * - 3-step public progress metadata
 */

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$fail = 0;
$passed = 0;

/**
 * @param bool $ok
 * @param string $label
 * @return void
 */
function ux_assert_true($ok, $label) {
    global $fail, $passed;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}\n";
    $fail++;
}

/**
 * @param array<string,mixed> $override
 * @return array<string,mixed>
 */
function ux_base_collected($override = array()) {
    return array_merge(
        array(
            'session_id' => 'ux-regression-' . gmdate('YmdHis'),
            'typ_budynku' => 'wolnostojacy',
            'standard' => 'sredni',
            'emitter_type' => 'podlogowka',
            'powierzchnia' => 140,
            'postal_code' => '30-001',
            'insulation_level' => 'good',
            'insulation_confirmed' => true,
            'dhw_persons' => 4,
            'dhw_usage' => 'shower_bath',
            'ventilation_type' => 'natural',
        ),
        $override
    );
}

/**
 * @param array<string,mixed> $collected
 * @return array<string,mixed>|WP_Error
 */
function ux_run_calculate($collected) {
    $req = new WP_REST_Request('POST', '/topinstal-lead/v1/calculate');
    $req->set_header('Content-Type', 'application/json; charset=utf-8');
    $req->set_body(wp_json_encode(array('collected' => $collected)));
    $res = Topinstal_Lead_Widget_Calculator::handle($req);
    if (is_wp_error($res)) {
        return $res;
    }
    return $res->get_data();
}

$widgetJs = file_get_contents(dirname(__DIR__) . '/wp-content/plugins/topinstal-lead-widget/assets/widget.js');
$widgetJs = is_string($widgetJs) ? $widgetJs : '';
ux_assert_true(strpos($widgetJs, "value: 'inny'") === false, 'public JS options do not include value=inny');
ux_assert_true(strpos($widgetJs, 'Wielorodzinny') === false, 'public JS options do not include Wielorodzinny');
ux_assert_true((bool) preg_match('/var\s+STEP_TOTAL\s*=\s*3\s*;/', $widgetJs), 'public progress total is 3');
ux_assert_true(strpos($widgetJs, "{ num: '04'") === false && strpos($widgetJs, "{ num: '05'") === false, 'public step metadata has no 04/05');

$legal = array(
    'wolnostojacy' => 'single_house',
    'blizniak' => 'double_house',
    'szeregowiec' => 'row_house',
);
foreach ($legal as $input => $expected) {
    $req = Topinstal_Lead_Widget_Defaults::to_calc_request(ux_base_collected(array('typ_budynku' => $input)));
    ux_assert_true(
        isset($req['building']['building_type']) && $req['building']['building_type'] === $expected,
        "building type {$input} maps to {$expected}"
    );
}

$invalid = ux_base_collected(array('typ_budynku' => 'inny'));
$validation = Topinstal_Lead_Widget_Defaults::validate_collected_for_calculate($invalid);
ux_assert_true(is_wp_error($validation), 'building_type=inny is rejected by calculate validation');
$invalidReq = Topinstal_Lead_Widget_Defaults::to_calc_request($invalid);
ux_assert_true(
    !isset($invalidReq['building']['building_type']) || $invalidReq['building']['building_type'] !== 'single_house',
    'building_type=inny is not mapped to single_house'
);
$invalidCalc = ux_run_calculate($invalid);
ux_assert_true(is_wp_error($invalidCalc), 'building_type=inny does not pass /calculate');

$currentOnly = Topinstal_Lead_Widget_Defaults::to_calc_request(
    ux_base_collected(array('obecne_ogrzewanie' => 'gaz'))
);
ux_assert_true(empty($currentOnly['building']['bivalent_enabled']), 'current gaz alone does not enable bivalence');
ux_assert_true(empty($currentOnly['context']['configurator']['hydraulics_inputs']['bivalent_enabled']), 'current gaz alone does not enable buffer bivalence');

$keepNo = Topinstal_Lead_Widget_Defaults::to_calc_request(
    ux_base_collected(array('obecne_ogrzewanie' => 'gaz', 'keep_existing_heat_source' => false))
);
ux_assert_true(empty($keepNo['building']['bivalent_enabled']), 'keep_existing_heat_source=false keeps bivalence disabled');

$keepYes = Topinstal_Lead_Widget_Defaults::to_calc_request(
    ux_base_collected(array('obecne_ogrzewanie' => 'gaz', 'keep_existing_heat_source' => true))
);
ux_assert_true(!empty($keepYes['building']['bivalent_enabled']), 'keep_existing_heat_source=true enables bivalence');
ux_assert_true(($keepYes['building']['secondary_source_type'] ?? '') === 'gas', 'gas secondary source mapping is preserved');
ux_assert_true(
    ($keepYes['context']['configurator']['hydraulics_inputs']['bivalent_source_type'] ?? '') === 'gas_boiler',
    'gas buffer bivalence mapping is preserved'
);

$pendingWithCurrent = Topinstal_Lead_Widget_Defaults::pending_chat_questions(
    ux_base_collected(array('obecne_ogrzewanie' => 'gaz'))
);
$pendingFields = array_map(static function ($item) {
    return $item['field'];
}, $pendingWithCurrent);
ux_assert_true(in_array('keep_existing_heat_source', $pendingFields, true), 'backup/support question is pending after current source is known');

$newBuildingPending = Topinstal_Lead_Widget_Defaults::pending_chat_questions(
    ux_base_collected(array('standard' => 'w_budowie'))
);
$newBuildingFields = array_map(static function ($item) {
    return $item['field'];
}, $newBuildingPending);
ux_assert_true(!in_array('obecne_ogrzewanie', $newBuildingFields, true), 'new building does not ask current heat source');
ux_assert_true(!in_array('keep_existing_heat_source', $newBuildingFields, true), 'new building does not ask backup/support source');

echo "\n=== SUMMARY: {$passed} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
