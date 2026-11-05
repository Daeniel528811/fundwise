<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";
require_once "../includes/input.php";

$user_id = (int) $_SESSION["user_id"];

// Currency choices are loaded from the Frankfurter API in the browser.
// The server validates the ISO-style code; the dropdown controls available choices.
$default_currency_codes = ["PHP", "USD", "EUR", "GBP", "JPY", "AUD", "CAD", "SGD"];

$securityFlash=$_SESSION['security_flash']??null;unset($_SESSION['security_flash']);
$success = "";
$error = "";

// CSRF token
if (empty($_SESSION["profile_csrf"])) {
    $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));
}

// Get current user information
$stmt = mysqli_prepare($conn, "
    SELECT full_name, email, preferred_currency, theme
    FROM users
    WHERE user_id = ?
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    die("User account not found.");
}

$currencyLocked=fw_currency_locked($conn,$user_id);
// Process profile update
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!is_string($token) || !hash_equals($_SESSION["profile_csrf"], $token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $full_name = fw_text($_POST["full_name"] ?? null);
        $preferred_currency = strtoupper(fw_text($_POST["preferred_currency"] ?? null));
        $theme = fw_text($_POST["theme"] ?? null);

        if ($full_name === "") {
            $error = "Please enter your full name.";
        } elseif (strlen($full_name) > 100) {
            $error = "Your name must not exceed 100 characters.";
        } elseif ($currencyLocked && $preferred_currency !== strtoupper($user['preferred_currency'])) {
            $error = 'Your ledger currency is fixed because financial records exist. Use Currency Converter for reference conversions.';
        } elseif (!preg_match('/^[A-Z]{3}$/D', $preferred_currency)) {
            $error = "Please select a valid currency.";
        } elseif (!in_array($theme, ["light", "dark"], true)) {
            $error = "Please select a valid theme.";
        } else {

            $stmt = mysqli_prepare($conn, "
                UPDATE users
                SET full_name = ?,
                    preferred_currency = ?,
                    theme = ?
                WHERE user_id = ?
            ");

            mysqli_stmt_bind_param(
                $stmt,
                "sssi",
                $full_name,
                $preferred_currency,
                $theme,
                $user_id
            );

            if (mysqli_stmt_execute($stmt)) {

                $_SESSION["full_name"] = $full_name;

                $user["full_name"] = $full_name;
                $user["preferred_currency"] = $preferred_currency;
                $user["theme"] = $theme;

                $success = "Your profile settings have been updated.";

                // Generate a new token after successful update
                $_SESSION["profile_csrf"] = bin2hex(random_bytes(32));

            } else {
                $error = "Unable to update your profile. Please try again.";
            }

            mysqli_stmt_close($stmt);
        }
    }
}

$selected_currency = preg_match('/^[A-Z]{3}$/D', (string) $user["preferred_currency"])
    ? strtoupper($user["preferred_currency"])
    : "PHP";

$selected_theme = in_array(
    $user["theme"],
    ["light", "dark"],
    true
) ? $user["theme"] : "light";

$is_dark = $selected_theme === "dark";
?>

