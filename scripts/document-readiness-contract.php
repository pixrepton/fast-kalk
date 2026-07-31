<?php
/**
 * Focused contract proof for fast-kalk generator readiness handling.
 */

require dirname(__DIR__) . '/scripts/_wp-bootstrap.php';

$ref = new ReflectionClass('Topinstal_Lead_Widget_Offer_Dispatch');
$method = $ref->getMethod('resolve_pdf_document');
$method->setAccessible(true);

$fail = 0;
$pass = 0;

function assert_contract($ok, $label) {
    global $fail, $pass;
    if ($ok) {
        echo "PASS: {$label}\n";
        $pass++;
        return;
    }
    echo "FAIL: {$label}\n";
    $fail++;
}

$ready = $method->invoke(null, array(
    'document' => array(
        'format' => 'pdf',
        'filename' => 'offer.pdf',
        'downloadUrl' => 'https://example.test/offer.pdf',
        'verified' => true,
    ),
    'readiness' => array(
        'status' => 'READY',
        'requestedFormat' => 'pdf',
        'actualFormat' => 'pdf',
        'artifactVerified' => true,
    ),
));
assert_contract(is_array($ready) && ($ready['format'] ?? '') === 'pdf', 'verified PDF stays deliverable');

$degraded = $method->invoke(null, array(
    'document' => array(
        'format' => 'docx',
        'filename' => 'offer.docx',
        'downloadUrl' => 'https://example.test/offer.docx',
        'verified' => true,
    ),
    'readiness' => array(
        'status' => 'DEGRADED',
        'requestedFormat' => 'pdf',
        'actualFormat' => 'docx',
        'artifactVerified' => true,
        'retryable' => true,
        'degradedCode' => 'FALLBACK_DOCX_RETURNED',
    ),
));
assert_contract(is_wp_error($degraded) && $degraded->get_error_code() === 'generator_pdf_degraded', 'DOCX fallback is rejected as PDF success');

$missingUrl = $method->invoke(null, array(
    'document' => array(
        'format' => 'pdf',
        'filename' => 'offer.pdf',
        'downloadUrl' => '',
        'verified' => true,
    ),
    'readiness' => array(
        'status' => 'READY',
        'requestedFormat' => 'pdf',
        'actualFormat' => 'pdf',
        'artifactVerified' => true,
    ),
));
assert_contract(is_wp_error($missingUrl) && $missingUrl->get_error_code() === 'generator_no_url', 'missing URL fails closed');

$unverified = $method->invoke(null, array(
    'document' => array(
        'format' => 'pdf',
        'filename' => 'offer.pdf',
        'downloadUrl' => 'https://example.test/offer.pdf',
        'verified' => false,
    ),
    'readiness' => array(
        'status' => 'READY',
        'requestedFormat' => 'pdf',
        'actualFormat' => 'pdf',
        'artifactVerified' => false,
    ),
));
assert_contract(is_wp_error($unverified) && $unverified->get_error_code() === 'generator_pdf_unverified', 'unverified artifact fails closed');

echo "\nSUMMARY: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
