<?php

/**
 * Thanh lọc riêng của hai màn báo cáo VAT — nằm trên đầu bảng, dùng chung cho
 * cả "Phiếu xuất bán (VAT)" lẫn "Phiếu điều chỉnh giảm (VAT)".
 *
 * Hai tiêu chí:
 *   - Loại phiếu: bỏ / chỉ / gồm cả phiếu nội bộ (bill Z, mã đuôi Z).
 *   - Thông tin VAT: đã có bản ghi hoá đơn / chưa có (bỏ qua gửi thuế) /
 *     có bản ghi nhưng đang lỗi.
 *
 * bctk-vat-report.js đọc hai <select> này qua window.TGS_BCTK.extraParams và
 * gửi kèm mọi lượt gọi; đổi giá trị là tự chạy lại.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<span class="bctk-daterange">
    Loại phiếu
    <select id="bctkVatBillScope">
        <option value="normal" selected>Phiếu thường (bỏ nội bộ)</option>
        <option value="internal">Chỉ phiếu nội bộ (Z)</option>
        <option value="all">Tất cả</option>
    </select>
</span>
<span class="bctk-daterange">
    Thông tin VAT
    <select id="bctkVatInfoFilter">
        <option value="all" selected>Tất cả</option>
        <option value="has_vat">Đã có thông tin VAT</option>
        <option value="no_vat">Chưa có (bỏ qua gửi thuế)</option>
        <option value="vat_error">Có VAT nhưng gửi lỗi</option>
    </select>
</span>
