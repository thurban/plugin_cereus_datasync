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
        // Runs on every Cacti page (loaded via page_head) — the device-delete
        // option lives on host.php, not on a plugin page.
        initDeviceDeleteOption();

        if (!isCdsPage()) return;
        initColumnInputs();
        initScheduleToggle();
    }

    // ── Device delete: offer empty Site / Tree branch cleanup ────────────────
    // Cacti's core delete-confirmation screen (host.php, drp_action=1) is
    // rendered without a plugin render-hook, so the opt-in checkbox is injected
    // client-side and read back server-side via the device_action_bottom hook.
    function initDeviceDeleteOption() {
        if (window.cereusDatasyncDeleteCleanup !== true) return;
        if (!/\/host\.php/.test(window.location.pathname)) return;
        if (document.getElementById('cereus_ds_cleanup_empty')) return;

        // Confirmation screen for the delete action carries a hidden drp_action=1
        // and the delete_type radio group.
        var drp = document.querySelector('input[name="drp_action"][value="1"]');
        if (!drp) return;

        var radios = document.querySelectorAll('input[name="delete_type"]');
        if (!radios.length) return;

        var cell = radios[radios.length - 1].closest('td');
        if (!cell) return;

        var wrap = document.createElement('div');
        wrap.style.marginTop = '10px';
        wrap.style.paddingTop = '8px';
        wrap.style.borderTop = '1px solid rgba(128,128,128,0.35)';

        var label = document.createElement('label');
        label.style.cursor = 'pointer';

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = 'cereus_ds_cleanup_empty';
        cb.name = 'cereus_ds_cleanup_empty';
        cb.value = '1';
        cb.style.marginRight = '6px';
        cb.style.verticalAlign = 'middle';

        label.appendChild(cb);
        label.appendChild(document.createTextNode(
            'Also delete any associated Site(s) and Tree branch(es) that become empty (Cereus Data Sync).'
        ));

        wrap.appendChild(label);
        cell.appendChild(wrap);
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
