<?php
/**
 * Comprehensive smoke: form scenarios, kalk-top calculate, OfferDTO cache, generator PDF, optional mail.
 *
 * Usage:
 *   php scripts/configure-local.php
 *   php scripts/e2e-scenarios-smoke.php
 *   php scripts/e2e-scenarios-smoke.php --live-mail
 */

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$liveMail = in_array('--live-mail', $argv ?? array(), true);
$fail = 0;
$passed = 0;

/**
 * Smoke runs many calculate/register calls. Clear lead-widget rate-limit buckets
 * when offer_test_mode is on so a second Gate B run is not blocked by the first.
 *
 * @return void
 */
function clear_smoke_rate_limits() {
    $testMode = (string) Topinstal_Lead_Widget_Plugin::get_option('offer_test_mode', '0');
    if ($testMode !== '1') {
        return;
    }
    foreach (array('0.0.0.0', '127.0.0.1', '::1') as $ip) {
        delete_transient('tilw_rate_' . md5($ip));
    }
}

clear_smoke_rate_limits();

/**
 * @param bool $ok
 * @param string $label
 * @return void
 */
function assert_true($ok, $label) {
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
 * @param array<string,mixed> $collected
 * @return array<string,mixed>|WP_Error
 */
function run_calculate($collected) {
    $req = new WP_REST_Request('POST', '/topinstal-lead/v1/calculate');
    $req->set_header('Content-Type', 'application/json; charset=utf-8');
    $req->set_body(wp_json_encode(array('collected' => $collected)));
    $res = Topinstal_Lead_Widget_Calculator::handle($req);
    if (is_wp_error($res)) {
        return $res;
    }
    return $res->get_data();
}

/**
 * @param array<string,mixed> $collected
 * @param array<string,mixed> $summary
 * @return array<string,mixed>|WP_Error
 */
function run_register($collected, $summary) {
    $req = new WP_REST_Request('POST', '/topinstal-lead/v1/register');
    $req->set_header('Content-Type', 'application/json; charset=utf-8');
    $req->set_body(wp_json_encode(array(
        'collected' => $collected,
        'result_summary' => $summary,
        'traceId' => isset($summary['traceId']) ? $summary['traceId'] : '',
    )));
    $res = Topinstal_Lead_Widget_Lead_Registry::handle($req);
    if (is_wp_error($res)) {
        return $res;
    }
    return $res->get_data();
}

$ref = new ReflectionClass('Topinstal_Lead_Widget_Calculator');
$mapMethod = $ref->getMethod('map_offer_to_summary');
$mapMethod->setAccessible(true);

$scenarios = array(
    'wolnostojacy_podlogowka' => array(
        'session_id' => 'e2e-wolno-' . gmdate('His'),
        'typ_budynku' => 'wolnostojacy',
        'standard' => 'sredni',
        'emitter_type' => 'podlogowka',
        'powierzchnia' => 140,
        'postal_code' => '30-001',
        'insulation_level' => 'good',
        'insulation_confirmed' => true,
        'dhw_persons' => '4–5 osób',
        'dhw_usage' => 'Standardowo',
        'ventilation_type' => 'natural',
        'obecne_ogrzewanie' => 'gaz',
    ),
    'w_budowie_skip_heat' => array(
        'session_id' => 'e2e-budowa-' . gmdate('His'),
        'typ_budynku' => 'wolnostojacy',
        'standard' => 'w_budowie',
        'emitter_type' => 'podlogowka',
        'powierzchnia' => 160,
        'postal_code' => '32-600',
        'insulation_level' => 'very_good',
        'insulation_confirmed' => true,
        'dhw_persons' => '2–3 osoby',
        'dhw_usage' => 'Raczej oszczędnie',
        'ventilation_type' => 'rekuperacja',
    ),
    'grzejniki_ht' => array(
        'session_id' => 'e2e-grzej-' . gmdate('His'),
        'typ_budynku' => 'bliźniak',
        'standard' => 'po_2010',
        'emitter_type' => 'grzejniki',
        'powierzchnia' => 110,
        'postal_code' => '00-001',
        'insulation_level' => 'average',
        'insulation_confirmed' => true,
        'dhw_persons' => 5,
        'dhw_usage' => 'shower_bath',
        'obecne_ogrzewanie' => 'wegiel',
        'ventilation_type' => 'natural',
        'hydraulics_confirmed' => true,
        'radiators_is_ht' => true,
    ),
    'wielorodzinny_intensywnie' => array(
        'session_id' => 'e2e-inny-' . gmdate('His'),
        'typ_budynku' => 'inny',
        'standard' => 'przed_1990',
        'emitter_type' => 'mieszane',
        'powierzchnia' => 320,
        'postal_code' => '50-001',
        'insulation_level' => 'poor',
        'insulation_confirmed' => true,
        'dhw_persons' => 'Więcej niż 5',
        'dhw_usage' => 'Korzystamy intensywnie',
        'obecne_ogrzewanie' => 'olej',
        'ventilation_type' => 'natural',
        'hydraulics_confirmed' => true,
        'radiators_is_ht' => false,
        'has_underfloor_actuators' => true,
    ),
);

echo "=== fast-kalk E2E scenarios ===\n";
echo 'Plugin: ' . TOPINSTAL_LEAD_WIDGET_VERSION . "\n";
echo 'Generator: ' . Topinstal_Lead_Widget_Plugin::get_option('generator_url', '(empty)') . "\n";
echo 'Operator: ' . Topinstal_Lead_Widget_Plugin::get_option('operator_email', '(empty)') . "\n\n";

foreach ($scenarios as $name => $raw) {
    echo "--- Scenario: {$name} ---\n";
    $collected = Topinstal_Lead_Widget_Defaults::sanitize_collected($raw);
    $session_id = (string) $collected['session_id'];

    $dhw_p = Topinstal_Lead_Widget_Defaults::normalize_dhw_persons_input($collected['dhw_persons'] ?? '');
    $dhw_u = Topinstal_Lead_Widget_Defaults::normalize_dhw_usage_input($collected['dhw_usage'] ?? '');
    assert_true($dhw_p > 0, "{$name}: dhw_persons normalized to {$dhw_p}");
    assert_true($dhw_u !== '', "{$name}: dhw_usage normalized to {$dhw_u}");

    if ($name === 'w_budowie_skip_heat') {
        $pending = Topinstal_Lead_Widget_Defaults::pending_chat_questions($collected);
        $fields = array_map(static function ($q) {
            return $q['field'];
        }, $pending);
        assert_true(!in_array('obecne_ogrzewanie', $fields, true), "{$name}: obecne_ogrzewanie not pending for w_budowie");
    }

    $summary = run_calculate($collected);
    if (is_wp_error($summary)) {
        assert_true(false, "{$name}: calculate — " . $summary->get_error_message());
        continue;
    }

    assert_true(!empty($summary['model']), "{$name}: model present ({$summary['model']})");
    $has_price = isset($summary['cena_min']) && (int) $summary['cena_min'] > 0;
    assert_true(
        $has_price || !empty($summary['model']),
        "{$name}: cena_min > 0 or model-only result (cena_min=" . ($summary['cena_min'] ?? 'null') . ')'
    );
    assert_true(
        !isset($summary['cena_min'], $summary['cena_max']) || (int) $summary['cena_max'] >= (int) $summary['cena_min'],
        "{$name}: cena_max >= cena_min"
    );

    $fp = Topinstal_Lead_Widget_Session_Store::fingerprint_collected($collected);
    $offer = Topinstal_Lead_Widget_Session_Store::get_cached_offer($session_id, $fp);
    assert_true(is_array($offer), "{$name}: OfferDTO cached");
    if (is_array($offer)) {
        $engineering = isset($offer['engineering']) && is_array($offer['engineering']) ? $offer['engineering'] : array();
        $selection = isset($engineering['selection']) && is_array($engineering['selection']) ? $engineering['selection'] : array();
        $has_pump = !empty($selection['pumpModel'])
            || !empty($selection['pump_model'])
            || !empty($selection['model'])
            || !empty($engineering['productLine'])
            || !empty($summary['model']);
        assert_true($has_pump, "{$name}: OfferDTO has pump selection or product line");
        assert_true(isset($offer['pricing']['totals']['gross']), "{$name}: OfferDTO has gross price");
    }

    $calc_req = Topinstal_Lead_Widget_Defaults::to_calc_request($collected);
    $offerReq = new WP_REST_Request('POST', '/topinstal/v1/calculate-offer');
    $offerReq->set_header('Content-Type', 'application/json');
    $agentKey = Topinstal_Lead_Widget_Plugin::get_option('calc_agent_api_key', '');
    if ($agentKey !== '') {
        $offerReq->set_header('X-Top-Instal-Agent-Key', Topinstal_Lead_Widget_Plugin::sanitize_ascii_value($agentKey));
    }
    $offerReq->set_body(wp_json_encode($calc_req));
    $direct = rest_get_server()->dispatch($offerReq);
    if ($direct->is_error()) {
        assert_true(false, "{$name}: direct kalk-top — " . $direct->as_error()->get_error_message());
    } else {
        $directSummary = $mapMethod->invoke(null, $direct->get_data());
        assert_true(
            ($directSummary['model'] ?? '') === ($summary['model'] ?? ''),
            "{$name}: widget summary model matches direct kalk-top"
        );
    }

    echo "\n";
}

echo "--- E2E dispatch (generator + mail) ---\n";
$dispatchCollected = Topinstal_Lead_Widget_Defaults::sanitize_collected(array(
    'session_id' => 'e2e-dispatch-' . gmdate('YmdHis'),
    'typ_budynku' => 'wolnostojacy',
    'standard' => 'sredni',
    'emitter_type' => 'podlogowka',
    'powierzchnia' => 125,
    'postal_code' => '30-001',
    'insulation_level' => 'good',
    'insulation_confirmed' => true,
    'dhw_persons' => '4–5 osób',
    'dhw_usage' => 'Standardowo',
    'ventilation_type' => 'natural',
    'obecne_ogrzewanie' => 'gaz',
    'contact_email' => $liveMail ? 'konradswierad@gmail.com' : 'smoke-test@example.invalid',
));

$dispatchSummary = run_calculate($dispatchCollected);
if (is_wp_error($dispatchSummary)) {
    assert_true(false, 'dispatch scenario calculate — ' . $dispatchSummary->get_error_message());
} else {
    $genBase = rtrim(Topinstal_Lead_Widget_Plugin::get_option('generator_url', ''), '/');
    if ($genBase === '') {
        assert_true(false, 'generator_url not configured — run configure-local.php');
    } else {
        $health = wp_remote_get($genBase . '/wp-json/', array('timeout' => 10));
        $healthOk = !is_wp_error($health) && (int) wp_remote_retrieve_response_code($health) === 200;
        assert_true($healthOk, 'generator base reachable');
        if (!$healthOk) {
            echo "HINT: kalk-top runtime-wp not reachable at {$genBase}. Run:\n";
            echo "  kalk-top\\scripts\\start-runtime-wp.ps1\n";
            echo "  php scripts/configure-local.php\n";
            echo "Or: powershell -File scripts/preflight-local-stack.ps1 -FullStack\n";
        }

        // The production journey is lead -> calculate -> register -> dispatch. The
        // register step used to run only under --live-mail, so the default run
        // bypassed it by calling Offer_Dispatch directly and never proved that the
        // REST entrypoint reaches dispatch at all. Both branches converge on the
        // same maybe_dispatch(), so exercising the real entrypoint here adds
        // coverage without adding a new class of side effect.
        $reg = run_register($dispatchCollected, $dispatchSummary);
        if (is_wp_error($reg)) {
            assert_true(false, 'register — ' . $reg->get_error_message());
        } else {
            assert_true(is_array($reg), 'register accepted the lead');
            assert_true(array_key_exists('offer_delivered', $reg), 'register reported an offer dispatch outcome');
            $delivered = !empty($reg['offer_delivered']);
            if ($liveMail) {
                assert_true($delivered, 'register offer_delivered=true (check konradswierad@gmail.com)');
            } else {
                assert_true($delivered, 'lead-to-dispatch journey completed through /register');
            }
            $regPdf = '';
            if (!empty($reg['pdf_url'])) {
                $regPdf = (string) $reg['pdf_url'];
            } elseif (!empty($reg['offer_pdf_url'])) {
                $regPdf = (string) $reg['offer_pdf_url'];
            }
            assert_true($regPdf !== '', 'register returned an offer PDF url');
            if ($regPdf !== '') {
                echo '  pdf_url: ' . $regPdf . "\n";
            }
            if (!empty($reg['offer_error'])) {
                echo '  dispatch error: ' . $reg['offer_error'] . "\n";
            }
        }
    }
}

echo "\n=== SUMMARY: {$passed} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
