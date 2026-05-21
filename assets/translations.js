(function () {
    const commonTranslations = {
        ar: {
            loc_page_title: "تحديد الموقع",
            address_label: "العنوان المختار",
            payment_alert: "ملاحظة: الخدمة تتطلب دفعاً إلكترونياً بعد الموافقة.",
            btn_confirm_loc: "تأكيد الموقع والمتابعة",
            reviews_title: "إدارة التقييمات",
            pay_cash: "نقداً",
            write_reply: "اكتب الرد هنا...",
            btn_send_reply: "إرسال الرد",
            btn_ok: "موافق",
            order_success_page_title: "تم الطلب بنجاح | Clevo",
            order_sent_title: "تم إرسال الطلب!",
            hello_thanks: "شكراً لك يا",
            order_sent_msg: "تم استلام طلبك بنجاح وهو الآن بانتظار موافقة المحطة.",
            order_id_label: "رقم الطلب:",
            back_to_home: "العودة للرئيسية",
            view_history: "عرض سجل الحجوزات",
            dues_title: "مستحقات معلقة",
            hello: "يا",
            dues_desc: "لديك مبالغ لم تسدد بعد، يرجى تصفية الحساب للمتابعة.",
            amount_label: "المبلغ المطلوب سداده",
            pay_cash_only: "نقداً | CASH",
            btn_pay_now: "سداد الآن",
            btn_support: "تواصل مع الدعم",
            device_id_label: "رقم مرجع الجهاز:"
        },
        en: {
            loc_page_title: "Select Location",
            address_label: "Selected Address",
            payment_alert: "Note: this service requires electronic payment after approval.",
            btn_confirm_loc: "Confirm Location",
            reviews_title: "Reviews Management",
            pay_cash: "Cash",
            write_reply: "Write your reply...",
            btn_send_reply: "Send Reply",
            btn_ok: "OK",
            order_success_page_title: "Order Sent | Clevo",
            order_sent_title: "Request Sent!",
            hello_thanks: "Thank you,",
            order_sent_msg: "Your request was received and is now waiting for station approval.",
            order_id_label: "Order ID:",
            back_to_home: "Back to Home",
            view_history: "View Booking History",
            dues_title: "Pending Dues",
            hello: "Hi",
            dues_desc: "You have unpaid dues. Please settle them to continue.",
            amount_label: "Amount due",
            pay_cash_only: "Cash | نقداً",
            btn_pay_now: "Pay Now",
            btn_support: "Contact Support",
            device_id_label: "Device reference:"
        }
    };

    window.applyTranslations = function (lang) {
        const dictionary = commonTranslations[lang] || commonTranslations.ar;
        document.querySelectorAll("[data-i18n]").forEach((el) => {
            const rawKey = el.getAttribute("data-i18n") || "";
            const isPlaceholder = rawKey.startsWith("[placeholder]");
            const key = isPlaceholder ? rawKey.replace("[placeholder]", "") : rawKey;
            if (!dictionary[key]) return;
            if (isPlaceholder) {
                el.setAttribute("placeholder", dictionary[key]);
            } else if (el.tagName === "TITLE") {
                document.title = dictionary[key];
            } else {
                el.innerText = dictionary[key];
            }
        });
    };
})();
