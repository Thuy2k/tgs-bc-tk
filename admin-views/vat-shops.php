<?php

/**
 * Quản lý shop áp dụng thuế thật sự (Bán hàng → Quản lý VAT)
 *
 * Khai shop nào đang xuất hoá đơn VAT thật. Hai màn còn lại của khối Quản lý
 * VAT sẽ lọc dữ liệu theo đúng danh sách này — xem
 * includes/class-bctk-vat-shops.php.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_vat_table_ready = TGS_BCTK_Vat_Shops::table_exists();
?>

<div class="bctk-vat-page">

    <?php if (!$bctk_vat_table_ready) : ?>
        <div class="notice notice-warning" style="margin:0 0 16px;padding:12px 16px;">
            <p style="margin:0;">
                <strong>Chưa có bảng <code><?php echo esc_html(TGS_BCTK_Vat_Shops::table()); ?></code>.</strong>
                Vào <em>Plugins</em>, tắt rồi bật lại plugin <strong>TGS Shop Management</strong> —
                bảng sẽ tự sinh, không mất dữ liệu nào khác.
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bx bx-receipt me-2"></i>Shop đang áp dụng hoá đơn VAT
            </h5>
            <button type="button" class="btn btn-sm btn-primary" id="bctkVatAddShop">
                <i class="bx bx-plus me-1"></i>Thêm shop
            </button>
        </div>

        <div class="card-body">
            <p class="text-muted" style="margin-top:0;">
                Chỉ shop khai ở đây mới được khối <strong>Quản lý VAT</strong> đụng tới. Mã shop phải
                trùng cột <code>tgs_site_code</code> trong bảng <code>wp_blogs</code> — gõ sai là báo cáo
                thuế thiếu nguyên một shop mà không ai biết vì sao.
            </p>

            <div class="table-responsive">
                <table class="table table-hover" id="bctkVatShopsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:110px;">Mã shop</th>
                            <th>Tên shop</th>
                            <th style="width:150px;">MST phát hành</th>
                            <th style="width:130px;">Áp dụng từ</th>
                            <th>Ghi chú</th>
                            <th style="width:130px;">Trạng thái</th>
                            <th style="width:110px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="bctkVatShopsBody">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bx bx-loader bx-spin"></i> Đang tải…
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal thêm/sửa shop -->
<div class="modal fade" id="bctkVatShopModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bctkVatShopModalTitle">Thêm shop áp dụng VAT</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bctkVatShopId">

                <div class="mb-3">
                    <label for="bctkVatShopCode" class="form-label">
                        Mã shop (tgs_site_code) <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control" id="bctkVatShopCode" placeholder="Ví dụ: 26003">
                    <div class="form-text">Đúng mã trong cột <code>tgs_site_code</code> của bảng <code>wp_blogs</code>.</div>
                </div>

                <div class="mb-3">
                    <label for="bctkVatShopTaxCode" class="form-label">MST đang phát hành</label>
                    <input type="text" class="form-control" id="bctkVatShopTaxCode" placeholder="Ví dụ: 0106933743-008">
                    <div class="form-text">Để trống nếu chưa chốt. Chỉ để ghi nhớ, không dùng để gửi hoá đơn.</div>
                </div>

                <div class="mb-3">
                    <label for="bctkVatShopAppliedFrom" class="form-label">Áp dụng thuế từ ngày</label>
                    <input type="date" class="form-control" id="bctkVatShopAppliedFrom">
                </div>

                <div class="mb-3">
                    <label for="bctkVatShopNote" class="form-label">Ghi chú</label>
                    <textarea class="form-control" id="bctkVatShopNote" rows="2"
                        placeholder="Ghi chú về đợt triển khai thuế của shop này…"></textarea>
                </div>

                <div class="mb-1">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bctkVatShopActive" checked>
                        <label class="form-check-label" for="bctkVatShopActive">Đang áp dụng thuế</label>
                    </div>
                    <div class="form-text">Bỏ tích = tạm dừng: shop vẫn nằm trong danh sách nhưng báo cáo VAT bỏ qua.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="bctkVatShopSave">Lưu</button>
            </div>
        </div>
    </div>
</div>