<?php
$pageTitle = "Profile Settings";
ob_start();
?>
<style>
        body {
            background: #f5f3ff;
            color: #24134d;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            background: #24134d;
            min-height: 100vh;
            padding: 25px 15px;
        }

        .sidebar h4 {
            color: white;
            margin-bottom: 30px;
        }

        .sidebar a {
            display: block;
            color: #ddd6fe;
            text-decoration: none;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 7px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #6d28d9;
            color: white;
        }

        .main-content {
            padding: 30px;
        }

        .settings-card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            color: #24134d;
        }

        .form-control,
        .form-select {
            background: white;
            color: #24134d;
            border-color: #ddd6fe;
        }

        .form-control:focus,
        .form-select:focus {
            background: white;
            color: #24134d;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 0.2rem rgba(139,92,246,0.15);
        }

        .form-select option {
            color: #24134d;
            background: white;
        }

        .btn-purple {
            background: #6d28d9;
            color: white;
        }

        .btn-purple:hover {
            background: #5b21b6;
            color: white;
        }

        .section-title {
            font-weight: bold;
            color: #2e1065;
        }

        .muted-text {
            color: #6b7280;
        }

        @media (max-width: 767px) {
            .sidebar {
                min-height: auto;
            }

            .main-content {
                padding: 18px;
            }
        }
    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
            <div class="mb-4">
                <h2 class="section-title">Profile & Settings</h2>
                <p class="muted-text">
                    Manage your personal information and application preferences.
                </p>
            </div>

            <?php if ($success !== ""): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="settings-card">

                <h5 class="section-title mb-4">Personal Information</h5>

                <form method="POST" action=""><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["profile_csrf"]) ?>"
                    >

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>

                        <input
                            type="text"
                            name="full_name"
                            class="form-control"
                            maxlength="100"
                            value="<?= htmlspecialchars($user["full_name"]) ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email Address</label>

                        <input
                            type="email"
                            class="form-control"
                            value="<?= htmlspecialchars($user["email"]) ?>"
                            disabled
                        >

                        <small class="muted-text">
                            Your email address is currently not editable.
                        </small>
                    </div>

                    <hr class="my-4">

                    <h5 class="section-title mb-3">Application Preferences</h5>

                    <div class="mb-3">
                        <label class="form-label">Preferred Currency</label>

                        <input
                            type="search"
                            id="currencySearch"
                            class="form-control mb-2"
                            placeholder="Search currency by name or code..."
                            aria-label="Search currencies"
                            autocomplete="off"
                        >
                        <select
                            name="preferred_currency"
                            id="preferredCurrency"
                            class="form-select"
                            required
                            data-selected="<?= htmlspecialchars($selected_currency, ENT_QUOTES, 'UTF-8') ?>" <?= $currencyLocked ? 'disabled' : '' ?>
                        >
                            <?php
                            // Keep the saved choice usable even if the API is unavailable.
                            $initial_codes = array_unique(array_merge([$selected_currency], $default_currency_codes));
                            foreach ($initial_codes as $code):
                            ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $selected_currency === $code ? "selected" : "" ?>>
                                    <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if($currencyLocked):?><input type="hidden" name="preferred_currency" value="<?= htmlspecialchars($selected_currency,ENT_QUOTES,'UTF-8') ?>"><?php endif;?><small id="currencyLoadStatus" class="muted-text d-block mt-1" role="status">
                            Loading available currencies...
                        </small>
                        <div class="form-text">Choose the currency your recorded amounts use. After financial records exist, it is locked to prevent relabeling historical amounts. Currency Converter remains a separate reference tool.</div>

                    </div>

                    <div class="mb-4">
                        <label class="form-label">Theme</label>

                        <select name="theme" class="form-select" required>
                            <option value="light" <?= $selected_theme === "light" ? "selected" : "" ?>>
                                Light Mode
                            </option>

                            <option value="dark" <?= $selected_theme === "dark" ? "selected" : "" ?>>
                                Dark Mode
                            </option>
                        </select>

                        <small class="muted-text">
                            Choose the appearance you prefer when using Fundwise.
                        </small>
                    </div>

                    <button type="submit" class="btn btn-purple px-4">
                        Save Changes
                    </button>

                </form>

            </div>

