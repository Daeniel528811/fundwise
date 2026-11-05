// UI-only enhancements shared by Fundwise pages.
(() => {
  'use strict';
  let fieldIndex = 0;
  document.querySelectorAll('label.form-label:not([for])').forEach(label => {
    if (label.querySelector('input,select,textarea')) return;
    const control=label.parentElement.querySelector('input:not([type="hidden"]),select,textarea');
    if (!control) return;
    if (!control.id) {
      let id;
      do { id='fw-field-'+(++fieldIndex); } while(document.getElementById(id));
      control.id=id;
    }
    label.htmlFor=control.id;
  });
  document.querySelectorAll('[data-fw-password-toggle]').forEach(button => {
    const input = document.getElementById(button.dataset.fwPasswordToggle);
    if (!input) return;
    button.setAttribute('aria-pressed',String(input.type === 'text'));
    button.innerHTML = input.type === 'text' ? '<i class="bi bi-eye" aria-hidden="true"></i>' : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';
    button.addEventListener('click',() => {
      const isShown = input.type === 'text';
      input.type = isShown ? 'password' : 'text';
      button.setAttribute('aria-label',isShown ? 'Show password' : 'Hide password');
      button.title = isShown ? 'Show password' : 'Hide password';
      button.innerHTML = isShown ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>' : '<i class="bi bi-eye" aria-hidden="true"></i>';
      button.setAttribute('aria-pressed',String(!isShown));
    });
  });
  const page=document.title.split('|')[0].trim().toLowerCase();
  document.body.dataset.fwModule=page.replace(/[^a-z]+/g,'-');
  const icons={'transactions':'arrow-left-right','budgets':'pie-chart','savings goals':'bullseye','savings challenges':'trophy','reports':'graph-up-arrow','currency converter':'currency-exchange','notifications':'bell','profile & settings':'person-gear'};
  const heading=document.querySelector('.fw-content h2.page-title,.fw-content h2.section-title');
  if(heading&&icons[page]){const badge=document.createElement('span');badge.className='fw-page-icon';badge.setAttribute('aria-hidden','true');badge.innerHTML='<i class="bi bi-'+icons[page]+'"></i>';heading.before(badge);heading.parentElement.classList.add('fw-module-heading-copy');}
  document.querySelectorAll('.fw-content p:not([id])').forEach(p=>{if(!p.children.length&&!p.textContent.trim())p.remove();});
  if(page==='profile & settings'){const first=document.querySelector('.settings-card'),security=document.getElementById('accountSecurity');if(first&&security){const grid=document.createElement('div');grid.className='fw-profile-grid';first.before(grid);grid.append(first,security);}}
  document.querySelectorAll('.fw-content a[href^="#"]').forEach(link=>link.addEventListener('click',()=>{const id=link.getAttribute('href').slice(1),target=document.getElementById(id);if(target){target.scrollIntoView({behavior:'smooth',block:'start'});const control=target.querySelector('input:not([type="hidden"]),select,textarea');if(control)control.focus({preventScroll:true});}}));
  // Bootstrap appends .modal-backdrop to <body>. Move dialogs to that same
  // stacking context: prevents a dark overlay hiding dialogs inside the shell.
  // Node movement preserves existing show.bs.modal handlers and form values.
  document.querySelectorAll('.fw-main .modal').forEach(modal => {
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
  });
  // Bootstrap still controls focus, Escape, and dismiss buttons.

})();

// Consistent confirmation and reminders, with the browser's native focus handling.
(() => {
 'use strict';
 const script=document.currentScript,base=script?new URL(script.src,location.href).pathname.replace(/\/js\/ui\.js$/,''):'';
 const allowed=new WeakSet();let logoutForm=null;
 const logout=document.createElement('dialog');logout.id='fwLogoutDialog';logout.className='fw-confirm-dialog';logout.setAttribute('aria-labelledby','fwLogoutTitle');
 logout.innerHTML='<span class="fw-dialog-icon"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></span><h2 id="fwLogoutTitle">Log out of Fundwise?</h2><p>You’ll need to sign in again to continue.</p><div class="fw-dialog-actions"><button type="button" class="btn btn-outline-secondary" data-cancel autofocus>Cancel</button><button type="button" class="btn btn-primary" data-confirm>Log out</button></div>';
 document.body.append(logout);
 document.addEventListener('submit',event=>{const form=event.target;if(!(form instanceof HTMLFormElement)||!new URL(form.getAttribute('action')||location.href,location.href).pathname.endsWith('/auth/logout.php')||allowed.has(form))return;event.preventDefault();logoutForm=form;if(typeof logout.showModal==='function')logout.showModal();else if(confirm('Log out of Fundwise?')){allowed.add(form);form.requestSubmit();}});
 logout.querySelector('[data-cancel]').addEventListener('click',()=>logout.close());logout.querySelector('[data-confirm]').addEventListener('click',()=>{if(logoutForm){allowed.add(logoutForm);logout.close();logoutForm.requestSubmit();}});
 let waiting=[];
 window.fwShowNotices=(messages,kind='reminder')=>{
  if(!Array.isArray(messages)||!messages.length)return;
  if(document.querySelector('dialog[open]')){waiting.push({messages,kind});return;}
  let dialog=document.getElementById('fwBudgetDialog');if(!dialog){dialog=document.createElement('dialog');dialog.id='fwBudgetDialog';dialog.className='fw-confirm-dialog';dialog.setAttribute('aria-labelledby','fwNoticeTitle');document.body.append(dialog);}
  dialog.replaceChildren();const icon=document.createElement('span');icon.className='fw-dialog-icon';icon.innerHTML='<i class="bi bi-bell" aria-hidden="true"></i>';dialog.append(icon);
  const title=document.createElement('h2');title.id='fwNoticeTitle';title.textContent=kind==='budget'?'Budget limit exceeded':'Your reminders';dialog.append(title);
  messages.slice(0,5).forEach(message=>{const p=document.createElement('p');p.className='fw-notice-item';p.textContent=String(message);dialog.append(p);});
  const actions=document.createElement('div');actions.className='fw-dialog-actions';const view=document.createElement('a');view.className='btn btn-primary';view.href=base+(kind==='budget'?'/budgets/':'/notifications/');view.textContent=kind==='budget'?'View budgets':'View notifications';actions.append(view);
  const close=document.createElement('button');close.type='button';close.className='btn btn-outline-secondary';close.textContent='Dismiss';close.autofocus=true;close.addEventListener('click',()=>dialog.close());actions.append(close);dialog.append(actions);if(typeof dialog.showModal==='function')dialog.showModal();
 };
 document.addEventListener('close',()=>{if(waiting.length&&!document.querySelector('dialog[open]')){const next=waiting.shift();window.fwShowNotices(next.messages,next.kind);}},true);
 if(document.querySelector('.fw-shell')){let inFlight=false;const poll=async()=>{if(document.hidden||inFlight)return;inFlight=true;try{const r=await fetch(base+'/api/reminder-poll.php',{credentials:'same-origin',headers:{Accept:'application/json'}});if(r.ok&&!r.redirected){const data=await r.json();window.fwShowNotices(data.messages,data.kind);}}catch{}finally{inFlight=false;}};setInterval(poll,60000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});}
})();
