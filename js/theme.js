// Theme switch, persisted through the existing CSRF-protected theme API.
// Also applies the Fundwise chart palette; data/calculations are unchanged.
(() => {
  'use strict';
  const root = document.documentElement;
  const button = document.getElementById('themeToggle');
  const icon = document.getElementById('themeIcon');
  const selector = document.querySelector('select[name="theme"]');
  const KEY = 'fundwise-theme';
  const palette = ['#1787f2','#17bdcb','#8454ef','#f4b44a','#ed6986','#16b988','#6287f0','#8ba1b8','#d6e1f0'];

  const setIcon = theme => {
    if (icon) icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    if (button) {
      button.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
      button.setAttribute('aria-label',button.title);
    }
  };
  const styleCharts = theme => {
    if (!window.Chart) return;
    const dark = theme === 'dark';
    const foreground = dark ? '#b7c8df' : '#73849a';
    const grid = dark ? 'rgba(167,194,223,.11)' : 'rgba(75,113,155,.10)';
    Chart.defaults.color = foreground;
    Chart.defaults.borderColor = grid;
    Chart.defaults.font.family = "'DM Sans', sans-serif";
    const instances = Chart.instances || {};
    for (const chart of Object.values(instances)) {
      if (!chart || !chart.options || !chart.data) continue;
      chart.options.color = foreground;
      if (!chart.options.plugins) chart.options.plugins = {};
      if (chart.options.plugins.legend) {
        chart.options.plugins.legend.labels = {...chart.options.plugins.legend.labels, color:foreground, padding:18, usePointStyle:true, pointStyle:'circle', boxWidth:8};
      }
      if (chart.options.scales) {
        for (const axis of Object.values(chart.options.scales)) {
          if (!axis) continue;
          axis.ticks = {...axis.ticks,color:foreground,padding:9};
          axis.grid = {...axis.grid,color:grid};
          axis.border = {...axis.border,display:false};
        }
      }
      if (chart.config.type === 'doughnut' || chart.config.type === 'pie') {
        chart.options.cutout = chart.config.type === 'doughnut' ? '71%' : undefined;
        for (const ds of chart.data.datasets || []) {
          ds.backgroundColor = (ds.data || []).map((_,i)=>palette[i % palette.length]);
          ds.borderColor = dark ? '#152439' : '#fff';
          ds.borderWidth = 3;
          ds.hoverOffset = 6;
        }
      } else if (chart.config.type === 'bar') {
        for (let i=0;i<(chart.data.datasets || []).length;i++) {
          const ds = chart.data.datasets[i];
          const name = String(ds.label || '').toLowerCase();
          ds.backgroundColor = name.includes('expense') ? (dark ? '#f47e98' : '#f0768f') :
            name.includes('income') ? (dark ? '#22cbb4' : '#0bbda4') : palette[i % palette.length];
          ds.borderColor = 'transparent';
          ds.borderWidth = 0;
          ds.borderRadius = 7;
          ds.maxBarThickness = 31;
        }
      }
      try { chart.update('none'); } catch (e) { /* keep original chart */ }
    }
  };
  const apply = theme => {
    root.setAttribute('data-bs-theme',theme);
    setIcon(theme);
    if (selector) selector.value = theme;
    styleCharts(theme);
  };
  const store = theme => {try {localStorage.setItem(KEY,theme);} catch(e) { /* optional */ }};
  const save = async theme => {
    const token = document.querySelector('meta[name="csrf-token"]');
    const base = document.querySelector('meta[name="base-url"]');
    if (!token || !base) return false;
    try {
      const body = new URLSearchParams({theme,csrf_token:token.content});
      const response = await fetch(base.content + '/api/theme.php',{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()
      });
      const result = await response.json();
      return response.ok && result.ok === true;
    } catch (error) {
      console.error('Theme save failed:',error);
      return false;
    }
  };
  const initial = root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
  apply(initial); store(initial);
  if (button) button.addEventListener('click',async()=>{
    if (button.disabled) return;
    const previous = root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    const next = previous === 'dark' ? 'light' : 'dark';
    button.disabled = true;
    apply(next);
    if (await save(next)) store(next);
    else { apply(previous); button.title = 'Could not save theme. Please try again.'; }
    button.disabled = false;
  });
})();
