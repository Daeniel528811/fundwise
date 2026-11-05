(() => {
 'use strict';
 const data=JSON.parse(document.getElementById('earningsData').textContent),items=document.getElementById('expenseItems'),add=document.getElementById('addExpense');
 const money=n=>data.currency+' '+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
 function update(){let cents=0;items.querySelectorAll('[name="expense_amount[]"]').forEach(input=>{const n=Number(input.value);if(Number.isFinite(n)&&n>0)cents+=Math.round(n*100);});document.getElementById('expenseTotal').textContent=money(cents/100);add.disabled=items.children.length>=30;}
 add.addEventListener('click',()=>{if(items.children.length>=30)return;items.append(document.getElementById('expenseTemplate').content.cloneNode(true));items.lastElementChild.querySelector('input').focus();update();});
 items.addEventListener('input',update);items.addEventListener('click',event=>{const button=event.target.closest('.fw-remove-expense');if(button){button.closest('.fw-expense-row').remove();update();}});update();
 const canvas=document.getElementById('earningsTrend');
 if(canvas&&window.Chart)new Chart(canvas,{type:'line',data:{labels:data.labels,datasets:[{label:'Net work earnings',data:data.values,borderColor:'#2798ed',backgroundColor:'rgba(39,152,237,.07)',borderWidth:2.5,tension:0,pointRadius:4,pointHoverRadius:6,pointBackgroundColor:'#2798ed',spanGaps:false,fill:false}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'nearest',intersect:false},scales:{y:{beginAtZero:true},x:{ticks:{maxTicksLimit:7,maxRotation:0}}},plugins:{legend:{display:false},tooltip:{callbacks:{label:context=>money(context.parsed.y)}}}}});
})();
