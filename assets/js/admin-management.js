(() => {
    function apply() {
        const language = localStorage.getItem('app_lang') === 'en' ? 'en' : 'ar';
        document.documentElement.lang = language;
        document.documentElement.dir = language === 'en' ? 'ltr' : 'rtl';
        document.documentElement.dataset.theme = localStorage.getItem('global_theme') === 'light' ? 'light' : 'dark';
        document.getElementById('bootstrap-css').href = language === 'en'
            ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
            : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css';
    }
    document.getElementById('language').addEventListener('click', () => {
        localStorage.setItem('app_lang', localStorage.getItem('app_lang') === 'en' ? 'ar' : 'en'); apply();
    });
    document.getElementById('theme').addEventListener('click', () => {
        localStorage.setItem('global_theme', document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'); apply();
    });
    apply();
})();
