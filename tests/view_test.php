<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/reliability.php';
function expectView(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
}
function viewDocument(callable $render): DOMDocument
{
    ob_start();
    $render();
    $html = ob_get_clean();
    $document = new DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    return $document;
}
$unsafe = 'field"><img src=x onerror=alert(1)>';
$document = viewDocument(static fn () => fw_password_field($unsafe, 'password', [
    'placeholder' => 'Quotes " & <tags>', 'autocomplete' => 'new-password',
    'minlength' => '8', 'maxlength' => '72', 'required' => true,
    'onclick' => 'untrusted()', 'type' => 'text', 'aria-describedby' => 'passwordHelp'
], 'mb-3'));
$input = $document->getElementsByTagName('input')->item(0);
$button = $document->getElementsByTagName('button')->item(0);
expectView($document->getElementsByTagName('img')->length === 0, 'unsafe identifier cannot create an element');
expectView($input->getAttribute('id') === $unsafe && $button->getAttribute('data-fw-password-toggle') === $unsafe, 'escaped identifiers preserve field/toggle association');
expectView($input->getAttribute('type') === 'password' && !$input->hasAttribute('onclick'), 'caller cannot inject handlers or reveal passwords');
expectView($input->getAttribute('placeholder') === 'Quotes " & <tags>', 'attribute text round trips safely');
expectView($input->hasAttribute('required') && $input->getAttribute('minlength') === '8' && $input->getAttribute('maxlength') === '72', 'password requirements remain on the input');
expectView($input->getAttribute('autocomplete') === 'new-password' && $input->getAttribute('aria-describedby') === 'passwordHelp', 'password manager and description attributes survive');
expectView($input->parentNode->getAttribute('class') === 'fw-password-wrap mb-3', 'wrapper spacing preserved');
expectView($button->getAttribute('type') === 'button' && $button->getAttribute('aria-label') === 'Show password', 'visibility toggle remains a labeled non-submit button');
expectView($button->getElementsByTagName('i')->item(0)->getAttribute('class') === 'bi bi-eye-slash', 'hidden password starts with crossed eye');
$document = viewDocument(static fn () => fw_password_field('optional', 'optional', ['required' => false]));
expectView(!$document->getElementsByTagName('input')->item(0)->hasAttribute('required'), 'optional input stays optional');
$_SESSION = ['csrf_token' => str_repeat('a', 64), 'profile_csrf' => 'profile-secret&"'];
foreach (['csrf_token', 'profile_csrf'] as $key) {
    $document = viewDocument(static fn () => fw_form_tokens($key));
    $fields = [];
    foreach ($document->getElementsByTagName('input') as $field) {
        $fields[$field->getAttribute('name')] = $field->getAttribute('value');
    }
    $_POST = $fields;
    expectView(count($fields) === 2 && fw_csrf_valid($key), 'selected form token validates: ' . $key);
    $_POST['csrf_token'] .= 'changed';
    expectView(!fw_csrf_valid($key), 'changed form token rejected: ' . $key);
    expectView((bool) preg_match('/^[a-f0-9]{48}\.[a-f0-9]{64}$/D', $fields['fw_request_token']), 'one-time request field retains its contract: ' . $key);
}
expectView(fw_h(null) === '' && fw_h(['invalid']) === '', 'non-scalar output safely becomes empty');
expectView(fw_format_money(-3400, 'PHP') === 'PHP -3,400.00', 'negative money display retained');
echo 'PASS: 18 shared-view escaping, form-token and password-field checks.' . PHP_EOL;
