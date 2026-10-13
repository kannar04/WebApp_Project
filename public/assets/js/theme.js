/* Nice-to-have dark mode: loaded synchronously in <head> so data-theme is set before the first paint. */
(function () {
    'use strict';

    var storageKey = 'h2h-theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function storedTheme() {
        try {
            var value = window.localStorage.getItem(storageKey);
            return value === 'dark' || value === 'light' ? value : null;
        } catch (error) {
            return null;
        }
    }

    function preferredTheme() {
        return storedTheme() || (media && media.matches ? 'dark' : 'light');
    }

    function syncButtons(theme) {
        var dark = theme === 'dark';
        var label = dark ? 'Tắt chế độ tối' : 'Bật chế độ tối';
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
            buttons[i].setAttribute('title', label);
            var text = buttons[i].querySelector('[data-theme-label]');
            if (text) { text.textContent = label; }
        }
    }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        syncButtons(theme);
    }

    function handleToggleClick(event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('[data-theme-toggle]') : null;
        if (!button) { return; }
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        try {
            window.localStorage.setItem(storageKey, next);
        } catch (error) {
            // Storage can be blocked; the choice still applies to the current page.
        }
        applyTheme(next);
    }

    function handleSystemChange() {
        if (!storedTheme()) { applyTheme(preferredTheme()); }
    }

    function handleStorage(event) {
        if (event.key === storageKey || event.key === null) { applyTheme(preferredTheme()); }
    }

    function handleReady() {
        syncButtons(root.getAttribute('data-theme'));
    }

    applyTheme(preferredTheme());
    document.addEventListener('click', handleToggleClick);
    window.addEventListener('storage', handleStorage);
    if (media) {
        if (media.addEventListener) { media.addEventListener('change', handleSystemChange); }
        else if (media.addListener) { media.addListener(handleSystemChange); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handleReady);
    } else {
        handleReady();
    }
}());
