/* Keep the original form and its native inputs, listeners, and focus intact. */
(function () {
    'use strict';
    function setup() {
        const form = document.querySelector('.focused-home [data-search-form]');
        if (!form) return;
        const header = document.querySelector('.site-header');
        const slot = document.createElement('div');
        slot.className = 'floating-search-slot';
        form.before(slot);
        slot.append(form);
        let floating = false;
        let scheduled = false;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        function update() {
            scheduled = false;
            const bounds = slot.getBoundingClientRect();
            const headerBottom = Math.max(0, header ? header.getBoundingClientRect().bottom : 0);
            const shouldFloat = bounds.bottom <= headerBottom + 8;
            form.style.setProperty('--floating-search-top', (headerBottom + 12) + 'px');
            form.style.setProperty('--floating-search-left', bounds.left + 'px');
            form.style.setProperty('--floating-search-width', bounds.width + 'px');
            if (floating === shouldFloat) {
                return;
            }
            if (shouldFloat) slot.style.height = form.getBoundingClientRect().height + 'px';
            floating = shouldFloat;
            form.classList.toggle('is-floating-search', floating);
            if (!floating) slot.style.removeProperty('height');
            if (floating && !reducedMotion.matches && typeof form.animate === 'function') {
                form.animate([
                    { opacity: 0, transform: 'translateY(-10px)' },
                    { opacity: 1, transform: 'translateY(0)' }
                ], { duration: 480, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
            }
        }
        function schedule() {
            if (!scheduled) { scheduled = true; requestAnimationFrame(update); }
        }
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule, { passive: true });
        window.addEventListener('pageshow', schedule);
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(schedule);
            observer.observe(form);
            if (header) observer.observe(header);
        }
        update();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, { once: true });
    else setup();
})();
