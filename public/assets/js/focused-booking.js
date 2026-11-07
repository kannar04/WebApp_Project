(function () {
    'use strict';
    const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
    ready(()=> {
        const form=document.querySelector('[data-focused-booking]'); if(!form)return;
        const h2hT=window.h2hT, price=document.querySelector('[data-mobile-price]'), label=document.querySelector('[data-mobile-price-label]'), action=document.querySelector('[data-mobile-action]'), link=document.querySelector('[data-mobile-book]');
        const initialPrice=price?.textContent; let valid=false;
        function remember() {
            const next=new URL(location.href); let changed=false;
            for(const name of ['check_in','check_out','guests','promo_code']) {
                const value=form.elements.namedItem(name)?.value || '';
                const wanted=name==='guests'&&value==='1'?'':value;
                if((next.searchParams.get(name)||'')===wanted)continue;
                changed=true;
                if(wanted)next.searchParams.set(name,wanted);else next.searchParams.delete(name);
            }
            if(changed)history.replaceState(history.state,'',next);
        }
        function pending() {
            valid=false;remember();
            if(price)price.textContent=initialPrice;
            if(label)label.textContent=h2hT('/ đêm');
            if(action)action.textContent=h2hT('Chọn ngày ở');
        }
        form.addEventListener('input',pending);
        form.addEventListener('h2h:quote',event=> {
            valid=true;
            if(price)price.textContent=new Intl.NumberFormat('vi-VN').format(event.detail.total)+'\u0111';
            if(label)label.textContent=h2hT('tổng cho {count} đêm',{count:event.detail.nights});
            if(action)action.textContent=h2hT('Xem lại đặt chỗ');
        });
        form.addEventListener('h2h:quote-failed',pending);
        link?.addEventListener('click',event=>{if(valid){event.preventDefault();form.requestSubmit();}});
        if(form.elements.check_in.value&&form.elements.check_out.value)form.elements.check_out.dispatchEvent(new Event('input',{bubbles:true}));
    });
})();
