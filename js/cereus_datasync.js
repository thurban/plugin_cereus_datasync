/* Cereus Data Sync — Frontend JS
 * Loaded via page_head hook on all Cacti pages.
 * Uses MutationObserver to init when plugin root is present.
 */
(function () {
    'use strict';

    // Only activate on cereus_datasync pages
    function isCdsPage() {
        return document.querySelector('[data-cds-page]') !== null
            || /cereus_datasync/.test(window.location.pathname);
    }

    function init() {
        if (!isCdsPage()) return;
        initColumnInputs();
        initScheduleToggle();
    }

    // ── Column letter inputs: uppercase on blur ──────────────────────────────
    function initColumnInputs() {
        document.querySelectorAll('input[name^="col_"]').forEach(function (el) {
            el.addEventListener('input', function () {
                this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '');
            });
        });
    }

    // ── Schedule section: hide hour/day fields when not relevant ────────────
    function initScheduleToggle() {
        var schedSel = document.getElementById('schedule_type');
        if (!schedSel) return;

        function applyVisibility() {
            var val = schedSel.value;
            var hourRow = document.getElementById('row_schedule_hour');
            var wdayRow = document.getElementById('row_schedule_wday');
            if (hourRow) hourRow.style.display = (val === 'daily' || val === 'weekly') ? '' : 'none';
            if (wdayRow) wdayRow.style.display = (val === 'weekly') ? '' : 'none';
        }

        schedSel.addEventListener('change', applyVisibility);
        applyVisibility();
    }

    // ── MutationObserver for AJAX navigation ──────────────────────────────────
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].addedNodes.length) {
                init();
                break;
            }
        }
    });

    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
        init();
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            observer.observe(document.body, { childList: true, subtree: true });
            init();
        });
    }
}());
