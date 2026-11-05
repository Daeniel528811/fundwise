<?php
// CLI-only basic tests: php tests/validation_test.php
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/input.php';
function check($passed, $message): void {
    if (!$passed) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}
check(fw_text(['bad']) === '', 'non-string text');
check(fw_positive_id('12') === 12, 'valid ID');
check(fw_positive_id(['12']) === 0, 'array ID');
check(fw_positive_id('-1') === 0, 'negative ID');
check(fw_money('10.25') === 10.25, 'valid money');
check(fw_money('10.999') === null, 'too many decimals');
check(fw_money('1e6') === null, 'exponent');
check(fw_money('0.00') === null, 'zero money');
check(fw_money('0.00', true) === 0.0, 'zero progress');
check(fw_money(['10']) === null, 'array amount');
check(fw_valid_date('2024-02-29'), 'leap date');
check(!fw_valid_date('2026-02-29'), 'invalid leap date');
check(!fw_valid_date(['2026-01-01']), 'array date');
$_SESSION = ['csrf_token' => 'testtoken'];
$_POST = ['csrf_token' => 'testtoken'];
check(fw_csrf_valid(), 'valid CSRF');
$_POST['csrf_token'] = ['testtoken'];
check(!fw_csrf_valid(), 'array CSRF');
echo "PASS: All Fundwise input-validation smoke tests.\n";
