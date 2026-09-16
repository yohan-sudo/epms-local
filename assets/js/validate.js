/* =============================================================
 * U EPMS - Client-Side Form Validation Engine
 * Plain JavaScript (zero dependencies). Loaded on every page
 * with forms. Replaces default browser popups with inline,
 * styled, per-field error messages and adds cross-field rules
 * that HTML5 constraints alone cannot express.
 * Mirrors the server-side rules in includes/functions.php so
 * users see problems instantly, and the server stays the
 * final authority if JavaScript is disabled.
 * ============================================================= */
(function () {
  'use strict';

  var MESSAGES = {
    valueMissing: 'This field is required.',
    typeEmail: 'Enter a valid email address.',
    typeNumber: 'Enter a valid number.',
    patternMismatch: 'Invalid format.',
    tooLong: 'Too long — shorten this value.',
    tooShort: 'Too short — add more characters.',
    rangeUnderflow: 'Value is too small.',
    rangeOverflow: 'Value is too large.',
    stepMismatch: 'Enter a valid step value.'
  };

  function localizedMessage(input) {
    var state = input.validity;
    if (state.valueMissing) return input.dataset.requiredError || MESSAGES.valueMissing;
    if (state.typeMismatch) return MESSAGES.typeEmail;
    if (state.patternMismatch) return input.dataset.patternError || MESSAGES.patternMismatch;
    if (state.tooLong) return MESSAGES.tooLong;
    if (state.tooShort) {
      var min = input.getAttribute('minlength');
      return min ? 'Must be at least ' + min + ' characters.' : MESSAGES.tooShort;
    }
    if (state.rangeUnderflow) {
      var minV = input.getAttribute('min');
      return input.dataset.minError || (minV ? 'Must be at least ' + minV + '.' : MESSAGES.rangeUnderflow);
    }
    if (state.rangeOverflow) {
      var maxV = input.getAttribute('max');
      return input.dataset.maxError || (maxV ? 'Must not exceed ' + maxV + '.' : MESSAGES.rangeOverflow);
    }
    if (state.stepMismatch) return MESSAGES.stepMismatch;
    if (state.typeMismatch) return MESSAGES.typeNumber;
    return MESSAGES.valueMissing;
  }

  /* Cross-field rule registry: each returns '' when valid,
   * otherwise the message shown under the primary field. */
  var FORM_RULES = {
    /* Production shift report: good + partial + scrap must fit inside gross output. */
    'save_shift_report': function (form) {
      var n = function (id) { var el = form.querySelector('#' + id); return el ? (parseInt(el.value, 10) || 0) : 0; };
      var produced = n('units_produced');
      var good = n('good_units');
      var partial = n('partial_reject_count');
      var scrap = n('total_reject_count');
      var producedEl = form.querySelector('#units_produced');
      if (producedEl && produced > 0 && good > produced) {
        return { field: producedEl, message: 'Good units cannot exceed gross units produced.' };
      }
      if (producedEl && good + partial + scrap > produced) {
        return { field: producedEl, message: 'Good units + partial rejects + scrap (' + (good + partial + scrap) + ') cannot exceed gross units produced (' + produced + ').' };
      }
      return null;
    }
  };

  function todayISO() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function validateField(input) {
    if (input.disabled || input.readOnly) return true;
    if (!input.willValidate) return true;

    clearFieldError(input);

    /* Native constraint checks first (required, pattern, min/max, maxlength). */
    if (!input.checkValidity()) {
      showFieldError(input, localizedMessage(input));
      return false;
    }

    /* Extra date rule: business dates cannot be in the future. */
    if (input.type === 'date' && ['report_date', 'expense_date', 'issued_date'].indexOf(input.name) !== -1) {
      if (input.value && input.value > todayISO()) {
        showFieldError(input, 'Date cannot be in the future.');
        return false;
      }
    }

    /* Per-field custom constraint: data-min-error with numeric min. */
    if (input.dataset.minError && input.value !== '') {
      var num = parseFloat(input.value);
      var minAttr = parseFloat(input.getAttribute('min'));
      if (!isNaN(num) && !isNaN(minAttr) && num < minAttr) {
        showFieldError(input, input.dataset.minError);
        return false;
      }
    }

    /* Plain-text constraint: business text fields reject < > and backticks
     * (mirrors the server-side blocklist in includes/functions.php). */
    if (input.hasAttribute('data-plaintext') && /[<>`]/.test(input.value)) {
      showFieldError(input, 'Remove special characters like < > ` — not allowed.');
      return false;
    }

    return true;
  }

  function ensureErrorEl(input) {
    var group = input.closest('.form-group') || input.parentElement;
    var el = group.querySelector('.form-error');
    if (!el) {
      el = document.createElement('span');
      el.className = 'form-error';
      el.setAttribute('role', 'alert');
      group.appendChild(el);
    }
    return el;
  }

  function showFieldError(input, message) {
    input.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
    var el = ensureErrorEl(input);
    el.textContent = message;
  }

  function clearFieldError(input) {
    input.classList.remove('is-invalid');
    input.removeAttribute('aria-invalid');
    var group = input.closest('.form-group') || input.parentElement;
    var el = group && group.querySelector('.form-error');
    if (el) el.remove();
  }

  function validateForm(form) {
    var allValid = true;
    var firstInvalid = null;
    var fields = form.querySelectorAll('input, select, textarea');

    fields.forEach(function (input) {
      var ok = validateField(input);
      if (!ok) {
        allValid = false;
        if (!firstInvalid) firstInvalid = input;
      }
    });

    /* Cross-field rules registered for this form's action. */
    var action = (form.querySelector('input[name="action"]') || {}).value || '';
    var rule = FORM_RULES[action];
    if (rule) {
      var result = rule(form);
      if (result && result.field) {
        showFieldError(result.field, result.message);
        allValid = false;
        if (!firstInvalid) firstInvalid = result.field;
      }
    }

    return { valid: allValid, firstInvalid: firstInvalid };
  }

  function enhanceForm(form) {
    if (form.dataset.validationBound) return;
    form.dataset.validationBound = '1';
    form.novalidate = true;

    /* Provide friendly patterns + titles for username and password fields. */
    var username = form.querySelector('input[name="username"]');
    if (username) {
      username.setAttribute('pattern', '[A-Za-z0-9_.]{3,32}');
      username.setAttribute('maxlength', '32');
      username.setAttribute('data-pattern-error', 'Use only letters, numbers, dots or underscores (3-32 characters).');
    }
    var newPassword = form.querySelector('input[name="new_password"], input[name="password"]');
    if (newPassword) {
      newPassword.setAttribute('pattern', '\\S{6,64}');
      newPassword.setAttribute('data-pattern-error', '6-64 characters, no spaces.');
      newPassword.setAttribute('data-required-error', 'Password is required.');
    }

    /* Live feedback: re-validate only fields already flagged, full check on blur. */
    form.addEventListener('input', function (e) {
      var t = e.target;
      if (t.classList && (t.classList.contains('is-invalid') || t.type === 'date')) {
        validateField(t);
      }
    });
    form.addEventListener('change', function (e) {
      if (e.target.tagName === 'SELECT') validateField(e.target);
    });
    form.addEventListener('blur', function (e) {
      var t = e.target;
      if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') validateField(t);
    }, true);

    form.addEventListener('submit', function (e) {
      /* Inline confirm() handlers (delete/ban/reveal) cancel the event
       * before this listener — respect their decision and do nothing.
       * Same for handlers that already validated and cancelled (login page). */
      if (e.defaultPrevented) { form.dataset.submitting = '0'; return; }

      if (form.dataset.submitting === '1') { e.preventDefault(); return; }

      var result = validateForm(form);
      if (!result.valid) {
        e.preventDefault();
        if (result.firstInvalid) {
          result.firstInvalid.focus();
          result.firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
      }

      /* Double-submit guard: visual lock that keeps button values intact. */
      form.dataset.submitting = '1';
      form.classList.add('is-submitting');
      window.setTimeout(function () { form.dataset.submitting = '0'; }, 15000);
    });
  }

  function boot() {
    document.querySelectorAll('form').forEach(function (form) {
      if (!form.dataset.skipValidation) enhanceForm(form);
    });
  }

  /* Public hook: runs full validation and renders inline errors WITHOUT
   * engaging the double-submit lock. Used by page scripts that bind their
   * own submit handlers (e.g. the login loading-state effect) so they can
   * skip visual effects when the form is invalid. */
  window.uepmsFormValid = function (form) {
    return validateForm(form).valid;
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
