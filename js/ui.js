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
    button.addEventListener('click',() => {
      const isShown = input.type === 'text';
      input.type = isShown ? 'password' : 'text';
      button.setAttribute('aria-label',isShown ? 'Show password' : 'Hide password');
      button.title = isShown ? 'Show password' : 'Hide password';
      button.innerHTML = isShown ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  });
  // Consistent page eyebrows on the existing module pages, without changing their PHP.
  const page = document.title.split('|')[0].trim().toLowerCase();
  const labels = {
    'transactions':['TRANSACTION CENTER','bi-arrow-left-right'],
    'categories':['ORGANIZE YOUR MONEY','bi-grid'],
    'budgets':['SPENDING PLAN','bi-pie-chart'],
    'savings goals':['GROW YOUR SAVINGS','bi-bullseye'],
    'savings challenges':['BUILD GOOD HABITS','bi-trophy'],
    'savings allocation':['PLAN WITH PURPOSE','bi-diagram-3'],
    'reports':['FINANCIAL INSIGHTS','bi-graph-up-arrow'],
    'currency converter':['GLOBAL CURRENCIES','bi-currency-exchange'],
    'notifications':['YOUR ACTIVITY','bi-bell'],
    'profile settings':['YOUR ACCOUNT','bi-person-gear']
  };
  if (labels[page]) {
    const heading = document.querySelector('.fw-content h2.page-title, .fw-content h2.section-title');
    if (heading) {
      heading.parentElement.classList.add('fw-module-heading-copy');
      const eyebrow = document.createElement('div');
      eyebrow.className = 'fw-page-eyebrow';
      const icon = document.createElement('i');
      icon.className = 'bi ' + labels[page][1];
      icon.setAttribute('aria-hidden','true');
      eyebrow.appendChild(icon);
      eyebrow.appendChild(document.createTextNode(labels[page][0]));
      heading.before(eyebrow);
    }
  }
  // Bootstrap appends .modal-backdrop to <body>. Move dialogs to that same
  // stacking context: prevents a dark overlay hiding dialogs inside the shell.
  // Node movement preserves existing show.bs.modal handlers and form values.
  document.querySelectorAll('.fw-main .modal').forEach(modal => {
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
  });
  // Bootstrap still controls focus, Escape, and dismiss buttons.

})();
