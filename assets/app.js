(function () {
  'use strict';

  // ---------- Modal (bottom sheet) ----------
  document.addEventListener('click', function (e) {
    var openTrigger = e.target.closest('[data-open-modal]');
    if (openTrigger) {
      var modal = document.getElementById(openTrigger.getAttribute('data-open-modal'));
      if (!modal) return;
      modal.classList.add('is-open');

      var form = modal.querySelector('form');
      var titleEl = modal.querySelector('h3');
      var sheet = modal.querySelector('.modal-sheet');
      var titleNew = sheet ? sheet.getAttribute('data-title-new') : null;
      var titleEdit = sheet ? sheet.getAttribute('data-title-edit') : null;
      if (form) {
        var dateField = form.querySelector('[name="entry_date"]');
        if (openTrigger.hasAttribute('data-edit-date')) {
          // Επεξεργασία υπάρχουσας καταχώρησης: γεμίζουμε τη φόρμα, η ημερομηνία κλειδώνει
          if (dateField) { dateField.value = openTrigger.getAttribute('data-edit-date') || ''; dateField.readOnly = true; }
          form.querySelector('[name="weight"]').value = openTrigger.getAttribute('data-edit-weight') || '';
          form.querySelector('[name="note"]').value = openTrigger.getAttribute('data-edit-note') || '';
          if (titleEl && titleEdit) titleEl.textContent = titleEdit;
        } else {
          // Νέα καταχώρηση: καθαρή φόρμα με σημερινή ημερομηνία
          form.reset();
          if (dateField) dateField.readOnly = false;
          if (titleEl && titleNew) titleEl.textContent = titleNew;
        }
      }
      return;
    }

    var copyBtn = e.target.closest('[data-copy]');
    if (copyBtn) {
      var text = copyBtn.getAttribute('data-copy');
      var label = copyBtn.textContent;
      var done = function () {
        copyBtn.textContent = copyBtn.getAttribute('data-copied') || label;
        setTimeout(function () { copyBtn.textContent = label; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        // Fallback για σελίδες χωρίς HTTPS
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (err) { /* ignore */ }
        document.body.removeChild(ta);
      }
      return;
    }

    if (e.target.closest('[data-close-modal]')) {
      var openModal = document.querySelector('.modal-backdrop.is-open');
      if (openModal) openModal.classList.remove('is-open');
      return;
    }

    if (e.target.classList.contains('modal-backdrop')) {
      e.target.classList.remove('is-open');
    }
  });

  // ---------- Επιβεβαίωση διαγραφής ----------
  document.addEventListener('submit', function (e) {
    if (e.target.matches('[data-confirm]')) {
      if (!window.confirm(e.target.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    }
  });

  // ---------- Chart.js: ραβδογράμμα ----------
  window.varosBarChart = function (canvasId, labels, values) {
    var el = document.getElementById(canvasId);
    if (!el || typeof Chart === 'undefined') return null;
    var css = getComputedStyle(document.documentElement);
    var primary = css.getPropertyValue('--primary').trim() || '#1F6F5C';
    var ink = css.getPropertyValue('--ink-soft').trim() || '#52625B';

    return new Chart(el.getContext('2d'), {
      type: 'bar',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: primary, borderRadius: 5, maxBarThickness: 28 }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 400 },
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: ink, font: { size: 10 }, maxTicksLimit: 12 } },
          y: { beginAtZero: true, grid: { color: '#DCE4DF' }, ticks: { color: ink, font: { size: 10 } } }
        }
      }
    });
  };

  // ---------- Chart.js helper ----------
  window.varosLineChart = function (canvasId, labels, values, opts) {
    var el = document.getElementById(canvasId);
    if (!el || typeof Chart === 'undefined') return null;
    opts = opts || {};
    var css = getComputedStyle(document.documentElement);
    var primary = css.getPropertyValue('--primary').trim() || '#1F6F5C';
    var primarySoft = css.getPropertyValue('--primary-soft').trim() || '#DCEDE7';
    var ink = css.getPropertyValue('--ink-soft').trim() || '#52625B';

    return new Chart(el.getContext('2d'), {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          data: values,
          borderColor: primary,
          backgroundColor: primarySoft,
          borderWidth: 2,
          pointRadius: opts.points === false ? 0 : 2,
          pointBackgroundColor: primary,
          tension: 0.35,
          fill: true,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 400 },
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
        scales: {
          x: {
            display: opts.showAxes !== false,
            grid: { display: false },
            ticks: { color: ink, font: { size: 10 }, maxTicksLimit: 6 }
          },
          y: {
            display: opts.showAxes !== false,
            grid: { color: '#DCE4DF' },
            ticks: { color: ink, font: { size: 10 } }
          }
        }
      }
    });
  };
})();
