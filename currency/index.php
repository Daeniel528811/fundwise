<?php
require_once "../includes/auth_check.php";
require_once "../includes/db.php";

$user_id = (int) $_SESSION["user_id"];
$preferred_currency = "PHP";

$stmt = mysqli_prepare($conn, "
    SELECT preferred_currency
    FROM users
    WHERE user_id = ?
");

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!empty($user["preferred_currency"])) {
    $preferred_currency = strtoupper($user["preferred_currency"]);
}

mysqli_stmt_close($stmt);
?>

<?php
$pageTitle = "Currency Converter";
ob_start();
?>
<style>
        body {
            background: #f5f3ff;
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

        .converter-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            max-width: 800px;
        }

        .section-title {
            color: #2e1065;
            font-weight: bold;
        }

        .form-control,
        .form-select {
            padding: 12px;
            border-color: #ddd6fe;
        }

        .btn-purple {
            background: #6d28d9;
            color: white;
            padding: 12px 20px;
        }

        .btn-purple:hover {
            background: #5b21b6;
            color: white;
        }

        .result-box {
            background: #f3e8ff;
            border-radius: 12px;
            padding: 25px;
            margin-top: 25px;
            display: none;
        }

        .result-amount {
            font-size: 32px;
            font-weight: bold;
            color: #5b21b6;
            overflow-wrap: anywhere;
        }

        .rate-info {
            color: #6b7280;
            font-size: 14px;
        }

        .status-message {
            font-size: 14px;
            margin-top: 12px;
        }

        @media (max-width: 767px) {
            .sidebar {
                min-height: auto;
            }

            .main-content {
                padding: 18px;
            }

            .converter-card {
                padding: 20px;
            }
        }
    </style>
<?php
$pageHead = ob_get_clean();
require_once __DIR__ . "/../includes/header.php";
?>
            <div class="mb-4">
                <h2 class="section-title">Currency Converter</h2>

                <p class="text-muted">
                    Convert your money between different supported currencies.
                </p>
            </div>

            <div class="converter-card">

                <h5 class="section-title mb-4">
                    Convert Your Money
                </h5>

                <form id="converterForm">

                    <div class="mb-3">
                        <label for="amount" class="form-label">
                            Amount
                        </label>

                        <input
                            type="number"
                            id="amount"
                            class="form-control"
                            placeholder="Enter amount"
                            min="0.000001"
                            step="any"
                            value="1000"
                            required
                        >
                    </div>

                    <div class="row align-items-end">

                        <div class="col-md-5 mb-3">
                            <label for="fromCurrency" class="form-label">
                                From Currency
                            </label>

                            <select
                                id="fromCurrency"
                                class="form-select"
                                required
                            >
                                <option value="">Loading currencies...</option>
                            </select>
                        </div>

                        <div class="col-md-2 text-center mb-3">
                            <button
                                type="button"
                                id="swapButton"
                                class="btn btn-outline-secondary w-100"
                                title="Swap currencies"
                            >
                                ⇄ Swap
                            </button>
                        </div>

                        <div class="col-md-5 mb-3">
                            <label for="toCurrency" class="form-label">
                                To Currency
                            </label>

                            <select
                                id="toCurrency"
                                class="form-select"
                                required
                            >
                                <option value="">Loading currencies...</option>
                            </select>
                        </div>

                    </div>

                    <button
                        type="submit"
                        id="convertButton"
                        class="btn btn-purple w-100"
                        disabled
                    >
                        Convert Currency
                    </button>

                    <p id="statusMessage" class="status-message text-muted">
                        Loading supported currencies...
                    </p>

                </form>

                <!-- Conversion Result -->
                <div class="result-box" id="resultBox">

                    <p class="text-muted mb-2">
                        Converted Amount
                    </p>

                    <div class="result-amount" id="convertedAmount">
                        --
                    </div>

                    <hr>

                    <p id="exchangeRate" class="mb-1"></p>

                    <p id="rateDate" class="rate-info mb-0"></p>

                </div>

            </div>

            <div class="mt-4 text-muted small">
                <p>
                    <strong>Note:</strong> Exchange rates are reference rates
                    and may not match the exact rates offered by banks or
                    money transfer services.
                </p>

                <p>
                    Currency conversion is for estimation only. It does not
                    change your existing Fundwise transactions or savings.
                </p>
            </div>

        

<script>
const API_URL = "https://api.frankfurter.dev/v2";

const preferredCurrency = <?php
    echo json_encode($preferred_currency);
?>;

const amountInput = document.getElementById("amount");
const fromSelect = document.getElementById("fromCurrency");
const toSelect = document.getElementById("toCurrency");
const convertButton = document.getElementById("convertButton");
const swapButton = document.getElementById("swapButton");

const statusMessage = document.getElementById("statusMessage");
const resultBox = document.getElementById("resultBox");
const convertedAmount = document.getElementById("convertedAmount");
const exchangeRate = document.getElementById("exchangeRate");
const rateDate = document.getElementById("rateDate");

let currencyList = [];


// Load all supported currencies
async function loadCurrencies() {
    try {
        statusMessage.textContent = "Loading supported currencies...";

        const response = await fetch(`${API_URL}/currencies`);

        if (!response.ok) {
            throw new Error("Unable to load currency list.");
        }

        const data = await response.json();

        // Support the API's returned currency records
        currencyList = data.map(item => {
            if (typeof item === "string") {
                return {
                    code: item,
                    name: item
                };
            }

            const code = item.iso_code || item.code || item.currency;

            const name = item.name || item.currency_name || code;

            return {
                code: code,
                name: name
            };
        }).filter(item => item.code && item.name);

        currencyList.sort((a, b) => a.code.localeCompare(b.code));

        if (currencyList.length === 0) {
            throw new Error("No supported currencies were returned.");
        }

        populateCurrencyDropdowns();

        convertButton.disabled = false;

        statusMessage.textContent =
            currencyList.length + " supported currencies loaded.";

    } catch (error) {
        statusMessage.textContent =
            "Unable to load currencies. Please refresh the page.";

        statusMessage.classList.add("text-danger");

        console.error(error);
    }
}


