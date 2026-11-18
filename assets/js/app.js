/* Care Calendar — modal, toast, and live-update client-side behaviour */

(function () {
    'use strict';
         /* ---------- Strip transient query params (first thing, before anything else) ---------- */

    (function stripTransientParams() {
        if (!window.history || !window.history.replaceState || !window.URL) return;
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.has('signed_out')) {
                url.searchParams.delete('signed_out');
                var qs = url.searchParams.toString();
                var clean = url.pathname + (qs ? '?' + qs : '') + url.hash;
                window.history.replaceState({}, '', clean);
            }
        } catch (e) { /* ignore */ }
    })();

    /* ---------- Modal open / close ---------- */

        function openModal(id, focusInput) {
        var el = document.getElementById(id);
        if (!el) return;
        el.hidden = false;
        document.body.classList.add('modal-open');
        
        // If this is a modal with an unread-date, tell the server we've seen it
        var unreadDate = el.getAttribute('data-unread-date');
        if (unreadDate) {
            fetch('/care-calendar/api/mark-read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'date=' + encodeURIComponent(unreadDate)
            }).catch(function () {});

            // Remove the "N new" badge from the thread box immediately
            var badge = document.querySelector('.thread--messages .thread__unread');
            if (badge) badge.remove();

            // Also remove the red dots beside any unread message rows
            document.querySelectorAll('.thread--messages .thread__dot').forEach(function (dot) {
                dot.remove();
            });

            el.removeAttribute('data-unread-date');
        }

        // Focus the textarea if requested, or if the modal has no entries
        var ta = el.querySelector('textarea');
        if (ta) {
            var list = el.querySelector('.modal__list');
            var empty = !list || !list.children.length;
            if (focusInput || empty) {
                setTimeout(function () { ta.focus(); }, 80);
            }
        }
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.hidden = true;
        document.body.classList.remove('modal-open');

        // Flush any queued toasts
        if (window.CC && typeof window.CC.flushQueue === 'function') {
            window.CC.flushQueue();
        }
    }

    document.addEventListener('click', function (e) {
                var opener = e.target.closest('[data-modal-open]');
        if (opener) {
            e.preventDefault();
            var modalId = opener.getAttribute('data-modal-open');
            var focusInput = opener.getAttribute('data-modal-focus') === '1';
            openModal(modalId, focusInput);
            return;
        }

        var closer = e.target.closest('[data-modal-close]');
        if (closer) {
            e.preventDefault();
            closeModal(closer.getAttribute('data-modal-close'));
            return;
        }

        // Click on backdrop closes
        //if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
        //    closeModal(e.target.id);
        //}
    });

    // Escape key closes the topmost open modal
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var modals = document.querySelectorAll('.modal-backdrop:not([hidden])');
        if (modals.length) {
            closeModal(modals[modals.length - 1].id);
        }
    });

    // Close modal on submit so navigation happens cleanly
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches('[data-modal-form]')) {
            var id = form.getAttribute('data-modal-form');
            var el = document.getElementById(id);
            if (el) { el.hidden = true; document.body.classList.remove('modal-open'); }
        }
    }, true);

    /* ---------- Toasts ---------- */

    var toastQueue = [];
    var toastActive = false;

    function isModalOpen() {
        return document.querySelectorAll('.modal-backdrop:not([hidden])').length > 0;
    }

    function showToast(message, type) {
        type = type || 'info';

        if (isModalOpen()) {
            toastQueue.push({ message: message, type: type });
            return;
        }

        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        var el = document.createElement('div');
        el.className = 'toast toast--' + type;
        el.setAttribute('role', 'status');
        el.textContent = message;

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'toast__close';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', function () {
            dismissToast(el);
        });
        el.appendChild(closeBtn);

        container.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('is-visible'); });

        setTimeout(function () { dismissToast(el); }, 3000);
    }

    function dismissToast(el) {
        if (!el || el.classList.contains('is-leaving')) return;
        el.classList.add('is-leaving');
        setTimeout(function () { el.remove(); }, 300);
    }

    function flushQueue() {
        if (toastActive) return;
        if (isModalOpen()) return;
        while (toastQueue.length) {
            var t = toastQueue.shift();
            showToast(t.message, t.type);
        }
    }

    /* ---------- Expose ---------- */

    window.CC = window.CC || {};
    window.CC.openModal  = openModal;
    window.CC.closeModal = closeModal;
    window.CC.toast      = showToast;
    window.CC.flushQueue = flushQueue;
    window.CC.isModalOpen = isModalOpen;


    /* ---------- "Choose a day first" bubble on week/month/year views ---------- */

    var fabBubbleTimer = null;

    document.addEventListener('click', function (e) {
        var hint = e.target.closest('#week-fab-hint');
        if (!hint) return;

        e.preventDefault();
        var bubble = document.getElementById('week-fab-bubble');
        if (!bubble) return;

        bubble.hidden = false;
        void bubble.offsetWidth;
        bubble.classList.add('is-visible');

        if (fabBubbleTimer) clearTimeout(fabBubbleTimer);
        fabBubbleTimer = setTimeout(function () {
            bubble.classList.remove('is-visible');
            setTimeout(function () { bubble.hidden = true; }, 220);
        }, 3000);
    });

    /* ---------- Convert flash messages on load to toasts ---------- */

    document.addEventListener('DOMContentLoaded', function () {
        var flashes = document.querySelectorAll('.flashes .flash');
        flashes.forEach(function (f) {
            var type = 'info';
            if (f.classList.contains('flash--error'))   type = 'error';
            if (f.classList.contains('flash--success')) type = 'success';
            if (f.classList.contains('flash--warning')) type = 'warning';
            showToast(f.textContent.trim(), type);
            f.remove();
        });
        // Hide the flashes container
        var wrap = document.querySelector('.flashes');
        if (wrap) wrap.style.display = 'none';
    });

    /* ---------- Appearance page: sync custom colour with presets ---------- */

    document.addEventListener('DOMContentLoaded', function () {
        var customPicker = document.getElementById('theme_color_custom');
        var presets = document.querySelectorAll('input[name="theme_color"][type="radio"]');

        if (!customPicker || !presets.length) return;

        // When the user picks a custom colour, uncheck any preset
        customPicker.addEventListener('input', function () {
            presets.forEach(function (p) { p.checked = false; });
        });

        // When the user picks a preset, update the custom picker to match
        presets.forEach(function (p) {
            p.addEventListener('change', function () {
                if (p.checked) {
                    customPicker.value = p.value;
                }
            });
        });
    });

    /* ---------- Appearance page: live preview (v2) ---------- */

    document.addEventListener('DOMContentLoaded', function () {
        
        var hiddenInput   = document.getElementById('theme_color_hidden');
        var customPicker  = document.getElementById('theme_color_custom');
        var brightness    = document.getElementById('theme_brightness');
        var contrast      = document.getElementById('theme_contrast');
        var swatches      = document.querySelectorAll('.appearance-swatch[data-color]');
        var customSwatch  = document.querySelector('.appearance-swatch--custom');

        if (!hiddenInput || !customPicker || !brightness || !contrast) return;

        function hexToHsl(hex) {
            hex = hex.replace('#', '');
            var r = parseInt(hex.substr(0, 2), 16) / 255;
            var g = parseInt(hex.substr(2, 2), 16) / 255;
            var b = parseInt(hex.substr(4, 2), 16) / 255;
            var max = Math.max(r, g, b), min = Math.min(r, g, b);
            var l = (max + min) / 2;
            if (max === min) return [0, 0, l];
            var d = max - min;
            var s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            var h;
            if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
            else if (max === g) h = ((b - r) / d + 2) / 6;
            else h = ((r - g) / d + 4) / 6;
            return [h * 360, s, l];
        }

        function hslToHex(h, s, l) {
            h = ((h % 360) + 360) % 360 / 360;
            function hue2rgb(p, q, t) {
                if (t < 0) t += 1;
                if (t > 1) t -= 1;
                if (t < 1/6) return p + (q - p) * 6 * t;
                if (t < 1/2) return q;
                if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                return p;
            }
            var r, g, b;
            if (s === 0) { r = g = b = l; }
            else {
                var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                var p = 2 * l - q;
                r = hue2rgb(p, q, h + 1/3);
                g = hue2rgb(p, q, h);
                b = hue2rgb(p, q, h - 1/3);
            }
            function toHex(v) {
                var s = Math.round(v * 255).toString(16);
                return s.length === 1 ? '0' + s : s;
            }
            return '#' + toHex(r) + toHex(g) + toHex(b);
        }

        function adjustLightness(hex, delta) {
            var hsl = hexToHsl(hex);
            var l = Math.max(0.05, Math.min(0.95, hsl[2] + delta));
            return hslToHex(hsl[0], hsl[1], l);
        }

        function ensureMaxLightness(hex, maxL) {
            var hsl = hexToHsl(hex);
            maxL = maxL / 100;
            if (hsl[2] > maxL) return hslToHex(hsl[0], hsl[1], maxL);
            return hex;
        }

        function updatePreview() {
            var color         = hiddenInput.value;
            var brightnessVal = parseInt(brightness.value, 10);
            var contrastVal   = parseInt(contrast.value, 10);

            var lightnessShift = (brightnessVal / 100) * 0.30;
            var contrastShift  = (contrastVal / 100) * 0.25;

            var primary = adjustLightness(color, lightnessShift);
            primary = ensureMaxLightness(primary, 70);

            var darker = adjustLightness(primary, -contrastShift);
            var darkerHsl = hexToHsl(darker);
            if (darkerHsl[2] < 0.15) darker = adjustLightness(darker, 0.15 - darkerHsl[2]);

            document.documentElement.style.setProperty('--c-primary', primary);
            document.documentElement.style.setProperty('--c-primary-h', darker);
        }

        function selectColor(hex) {
            hiddenInput.value = hex;
            customPicker.value = hex;

            // Update custom preview swatch (inner circle colour)
            var customPreview = document.getElementById('custom-preview-swatch');
            if (customPreview) {
                customPreview.style.setProperty('--custom-current', hex);
            }

            // Update selection visual on preset swatches
            swatches.forEach(function (sw) {
                sw.classList.toggle('is-selected', sw.getAttribute('data-color') === hex);
            });

            updatePreview();
        }


        // Wire up swatches
        swatches.forEach(function (sw) {
            sw.addEventListener('click', function () {
                selectColor(sw.getAttribute('data-color'));
            });
        });

        // Custom picker
        customPicker.addEventListener('input', function () {
            selectColor(customPicker.value);
        });

        // Sliders
        brightness.addEventListener('input', updatePreview);
        contrast.addEventListener('input', updatePreview);

        // Initial paint
        updatePreview();
    });
})();
