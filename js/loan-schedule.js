(() => {
  "use strict";
  document.querySelectorAll(".fw-loan-plan").forEach((form) => {
    const output = form.querySelector(".fw-loan-schedule-preview"),
      money = (n) =>
        form.dataset.currency +
        " " +
        (n / 100).toLocaleString("en-PH", {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        });
    const update = () => {
      const count = Number(form.elements.installment_count.value),
        principal = Number(form.dataset.principal),
        interest = Math.round(
          Number(form.elements.installment_interest.value) * 100,
        ),
        fees = Math.round(Number(form.elements.installment_fees.value) * 100),
        first = form.elements.first_due.value;
      if (
        !Number.isInteger(count) ||
        count < 1 ||
        count > 60 ||
        principal < count ||
        !Number.isFinite(interest) ||
        interest < 0 ||
        !Number.isFinite(fees) ||
        fees < 0
      ) {
        output.textContent = "Enter valid terms to preview the schedule.";
        return;
      }
      const base = Math.floor(principal / count),
        last = principal - base * (count - 1);
      output.replaceChildren();
      const title = document.createElement("strong");
      title.textContent = "Schedule preview";
      output.append(title);
      for (let i = 0; i < count; i++) {
        const row = document.createElement("div");
        let date = "Choose a first due date";
        if (first) {
          const [y, m, d] = first.split("-").map(Number),
            a = new Date(Date.UTC(y, m - 1 + i, 1)),
            end = new Date(
              Date.UTC(a.getUTCFullYear(), a.getUTCMonth() + 1, 0),
            ).getUTCDate();
          a.setUTCDate(Math.min(d, end));
          date = a.toISOString().slice(0, 10);
        }
        row.textContent =
          i +
          1 +
          ". " +
          date +
          " · " +
          money((i === count - 1 ? last : base) + interest + fees);
        output.append(row);
      }
      const total = document.createElement("strong");
      total.textContent =
        "Planned total " + money(principal + count * (interest + fees));
      output.append(total);
    };
    form.addEventListener("input", () => {
      form.elements.plan_reviewed.checked = false;
      update();
    });
    form.elements.plan_reviewed.addEventListener("input", (event) =>
      event.stopPropagation(),
    );
    update();
  });
})();
