(function () {
    'use strict';
    const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
    ready(()=> {
        const form=document.querySelector('[data-focused-booking]'); if(!form)return;
        const t=window.h2hT, price=document.querySelector('[data-mobile-price]'), label=document.querySelector('[data-mobile-price-label]'), action=document.querySelector('[data-mobile-action]'), link=document.querySelector('[data-mobile-book]');
        const initialPrice=price?.textContent; let valid=false;
        function remember() {
            const next=new URL(location.href);
            for(const name of ['check_in','check_out','guests','promo_code']) {
                const value=form.elements.namedItem(name)?.value || '';
                if(value)next.searchParams.set(name,value);else next.searchParams.delete(name);
            }
            history.replaceState(history.state,'',next);
        }
        function pending() {
            valid=false;remember();
            if(price)price.textContent=initialPrice;
            if(label)label.textContent=t('/ đêm');
            if(action)action.textContent=t('Chọn ngày ở');
        }
        form.addEventListener('input',pending);
        form.addEventListener('h2h:quote',event=> {
            valid=true;
            if(price)price.textContent=new Intl.NumberFormat('vi-VN').format(event.detail.total)+'đ';
            if(label)label.textContent=t('tổng cho {count} đêm',{count:event.detail.nights});
            if(action)action.textContent=t('Xem lại đặt chỗ');
        });
        form.addEventListener('h2h:quote-failed',pending);
        link?.addEventListener('click',event=>{if(valid){event.preventDefault();form.requestSubmit();}});
        if(form.elements.check_in.value&&form.elements.check_out.value)form.elements.check_out.dispatchEvent(new Event('input',{bubbles:true}));
    });
})();
