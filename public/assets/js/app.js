(function () {
    'use strict';

    function initialize() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const baseUrl = document.querySelector('meta[name="app-base-url"]')?.content.replace(/\/$/, '') || '';

        const filterForm = document.querySelector('[data-filter-form]');
        if (filterForm) {
            const minimum = filterForm.elements.min_price;
            const maximum = filterForm.elements.max_price;
            const feedback = filterForm.querySelector('[data-price-error]');
            const rangeMessage = 'Giá đến phải lớn hơn hoặc bằng giá từ.';
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

        document.querySelectorAll('[data-filter-form], [data-search-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
                form.dataset.submitting = 'true';
                form.setAttribute('aria-busy', 'true');
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.dataset.idleLabel = button.textContent;
                    button.disabled = true;
                    button.textContent = 'Đang tìm…';
                }
            });
        });

        // Back/forward cache must not leave a restored search/booking button disabled.
        window.addEventListener('pageshow', () => {
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
                        throw new Error(result.message || 'Không thể cập nhật yêu thích.');
                    }
                    this.classList.toggle('is-saved', result.data.saved);
                    this.setAttribute('aria-pressed', String(result.data.saved));
                } catch (error) {
                    alert(error.message || 'Không thể cập nhật yêu thích. Vui lòng thử lại.');
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
            const money = value => new Intl.NumberFormat('vi-VN').format(value) + 'đ';

            function updateQuote() {
                clearTimeout(timer);
                requestController?.abort();
                const currentRevision = ++revision;
                quotePanel.classList.remove('text-danger');
                const parameters = new URLSearchParams({
                    check_in: fields[0].value,
                    check_out: fields[1].value,
                    guests: fields[2].value
                });
                if (!parameters.get('check_in') || !parameters.get('check_out')) {
                    quotePanel.textContent = 'Chọn ngày để xem tổng tiền';
                    return;
                }
                quotePanel.textContent = 'Đang kiểm tra lịch và tính giá…';
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
                            throw new Error(result.message || 'Không thể tính giá.');
                        }
                        const rows = [
                            `${result.data.nights} đêm × ${money(result.data.nightly_price)}`,
                            `Phụ phí: ${money(result.data.fee)}`,
                            `Tổng: ${money(result.data.total)}`
                        ];
                        quotePanel.replaceChildren(...rows.map((message, index) => {
                            const element = document.createElement(index === 2 ? 'strong' : 'div');
                            element.textContent = message;
                            return element;
                        }));
                    } catch (error) {
                        if (error.name === 'AbortError' || currentRevision !== revision) { return; }
                        quotePanel.textContent = error.message || 'Không thể tính giá. Vui lòng thử lại.';
                        quotePanel.classList.add('text-danger');
                    }
                }, 250);
            }

            quotePanel.setAttribute('aria-live', 'polite');
            fields.forEach(field => field.addEventListener('input', updateQuote));
            bookingForm.addEventListener('submit', () => {
                const submitButton = bookingForm.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.dataset.idleLabel = submitButton.textContent;
                    submitButton.disabled = true;
                    submitButton.textContent = 'Đang gửi yêu cầu…';
                }
            });
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
                    alert('Đã sao chép liên kết.');
                }
            } catch (error) {
                if (error.name !== 'AbortError') { alert('Không thể chia sẻ. Bạn có thể sao chép liên kết trên thanh địa chỉ.'); }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
})();

