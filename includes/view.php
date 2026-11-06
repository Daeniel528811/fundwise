<?php
// Small presentation helpers shared by page templates. No database writes occur here.
function fw_h($value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8');
}

function fw_format_money($amount, string $currency = 'PHP'): string
{
    return $currency . ' ' . number_format((float) $amount, 2);
}

function fw_form_tokens(string $csrfKey = 'csrf_token'): void
{
    echo '<input type="hidden" name="fw_request_token" value="' . fw_h(fw_request_token()) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . fw_h($_SESSION[$csrfKey]) . '">';
}

function fw_password_field(string $id, string $name, array $attributes = [], string $wrapperClass = ''): void
{
    $allowed = ['placeholder', 'autocomplete', 'minlength', 'maxlength', 'required', 'aria-describedby'];
    echo '<div class="' . fw_h(trim('fw-password-wrap ' . $wrapperClass)) . '">';
    echo '<input id="' . fw_h($id) . '" name="' . fw_h($name) . '" type="password" class="form-control"';
    foreach ($allowed as $attribute) {
        $value = $attributes[$attribute] ?? null;
        if ($value === null || $value === false) {
            continue;
        }
        echo ' ' . $attribute;
        if ($value !== true) {
            echo '="' . fw_h($value) . '"';
        }
    }
    echo '>';
    echo '<button type="button" class="fw-password-btn" data-fw-password-toggle="' . fw_h($id) . '" aria-label="Show password" title="Show password"><i class="bi bi-eye-slash" aria-hidden="true"></i></button>';
    echo '</div>';
}
