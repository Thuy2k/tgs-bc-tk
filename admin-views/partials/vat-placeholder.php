<?php

/**
 * Khối "chờ yêu cầu" dùng chung cho mấy màn VAT chưa chốt nghiệp vụ.
 *
 * Cố ý KHÔNG dựng bảng rỗng: bảng trống trông như màn hỏng hoặc lọc không ra
 * dữ liệu, người dùng sẽ bấm Tìm kiếm mãi rồi đi báo lỗi. Nói thẳng "đang chờ
 * yêu cầu" là hết thắc mắc.
 *
 * @var int $bctk_vat_shop_count Số shop đang áp dụng VAT (đã khai)
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_vat_shop_count = isset($bctk_vat_shop_count) ? (int) $bctk_vat_shop_count : 0;
?>

<div class="bctk-tablewrap" style="padding:32px;">
    <div style="max-width:640px;">
        <p style="font-size:15px;font-weight:600;margin:0 0 8px;">Màn này đang chờ yêu cầu nghiệp vụ</p>
        <p style="margin:0 0 12px;color:#64748b;">
            Bộ lọc bên trái đã chạy sẵn (chi nhánh, mã kho, khoảng ngày) — dùng chung
            với mọi báo cáo BC_TK. Phần dữ liệu bên phải sẽ dựng sau khi chốt cần
            hiển thị những cột nào.
        </p>
        <?php if ($bctk_vat_shop_count === 0) : ?>
            <p style="margin:0;color:#b45309;font-weight:600;">
                Chưa khai shop nào áp dụng thuế nên bộ lọc bên trái đang trống.
            </p>
            <p style="margin:6px 0 0;color:#64748b;">
                Vào <strong>Quản lý VAT → Shop áp dụng thuế</strong> khai shop trước.
                Cố ý để trống thay vì hiện lại toàn bộ chi nhánh — tích nhầm một shop
                chưa áp thuế rồi tưởng số liệu sai thì còn khó lần hơn.
            </p>
        <?php else : ?>
            <p style="margin:0;color:#64748b;">
                Bộ lọc bên trái chỉ hiện
                <strong style="color:#0f172a;"><?php echo (int) $bctk_vat_shop_count; ?></strong>
                shop đã khai áp dụng thuế — khai thêm ở màn
                <strong>Quản lý VAT → Shop áp dụng thuế</strong>.
            </p>
        <?php endif; ?>
    </div>
</div>
