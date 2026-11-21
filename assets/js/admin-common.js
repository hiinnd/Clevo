(() => {
    const sharedLabels = {
        nav_main: ['الرئيسية', 'Dashboard'], nav_approvals: ['الموافقات', 'Approvals'],
        nav_security: ['الأمان', 'Security'], nav_reports: ['التقارير', 'Reports'],
        nav_plans: ['الخطط', 'Plans'], nav_notif: ['الإشعارات', 'Notifications'],
        nav_support_chat: ['الدعم', 'Support'], nav_logout: ['خروج', 'Logout'],
        logout_title: ['تأكيد الخروج', 'Logout confirmation'],
        logout_msg: ['هل أنت متأكد من الخروج؟', 'Are you sure you want to sign out?'],
        btn_confirm_logout: ['نعم، خروج', 'Sign out'], btn_cancel: ['إلغاء', 'Cancel']
    };
    const apply = () => {
        const language = localStorage.getItem('app_lang') === 'en' ? 'en' : 'ar';
        if (document.documentElement.lang !== language) document.documentElement.lang = language;
        const theme = localStorage.getItem('global_theme') === 'light' ? 'light' : 'dark';
        if (document.documentElement.dataset.theme !== theme) document.documentElement.dataset.theme = theme;
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const labels = sharedLabels[el.dataset.i18n];
            if (labels) el.textContent = labels[language === 'en' ? 1 : 0];
        });
        document.querySelectorAll('[data-admin-ar][data-admin-en]').forEach(el => {
            el.textContent = el.dataset[language === 'en' ? 'adminEn' : 'adminAr'];
        });
        document.querySelectorAll('#secureExportForm input[name="lang"]').forEach(el => { el.value = language; });
    };
    document.addEventListener('DOMContentLoaded', apply);
    window.addEventListener('load', apply);
    window.addEventListener('pageshow', apply);
    window.addEventListener('storage', apply);
    document.addEventListener('click', () => setTimeout(apply, 0));
    new MutationObserver(apply).observe(document.documentElement, {attributes: true, attributeFilter: ['lang']});
})();
