<?php
require_once __DIR__ . '/common.php';
$currency = fi_currency($conn, $fi_user_id);

$summary = fi_query($conn, "
    SELECT
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) AS expenses
    FROM transactions WHERE user_id = ?
", 'i', [$fi_user_id]);
$income = (float)($summary[0]['income'] ?? 0);
$expenses = (float)($summary[0]['expenses'] ?? 0);
$remaining = $income - $expenses;

$goals = fi_query($conn, "
    SELECT sg.goal_id, sg.goal_name, sg.target_amount,
           COALESCE(SUM(CASE WHEN sc.contribution_type = 'deposit' THEN sc.amount
                WHEN sc.contribution_type = 'withdrawal' THEN -sc.amount ELSE 0 END),0) AS saved
    FROM savings_goals sg LEFT JOIN savings_contributions sc ON sc.goal_id=sg.goal_id
    WHERE sg.user_id = ?
    GROUP BY sg.goal_id, sg.goal_name, sg.target_amount
    ORDER BY sg.goal_name
", 'i', [$fi_user_id]);

$today = date('Y-m-d');
$budgets = fi_query($conn, "
    SELECT b.budget_id, b.budget_limit, b.start_date, b.end_date,
           c.category_name,
           COALESCE((SELECT SUM(t.amount) FROM transactions t
                WHERE t.user_id=b.user_id AND t.category_id=b.category_id
                AND t.transaction_type='expense'
                AND t.transaction_date BETWEEN b.start_date AND b.end_date),0) AS spent
    FROM budgets b INNER JOIN categories c ON c.category_id=b.category_id AND c.user_id=b.user_id
    WHERE b.user_id=? AND c.category_type='expense' AND ? BETWEEN b.start_date AND b.end_date
    ORDER BY c.category_name
", 'is', [$fi_user_id, $today]);
$jsData = ['currency'=>$currency, 'remaining'=>$remaining,
    'goals'=>array_map(static fn($g)=>[
        'id'=>(int)$g['goal_id'], 'name'=>$g['goal_name'], 'target'=>(float)$g['target_amount'],
        'saved'=>(float)$g['saved']], $goals),
    'budgets'=>array_map(static fn($b)=>[
        'id'=>(int)$b['budget_id'], 'name'=>$b['category_name'], 'limit'=>(float)$b['budget_limit'],
        'spent'=>(float)$b['spent']], $budgets)];

$pageTitle = 'Purchase Consequence Map';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="fi-page">
  <div class="fi-hero fi-hero-map">
    <div>
      <span class="fi-kicker"><i class="bi bi-git-branch"></i> FINANCIAL INTELLIGENCE / DECISION SIMULATOR</span>
      <h1>See the impact <span>before you spend.</span></h1>
      <p>Test a planned purchase against your recorded balance, active category budget, or savings goal. Nothing here changes your real records.</p>
    </div>
    <div class="fi-hero-icon"><i class="bi bi-signpost-split"></i></div>
  </div>
  <div class="row g-4 fi-columns">
    <div class="col-lg-5">
      <section class="fi-panel h-100" aria-labelledby="mapSetup">
        <div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">01 / SETUP</span><h2 id="mapSetup">Planned purchase</h2></div><span class="fi-icon-soft"><i class="bi bi-bag-check"></i></span></div>
        <div class="mb-3"><label class="form-label" for="purchaseTitle">What are you planning to buy?</label><input id="purchaseTitle" class="form-control" value="New headphones" maxlength="80" placeholder="e.g. New headphones"></div>
        <div class="mb-3"><label class="form-label" for="purchaseAmount">Expected price (<?= fi_h($currency) ?>)</label><input id="purchaseAmount" class="form-control" type="number" value="2500" min="0" max="9999999999.99" step="0.01" inputmode="decimal"></div>
        <div class="mb-3"><label class="form-label" for="purchaseSource">Where will the money come from?</label><select id="purchaseSource" class="form-select"><option value="balance">Remaining income balance</option><option value="goal" <?= count($goals) ? '' : 'disabled' ?>>Existing savings goal</option></select></div>
        <div class="mb-3 d-none" id="mapGoalWrap"><label class="form-label" for="mapGoal">Savings goal</label><select id="mapGoal" class="form-select"><?php foreach ($goals as $g): ?><option value="<?= (int)$g['goal_id'] ?>"><?= fi_h($g['goal_name']) ?></option><?php endforeach ?></select><div class="form-text">Goal savings are tracked separately from income minus expenses.</div></div>
        <div class="mb-3 d-none" id="mapMonthlyWrap"><label class="form-label" for="monthlySave">Planned monthly goal contribution (<?= fi_h($currency) ?>)</label><input id="monthlySave" type="number" min="0" step="0.01" class="form-control" value="1000"><div class="form-text">For estimated goal completion delay only.</div></div>
        <div class="mb-3"><label class="form-label" for="mapBudget">Which budget category would this purchase affect?</label><select id="mapBudget" class="form-select"><option value="">No active category budget</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['budget_id'] ?>"><?= fi_h($b['category_name']) ?></option><?php endforeach ?></select></div>
        <div class="fi-note"><i class="bi bi-shield-lock"></i><span>Simulation only. You must record an actual transaction separately if you make the purchase.</span></div>
      </section>
    </div>
    <div class="col-lg-7">
      <div class="fi-panel h-100">
        <div class="fi-panel-heading"><div><span class="fi-mini-eyebrow">02 / PROJECTION</span><h2>Consequence preview</h2></div><span class="fi-status" id="mapStatus">Ready to explore</span></div>
        <div class="fi-projection-grid">
          <div class="fi-projection-card"><span>Current selected balance</span><strong id="mapBefore">—</strong><small>Before the hypothetical purchase</small></div>
          <div class="fi-projection-card fi-projection-primary"><span>Projected selected balance</span><strong id="mapAfter">—</strong><small id="mapDelta">If you make this purchase</small></div>
        </div>
        <div class="fi-divider"></div>
        <div class="fi-impact" id="mapBudgetResult"><span class="fi-impact-icon"><i class="bi bi-pie-chart"></i></span><div><strong>Budget impact</strong><p>Choose an active budget to see how the purchase may affect its remaining allowance.</p></div></div>
        <div class="fi-impact" id="mapGoalResult"><span class="fi-impact-icon"><i class="bi bi-bullseye"></i></span><div><strong>Savings impact</strong><p>Choose a savings goal as the source to explore its projected timeline.</p></div></div>
        <div class="fi-insight" role="status" aria-live="polite"><i class="bi bi-lightbulb"></i><span id="mapInsight">Adjust the price and funding source to explore possible outcomes.</span></div>
      </div>
    </div>
  </div>
  <div class="fi-footnote"><i class="bi bi-info-circle"></i> These are estimates based on your saved Fundwise records. Values use your preferred currency code as a label; this tool does not convert transaction amounts.</div>
</div>
<script type="application/json" id="fiMapData"><?= json_encode($jsData, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script>
(() => {
  const sourceData = JSON.parse(document.getElementById('fiMapData').textContent);
  const el = id => document.getElementById(id);
  const money = n => sourceData.currency + ' ' + Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
  const safeNumber = v => { const n=Number(v);return Number.isFinite(n)?Math.max(0,Math.min(9999999999.99,n)):0; };
  const setText=(id,txt)=>{el(id).textContent=txt};
  function render(){
    const amount=safeNumber(el('purchaseAmount').value), useGoal=el('purchaseSource').value==='goal';
    el('mapGoalWrap').classList.toggle('d-none',!useGoal);
    el('mapMonthlyWrap').classList.toggle('d-none',!useGoal);
    const goal=sourceData.goals.find(g=>String(g.id)===el('mapGoal').value);
    const budget=sourceData.budgets.find(b=>String(b.id)===el('mapBudget').value);
    const current=useGoal?(goal?.saved??0):sourceData.remaining;
    const after=current-amount;
    setText('mapBefore',money(current)); setText('mapAfter',money(after));
    el('mapAfter').classList.toggle('text-danger',after<0);
    setText('mapDelta',amount>0?'Change: −'+money(amount):'No purchase amount entered');
    setText('mapStatus',after<0?'Not enough in selected source':'Simulation only');
    const budgetBox=el('mapBudgetResult').querySelector('p');
    if (budget){const available=budget.limit-budget.spent;
      budgetBox.textContent=budget.name+': '+money(available)+' available today. After this hypothetical expense: '+money(available-amount)+(available-amount<0?' (over the budget limit).':'.');
    }else budgetBox.textContent='Select an active category budget above to preview its remaining allowance.';
    const goalBox=el('mapGoalResult').querySelector('p');
    if (useGoal&&goal){
      const rate=safeNumber(el('monthlySave').value);const oldLeft=Math.max(0,goal.target-goal.saved);const newLeft=Math.max(0,goal.target-(goal.saved-amount));
      const delay=rate>0?Math.ceil(newLeft/rate)-Math.ceil(oldLeft/rate):null;
      goalBox.textContent=goal.name+': saved '+money(goal.saved)+', projected '+money(goal.saved-amount)+'. '+(rate>0?'Estimated goal delay: '+delay+' month(s) at '+money(rate)+'/month.':'Enter a positive monthly contribution to estimate completion delay.');
    }else goalBox.textContent='Savings records are unchanged when you choose remaining income balance as the funding source.';
    const purchase=(el('purchaseTitle').value.trim()||'This purchase');
    setText('mapInsight',after<0?purchase+' would exceed the chosen funding balance by '+money(-after)+'. Consider reducing its cost or changing your plan.':budget&&budget.limit-budget.spent-amount<0?purchase+' fits the selected funding source, but would exceed the chosen category budget.':purchase+' would leave '+money(after)+' in the selected funding source. Compare the trade-off before deciding.');
  }
  ['purchaseTitle','purchaseAmount','purchaseSource','mapGoal','monthlySave','mapBudget'].forEach(id=>{
    el(id).addEventListener('input',render);el(id).addEventListener('change',render);
  }); render();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
