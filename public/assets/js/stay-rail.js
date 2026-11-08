/* Native swipe/trackpad scrolling with optional desktop controls. */
(function () {
    'use strict';
    function setupRail(section) {
        const track=section.querySelector('.stay-rail');
        const previous=section.querySelector('[data-rail-prev]');
        const next=section.querySelector('[data-rail-next]');
        function update() {
            const end=track.scrollWidth-track.clientWidth;
            previous.hidden=next.hidden=end<2;
            previous.disabled=track.scrollLeft<2;
            next.disabled=track.scrollLeft>=end-2;
        }
        function scroll(direction) {
            const gap=parseFloat(getComputedStyle(track).columnGap)||0;
            track.scrollBy({left:direction*(track.clientWidth+gap),behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
        }
        function scrollBack() { scroll(-1); }
        function scrollForward() { scroll(1); }
        function scrollByKey(event) {
            if(event.target!==track || !['ArrowLeft','ArrowRight'].includes(event.key))return;
            event.preventDefault();scroll(event.key==='ArrowRight'?1:-1);
        }
        previous.addEventListener('click',scrollBack);
        next.addEventListener('click',scrollForward);
        track.addEventListener('scroll',update,{passive:true});
        track.addEventListener('keydown',scrollByKey);
        if('ResizeObserver' in window)new ResizeObserver(update).observe(track);
        else window.addEventListener('resize',update);
        update();
    }
    function initialize() {
        document.querySelectorAll('[data-stay-rail]').forEach(setupRail);
    }
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize,{once:true});
    else initialize();
})();
