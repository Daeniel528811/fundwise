export function parseAmount(value, label = "Amount", allowZero = true) {
  const raw = String(value).trim();
  if (!/^\d+(?:\.\d{1,2})?$/.test(raw))
    throw Error(label + " needs a valid amount with up to two decimals.");
  const cents = Math.round(Number(raw) * 100);
  if (
    !Number.isSafeInteger(cents) ||
    cents > 999999999999 ||
    cents < (allowZero ? 0 : 1)
  )
    throw Error(label + " is outside the allowed range.");
  return cents;
}
export function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(value + "T00:00:00Z");
  return (
    Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value
  );
}
export function calculatePurchase(
  input,
  balance,
  goal = null,
  hourly = null,
  budget = null,
) {
  const committed = input.obligations.reduce(
      (sum, item) => sum + item.amount,
      0,
    ),
    available = balance - committed - input.reserve,
    after = available - input.amount,
    assessed = input.balance_checked && input.commitments_checked;
  const budgetAfter =
    budget !== null ? budget.limit - budget.spent - input.amount : null;
  const result = {
    balance,
    committed,
    reserve: input.reserve,
    available,
    after,
    status:
      after < 0
        ? "Shortfall in entered plan"
        : budgetAfter !== null && budgetAfter < 0
          ? "Exceeds selected budget"
          : assessed
            ? "Within entered plan"
            : "More information needed",
    assessed,
    kind:
      after < 0 || (budgetAfter !== null && budgetAfter < 0)
        ? "danger"
        : assessed
          ? "success"
          : "warning",
    budget_after: budgetAfter,
    cheaper_after: input.cheaper === null ? null : available - input.cheaper,
    goal_before_months: null,
    goal_after_months: null,
    goal_replacement: null,
    work_hours:
      hourly !== null && hourly > 0
        ? Math.round((input.amount / 100 / hourly) * 100) / 100
        : null,
  };
  if (goal !== null && available >= input.amount) {
    result.goal_replacement = input.amount;
    if (input.monthly > 0) {
      result.goal_before_months = Math.ceil(
        Math.max(0, goal.target - balance) / input.monthly,
      );
      result.goal_after_months = Math.ceil(
        Math.max(0, goal.target - (balance - input.amount)) / input.monthly,
      );
    }
  }
  return result;
}
