(function () {
    const servicePairs = [
        ['غسيل شامل', 'Full Wash'],
        ['غسيل كامل', 'Full Wash'],
        ['غسيل داخلي', 'Interior Wash'],
        ['تنظيف داخلي', 'Interior Wash'],
        ['تنظيف داخلي عميق', 'Interior Deep Clean'],
        ['تنظيف داخلي تفصيلي', 'Interior Detailing'],
        ['غسيل خارجي', 'Exterior Wash'],
        ['تنظيف خارجي', 'Exterior Wash'],
        ['تلميع خارجي', 'Exterior Polish'],
        ['تلميع داخلي', 'Interior Detailing'],
        ['تنظيف داخلي بالبخار', 'Interior Steam Clean'],
        ['تنظيف بالبخار', 'Steam Cleaning'],
        ['تغيير زيت', 'Oil Change'],
        ['غيار زيت', 'Oil Change'],
        ['طبقة سيراميك سريعة', 'Ceramic Quick Coat'],
        ['سيراميك', 'Ceramic Coating'],
        ['نانو سيراميك', 'Nano Ceramic Coating'],
        ['تجديد المصابيح', 'Headlight Restoration'],
        ['تنظيف مكينة', 'Engine Cleaning'],
        ['غسيل محرك', 'Engine Wash'],
        ['تنظيف فرش', 'Seat Cleaning'],
        ['تنظيف مقاعد', 'Seat Cleaning'],
        ['خدمة كاملة مع استلام وتسليم', 'Pickup Full Service'],
        ['استلام وتسليم', 'Pickup & Drop'],
        ['خدمة منزلية', 'Home Service'],
        ['غسيل داخلي منزلي', 'Home Interior Wash'],
        ['شمع ممتاز', 'Premium Wax'],
        ['واكس', 'Wax'],
        ['شمع', 'Wax'],
        ['حماية شمع', 'Wax Protection']
    ];

    const phrasePairs = [
        ['غسيل داخلي وخارجي مع تلميع الإطارات', 'Interior and exterior wash with tire polish'],
        ['تنظيف المقاعد والتابلوه والدواسات وتعقيم المقصورة', 'Seats, dashboard, mats, and cabin sanitizing'],
        ['غسيل رغوي وطبقة شمع وتلميع للدهان', 'Foam wash, wax layer, and paint shine'],
        ['تغيير زيت المحرك مع فحص أساسي', 'Engine oil replacement with basic checkup'],
        ['غسيل كامل باستخدام شامبو ممتاز', 'Complete wash using premium shampoo'],
        ['شفط وتنظيف أسطح المقصورة مع تعطير', 'Cabin vacuum, surfaces, and fragrance'],
        ['غسيل الهيكل والعجلات مع شمع سريع', 'Body wash, wheels, and quick wax'],
        ['رذاذ سيراميك خفيف للحماية', 'Light protective ceramic spray'],
        ['خدمة كاملة وسريعة لسيارات الاستخدام اليومي', 'Fast full service for daily use cars'],
        ['تنظيف عميق للأقمشة والبلاستيك', 'Deep fabric and plastic cleaning'],
        ['رغوة وشطف وتجفيف وتنظيف العجلات', 'Foam, rinse, dry, and wheel clean'],
        ['خدمة تغيير الزيت والفلتر', 'Oil and filter change service'],
        ['غسيل تفصيلي من الداخل والخارج', 'Detailed inside and outside wash'],
        ['تنظيف بالبخار ومعالجة الروائح', 'Steam clean and odor treatment'],
        ['تلميع ممتاز مع طبقة شمع نهائية', 'Premium polish and wax finish'],
        ['تنظيف المصابيح واستعادة لمعانها', 'Headlight cleaning and shine restoration'],
        ['غسيل كامل في المحطة أو عند العميل', 'Complete wash at station or customer location'],
        ['خدمة تنظيف داخلية متنقلة', 'Mobile cabin cleaning service'],
        ['غسيل خارجي سريع مع تلميع الإطارات', 'Fast exterior wash and tire shine'],
        ['تغيير زيت مع فحص مستويات السوائل', 'Oil change with fluid level inspection'],
        ['غسيل سيارة شامل وسريع', 'Express full car wash'],
        ['باقة غسيل كاملة مع الاستلام والتسليم', 'Pickup and drop full wash package'],
        ['لمعة خارجية سريعة', 'Quick exterior shine'],
        ['شمع حماية ولمعة نهائية', 'Protective wax and gloss finish'],
        ['غسيل شامل مع عناية بالتابلوه', 'Full car wash with dashboard care'],
        ['شفط داخلي ومسح للأسطح', 'Interior vacuum and wipe down'],
        ['تنظيف الهيكل والعجلات', 'Body and wheel clean'],
        ['يتوفر خيار الزيت الصناعي', 'Synthetic oil option available'],
        ['غسيل شامل فاخر مع تشطيب نهائي', 'Elite full wash and finishing'],
        ['باقة تنظيف داخلي تفصيلية ممتازة', 'Premium cabin detailing package'],
        ['تلميع آمن للدهان مع شمع', 'Paint-safe polish and wax'],
        ['زيت وفلتر وفحص من 10 نقاط', 'Oil, filter, and 10-point check']
    ];

    const wordPairs = [
        ['داخلي', 'interior'],
        ['خارجي', 'exterior'],
        ['غسيل', 'wash'],
        ['تنظيف', 'cleaning'],
        ['تلميع', 'polish'],
        ['شمع', 'wax'],
        ['زيت', 'oil'],
        ['فلتر', 'filter'],
        ['بخار', 'steam'],
        ['مقاعد', 'seats'],
        ['فرش', 'seats'],
        ['إطارات', 'tires'],
        ['عجلات', 'wheels'],
        ['مصابيح', 'headlights'],
        ['محرك', 'engine'],
        ['مكينة', 'engine'],
        ['سيراميك', 'ceramic'],
        ['تعقيم', 'sanitizing'],
        ['تعطير', 'fragrance'],
        ['استلام', 'pickup'],
        ['تسليم', 'drop'],
        ['منزلي', 'home'],
        ['سريع', 'quick'],
        ['شامل', 'full'],
        ['كامل', 'complete'],
        ['عميق', 'deep'],
        ['تفصيلي', 'detailing'],
        ['ممتاز', 'premium']
    ];

    const normalize = (value) => (value || '').toString().trim().toLowerCase().replace(/\s+/g, ' ');
    const hasArabic = (value) => /[\u0600-\u06FF]/.test(value || '');
    const titleCase = (value) => (value || '').replace(/\b[a-z]/g, (letter) => letter.toUpperCase());

    function translateKnown(value, lang, pairs) {
        const original = (value || '').toString().trim();
        const normalized = normalize(original);
        for (const [ar, en] of pairs) {
            if (lang === 'en' && normalize(ar) === normalized) return en;
            if (lang === 'ar' && normalize(en) === normalized) return ar;
        }
        return '';
    }

    function translateFreeText(value, lang) {
        const original = (value || '').toString().trim();
        if (!original) return original;
        const known = translateKnown(original, lang, phrasePairs) || translateKnown(original, lang, servicePairs);
        if (known) return known;

        if (lang === 'en' && hasArabic(original)) {
            let result = original;
            [...phrasePairs, ...servicePairs, ...wordPairs].forEach(([ar, en]) => {
                result = result.replaceAll(ar, en);
            });
            return hasArabic(result) ? original : titleCase(result.replace(/\s+/g, ' ').trim());
        }

        if (lang === 'ar' && !hasArabic(original)) {
            let result = original;
            [...phrasePairs, ...servicePairs, ...wordPairs].forEach(([ar, en]) => {
                result = result.replace(new RegExp(en.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), ar);
            });
            return result === original ? original : result.trim();
        }

        return original;
    }

    function translateServiceName(value, lang) {
        const original = (value || '').toString().trim();
        const currentLang = lang || localStorage.getItem('app_lang') || 'ar';
        if (!original) return original;
        if (original.includes(' + ')) {
            return original.split(' + ').map((part) => translateServiceName(part, currentLang)).join(' + ');
        }
        return translateFreeText(original, currentLang);
    }

    function localizeService(service, lang) {
        const currentLang = lang || localStorage.getItem('app_lang') || 'ar';
        const nameAr = service.Name_ar || (hasArabic(service.Name) ? service.Name : translateServiceName(service.Name, 'ar'));
        const nameEn = service.Name_en || (!hasArabic(service.Name) ? service.Name : translateServiceName(service.Name, 'en'));
        const descAr = service.Description_ar || (hasArabic(service.Description) ? service.Description : translateFreeText(service.Description, 'ar'));
        const descEn = service.Description_en || (!hasArabic(service.Description) ? service.Description : translateFreeText(service.Description, 'en'));
        return {
            name: currentLang === 'ar' ? (nameAr || service.Name || '') : (nameEn || service.Name || ''),
            description: currentLang === 'ar' ? (descAr || service.Description || '') : (descEn || service.Description || '')
        };
    }

    function servicePayloadFromInput(name, description, inputLang) {
        const lang = inputLang || localStorage.getItem('app_lang') || (hasArabic(name + ' ' + description) ? 'ar' : 'en');
        const nameValue = (name || '').trim();
        const descValue = (description || '').trim();
        return {
            name_ar: lang === 'ar' ? nameValue : translateServiceName(nameValue, 'ar'),
            name_en: lang === 'en' ? nameValue : translateServiceName(nameValue, 'en'),
            description_ar: lang === 'ar' ? descValue : translateFreeText(descValue, 'ar'),
            description_en: lang === 'en' ? descValue : translateFreeText(descValue, 'en')
        };
    }

    function applyServiceTranslations(root, lang) {
        const scope = root || document;
        const currentLang = lang || localStorage.getItem('app_lang') || 'ar';
        scope.querySelectorAll('[data-service-name]').forEach((el) => {
            const source = el.getAttribute('data-service-name') || el.textContent;
            el.textContent = translateServiceName(source, currentLang);
        });
        scope.querySelectorAll('[data-service-ar][data-service-en]').forEach((el) => {
            el.textContent = currentLang === 'ar' ? el.getAttribute('data-service-ar') : el.getAttribute('data-service-en');
        });
    }

    window.clevoTranslateServiceName = translateServiceName;
    window.clevoTranslateServiceText = translateFreeText;
    window.clevoLocalizeService = localizeService;
    window.clevoServicePayloadFromInput = servicePayloadFromInput;
    window.clevoApplyServiceTranslations = applyServiceTranslations;
})();
