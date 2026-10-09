(function () {
    'use strict';

    function initialize() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const baseUrl = document.querySelector('meta[name="app-base-url"]')?.content.replace(/\/$/, '') || '';

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

