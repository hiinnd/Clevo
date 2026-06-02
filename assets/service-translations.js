(function () {
    const servicePairs = [
        ['غسيل شامل', 'Full Wash'],
        ['غسيل كامل', 'Full Wash'],
        ['غسيل داخلي', 'Interior Wash'],
        ['تنظيف داخلي', 'Interior Wash'],
        ['غسيل خارجي', 'Exterior Wash'],
        ['تنظيف خارجي', 'Exterior Wash'],
        ['تلميع خارجي', 'Exterior Polish'],
        ['تلميع داخلي', 'Interior Detailing'],
        ['تلميع', 'Polishing'],
        ['واكس', 'Wax'],
        ['شمع', 'Wax'],
        ['حماية شمع', 'Wax Protection'],
        ['غيار زيت', 'Oil Change'],
        ['تغيير زيت', 'Oil Change'],
        ['تنظيف بالبخار', 'Steam Cleaning'],
        ['بخار', 'Steam Cleaning'],
        ['تعقيم', 'Sanitizing'],
        ['تعطير', 'Fragrance'],
        ['سيراميك', 'Ceramic Coating'],
        ['نانو سيراميك', 'Nano Ceramic Coating'],
        ['تنظيف مكينة', 'Engine Cleaning'],
        ['غسيل محرك', 'Engine Wash'],
        ['تنظيف فرش', 'Seat Cleaning'],
        ['تنظيف مقاعد', 'Seat Cleaning'],
        ['استلام وتسليم', 'Pickup & Drop'],
        ['خدمة منزلية', 'Home Service'],
        ['في الموقع', 'On-site Service']
    ];

    const normalize = (value) => (value || '')
        .toString()
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ');

    function titleCase(value) {
        return (value || '').replace(/\b[a-z]/g, (letter) => letter.toUpperCase());
    }

    function translateServiceName(value, lang) {
        const original = (value || '').toString().trim();
        if (!original) return original;

        const currentLang = lang || localStorage.getItem('app_lang') || 'ar';
        if (original.includes(' + ')) {
            return original
                .split(' + ')
                .map((part) => translateServiceName(part, currentLang))
                .join(' + ');
        }
        const normalized = normalize(original);

        for (const pair of servicePairs) {
            const ar = pair[0];
            const en = pair[1];
            const arNorm = normalize(ar);
            const enNorm = normalize(en);

            if (currentLang === 'en') {
                if (normalized === arNorm || normalized.includes(arNorm)) {
                    return en;
                }
            } else if (normalized === enNorm || normalized.includes(enNorm)) {
                return ar;
            }
        }

        return currentLang === 'en' && /^[a-z\s&-]+$/i.test(original) ? titleCase(original) : original;
    }

    function applyServiceTranslations(root, lang) {
        const scope = root || document;
        const currentLang = lang || localStorage.getItem('app_lang') || 'ar';
        scope.querySelectorAll('[data-service-name]').forEach((el) => {
            const source = el.getAttribute('data-service-name') || el.textContent;
            el.textContent = translateServiceName(source, currentLang);
        });
    }

    window.clevoTranslateServiceName = translateServiceName;
    window.clevoApplyServiceTranslations = applyServiceTranslations;
})();
