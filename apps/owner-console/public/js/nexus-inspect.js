/* ==========================================================================
   TEMM Nexus 0.4.0 Phase I — Inspect Mode client
   --------------------------------------------------------------------------
   When Inspect Mode is on, the user can click a registered UI component and
   attach it to the Nexus AI conversation.

   WHAT THIS FILE DOES NOT DO
   It never sends a DESCRIPTION of a component. It sends the component KEY
   from a `data-nx-inspect` attribute that the server rendered. The server
   resolves the key against ComponentRegistry and refuses anything unknown or
   unauthorised, so a manipulated DOM cannot inject text into the assistant's
   context, and no DOM fragment ever leaves the browser.

   It also never mutates anything. Selecting a component only records a
   choice; nothing is applied without an explicit preview + apply step.

   Filament pages navigate with wire:navigate (the DOM is replaced without a
   full page load), so every control here is handled by DELEGATED document
   listeners that survive DOM replacement, and the selection restore runs
   again on every livewire:navigated event.
   ========================================================================== */
(function () {
    'use strict';

    var STORAGE_KEY = 'nx.inspect.selection';
    var bodyClass = 'nx-inspecting';

    function isOn() {
        return document.documentElement.classList.contains(bodyClass);
    }

    function targets() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-nx-inspect]'));
    }

    /** Announce state changes to assistive technology (§I.12). */
    function announce(message) {
        var live = document.querySelector('[data-nx-inspect-live]');
        if (live) {
            live.textContent = message;
        }
    }

    /** Clear any previous highlight. */
    function clearHighlights() {
        targets().forEach(function (el) {
            el.classList.remove('nx-inspect-target', 'nx-inspect-selected');
        });
    }

    /** Mark every inspectable component on this page. */
    function markTargets() {
        targets().forEach(function (el) {
            el.classList.add('nx-inspect-target');
            // Programmatic focus (keyboard selection) without joining the
            // tab order — normal navigation is untouched when Inspect is off.
            if (!el.hasAttribute('tabindex')) {
                el.setAttribute('tabindex', '-1');
            }
        });
    }

    /** Publish the selection so the AI page and the toggle can react. */
    function remember(selection) {
        try {
            if (selection === null) {
                sessionStorage.removeItem(STORAGE_KEY);
            } else {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify(selection));
            }
        } catch (e) {
            /* private mode: the selection simply does not persist across pages */
        }

        document.dispatchEvent(new CustomEvent('nx:inspect-selected', { detail: selection }));
    }

    function readRemembered() {
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function select(el) {
        clearHighlights();
        markTargets();
        el.classList.add('nx-inspect-selected');
        el.setAttribute('data-nx-inspect-state', 'selected');

        // The KEY is the only payload. The label read here is used for the
        // immediate local announcement only — the server re-derives every
        // label it displays or sends to a model from its own registry.
        var key = el.getAttribute('data-nx-inspect') || '';
        var label = (el.getAttribute('data-nx-inspect-label') || '').trim();

        // 0.4.0-rc.5 (C.1): the announcement text is provided by the server
        // shell in the user's locale; the JS never hard-codes English.
        var toggle = document.querySelector('[data-nx-inspect-toggle]');
        var template = (toggle && toggle.getAttribute('data-nx-inspect-selected-format'))
            || 'Selected :label. Open Nexus AI to continue.';
        announce(template.replace(':label', label || (toggle && toggle.getAttribute('data-nx-inspect-fallback')) || 'component'));

        remember({ key: key });
    }

    function setOn(on) {
        document.documentElement.classList.toggle(bodyClass, on);

        var button = document.querySelector('[data-nx-inspect-toggle]');
        if (button) {
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        }

        if (on) {
            markTargets();
            announce('Inspect Mode on. Registered components are highlighted on hover. Press Escape to exit.');
        } else {
            clearHighlights();
            targets().forEach(function (el) {
                el.removeAttribute('data-nx-inspect-state');
            });
            announce('Inspect Mode off.');
        }
    }

    function cancel() {
        if (!isOn()) {
            return;
        }
        setOn(false);
        remember(null);
    }

    function onToggle() {
        setOn(!isOn());
        if (!isOn()) {
            remember(null);
        }
    }

    // ── Delegated listeners — they survive wire:navigate DOM replacement ──

    function onClick(event) {
        if (event.target.closest && event.target.closest('[data-nx-inspect-toggle]')) {
            event.preventDefault();
            onToggle();

            return;
        }

        if (event.target.closest && event.target.closest('[data-nx-inspect-exit]')) {
            event.preventDefault();
            cancel();

            return;
        }

        if (!isOn()) {
            return;
        }

        var el = event.target.closest ? event.target.closest('[data-nx-inspect]') : null;
        if (!el) {
            return;
        }

        // Selecting a component must not activate it: a user inspecting a
        // link or a button is describing it, not clicking it. Captured here
        // before the element's own handlers can run.
        event.preventDefault();
        event.stopPropagation();
        select(el);
    }

    function onKeydown(event) {
        if (event.key === 'Escape' && isOn()) {
            cancel();
            return;
        }

        // Never hijack typing: the selection guard is for links and buttons,
        // not for the composer or any other field.
        if (/INPUT|TEXTAREA|SELECT/.test(event.target.tagName)) {
            return;
        }

        // Keyboard parity with the click guard: Enter or Space on an
        // inspectable element selects it instead of activating it.
        if (isOn() && (event.key === 'Enter' || event.key === ' ')) {
            var el = event.target.closest ? event.target.closest('[data-nx-inspect]') : null;
            if (el) {
                event.preventDefault();
                event.stopPropagation();
                select(el);
            }
        }
    }

    // ── Handoff to the Nexus AI page ───────────────────────────────────
    //
    // The Nexus AI page renders `data-nx-attach-root` inside its Livewire
    // component. The validated KEY is handed to the server, which re-resolves
    // it against the registry and the acting user's permissions. If the
    // server refuses, no chip appears and nothing errors — refusal is silent
    // by design.

    function attachToNexusAi(key) {
        if (!window.Livewire || !key) {
            return;
        }

        var root = document.querySelector('[data-nx-attach-root]');
        if (!root) {
            return;
        }

        var host = root.closest('[wire\\:id]') || root;
        var id = host.getAttribute ? host.getAttribute('wire:id') : null;
        var component = id ? window.Livewire.find(id) : null;
        if (component) {
            component.call('attachComponent', key);
        }
    }

    /** Carry a selection to the Nexus AI page if we are on it right now. */
    function restoreIfOnAiPage() {
        if (!document.querySelector('[data-nx-attach-root]')) {
            return;
        }

        var remembered = readRemembered();
        if (!remembered || !remembered.key) {
            return;
        }

        if (window.Livewire) {
            attachToNexusAi(remembered.key);
        } else {
            document.addEventListener('livewire:init', function () {
                attachToNexusAi(remembered.key);
            }, { once: true });
        }
    }

    // A selection event with a detail carries a NEW choice — hand it over
    // immediately when we are on the AI page (e.g. selecting an AI-page
    // component while inspecting there).
    function onNewSelection(event) {
        if (event.detail && event.detail.key && document.querySelector('[data-nx-attach-root]')) {
            attachToNexusAi(event.detail.key);
        }
    }

    function init() {
        // wire:navigate may re-execute this file on every page. Every
        // listener here is delegated on `document`, so it must be registered
        // exactly once per session.
        if (window.__nxInspectLoaded) {
            restoreIfOnAiPage();

            return;
        }
        window.__nxInspectLoaded = true;

        document.addEventListener('click', onClick, true);
        document.addEventListener('keydown', onKeydown, true);
        document.addEventListener('nx:inspect-selected', onNewSelection);

        // wire:navigate lands on a new page without a load event.
        document.addEventListener('livewire:navigated', restoreIfOnAiPage);

        restoreIfOnAiPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.NexusInspect = {
        isOn: isOn,
        selection: readRemembered,
        clear: function () {
            remember(null);
            clearHighlights();
        },
    };
})();