// Add currencies to both dropdowns
function populateCurrencyDropdowns() {

    fromSelect.innerHTML = "";
    toSelect.innerHTML = "";

    currencyList.forEach(currency => {

        const option1 = document.createElement("option");

        option1.value = currency.code;

        option1.textContent =
            currency.code + " - " + currency.name;

        fromSelect.appendChild(option1);


        const option2 = document.createElement("option");

        option2.value = currency.code;

        option2.textContent =
            currency.code + " - " + currency.name;

        toSelect.appendChild(option2);

    });

    // Use the user's preferred currency as the default source
    if (currencyList.some(c => c.code === preferredCurrency)) {
        fromSelect.value = preferredCurrency;
    } else {
        fromSelect.value = "EUR";
    }

    // Choose a different destination currency
    const otherCurrency = currencyList.find(
        c => c.code !== fromSelect.value
    );

    toSelect.value = otherCurrency
        ? otherCurrency.code
        : fromSelect.value;
}


// Searchable currency dropdowns
function enableCurrencySearch(selectElement) {

    const wrapper = document.createElement("div");

    wrapper.style.position = "relative";

    selectElement.parentNode.insertBefore(wrapper, selectElement);

    wrapper.appendChild(selectElement);

    const searchInput = document.createElement("input");

    searchInput.type = "text";
    searchInput.className = "form-control mb-2";
    searchInput.placeholder = "Search currency code or name...";

    wrapper.insertBefore(searchInput, selectElement);

    searchInput.addEventListener("input", function() {

        const search = this.value.toLowerCase();

        const currentValue = selectElement.value;

        const filtered = currencyList.filter(currency =>
            currency.code.toLowerCase().includes(search) ||
            currency.name.toLowerCase().includes(search)
        );

        selectElement.innerHTML = "";

        filtered.forEach(currency => {

            const option = document.createElement("option");

            option.value = currency.code;

            option.textContent =
                currency.code + " - " + currency.name;

            selectElement.appendChild(option);

        });

        if (filtered.some(c => c.code === currentValue)) {
            selectElement.value = currentValue;
        }

        if (filtered.length === 0) {
            const option = document.createElement("option");

            option.textContent = "No matching currency found";
            option.value = "";

            selectElement.appendChild(option);
        }

    });

    searchInput.addEventListener("input", function() {
        resultBox.style.display = "none";
    });
}


// Convert currency
document.getElementById("converterForm").addEventListener(
    "submit",
    async function(event) {

        event.preventDefault();

        const amount = Number(amountInput.value);

        const from = fromSelect.value;
        const to = toSelect.value;

        if (!Number.isFinite(amount) || amount <= 0) {
            statusMessage.textContent =
                "Please enter a valid amount greater than zero.";

            statusMessage.classList.add("text-danger");

            return;
        }

        if (!from || !to) {
            statusMessage.textContent =
                "Please select both currencies.";

            return;
        }

        convertButton.disabled = true;
        convertButton.textContent = "Converting...";

        statusMessage.textContent = "Getting exchange rate...";

        statusMessage.classList.remove("text-danger");

        resultBox.style.display = "none";

        try {

            let rate = 1;
            let date = "Same currency";

            // No API request needed if currencies are identical
            if (from !== to) {

                const response = await fetch(
                    `${API_URL}/rate/${encodeURIComponent(from)}/${encodeURIComponent(to)}`
                );

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(
                        data.message || "Exchange rate unavailable."
                    );
                }

                rate = Number(data.rate);
                date = data.date;

                if (!Number.isFinite(rate) || rate <= 0) {
                    throw new Error("Invalid exchange rate received.");
                }
            }

            const converted = amount * rate;

            const formattedAmount = new Intl.NumberFormat(
                "en-US",
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 4
                }
            ).format(converted);

            convertedAmount.textContent =
                to + " " + formattedAmount;

            exchangeRate.textContent =
                "1 " + from + " = " +
                rate.toLocaleString("en-US", {
                    maximumFractionDigits: 6
                }) + " " + to;

            rateDate.textContent =
                date === "Same currency"
                    ? "Both currencies are the same."
                    : "Rate date: " + date;

            resultBox.style.display = "block";

            statusMessage.textContent = "Conversion successful.";

        } catch (error) {

            statusMessage.textContent =
                error.message || "Something went wrong.";

            statusMessage.classList.add("text-danger");

        } finally {

            convertButton.disabled = false;
            convertButton.textContent = "Convert Currency";

        }

    }
);


// Swap selected currencies
swapButton.addEventListener("click", function() {

    const from = fromSelect.value;
    const to = toSelect.value;

    fromSelect.value = to;
    toSelect.value = from;

    resultBox.style.display = "none";

});


// Hide old result when values change
amountInput.addEventListener("input", function() {
    resultBox.style.display = "none";
});
fromSelect.addEventListener("change", function() {
    resultBox.style.display = "none";
});

toSelect.addEventListener("change", function() {
    resultBox.style.display = "none";
});


// Start loading currencies
loadCurrencies().then(() => {

    if (currencyList.length > 0) {
        enableCurrencySearch(fromSelect);
        enableCurrencySearch(toSelect);
    }

});

</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
