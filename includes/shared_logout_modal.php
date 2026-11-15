<?php


?>
<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center p-4 shadow-lg border-0" style="border-radius: 25px;">
            <div class="modal-body">
                <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-4x"></i></div>
                <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                <p class="text-muted-custom small mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج من النظام؟</p>
                <div class="d-grid gap-2">
                    <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                    <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>
</div>
