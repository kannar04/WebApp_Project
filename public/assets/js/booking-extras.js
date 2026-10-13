/* Booking extras on the listing page: promo code input and the itemised price breakdown returned by the quote API. */
(function bookingExtrasPage() {
    'use strict';

    function formatMoney(value) {
        const amount = Number(value);
        const sign = amount < 0 ? '−' : '';
        // Money keeps the Vietnamese format (dong sign) in both languages.
        return sign + new Intl.NumberFormat('vi-VN').format(Math.abs(amount)) + '\u0111';
    }

    function buildRow(row) {
        const isTotal = row.kind === 'total';
        const element = document.createElement(isTotal ? 'strong' : 'div');
        const label = document.createElement('span');
        const amount = document.createElement('span');
        element.className = 'quote-row' + (row.kind === 'discount' ? ' is-discount' : '') + (isTotal ? ' is-total' : '');
        label.textContent = row.label;
        amount.textContent = formatMoney(row.amount);
        element.append(label, amount);
        return element;
    }

    function setup() {
        const form = document.querySelector('[data-booking-form]');
        if (!form) { return; }
        const panel = form.querySelector('[data-quote]');
        const promo = form.querySelector('[data-promo-input]');
        const feedback = form.querySelector('[data-promo-feedback]');
        const checkIn = form.elements.namedItem('check_in');
        const checkOut = form.elements.namedItem('check_out');

        function showFeedback(message, state) {
            if (!feedback) { return; }
            feedback.textContent = message;
            feedback.classList.toggle('is-valid', state === 'valid');
            feedback.classList.toggle('is-invalid', state === 'invalid');
            if (promo) {
                promo.classList.toggle('is-invalid', state === 'invalid');
                promo.setAttribute('aria-invalid', state === 'invalid' ? 'true' : 'false');
            }
        }

        function renderQuote(event) {
            const data = event.detail || {};
            const promoInfo = data.promo || null;
            if (promo && promo.value.trim() === '') {
                showFeedback('', '');
            } else if (promoInfo && promoInfo.message) {
                showFeedback(promoInfo.message, 'invalid');
            } else if (promoInfo && promoInfo.code) {
                showFeedback(window.h2hT('Đã áp dụng mã {code}: giảm {amount}.', {code: promoInfo.code, amount: formatMoney(promoInfo.discount)}), 'valid');
            }
            if (!panel || !Array.isArray(data.breakdown) || data.breakdown.length === 0) { return; }
            panel.replaceChildren(...data.breakdown.map(buildRow));
        }

        function quoteFailed() {
            if (promo && promo.value.trim() !== '') {
                showFeedback(window.h2hT('Chưa kiểm tra được mã: hãy chọn ngày và số khách hợp lệ.'), '');
            }
        }

        function promoChanged() {
            if (promo.value.trim() === '') {
                showFeedback('', '');
            } else if (!checkIn.value || !checkOut.value) {
                showFeedback(window.h2hT('Chọn ngày nhận và trả phòng để kiểm tra mã.'), '');
            } else {
                showFeedback(window.h2hT('Đang kiểm tra mã…'), '');
            }
            // Re-run the shared quote request (debounced in app.js) with the new code.
            checkOut.dispatchEvent(new Event('input', {bubbles: true}));
        }

        form.addEventListener('h2h:quote', renderQuote);
        form.addEventListener('h2h:quote-failed', quoteFailed);
        if (promo && checkIn && checkOut) { promo.addEventListener('input', promoChanged); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup, {once: true});
    } else {
        setup();
    }
})();
