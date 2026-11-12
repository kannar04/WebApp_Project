(() => {
    'use strict';
    // Native links remain usable with keyboard, modified clicks and without JavaScript.
    document.querySelectorAll('[data-detail-url]').forEach(row => {
        row.addEventListener('click', event => {
            if (event.target.closest('a,button,input,select,textarea,label,form,details')) return;
            window.location.assign(row.dataset.detailUrl);
        });
    });
    const t = window.h2hT || (text => text);
    const upload = document.querySelector('input[name="photos[]"]');
    upload?.addEventListener('change', () => {
        const count = upload.files.length;
        upload.setCustomValidity(count > 20 ? t('Mỗi lần được tải tối đa 20 ảnh.') : '');
        let status = document.querySelector('[data-upload-status]');
        if (!status) { status = document.createElement('p'); status.dataset.uploadStatus=''; status.setAttribute('role','status'); upload.after(status); }
        status.textContent = count ? t('Đã chọn {count} ảnh.', {count}) : '';
        if (count > 20) upload.reportValidity();
    });
    const photos = [...document.querySelectorAll('.photo-grid img, .photo-thumbnail')];
    if (!photos.length) return;
    const gallery = photos.filter((photo,index) => photos.findIndex(other=>other.src===photo.src)===index);
    const dialog = document.createElement('dialog'); dialog.className = 'photo-viewer';
    dialog.setAttribute('aria-label', t('Xem ảnh'));
    const image = document.createElement('img');
    const close = document.createElement('button'); close.type = 'button'; close.className = 'btn btn-primary'; close.textContent = t('Đóng');
    const previous = document.createElement('button'); previous.type = 'button'; previous.className = 'btn btn-soft'; previous.textContent = t('Ảnh trước');
    const next = document.createElement('button'); next.type = 'button'; next.className = 'btn btn-soft'; next.textContent = t('Ảnh tiếp');
    const count = document.createElement('span'); count.setAttribute('aria-live', 'polite');
    const controls = document.createElement('div'); controls.className = 'photo-viewer-controls'; controls.append(previous, count, next, close);
    dialog.append(controls, image); document.body.append(dialog);
    let index = 0;
    function show(value) { index = (value + gallery.length) % gallery.length; image.src = gallery[index].src; image.alt = gallery[index].alt; count.textContent = `${index + 1} / ${gallery.length}`; }
    close.addEventListener('click', () => dialog.close());
    previous.addEventListener('click', () => show(index - 1)); next.addEventListener('click', () => show(index + 1));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('keydown', event => { if (event.key === 'ArrowRight') show(index + 1); if (event.key === 'ArrowLeft') show(index - 1); });
    photos.forEach((photo, i) => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'photo-open ' + [...photo.classList].filter(c => c.startsWith('photo-')).join(' ');
        button.setAttribute('aria-label', t('Xem ảnh {number}: {description}', {number:i+1,description:photo.alt}));
        photo.before(button); button.append(photo);
        button.addEventListener('click', () => { show(gallery.findIndex(item=>item.src===photo.src)); dialog.showModal(); });
    });
})();
