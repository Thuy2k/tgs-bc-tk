<?php

/**
 * Quản lý phiếu xuất bán — VAT (Bán hàng → Quản lý VAT)
 *
 * Dùng lại nguyên bộ lọc trái của BC_TK (chi nhánh / mã kho), phần bên phải
 * chờ chốt yêu cầu nghiệp vụ.
 *
 * Bước tiếp theo đã định sẵn: chỉ lấy dữ liệu của những shop khai trong
 * TGS_BCTK_Vat_Shops::active_blog_ids() — xem màn "Shop áp dụng thuế".
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Bộ lọc trái CHỈ hiện shop đã khai áp dụng thuế.
 *
 * Toàn hệ thống có hàng chục chi nhánh nhưng số shop lên thuế thật thì đếm trên
 * đầu ngón tay — liệt kê hết là người dùng phải dò giữa một đống dòng không
 * liên quan, và tệ hơn là tích nhầm một shop chưa áp thuế rồi tưởng số liệu sai.
 *
 * Khai thêm shop ở màn Quản lý VAT → Shop áp dụng thuế.
 */
$bctk_vat_blog_ids = TGS_BCTK_Vat_Shops::active_blog_ids();
$bctk_boot  = TGS_BCTK_Sites::filter_bootstrap($bctk_vat_blog_ids);
$bctk_today = current_time('Y-m-d');
$bctk_vat_shop_count = count($bctk_vat_blog_ids);
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
            </div>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <?php include __DIR__ . '/partials/vat-placeholder.php'; ?>
    </section>
</div>
