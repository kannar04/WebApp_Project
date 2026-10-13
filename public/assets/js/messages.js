// Booking messages: show the latest message, Ctrl/Cmd + Enter to send, and no double submission.
(function bookingMessages() {
    'use strict';

    function scrollToLatest(list) {
        list.scrollTop = list.scrollHeight;
    }

    function submitOnShortcut(event) {
        if (event.key !== 'Enter' || !(event.ctrlKey || event.metaKey)) { return; }
        event.preventDefault();
        var form = event.target.form;
        if (!form || !form.reportValidity()) { return; }
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    }

    function lockSubmit(event) {
        var button = event.target.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Đang gửi…';
        }
    }

    function unlockSubmit() {
        var button = document.querySelector('[data-message-form] button[type="submit"]');
        if (button) {
            button.disabled = false;
            button.textContent = 'Gửi tin nhắn';
        }
    }

    function initializeMessages() {
        var list = document.querySelector('[data-message-list]');
        if (list) { scrollToLatest(list); }
        var form = document.querySelector('[data-message-form]');
        if (!form) { return; }
        form.elements.body.addEventListener('keydown', submitOnShortcut);
        form.addEventListener('submit', lockSubmit);
        // A page restored from the back/forward cache must not keep a disabled button.
        window.addEventListener('pageshow', unlockSubmit);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMessages, {once: true});
    } else {
        initializeMessages();
    }
}());
