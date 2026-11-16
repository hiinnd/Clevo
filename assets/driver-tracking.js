/* Foreground only. No location, customer code or task credential is persisted. */
(() => {
    'use strict';
    const ctx = window.clevoDelivery;
    if (!ctx) return;
    const en = localStorage.getItem('app_lang') === 'en';
    document.querySelectorAll('[data-delivery-ar]').forEach(el => { el.textContent = en ? el.dataset.deliveryEn : el.dataset.deliveryAr; });
    const feedback = document.getElementById('deliveryFeedback');
    let active = ctx.sharing_allowed, assignment = ctx.assignment_id, sequence = ctx.last_sequence;
    let authorizedAt = Date.now(), pending = null, uploading = false, collecting = false, polling = false, closed = false;
    const key = () => crypto.getRandomValues(new Uint32Array(6)).join('');
    const notice = message => { if (feedback) feedback.textContent = message; };
    async function post(fields) {
        const response = await fetch(location.href, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf_token: ctx.csrf, ...fields }) });
        let data; try { data = await response.json(); } catch { throw new Error('unavailable'); }
        if (!response.ok || data.status !== 'success') throw new Error(String(response.status));
        return data;
    }
    document.querySelectorAll('[data-delivery-next]').forEach(button => button.addEventListener('click', async () => {
        const target = button.dataset.deliveryNext;
        const fields = { action: 'delivery_stage', stage: target };
        if (['vehicle_collected', 'delivered'].includes(target)) {
            const code = prompt(en ? 'Ask the customer for the current handoff code' : 'أدخل رمز التأكيد من الزبون');
            if (!code) return;
            if (!/^[0-9]{6}$/.test(code)) { notice(en ? 'Enter six digits' : 'أدخل ستة أرقام'); return; }
            Object.assign(fields, { action: 'confirm_handoff', kind: target === 'vehicle_collected' ? 'pickup' : 'delivery', code, idempotency_key: key() });
        }
        button.disabled = true;
        try { await post(fields); active = false; pending = null; location.reload(); }
        catch { notice(en ? 'Update not confirmed. Refresh the job before retrying.' : 'لم يتأكد التحديث. حدّث المهمة قبل إعادة المحاولة.'); button.disabled = false; }
    }));
    async function poll() {
        if (polling || document.hidden || closed) return;
        polling = true;
        try {
            const url = new URL(location.href); url.searchParams.set('tracking', '1');
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if ([401, 403, 404].includes(response.status)) { active = false; pending = null; return; }
            const result = await response.json(); if (result.status !== 'success') throw new Error();
            const tracking = result.data.tracking;
            if (tracking.assignment_id !== assignment) { active = false; pending = null; notice(en ? 'Assignment changed. Refresh the job.' : 'تغير إسناد المهمة. حدّث الصفحة.'); return; }
            if (tracking.delivery_stage !== ctx.delivery_stage) { active = false; pending = null; location.reload(); return; }
            active = tracking.sharing_allowed; authorizedAt = Date.now(); sequence = Math.max(sequence, tracking.last_sequence);
            if (!active) pending = null;
        } catch { notice(en ? 'Offline: location may be stale' : 'الاتصال غير متاح؛ قد يكون الموقع قديماً'); }
        finally { polling = false; }
    }
    async function upload() {
        if (!pending || uploading || !active || document.hidden || Date.now() - authorizedAt > 30000) return;
        if (Date.now() - pending.time > 120000) { pending = null; return; }
        uploading = true; const sample = pending;
        try {
            await post({ action: 'update_location', lat: sample.latitude, lng: sample.longitude, accuracy_m: sample.accuracy, captured_at: new Date(sample.time).toISOString(), assignment_id: assignment, sequence: sample.sequence });
            if (pending === sample) pending = null;
        } catch (error) { notice(en ? 'Location upload not confirmed' : 'لم يتأكد إرسال الموقع'); if (['401', '403', '404', '409'].includes(error.message)) { active = false; pending = null; } }
        finally { uploading = false; }
    }
    function collect() {
        if (closed || collecting || document.hidden || !active || Date.now() - authorizedAt > 30000 || !navigator.geolocation) return;
        if (pending) { upload(); return; }
        collecting = true;
        navigator.geolocation.getCurrentPosition(position => {
            collecting = false;
            if (!active || closed || document.hidden) return;
            pending = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, time: position.timestamp, sequence: ++sequence };
            upload();
        }, () => { collecting = false; notice(en ? 'Location permission or GPS unavailable' : 'إذن الموقع أو GPS غير متاح'); }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 8000 });
    }
    const pollTimer = setInterval(poll, 10000), sampleTimer = setInterval(collect, 10000);
    document.addEventListener('visibilitychange', () => { pending = null; if (!document.hidden) poll(); });
    window.addEventListener('pagehide', () => { closed = true; active = false; pending = null; clearInterval(pollTimer); clearInterval(sampleTimer); });
    poll(); collect();
})();
