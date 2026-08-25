<?php
/**
 * Regression for fast-kalk offer document handoff:
 * - PDF generation request uses the same from-offer-dto + context path as kalk-top.
 * - Device/power facts come from OfferDTO, not local lead-widget selection logic.
 * - Email bodies carry the same useful offer facts as the PDF handoff.
 */

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$fail = 0;
$passed = 0;

function offer_doc_assert($ok, $label) {
    global $fail, $passed;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}\n";
    $fail++;
}

function offer_doc_invoke($method, $args) {
    $ref = new ReflectionMethod('Topinstal_Lead_Widget_Offer_Dispatch', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

function offer_doc_calc_invoke($method, $args) {
    $ref = new ReflectionMethod('Topinstal_Lead_Widget_Calculator', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

$offer = array(
    'traceId' => 'offer-parity-trace-001',
    'engineering' => array(
        'ozc' => array(
            'designHeatLoss_kW' => 8.4,
            'recommendedPower_kW' => 8.4,
        ),
        'selection' => array(
            'pumpModel' => 'KIT-WC09K3E8',
            'capacity_kW' => 9,
        ),
        'buffer' => array(
            'liters' => 80,
            'setupType' => 'SERIES',
        ),
        'cwu' => array(
            'recommendedCapacityL' => 200,
        ),
    ),
    'pricing' => array(
        'totals' => array(
            'gross' => 41000,
        ),
    ),
);

$request = offer_doc_invoke('build_generator_request', array($offer, 'offer-parity-trace-001', 'eng-123'));
$context = isset($request['context']) && is_array($request['context']) ? $request['context'] : array();
$snapshot = isset($context['machineRoomSnapshot']) && is_array($context['machineRoomSnapshot'])
    ? $context['machineRoomSnapshot']
    : array();
$components = isset($snapshot['selected_components']) && is_array($snapshot['selected_components'])
    ? $snapshot['selected_components']
    : array();

offer_doc_assert(($request['mode'] ?? '') === 'from-offer-dto', 'generator mode is from-offer-dto');
offer_doc_assert(($request['outputFormat'] ?? '') === 'pdf', 'generator outputFormat is pdf');
offer_doc_assert(isset($request['offerDto']) && $request['offerDto'] === $offer, 'full OfferDTO is passed through');
offer_doc_assert(($context['source'] ?? '') === 'fast-kalk', 'generator context source=fast-kalk');
offer_doc_assert(($context['channel'] ?? '') === 'lead_widget', 'generator context channel=lead_widget');
offer_doc_assert(($context['documentMode'] ?? '') === 'offer', 'generator context documentMode=offer');
offer_doc_assert(($context['traceId'] ?? '') === 'offer-parity-trace-001', 'generator context traceId is preserved');
offer_doc_assert(($context['engagementId'] ?? '') === 'eng-123', 'generator context engagementId is preserved');
offer_doc_assert(($components['pump']['model'] ?? '') === 'KIT-WC09K3E8', 'snapshot pump model comes from OfferDTO selection');
offer_doc_assert((float) ($components['pump']['power_kw'] ?? 0) === 9.0, 'snapshot pump power comes from OfferDTO selection');
offer_doc_assert((int) ($components['cwu']['capacity_l'] ?? 0) === 200, 'snapshot CWU capacity comes from OfferDTO engineering.cwu');
offer_doc_assert(($components['cwu']['name'] ?? '') === 'Trinnity', 'snapshot CWU manufacturer is Trinnity for generator');
offer_doc_assert((int) ($components['buffer']['capacity_l'] ?? 0) === 80, 'snapshot buffer capacity comes from OfferDTO engineering.buffer');
offer_doc_assert((int) ($snapshot['total_brutto_pln'] ?? 0) === 41000, 'snapshot total gross comes from OfferDTO pricing');
offer_doc_assert(($request['payload']['tank']['capacity'] ?? '') === '200', 'legacy generator payload tank capacity remains present');

$workspace = dirname(__DIR__, 2);
$generatorMapper = $workspace . '/top-instal-generator/core/application/OfferDocumentInputMapper.php';
$documentCodes = $workspace . '/top-instal-generator/core/contracts/DocumentReasonCodes.php';
$documentException = $workspace . '/top-instal-generator/core/application/OfferDocumentException.php';
if (is_readable($generatorMapper) && is_readable($documentCodes) && is_readable($documentException)) {
    require_once $documentCodes;
    require_once $documentException;
    require_once $generatorMapper;
    $warnings = array();
    $mapped = TopInstal_OfferDocument_InputMapper::map_from_offer_dto($request, $warnings);
    offer_doc_assert(($mapped['kitModel'] ?? '') === 'KIT-WC09K3E8', 'generator mapper uses snapshot/OfferDTO pump model');
    offer_doc_assert((int) ($mapped['powerKw'] ?? 0) === 9, 'generator mapper uses selected pump power');
    offer_doc_assert(($mapped['tankCapacity'] ?? '') === '200', 'generator mapper uses CWU capacity 200');
    offer_doc_assert(($mapped['tankManufacturer'] ?? '') === 'Trinnity', 'generator mapper uses Trinnity tank manufacturer');
    offer_doc_assert(($mapped['bufferCapacity'] ?? '') === '80', 'generator mapper uses buffer capacity 80');
    offer_doc_assert((int) ($mapped['customPriceGross'] ?? 0) === 41000, 'generator mapper uses OfferDTO gross total');
} else {
    offer_doc_assert(false, 'top-instal-generator mapper is readable from workspace');
}

$facts = offer_doc_invoke('extract_offer_facts', array($offer));
offer_doc_assert(($facts['pump_label'] ?? '') === 'KIT-WC09K3E8', 'email facts use OfferDTO pump model');
offer_doc_assert(($facts['capacity_kw'] ?? '') === '9', 'email facts use OfferDTO pump capacity');
offer_doc_assert(($facts['heat_loss_kw'] ?? '') === '8,4', 'email facts use OfferDTO design heat loss');
offer_doc_assert(($facts['recommended_power_kw'] ?? '') === '8,4', 'email facts use OfferDTO recommended power');
offer_doc_assert(($facts['gross_pln'] ?? '') === '41 000', 'email facts use OfferDTO gross price');

$operator = offer_doc_invoke('build_operator_body', array(
    'lead@example.test',
    $facts,
    'offer-parity-trace-001',
    array(
        'typ_budynku' => 'wolnostojacy',
        'powierzchnia' => 125,
        'postal_code' => '30-001',
        'emitter_type' => 'podlogowka',
    ),
));
$client = offer_doc_invoke('build_client_body', array($facts));
offer_doc_assert(strpos($operator['html'], 'top-instal-generator') !== false, 'operator email states generator PDF path');
offer_doc_assert(strpos($operator['html'], 'KIT-WC09K3E8') !== false, 'operator email contains selected pump');
offer_doc_assert(strpos($operator['html'], '8,4 kW') !== false, 'operator email contains heat loss/recommended power');
offer_doc_assert(strpos($operator['html'], 'offer-parity-trace-001') !== false, 'operator email contains trace id');
offer_doc_assert(strpos($client['html'], 'KIT-WC09K3E8') !== false, 'client email contains selected pump');
offer_doc_assert(strpos($client['html'], '8,4 kW') !== false, 'client email contains heat loss/recommended power');
offer_doc_assert(strpos($client['html'], 'OfferDTO') === false, 'client email does not expose OfferDTO implementation detail');
offer_doc_assert(strpos($client['html'], 'PDF') !== false, 'client email mentions attached PDF');

$summary = offer_doc_calc_invoke('map_offer_to_summary', array($offer));
offer_doc_assert((float) ($summary['heat_loss_kw'] ?? 0) === 8.4, 'public summary exposes design heat loss from OfferDTO');
offer_doc_assert((float) ($summary['recommended_power_kw'] ?? 0) === 8.4, 'public summary exposes recommended power from OfferDTO');
offer_doc_assert((float) ($summary['selection_power_kw'] ?? 0) === 9.0, 'public summary exposes selected pump power from OfferDTO');

$widgetJs = file_get_contents(dirname(__DIR__) . '/wp-content/plugins/topinstal-lead-widget/assets/widget.js');
$widgetJs = is_string($widgetJs) ? $widgetJs : '';
offer_doc_assert(strpos($widgetJs, 'formatSelectionBasis') !== false, 'public JS renders selection basis');
offer_doc_assert(strpos($widgetJs, 'Dobór z kalk-top') !== false, 'public JS labels kalk-top as selection source');

echo "\n=== SUMMARY: {$passed} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
