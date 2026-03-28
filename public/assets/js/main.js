'use strict';

/**
 * OSGridManager — Main JavaScript
 * Vanilla JS only. No frameworks, no jQuery.
 * This file is minimal — only progressively enhances where needed.
 */

// ---------------------------------------------------------------------------
// Auto-dismiss flash alerts after 6 seconds
// ---------------------------------------------------------------------------
(function () {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 400);
        }, 6000);
    });
}());

// ---------------------------------------------------------------------------
// Confirm before submitting dangerous forms
// (Add data-confirm="Are you sure?" to any form or button)
// ---------------------------------------------------------------------------
(function () {
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm]');
        if (btn && btn.tagName !== 'FORM') {
            var msg = btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        }
    });
}());

// ---------------------------------------------------------------------------
// Client-side password confirmation check
// ---------------------------------------------------------------------------
(function () {
    var form = document.querySelector('form[data-pw-confirm]');
    if (!form) return;

    var pw1 = form.querySelector('[name="password"]');
    var pw2 = form.querySelector('[name="password_confirm"]');
    if (!pw1 || !pw2) return;

    form.addEventListener('submit', function (e) {
        if (pw1.value !== pw2.value) {
            e.preventDefault();
            var err = form.querySelector('.pw-confirm-error');
            if (!err) {
                err = document.createElement('p');
                err.className = 'form-error pw-confirm-error';
                pw2.parentNode.appendChild(err);
            }
            err.textContent = 'Passwords do not match.';
            pw2.focus();
        }
    });
}());
