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
        const destinations = document.querySelector('.focused-home .destination-links');
        if (destinations) {
            const floatingDestinations = destinations.cloneNode(true);
            floatingDestinations.className = 'floating-destinations';
            floatingDestinations.setAttribute('role', 'navigation');
            floatingDestinations.setAttribute('aria-label', document.documentElement.lang === 'en' ? 'Destinations' : 'Điểm đến');
            form.append(floatingDestinations);
        }
        let floating = false;
        let scheduled = false;
        let motion = null;
        let directionAnchor = window.scrollY;
        let scrollingUp = false;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        function update() {
            scheduled = false;
            const bounds = slot.getBoundingClientRect();
            const headerBottom = Math.max(0, header ? header.getBoundingClientRect().bottom : 0);
            // Hand back to the main form while its original position is still
            // above the viewport, rather than morphing the floating form in view.
            const shouldFloat = bounds.bottom <= headerBottom - 160;
            const scrollDelta = window.scrollY - directionAnchor;
            if (Math.abs(scrollDelta) >= 8) {
                scrollingUp = scrollDelta < 0;
                directionAnchor = window.scrollY;
            }
            form.classList.toggle('show-floating-destinations', shouldFloat && scrollingUp);
            form.style.setProperty('--floating-search-top', (headerBottom + 12) + 'px');
            form.style.setProperty('--floating-search-left', bounds.left + 'px');
            form.style.setProperty('--floating-search-width', bounds.width + 'px');
            if (floating === shouldFloat) {
                if (!floating && motion && bounds.top >= headerBottom) motion.finish();
                return;
            }
            const currentOpacity = getComputedStyle(form).opacity;
            if (motion) { motion.cancel(); motion = null; }
            if (shouldFloat && !form.classList.contains('is-floating-search')) {
                slot.style.height = form.getBoundingClientRect().height + 'px';
            }
            floating = shouldFloat;
            if (floating) form.classList.add('is-floating-search');
            function finish() {
                if (!floating) {
                    form.classList.remove('is-floating-search');
                    slot.style.removeProperty('height');
                }
                motion = null;
            }
            if (!floating && bounds.top < headerBottom && !reducedMotion.matches && typeof form.animate === 'function') {
                const frames = [
                    { opacity: currentOpacity, transform: 'translateY(0)' },
                    { opacity: 0, transform: 'translateY(-6px)' }
                ];
                motion = form.animate(frames, { duration: 800, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' });
                motion.onfinish = finish;
            } else {
                finish();
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
