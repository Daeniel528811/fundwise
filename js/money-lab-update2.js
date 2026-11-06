(() => {
  "use strict";
  const data = JSON.parse(document.getElementById("labData").textContent),
    category = document.getElementById("category_id"),
    duration = document.getElementById("duration"),
    mode = document.getElementById("baseline_mode"),
    manual = document.getElementById("manual_baseline"),
    preview = document.getElementById("baselinePreview"),
    records = document.getElementById("baselineRecordList"),
    label = document.getElementById("spending_label");
  const cash = (value) =>
    data.currency +
    " " +
    Number(value).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  let request = 0,
    timer;
  function node(tag, text, cls) {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (cls) el.className = cls;
    return el;
  }
  function review(container, row) {
    container.replaceChildren();
    const box = node("div", undefined, "fw-record-review");
    box.tabIndex = 0;
    box.setAttribute("role", "region");
    box.setAttribute(
      "aria-label",
      "Transaction review for " +
        row.category_name +
        (row.label ? " · " + row.label : ""),
    );
    box.append(
      node(
        "h4",
        row.category_name +
          (row.label ? " → " + row.label : " · entire category"),
      ),
    );
    box.append(
      node(
        "p",
        row.start + (row.start !== row.end ? " to " + row.end : ""),
        "fw-record-scope",
      ),
    );
    const matching = row.items.filter((item) => item.is_matching),
      unknown = row.items.filter((item) => !item.is_matching);
    const show = (items, heading) => {
      box.append(node("h4", heading));
      if (!items.length)
        box.append(node("p", "No transactions to show.", "fw-record-empty"));
      for (const item of items) {
        const line = node("div", undefined, "fw-record-row"),
          copy = node("div");
        copy.append(
          node("span", item.description || "No description"),
          node(
            "small",
            item.transaction_date +
              " · " +
              item.category_name +
              " · " +
              (item.spending_label
                ? "Label: " + item.spending_label
                : "No spending label"),
          ),
        );
        const edit = node("a", "Review / edit");
        edit.href =
          data.base +
          "/transactions/?edit=" +
          item.transaction_id +
          "#transactionForm";
        copy.append(edit);
        line.append(copy, node("strong", cash(item.amount)));
        box.append(line);
      }
    };
    show(matching, "Matching transactions · " + row.count);
    const total = node("div", undefined, "fw-record-row");
    total.append(
      node("span", "Matching total"),
      node("strong", cash(row.amount)),
    );
    box.append(total);
    if (row.label && row.unclassified) {
      show(unknown, "Needs a spending label · " + row.unclassified);
      box.append(
        node(
          "p",
          "These expenses are not included in the matching total. Classify them before confirming this period.",
          "fw-record-empty fw-record-warning",
        ),
      );
    }
    if (row.count + row.unclassified > row.items.length)
      box.append(
        node(
          "p",
          "Showing up to 100 matching or unclassified records.",
          "fw-record-empty",
        ),
      );
    const all = node("a", "Open this period in Transactions");
    all.href =
      data.base +
      "/transactions/?" +
      new URLSearchParams({
        type: "expense",
        category: row.category,
        date_from: row.start,
        date_to: row.end,
      });
    const end = node("p", undefined, "fw-record-empty");
    end.append(all);
    box.append(end);
    container.append(box);
  }
  function baselineMode() {
    const active = mode.value === "manual";
    document.getElementById("manualBaselineWrap").hidden = !active;
    manual.required = active;
  }
  function suggestions() {
    const list = document.getElementById("labSpendingLabels");
    list.replaceChildren();
    for (const row of data.labels || [])
      if (String(row.category_id) === category.value) {
        const option = node("option");
        option.value = row.label;
        list.append(option);
      }
  }
  async function refresh() {
    baselineMode();
    suggestions();
    const current = ++request;
    records.replaceChildren();
    if (!category.value || !data.ready) {
      preview.textContent =
        "Choose a category to review the previous matching period.";
      return;
    }
    preview.textContent = "Loading previous-period records…";
    try {
      const url = new URL(location.href);
      url.search = "";
      url.searchParams.set("preview", "1");
      url.searchParams.set("category_id", category.value);
      url.searchParams.set("duration", duration.value);
      url.searchParams.set("spending_label", label.value);
      const response = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const row = await response.json();
      if (current !== request) return;
      if (!response.ok) throw Error();
      preview.textContent =
        row.start +
        " to " +
        row.end +
        " · " +
        cash(row.amount) +
        " · " +
        row.count +
        " matching transactions" +
        (row.unclassified
          ? " · " + row.unclassified + " category expenses need labels"
          : "") +
        ". Review whether this period is representative.";
      review(records, row);
    } catch (error) {
      if (current === request)
        preview.textContent =
          "Could not load the baseline. Refresh and check your records before starting.";
    }
  }
  const resetReview = () => {
    document.querySelector('#labCreate [name="baseline_confirmed"]').checked =
      false;
  };
  label.addEventListener("input", () => {
    resetReview();
    ++request;
    clearTimeout(timer);
    timer = setTimeout(refresh, 250);
  });
  category.addEventListener("change", () => {
    resetReview();
    refresh();
  });
  duration.addEventListener("change", () => {
    resetReview();
    refresh();
  });
  mode.addEventListener("change", () => {
    resetReview();
    baselineMode();
  });
  manual.addEventListener("input", resetReview);
  refresh();
  document.querySelectorAll("[data-lab-day]").forEach((select) => {
    const target = document.getElementById(
      "labRecords" + select.dataset.labDay,
    );
    let version = 0;
    async function load() {
      const current = ++version;
      target.textContent = "Loading this day’s transactions…";
      try {
        const url = new URL(location.href);
        url.search = "";
        url.searchParams.set("day_review", "1");
        url.searchParams.set("experiment_id", select.dataset.labDay);
        url.searchParams.set("day", select.value);
        const response = await fetch(url, {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        const row = await response.json();
        if (current !== version) return;
        if (!response.ok) throw Error();
        review(target, row);
      } catch (error) {
        if (current === version)
          target.textContent =
            "Could not load these records. Refresh before confirming.";
      }
    }
    select.addEventListener("change", load);
    select.closest("details").addEventListener("toggle", (event) => {
      if (event.target.open) load();
    });
  });
})();
