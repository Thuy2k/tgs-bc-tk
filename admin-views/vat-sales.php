<?php

/**
 * Quản lý phiếu xuất bán — VAT (Bán hàng → Quản lý VAT)
 *
 * Báo cáo TỔNG QUAN CHO KẾ TOÁN nhiều shop, khác màn "Danh sách gửi hoá đơn
 * thuế" của tgs_pos (màn quầy, một shop, 3 trạng thái). Đủ 32 cột chứng từ VAT,
 * tiền tính lại từ item qua TGS_Money. Xem
 * docs/bao-cao-vat-phieu-xuat-ban-va-dieu-chinh.md.
 *
 * Bộ lọc trái chỉ hiện shop đã khai áp dụng thuế
 * (TGS_BCTK_Vat_Shops::active_blog_ids()). Khai thêm ở màn Quản lý VAT → Shop
 * áp dụng thuế.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_vat_blog_ids = TGS_BCTK_Vat_Shops::active_blog_ids();
$bctk_boot  = TGS_BCTK_Sites::filter_bootstrap($bctk_vat_blog_ids);
$bctk_today = current_time('Y-m-d');
$bctk_vat_shop_count = count($bctk_vat_blog_ids);

/** 32 cột — ĐÚNG thứ tự nghiệp vụ. Chỉ số phải khớp cellText() trong JS. */
$bctk_vat_cols = [
    'Mã shop', 'Seri', 'Mẫu HĐ', 'Hình thức thanh toán', 'Mã KH', 'Số hóa đơn',
    'Ghi chú hóa đơn', 'Thành tiền chưa thuế', 'Ngày hóa đơn', 'Tổng thuế',
    'Thành tiền', 'Thành tiền bằng chữ', 'Tổng chiết khấu', 'Tỷ lệ thuế',
    'Tên công ty bên mua', 'Tên KH', 'Địa chỉ bên mua', 'Email bên mua',
    'Điện thoại bên mua', 'Mã số thuế bên mua', 'Địa chỉ công ty bên bán',
    'Tên công ty bên bán', 'Điện thoại bên bán', 'Mã số thuế bên bán',
    'Ngày xuất', 'Số phiếu xuất', 'Nhân viên xuất', 'Lý do', 'Trạng thái VAT',
    'Số SO', 'SL bản ghi', 'userID xuất',
];
?>

<div class="bctk-page" id="bctkPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="bctk-result">
        <div class="bctk-result__head">
            <div class="bctk-headline">
                <strong>Quản lý phiếu xuất bán (VAT)</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <?php include __DIR__ . '/partials/vat-report-filters.php'; ?>
            </div>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <?php if ($bctk_vat_shop_count === 0) : ?>
            <?php include __DIR__ . '/partials/vat-placeholder.php'; ?>
        <?php else : ?>
            <div class="bctk-tablewrap">
                <table class="bctk-table bctk-vat-table" id="bctkTable">
                    <thead>
                        <tr>
                            <?php foreach ($bctk_vat_cols as $bctk_i => $bctk_label) :
                                $bctk_num = in_array($bctk_i, [7, 9, 10, 12, 30, 31], true) ? ' c-num' : ''; ?>
                                <th class="c-vat<?php echo esc_attr($bctk_num); ?>"><?php echo esc_html($bctk_label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="bctkBody">
                        <tr class="bctk-empty">
                            <td colspan="32">Chọn shop bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                        </tr>
                    </tbody>
                    <?php /* 7 + 1 + 1 + 1 + 1 + 1 + 1 + 19 = 32 */ ?>
                    <tfoot id="bctkFoot" class="bctk-hidden">
                        <tr>
                            <td colspan="7">Tổng cộng</td>
                            <td class="c-num" id="fTtChuaThue">0</td>
                            <td></td>
                            <td class="c-num" id="fTongThue">0</td>
                            <td class="c-num" id="fThanhTien">0</td>
                            <td></td>
                            <td class="c-num" id="fTongCk">0</td>
                            <td colspan="19"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php /* Modal chi tiết dựng bằng JS: assets/js/bctk-phieu-modal.js (component dùng chung) */ ?>

<script>
    window.TGS_BCTK = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_BCTK_Ajax::NONCE)); ?>',
        action: 'tgs_bctk_fetch_vat_sales',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,

        extraParams: function () {
            return {
                bill_scope: (document.getElementById('bctkVatBillScope') || {}).value || 'normal',
                vat_filter: (document.getElementById('bctkVatInfoFilter') || {}).value || 'all'
            };
        }
    };
</script>
