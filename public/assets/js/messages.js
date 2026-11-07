// Booking messages: show the latest message, Ctrl/Cmd + Enter to send, and no double submission.
(function bookingMessages() {
    'use strict';

    var RELEASE_AFTER_MS = 20000;
    var releaseTimer = null;

    function scrollToLatest(list) {
        list.scrollTop = list.scrollHeight;
    }

    function submitOnShortcut(event) {
        if (event.key !== 'Enter' || !(event.ctrlKey || event.metaKey)) { return; }
        event.preventDefault();
        var form = event.target.form;
        if (!form || form.dataset.sending === 'true' || !form.reportValidity()) { return; }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            markSending(form);
            form.submit();
        }
    }

    function markSending(form) {
        form.dataset.sending = 'true';
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = window.h2hT('Đang gửi…');
        }
        // Stopping the page load fires no event, so give the form back in case the message never left. The text stays in the box.
        window.clearTimeout(releaseTimer);
        releaseTimer = window.setTimeout(unlockSubmit, RELEASE_AFTER_MS);
    }

    function lockSubmit(event) {
        if (event.target.dataset.sending === 'true') {
            event.preventDefault();
            return;
        }
        markSending(event.target);
    }

    function unlockSubmit() {
        window.clearTimeout(releaseTimer);
        var form = document.querySelector('[data-message-form]');
        delete form.dataset.sending;
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = false;
            button.textContent = window.h2hT('Gửi tin nhắn');
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
