/* Progressive enhancement: PHP search and pagination remain usable without scripting. */
(function () {
    'use strict';
    const ready = fn => document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn, {once:true}) : fn();
    ready(() => {
        const t = window.h2hT;
        const locationInput = document.querySelector('#location');
        const cityInput = document.querySelector('#destination-city');
        const options = document.querySelector('#destination-options');
        const form = document.querySelector('[data-search-form]');
        document.querySelectorAll('[data-search-date]').forEach(field => {
            const input = field.querySelector('input');
            const caption = field.querySelector('[data-date-caption]');
            const label = input.title;
            function syncDate() {
                const date = input.value ? new Date(input.value + 'T12:00:00') : null;
                caption.textContent = date && !Number.isNaN(date.getTime())
                    ? new Intl.DateTimeFormat(document.documentElement.lang, {day:'numeric', month:'short'}).format(date)
                    : caption.dataset.placeholder;
                input.title = label + (input.value ? ': ' + input.value : '');
                field.classList.toggle('has-date', Boolean(input.value));
            }
            field.classList.add('date-enhanced');
            input.addEventListener('input', syncDate);
            input.addEventListener('change', syncDate);
            input.addEventListener('click', () => { try { input.showPicker?.(); } catch (_) { /* Native keyboard entry remains available. */ } });
            window.addEventListener('pageshow', syncDate);
            syncDate();
        });
        const normalize = value => value.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d');
        const read = key => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } };
        const write = (key,value) => { try { sessionStorage.setItem(key,JSON.stringify(value)); } catch (_) { /* Storage is optional. */ } };
        let destinations=[];
        try { destinations=JSON.parse(document.querySelector('#destination-data')?.textContent || '[]'); } catch (_) { /* Plain text search still works. */ }
        let active=-1, choices=[];
        function closeOptions() {
            if (!options) return;
            options.hidden=true; locationInput.setAttribute('aria-expanded','false'); locationInput.removeAttribute('aria-activedescendant'); active=-1;
        }
        function choose(item) {
            locationInput.value=item.city; cityInput.value=item.city; closeOptions(); locationInput.focus();
        }
        function renderOptions() {
            const term=normalize(locationInput.value.trim());
            const recent=read('h2h-recent-destinations') || [];
            choices=destinations.filter(item=>!term || normalize(item.city).includes(term));
            if (!term) choices.sort((a,b)=>Number(recent.includes(b.city))-Number(recent.includes(a.city)));
            choices=choices.slice(0,6); options.replaceChildren(); active=-1;
            choices.forEach((item,index)=> {
                const row=document.createElement('div'); row.id='destination-choice-'+index; row.setAttribute('role','option'); row.setAttribute('aria-selected','false');
                const name=document.createElement('strong'); name.textContent=item.city;
                const meta=document.createElement('small'); meta.textContent=(recent.includes(item.city)?t('Tìm gần đây'):t('Điểm đến'))+' · '+t('{count} chỗ ở',{count:item.listing_count});
                row.append(name,meta); row.addEventListener('mousedown',e=>e.preventDefault()); row.addEventListener('click',()=>choose(item)); options.append(row);
            });
            if (!choices.length) { const note=document.createElement('p'); note.textContent=t('Nhấn Tìm chỗ ở để tìm theo địa điểm đã nhập.'); options.append(note); }
            options.hidden=false; locationInput.setAttribute('aria-expanded','true'); locationInput.removeAttribute('aria-activedescendant');
        }
        if (locationInput && options) {
            locationInput.addEventListener('focus',renderOptions);
            locationInput.addEventListener('input',()=>{cityInput.value='';renderOptions();});
            locationInput.addEventListener('keydown',e=> {
                if (e.key==='Escape') { closeOptions();return; }
                if (e.key==='Tab') { closeOptions();return; }
                if (e.key==='Enter' && !options.hidden && active>=0) { e.preventDefault();choose(choices[active]);return; }
                if (!['ArrowDown','ArrowUp'].includes(e.key)) return;
                e.preventDefault(); if (options.hidden) renderOptions(); if (!choices.length) return;
                active=(active+(e.key==='ArrowDown'?1:-1)+choices.length)%choices.length;
                [...options.querySelectorAll('[role=option]')].forEach((row,index)=>row.setAttribute('aria-selected',String(index===active)));
                locationInput.setAttribute('aria-activedescendant','destination-choice-'+active);
            });
            document.addEventListener('pointerdown',e=>{if(!e.target.closest('.destination-field'))closeOptions();});
            form.addEventListener('submit',()=> {
                closeOptions();
                const match=destinations.find(item=>normalize(item.city)===normalize(locationInput.value.trim()));
                if(match){cityInput.value=match.city;locationInput.value=match.city;write('h2h-recent-destinations',[match.city,...(read('h2h-recent-destinations')||[]).filter(x=>x!==match.city)].slice(0,5));}
            });
        }

        const feed=document.querySelector('[data-home-feed]');
        if (!feed) return;
        const sentinel=document.querySelector('[data-feed-sentinel]');
        const button=document.querySelector('[data-feed-more]');
        const status=document.querySelector('[data-feed-status]');
        const query=new URLSearchParams(location.search);
        const key='h2h-feed:'+feed.dataset.user+':'+document.documentElement.lang+':'+location.pathname+'?'+query.toString();
        const initial=Number(feed.dataset.page); let page=initial, pages=Number(feed.dataset.pages), busy=false, failed=false, restoring=false;
        const ids=new Set([...feed.querySelectorAll('[data-listing-id]')].map(el=>el.dataset.listingId));
        const guestKey='h2h-feed:0:'+document.documentElement.lang+':'+location.pathname+'?'+query.toString();
        const saved=read(key) || (location.hash.startsWith('#home-')?read(guestKey):null);
        document.querySelector('[data-feed-pagination]')?.setAttribute('hidden','');
        function updateEnd() {
            button.hidden=page>=pages; button.disabled=busy;
            if(page>=pages)status.textContent=t('Bạn đã xem hết chỗ ở trong tìm kiếm này.');
        }
        async function load(manual=false) {
            if(busy || page>=pages || (failed&&!manual))return false;
            busy=true;failed=false;button.disabled=true;feed.setAttribute('aria-busy','true');status.textContent=t('Đang tải thêm chỗ ở…');
            try {
                const params=new URLSearchParams(query);params.set('page',String(page+1));
                const response=await fetch(feed.dataset.endpoint+'?'+params,{headers:{Accept:'application/json'}});
                const result=await response.json();
                if(!response.ok||!result.success||typeof result.data.html!=='string')throw new Error();
                const template=document.createElement('template');template.innerHTML=result.data.html;
                let count=0;
                for(const card of template.content.querySelectorAll('[data-listing-id]')) {
                    if(!ids.has(card.dataset.listingId)){ids.add(card.dataset.listingId);feed.append(card);count++;}
                }
                page=Number(result.data.page);pages=Number(result.data.pages);
                feed.dataset.page=String(page);status.textContent=t('Đã tải thêm {count} chỗ ở.',{count});
                return true;
            } catch (_) { failed=true;status.textContent=t('Không tải được thêm chỗ ở. Các kết quả hiện tại vẫn được giữ.');button.textContent=t('Thử lại');return false; }
            finally {busy=false;feed.removeAttribute('aria-busy');updateEnd();}
        }
        function remember() {
            const anchor=[...feed.children].find(el=>el.getBoundingClientRect().bottom>100);
            write(key,{page,scroll:scrollY,anchor:anchor?.id,offset:anchor?.getBoundingClientRect().top,at:Date.now()});
        }
        button.addEventListener('click',()=>load(true));
        window.addEventListener('pagehide',remember);
        let opening=null;
        function resetOpening() {
            if(!opening)return;
            opening.cancel();
            opening=null;
        }
        window.addEventListener('pageshow',resetOpening);
        window.addEventListener('pagehide',resetOpening);
        document.addEventListener('click',e=>{
            const saveLink=e.target.closest('a.favorite-button');
            if(saveLink){const target=new URL(saveLink.href);target.searchParams.set('next',location.pathname+location.search+'#'+saveLink.closest('[data-listing-id]').id);saveLink.href=target.href;}
            if(e.target.closest('[data-open-home],.favorite-button'))remember();
            const link=e.target.closest('a[data-open-home]');
            if(!link || e.defaultPrevented || e.button!==0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || link.hasAttribute('download') || (link.target && link.target!=='_self'))return;
            const card=link.closest('.listing-card-glow');
            const glow=card?.querySelector('.listing-image-glow img');
            if(!glow?.animate || matchMedia('(prefers-reduced-motion: reduce)').matches)return;
            if(opening)return;
            const style=getComputedStyle(glow);
            const brightness=style.getPropertyValue('--glow-brightness').trim() || '1';
            const saturation=style.getPropertyValue('--glow-saturation').trim() || '1';
            const opacity=Math.max(Number(style.opacity),Number(style.getPropertyValue('--glow-opacity')) || .24);
            // Visual feedback may play while the browser loads; never intercept the link.
            opening=glow.animate([
                {filter:style.filter,transform:style.transform,opacity},
                {filter:`blur(44px) brightness(${brightness}) saturate(${saturation})`,transform:'translateY(12px) scale(1.24)',opacity:0}
            ],{duration:360,easing:'cubic-bezier(.16,1,.3,1)',fill:'forwards'});
        });
        async function restore() {
            const back=performance.getEntriesByType('navigation')[0]?.type==='back_forward';
            const returnFromAuth=location.hash.startsWith('#home-');
            if(saved && (back||returnFromAuth) && Date.now()-saved.at<1800000) {
                restoring=true;
                const target=Math.min(saved.page,pages,initial+20);
                while(page<target){if(!await load(true))break;}
                requestAnimationFrame(()=> {
                    const anchor=document.getElementById(saved.anchor);
                    if(anchor)window.scrollBy(0,anchor.getBoundingClientRect().top-(saved.offset||0));else window.scrollTo(0,saved.scroll||0);
                    restoring=false;
                });
            }
        }
        updateEnd();
        restore().then(()=> {
            if('IntersectionObserver' in window) {
                const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting)&&!restoring)load();},{rootMargin:'250px'});
                observer.observe(sentinel);
            }
        });
        window.addEventListener('pageshow',event=>{if(event.persisted){busy=false;updateEnd();}});
    });
})();
