(function () {
    'use strict';

    const SUBMIT_RELEASE_MS = 20000;
    const SELF_GUARDED_FORMS = '[data-report-form], [data-booking-form], [data-message-form]';
    const ENTER_SUBMITS_FROM = 'input:not([type="submit"], [type="image"], [type="button"], [type="reset"], [type="file"]), select';

    // Nice-to-have i18n: Vietnamese source text is the key; the layout emits English strings as JSON (empty in Vietnamese).
    let scriptDictionary = null;
    function h2hT(text, params) {
        if (scriptDictionary === null) {
            const source = document.getElementById('h2h-i18n');
            try {
                scriptDictionary = source ? JSON.parse(source.textContent) : {};
            } catch (error) {
                scriptDictionary = {};
            }
        }
        let result = Object.prototype.hasOwnProperty.call(scriptDictionary, text) ? scriptDictionary[text] : text;
        if (params) {
            for (const name of Object.keys(params)) {
                result = result.split('{' + name + '}').join(String(params[name]));
            }
        }
        return result;
    }
    window.h2hT = h2hT;

    // Same page apart from the #fragment: the browser only jumps to the results, so nothing loads and pageshow never fires.
    function isFragmentOnlySubmit(form) {
        const target = new URL(form.action, window.location.href);
        target.search = new URLSearchParams(new FormData(form)).toString();
        return target.hash !== '' && target.href.split('#')[0] === window.location.href.split('#')[0];
    }

    function markFormBusy(form, label) {
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.dataset.idleLabel = button.textContent;
            button.disabled = true;
            button.textContent = label;
        }
    }

    function handleSearchSubmit(event) {
        const form = event.currentTarget;
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        if (isFragmentOnlySubmit(form)) { return; }
        markFormBusy(form, h2hT('Đang tìm…'));
        scheduleFormRelease(form);
    }

    function watchSearchSubmit(form) {
        form.addEventListener('submit', handleSearchSubmit);
    }

    function releaseFormBusy(form) {
        const button = form.querySelector('button[data-idle-label]');
        if (button) {
            button.disabled = false;
            button.textContent = button.dataset.idleLabel;
        }
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
    }

    // Enter in a booking field only works the price out again, or an instant-book listing confirms a stay from the promo box. Booking takes the button itself.
    function requoteOnEnter(event) {
        if (event.key !== 'Enter' || event.isComposing || !event.target.matches('input')) { return; }
        event.preventDefault();
        event.currentTarget.elements.namedItem('check_out').dispatchEvent(new Event('input', {bubbles: true}));
    }

    function bookingQuoteKey(form) {
        const fields = form.elements;
        return [fields.namedItem('check_in').value, fields.namedItem('check_out').value, fields.namedItem('guests').value, fields.namedItem('promo_code').value].join('|');
    }

    // The browser fills the form back in on Back without an input event, later than the on-load quote looks.
    // A page out of the back/forward cache keeps its old total, so that gets a fresh one too. Either way one quote: dates already quoted on load are left alone.
    function quoteRestoredDates(event) {
        const form = document.querySelector('[data-booking-form]');
        if (!form || !form.elements.namedItem('check_in').value || !form.elements.namedItem('check_out').value) { return; }
        if (event.persisted || form.dataset.quotedFor !== bookingQuoteKey(form)) {
            form.elements.namedItem('check_out').dispatchEvent(new Event('input', {bubbles: true}));
        }
    }

    // Stopping the page load fires no event, so give the form back in case the request never left.
    function scheduleFormRelease(form) {
        window.clearTimeout(Number(form.dataset.releaseTimer));
        form.dataset.releaseTimer = String(window.setTimeout(releaseFormBusy, SUBMIT_RELEASE_MS, form));
    }

    function handleReportSubmit(event) {
        const form = event.currentTarget;
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        markFormBusy(form, h2hT('Đang gửi…'));
        scheduleFormRelease(form);
    }

    function handleBookingSubmit(event) {
        const form = event.currentTarget;
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        markFormBusy(form, h2hT('Đang gửi yêu cầu…'));
        scheduleFormRelease(form);
    }

    // Any other POST form: a double click would send it twice while the first request is still loading.
    // Runs at the document, after the form's own handlers; a dismissed confirm() or an invalid form never gets here.
    // The buttons stay as they are: a button disabled during submit would drop its name and value from the request.
    function guardPostSubmit(event) {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) { return; }
        if ((form.getAttribute('method') || '').toLowerCase() !== 'post' || form.hasAttribute('target') || form.matches(SELF_GUARDED_FORMS)) { return; }
        if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        scheduleFormRelease(form);
    }

    // Enter sends the form's first button, which may be the opposite of what was being typed: a refusal reason would approve the listing.
    // So a form with several different actions waits for the button the user chooses.
    function holdEnterWhenActionsDiffer(event) {
        if (event.key !== 'Enter' || event.isComposing || !event.target.matches(ENTER_SUBMITS_FROM) || !event.target.form) { return; }
        const actions = new Set();
        for (const control of event.target.form.elements) {
            if (control.type === 'submit' || control.type === 'image') { actions.add(control.name + '=' + control.value); }
        }
        if (actions.size > 1) { event.preventDefault(); }
    }

    // Result messages print at the top of the page, but a #fragment in the URL is where the user was working: the message moves right above it.
    // In a conversation that means above its form: a long thread would push a form placed further down off screen, with the kept draft in it.
    function moveFlashToFragment() {
        const target = window.location.hash === '' ? null : document.getElementById(window.location.hash.slice(1));
        if (!target || target.contains(document.querySelector('[data-flash]'))) { return; }
        const anchor = target.querySelector('[data-message-form]') || target;
        for (const flash of document.querySelectorAll('[data-flash]')) {
            const holder = flash.parentElement;
            if (anchor.closest('.container')) {
                flash.classList.add('mt-3');
                anchor.before(flash);
                holder.remove();
            } else {
                anchor.before(holder);
            }
        }
    }

    // Viewport position that belongs right under the header: the message, or in a conversation as much of the thread as fits with the message and form on screen.
    function flashLandingTop(flash, headerGap) {
        const flashTop = flash.getBoundingClientRect().top;
        const form = flash.nextElementSibling;
        if (!form || !form.matches('[data-message-form]')) { return flashTop; }
        const fittedTop = form.getBoundingClientRect().bottom + 12 + headerGap - window.innerHeight;
        return Math.min(flashTop, Math.max(form.parentElement.getBoundingClientRect().top, fittedTop));
    }

    // The scroll follows html{scroll-behavior}, which the stylesheet already turns off for prefers-reduced-motion.
    function revealFlash() {
        const flash = document.querySelector('[data-flash]');
        if (!flash || window.location.hash === '') { return; }
        const header = document.querySelector('.site-header');
        const headerGap = (header ? header.getBoundingClientRect().height : 0) + 12;
        window.scrollTo(0, Math.max(0, flashLandingTop(flash, headerGap) + window.scrollY - headerGap));
        flash.setAttribute('tabindex', '-1');
        flash.focus({preventScroll: true});
    }

    function initialize() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const baseUrl = document.querySelector('meta[name="app-base-url"]')?.content.replace(/\/$/, '') || '';

        const filterForm = document.querySelector('[data-filter-form]');
        if (filterForm) {
            const minimum = filterForm.elements.min_price;
            const maximum = filterForm.elements.max_price;
            const feedback = filterForm.querySelector('[data-price-error]');
            const rangeMessage = h2hT('Giá đến phải lớn hơn hoặc bằng giá từ.');
            function validatePriceRange() {
                const min = Number(minimum.value);
                const max = Number(maximum.value);
                // Zero has the existing server meaning: no price bound.
                const reversed = minimum.value !== '' && maximum.value !== '' && min > 0 && max > 0 && min > max;
                maximum.setCustomValidity(reversed ? rangeMessage : '');
                maximum.classList.toggle('is-invalid', reversed || !maximum.validity.valid);
                if (!maximum.validity.valid) {
                    maximum.setAttribute('aria-invalid', 'true');
                    maximum.setAttribute('aria-describedby', 'filter-help filter-error-max_price');
                    feedback.textContent = reversed ? rangeMessage : maximum.validationMessage;
                } else if (maximum.validity.valid) {
                    maximum.removeAttribute('aria-invalid');
                    maximum.setAttribute('aria-describedby', 'filter-help');
                }
            }
            minimum.addEventListener('input', validatePriceRange);
            maximum.addEventListener('input', validatePriceRange);
            validatePriceRange();
        }

        document.querySelectorAll('[data-filter-form], [data-search-form]').forEach(watchSearchSubmit);
        document.querySelector('[data-report-form]')?.addEventListener('submit', handleReportSubmit);
        document.addEventListener('submit', guardPostSubmit);
        document.addEventListener('keydown', holdEnterWhenActionsDiffer);

        moveFlashToFragment();
        // The browser's own fragment scroll keeps running until the page has loaded.
        if (document.readyState === 'complete') {
            revealFlash();
        } else {
            window.addEventListener('load', revealFlash, {once: true});
        }

        // Back/forward cache must not leave a restored search/booking button disabled.
        // The first pageshow of a page is its own load, which can land while a click on it is still being sent.
        window.addEventListener('pageshow', event => {
            if (event.persisted === false) { return; }
            document.querySelectorAll('button[data-idle-label]').forEach(button => {
                button.disabled = false;
                button.textContent = button.dataset.idleLabel;
            });
            document.querySelectorAll('form[data-submitting]').forEach(form => {
                delete form.dataset.submitting;
                form.removeAttribute('aria-busy');
            });
        });

        document.querySelectorAll('[data-favorite]').forEach(button => {
            button.addEventListener('click', async function () {
                this.disabled = true;
                try {
                    const response = await fetch(`${baseUrl}/api/wishlist/${this.dataset.favorite}`, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-Token': csrfToken,
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({_token: csrfToken})
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) {
                        const failure = new Error(result.message || h2hT('Không thể cập nhật yêu thích.'));
                        failure.fromServer = true;
                        throw failure;
                    }
                    this.classList.toggle('is-saved', result.data.saved);
                    this.setAttribute('aria-pressed', String(result.data.saved));
                } catch (error) {
                    // Network and JSON errors carry the browser's own (untranslated) text, so show the fallback instead.
                    alert(error.fromServer ? error.message : h2hT('Không thể cập nhật yêu thích. Vui lòng thử lại.'));
                } finally {
                    this.disabled = false;
                }
            });
        });

        const bookingForm = document.querySelector('[data-booking-form]');
        if (bookingForm) {
            const quotePanel = bookingForm.querySelector('[data-quote]');
            const fields = ['check_in', 'check_out', 'guests'].map(name => bookingForm.elements.namedItem(name));
            let timer;
            let requestController;
            let revision = 0;
            // Money keeps the Vietnamese format (dong sign) in both languages.
            const money = value => new Intl.NumberFormat('vi-VN').format(value) + '\u0111';

            function updateQuote() {
                bookingForm.dataset.quotedFor = bookingQuoteKey(bookingForm);
                clearTimeout(timer);
                requestController?.abort();
                const currentRevision = ++revision;
                quotePanel.classList.remove('text-danger');
                const parameters = new URLSearchParams({
                    check_in: fields[0].value,
                    check_out: fields[1].value,
                    guests: fields[2].value
                });
                for (const extra of bookingForm.querySelectorAll('[data-quote-extra]')) {
                    if (extra.value.trim() !== '') { parameters.set(extra.name, extra.value.trim()); }
                }
                if (!parameters.get('check_in') || !parameters.get('check_out')) {
                    quotePanel.textContent = h2hT('Chọn ngày để xem tổng tiền');
                    return;
                }
                quotePanel.textContent = h2hT('Đang kiểm tra lịch và tính giá…');
                timer = setTimeout(async () => {
                    requestController = new AbortController();
                    try {
                        const response = await fetch(`${bookingForm.dataset.quoteUrl}?${parameters}`, {
                            headers: {'Accept': 'application/json'},
                            signal: requestController.signal
                        });
                        const result = await response.json();
                        // A slow earlier response must never replace the latest dates.
                        if (currentRevision !== revision) { return; }
                        if (!response.ok || !result.success) {
                            const failure = new Error(result.message || h2hT('Không thể tính giá.'));
                            failure.fromServer = true;
                            throw failure;
                        }
                        const rows = [
                            h2hT('{nights} đêm × {price}', {nights: result.data.nights, price: money(result.data.nightly_price)}),
                            h2hT('Phụ phí: {amount}', {amount: money(result.data.fee)}),
                            h2hT('Tổng: {amount}', {amount: money(result.data.total)})
                        ];
                        quotePanel.replaceChildren(...rows.map((message, index) => {
                            const element = document.createElement(index === 2 ? 'strong' : 'div');
                            element.textContent = message;
                            return element;
                        }));
                        bookingForm.dispatchEvent(new CustomEvent('h2h:quote', {detail: result.data}));
                    } catch (error) {
                        if (error.name === 'AbortError' || currentRevision !== revision) { return; }
                        quotePanel.textContent = error.fromServer ? error.message : h2hT('Không thể tính giá. Vui lòng thử lại.');
                        quotePanel.classList.add('text-danger');
                        bookingForm.dispatchEvent(new CustomEvent('h2h:quote-failed', {detail: {fromServer: Boolean(error.fromServer)}}));
                    }
                }, 250);
            }

            quotePanel.setAttribute('aria-live', 'polite');
            fields.forEach(field => field.addEventListener('input', updateQuote));
            // Dates kept after a refused request need a total too, so the panel matches the form.
            if (fields[0].value && fields[1].value) { updateQuote(); }
            window.addEventListener('pageshow', quoteRestoredDates);
            bookingForm.addEventListener('keydown', requoteOnEnter);
            bookingForm.addEventListener('submit', handleBookingSubmit);
        }

        document.querySelectorAll('[data-confirm]').forEach(button => {
            button.addEventListener('click', event => {
                if (!confirm(button.dataset.confirm)) { event.preventDefault(); }
            });
        });

        document.querySelector('[data-share]')?.addEventListener('click', async () => {
            try {
                if (navigator.share) {
                    await navigator.share({title: document.title, url: location.href});
                } else {
                    await navigator.clipboard.writeText(location.href);
                    alert(h2hT('Đã sao chép liên kết.'));
                }
            } catch (error) {
                if (error.name !== 'AbortError') { alert(h2hT('Không thể chia sẻ. Bạn có thể sao chép liên kết trên thanh địa chỉ.')); }
            }
        });

        // Nice-to-have booking extras: flexible pricing, promo codes, Instant Book, payment status

        // Nice-to-have trust: identity verification, trusted Host badge, check-in guide
        // Check-in secrets stay readable without JavaScript; with it they start masked behind a Hiện/Ẩn toggle.
        function setTrustSecretVisible(button, visible) {
            const secret = document.getElementById(button.getAttribute('aria-controls'));
            const label = button.querySelector('[data-trust-secret-label]');
            if (!secret || !label) { return; }
            if (secret.dataset.trustSecret === undefined) { secret.dataset.trustSecret = secret.textContent; }
            secret.textContent = visible ? secret.dataset.trustSecret : '••••••';
            button.setAttribute('aria-pressed', String(visible));
            label.textContent = visible ? h2hT('Ẩn') : h2hT('Hiện');
        }
        function toggleTrustSecret(event) {
            const button = event.currentTarget;
            setTrustSecretVisible(button, button.getAttribute('aria-pressed') !== 'true');
        }
        function bindTrustSecret(button) {
            button.hidden = false;
            setTrustSecretVisible(button, false);
            button.addEventListener('click', toggleTrustSecret);
        }
        document.querySelectorAll('[data-trust-secret-toggle]').forEach(bindTrustSecret);

        // Nice-to-have conversation: booking messages, check-in reminders

        // Nice-to-have discovery: map search, recommendations

        // Nice-to-have content: articles and travel guides

        // Nice-to-have dark mode

    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
})();