<section class="settings-card mt-4" id="accountSecurity"><h2 class="h5 section-title mb-3">Account security</h2>
<?php if($securityFlash):?><div class="alert alert-<?= $securityFlash['ok']?'success':'danger' ?>" role="status"><?= htmlspecialchars($securityFlash['text'],ENT_QUOTES,'UTF-8') ?><?php if(isset($securityFlash['code'])):?><p class="mt-2 mb-1">Save this code now. It is shown once; never share it.</p><code class="fw-recovery-code"><?= htmlspecialchars($securityFlash['code'],ENT_QUOTES,'UTF-8') ?></code><?php endif;?></div><?php endif;?>
<form method="post" action="security.php" class="fw-security-form"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="change_password"><div class="mb-3"><label class="form-label" for="currentPassword">Current password</label><input id="currentPassword" name="current_password" type="password" class="form-control" autocomplete="current-password" required maxlength="72"></div><div class="row g-3"><div class="col-md-6"><label class="form-label" for="newPassword">New password</label><input id="newPassword" name="new_password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div><div class="col-md-6"><label class="form-label" for="confirmPassword">Confirm new password</label><input id="confirmPassword" name="confirm_password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div></div><p class="form-text mt-3">Use 8–72 characters. Changing your password signs out other sessions and replaces your recovery code.</p><button class="btn btn-purple" type="submit">Change password</button></form>
<hr class="my-4"><h3 class="h6">Recovery code</h3><p class="text-muted small">Existing accounts should generate a recovery code now. It lets you reset a forgotten password without email delivery. Regenerating replaces the previous code.</p><form method="post" action="security.php"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="action" value="recovery_code"><label class="form-label" for="recoveryCurrentPassword">Confirm current password</label><input id="recoveryCurrentPassword" name="current_password" type="password" class="form-control mb-3" autocomplete="current-password" required maxlength="72"><button class="btn btn-outline-primary" type="submit">Generate recovery code</button></form><p class="form-text mt-3">Email ownership is not verified in this version. Do not treat a registered address as a verified identity.</p></section>

            <div class="settings-card mt-4">
                <h5 class="section-title">Account Information</h5>

                <p class="muted-text mb-1">
                    Account ID: <?= $user_id ?>
                </p>

                <p class="muted-text mb-0">
                    Registered Email: <?= htmlspecialchars($user["email"]) ?>
                </p>
            </div>

        

<script>
(() => {
    const select = document.getElementById("preferredCurrency");
    const search = document.getElementById("currencySearch");
    const status = document.getElementById("currencyLoadStatus");
    const selectedCode = select.dataset.selected || "PHP";
    let currencies = [];

    function render(filter = "") {
        const query = filter.trim().toLowerCase();
        const current = select.value || selectedCode;
        const visible = currencies.filter(c =>
            c.code.toLowerCase().includes(query) ||
            c.name.toLowerCase().includes(query)
        );
        // Keep the user's saved currency available even when it isn't offered by the API.
        if (!visible.some(c => c.code === selectedCode)) {
            visible.unshift({code: selectedCode, name: "Current preference"});
        }
        select.replaceChildren();
        for (const currency of visible) {
            const option = document.createElement("option");
            option.value = currency.code;
            option.textContent = `${currency.code} - ${currency.name}`;
            select.append(option);
        }
        if (visible.some(c => c.code === current)) {
            select.value = current;
        } else if (visible.some(c => c.code === selectedCode)) {
            select.value = selectedCode;
        }
    }

    search.addEventListener("input", () => render(search.value));

    async function loadCurrencies() {
        try {
            const response = await fetch("https://api.frankfurter.dev/v2/currencies", {
                headers: {Accept: "application/json"}
            });
            if (!response.ok) throw new Error("Currency service unavailable");
            const data = await response.json();
            const items = Array.isArray(data)
                ? data
                : Object.entries(data).map(([code, name]) =>
                    typeof name === "string" ? {iso_code: code, name} : {iso_code: code, ...name}
                  );
            currencies = items.map(item => ({
                code: String(item.iso_code || item.code || item.currency || "").toUpperCase(),
                name: String(item.name || item.currency_name || item.iso_code || item.code || "")
            })).filter(item => /^[A-Z]{3}$/.test(item.code));
            currencies = [...new Map(currencies.map(c => [c.code, c])).values()]
                .sort((a, b) => a.code.localeCompare(b.code));
            if (!currencies.length) throw new Error("No currency data");
            render();
            status.textContent = `${currencies.length} currencies loaded. Your saved currency stays available.`;
        } catch (error) {
            status.textContent = "Currency service unavailable. Saved and common currencies remain available.";
        }
    }
    loadCurrencies();
})();
</script>

<section class="card mt-4 mb-4 fw-profile-session"><div class="card-body"><h2 class="h5">Your session</h2><p class="text-muted">Sign out of Fundwise on this device.</p><form method="post" action="<?= htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8') ?>/auth/logout.php"><input type="hidden" name="fw_request_token" value="<?= htmlspecialchars(fw_request_token(),ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>"><button class="btn btn-outline-danger" type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Log out</button></form></div></section>
<?php require_once __DIR__ . "/../includes/footer.php"; ?>
