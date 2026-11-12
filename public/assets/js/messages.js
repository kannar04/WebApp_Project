// Poll only while the conversation is visible. Keep drafts on every failure.
(() => {
    'use strict';
    const root = document.querySelector('[data-message-thread]');
    if (!root) return;
    const list = root.querySelector('[data-message-list]');
    const form = root.querySelector('[data-message-form]');
    const error = root.querySelector('[data-message-error]');
    const t = window.h2hT || (s => s);
    let busy = false, polling = false, stopped = false, timer, snapshot = '';
    const showError = message => { error.textContent = message; error.hidden = !message; };
    async function request(url, options = {}) {
        const response = await fetch(url, {...options, headers: {'Accept':'application/json'}, credentials:'same-origin', signal:AbortSignal.timeout(15000)});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || t('Không thể tải tin nhắn. Vui lòng thử lại.'));
        return result.data;
    }
    async function refresh() {
        const data = await request(root.dataset.updatesUrl);
        const next = JSON.stringify(data.messages);
        if (next !== snapshot) {
            const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
            const fragment = document.createDocumentFragment();
            data.messages.forEach(message => {
                const item = document.createElement('li');
                item.className = 'message-item ' + (Number(message.is_mine) ? 'is-mine' : 'is-theirs');
                const bubble = document.createElement('div'); bubble.className = 'message-bubble';
                const meta = document.createElement('p'); meta.className = 'message-meta';
                meta.textContent = (Number(message.is_mine) ? t('Bạn') : message.sender_name) + ' · ' + message.created_at.slice(0,16);
                const body = document.createElement('p'); body.className = 'message-body';
                body.style.whiteSpace = 'pre-wrap';
                body.textContent = Number(message.is_hidden) ? t('Tin nhắn đã bị quản trị viên ẩn.') : message.body;
                bubble.append(meta, body); item.append(bubble); fragment.append(item);
            });
            list.replaceChildren(fragment);
            root.querySelector('.thread-empty')?.remove();
            if (atBottom || !snapshot) list.scrollTop = list.scrollHeight;
            snapshot = next;
        }
        if (form) {
            form.hidden = !Number(data.state.can_send);
            if (form.hidden) showError(t('Cuộc trò chuyện hiện chỉ có thể xem lại.'));
        }
    }
    async function poll() {
        if (polling || stopped) return;
        polling = true;
        clearTimeout(timer);
        if (!document.hidden && !busy) {
            try { await refresh(); } catch (e) { showError(e.message || t('Mất kết nối. Tin nhắn sẽ được tải lại tự động.')); }
        }
        polling = false;
        if (!stopped) timer = setTimeout(poll, 4000);
    }
    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; showError('');
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        const draft = form.elements.body.value;
        try {
            await request(form.action, {method:'POST', body:new FormData(form)});
            if (form.elements.body.value === draft) form.elements.body.value = '';
            await refresh(); list.scrollTop = list.scrollHeight;
        } catch (e) { showError(e.message || t('Chưa gửi được tin nhắn. Nội dung của bạn vẫn được giữ lại.')); }
        finally { busy = false; button.disabled = false; }
    });
    form?.elements.body.addEventListener('keydown', event => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); form.requestSubmit(); }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', () => { stopped = false; poll(); });
    list.scrollTop = list.scrollHeight;
    poll();
})();
