<?php

/**
 * Báo cáo phiếu điều chỉnh giảm VAT — Quản lý nhiều shop (Bán hàng → Quản lý VAT)
 *
 * Hiển thị phiếu hoàn hàng (return) và phiếu nội bộ đuôi Z đã gửi VAT điều chỉnh.
 * Các cột giống phiếu bán nhưng có thêm cột "HĐ gốc" để biết điều chỉnh từ phiếu nào.
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
?>

<div class="bctk-page" id="bctkPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="bctk-result">
        <div class="bctk-result__head">
            <div class="bctk-headline">
                <strong>Báo cáo phiếu điều chỉnh giảm VAT</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <span class="bctk-daterange">
                    Trạng thái VAT
                    <select id="bctkVatStatus">
                        <option value="all">Tất cả</option>
                        <option value="has_vat">Đã có thông tin VAT</option>
                        <option value="no_vat">Chưa có thông tin VAT</option>
                        <option value="vat_error">Gửi VAT bị lỗi</option>
                    </select>
                </span>
                <span class="bctk-daterange">
                    Loại phiếu
                    <select id="bctkDocType">
                        <option value="adjustment">Phiếu điều chỉnh</option>
                        <option value="internal">Phiếu nội bộ (đuôi Z)</option>
                        <option value="all">Tất cả</option>
                    </select>
                </span>
            </div>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <div class="bctk-tablewrap">
            <table class="bctk-table" id="bctkTable">
                <thead>
                    <tr>
                        <th class="c-sku">Seri</th>
                        <th class="c-sku">Mẫu HĐ</th>
                        <th class="c-unit">Hình thức TT</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-sku">Số hóa đơn</th>
                        <th class="c-name">Ghi chú HĐ</th>
                        <th class="c-num">Tiền chưa thuế</th>
                        <th class="c-sku">Ngày HĐ</th>
                        <th class="c-num">Tổng thuế</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-name">Tiền bằng chữ</th>
                        <th class="c-num">Tổng CK</th>
                        <th class="c-unit">Tỷ lệ thuế</th>
                        <th class="c-name">Công ty mua</th>
                        <th class="c-name">Tên KH</th>
                        <th class="c-name">Địa chỉ mua</th>
                        <th class="c-name">Email mua</th>
                        <th class="c-sku">ĐT mua</th>
                        <th class="c-sku">MST mua</th>
                        <th class="c-name">Địa chỉ bán</th>
                        <th class="c-name">Công ty bán</th>
                        <th class="c-sku">ĐT bán</th>
                        <th class="c-sku">MST bán</th>
                        <th class="c-sku">Ngày xuất</th>
                        <th class="c-sku">Số phiếu xuất</th>
                        <th class="c-name">NV xuất</th>
                        <th class="c-unit">Lý do</th>
                        <th class="c-name">Trạng thái VAT</th>
                        <th class="c-sku">Số SO</th>
                        <th class="c-num">SL bản ghi</th>
                        <th class="c-sku">UserID xuất</th>
                        <th class="c-sku">HĐ gốc</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="32">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
    window.TGS_BCTK = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_BCTK_Ajax::NONCE)); ?>',
        action: 'tgs_bctk_fetch_vat_adjustment',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,

        extraParams: function () {
            return {
                vat_status: document.getElementById('bctkVatStatus').value,
                doc_type: document.getElementById('bctkDocType').value
            };
        }
    };

    jQuery(function ($) {
        var B = window.TGSBctk;
        if (!B || !B.setRenderer) return;

        var esc = B.esc;
        var fmt = B.fmt;

        function ngay(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '-';
        }

        function getVatStatus(row) {
            if (row.adjustment_invoice_no) return 'Hóa đơn có chữ ký số';
            if (!row.adjustment_status || row.adjustment_status === 'pending') return 'Hóa đơn chưa lập VAT';
            if (row.adjustment_status === 'error' || row.adjustment_status === 'issue_error') return 'Gửi VAT bị lỗi';
            if (row.adjustment_status === 'done') return 'Hóa đơn có chữ ký số';
            return 'Đang xử lý';
        }

        function numberToVietnamese(num) {
            if (!num || num === 0) return 'Không đồng chẵn';
            return fmt(Math.round(Math.abs(num))) + ' đồng';
        }

        function cellText(r, col) {
            switch (col) {
                case 0:  return r.invoice_series || '';
                case 1:  return r.template_code || '';
                case 2:  return r.payment_method || 'Tiền mặt';
                case 3:  return r.customer_phone || '';
                case 4:  return r.adjustment_invoice_no || r.local_ledger_code || '';
                case 5:  return r.invoice_note || r.return_reason || '';
                case 6:  return fmt(Math.abs(r.total_before_tax || 0));
                case 7:  return ngay(r.created_at);
                case 8:  return fmt(Math.abs(r.total_tax_amount || 0));
                case 9:  return fmt(Math.abs(r.total_after_tax || 0));
                case 10: return numberToVietnamese(r.total_after_tax);
                case 11: return fmt(Math.abs(r.total_discount || 0));
                case 12: return r.tax_percent ? r.tax_percent + '%' : '';
                case 13: return r.buyer_company_name || '';
                case 14: return r.buyer_name || 'Khách lẻ';
                case 15: return r.buyer_address || '';
                case 16: return r.buyer_email || '';
                case 17: return r.buyer_phone || '';
                case 18: return r.buyer_tax_code || '';
                case 19: return r.seller_address || '';
                case 20: return r.seller_company_name || '';
                case 21: return r.seller_phone || '';
                case 22: return r.seller_tax_code || '';
                case 23: return ngay(r.created_at);
                case 24: return r.local_ledger_code || '';
                case 25: return r.cashier_name || '';
                case 26: return r.return_reason || 'Điều chỉnh giảm';
                case 27: return getVatStatus(r);
                case 28: return '';
                case 29: return String(r.item_count || 0);
                case 30: return r.created_by || '';
                case 31: return r.original_invoice_no || r.original_sale_code || '';
                default: return '';
            }
        }

        function rowHtml(row) {
            return '<tr>' +
                '<td class="c-sku">' + esc(row.invoice_series || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.template_code || '-') + '</td>' +
                '<td class="c-unit">' + esc(row.payment_method || 'Tiền mặt') + '</td>' +
                '<td class="c-sku">' + esc(row.customer_phone || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.adjustment_invoice_no || row.local_ledger_code || '-') + '</td>' +
                '<td class="c-name">' + esc(row.invoice_note || row.return_reason || '-') + '</td>' +
                '<td class="c-num">' + fmt(Math.abs(row.total_before_tax || 0)) + '</td>' +
                '<td class="c-sku">' + ngay(row.created_at) + '</td>' +
                '<td class="c-num">' + fmt(Math.abs(row.total_tax_amount || 0)) + '</td>' +
                '<td class="c-num">' + fmt(Math.abs(row.total_after_tax || 0)) + '</td>' +
                '<td class="c-name">' + numberToVietnamese(row.total_after_tax) + '</td>' +
                '<td class="c-num">' + fmt(Math.abs(row.total_discount || 0)) + '</td>' +
                '<td class="c-unit">' + (row.tax_percent ? row.tax_percent + '%' : '-') + '</td>' +
                '<td class="c-name">' + esc(row.buyer_company_name || '-') + '</td>' +
                '<td class="c-name">' + esc(row.buyer_name || 'Khách lẻ') + '</td>' +
                '<td class="c-name">' + esc(row.buyer_address || '-') + '</td>' +
                '<td class="c-name">' + esc(row.buyer_email || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.buyer_phone || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.buyer_tax_code || '-') + '</td>' +
                '<td class="c-name">' + esc(row.seller_address || '-') + '</td>' +
                '<td class="c-name">' + esc(row.seller_company_name || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.seller_phone || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.seller_tax_code || '-') + '</td>' +
                '<td class="c-sku">' + ngay(row.created_at) + '</td>' +
                '<td class="c-sku">' + esc(row.local_ledger_code || '-') + '</td>' +
                '<td class="c-name">' + esc(row.cashier_name || '-') + '</td>' +
                '<td class="c-unit">' + esc(row.return_reason || 'Điều chỉnh giảm') + '</td>' +
                '<td class="c-name">' + esc(getVatStatus(row)) + '</td>' +
                '<td class="c-sku">-</td>' +
                '<td class="c-num">' + (row.item_count || 0) + '</td>' +
                '<td class="c-sku">' + esc(row.created_by || '-') + '</td>' +
                '<td class="c-sku">' + esc(row.original_invoice_no || row.original_sale_code || '-') + '</td>' +
                '</tr>';
        }

        B.setRenderer(function (rows) {
            var table = document.getElementById('bctkTable');
            var ds = window.TGSDesignSystem;

            if (ds && ds.renderVirtualizedTable) {
                ds.renderVirtualizedTable({
                    table: table,
                    rows: rows,
                    rowHtml: rowHtml,
                    cellText: cellText
                });
            } else {
                var buf = [];
                for (var i = 0; i < rows.length; i++) {
                    buf.push(rowHtml(rows[i]));
                }
                document.getElementById('bctkBody').innerHTML = buf.join('');
            }
        });
    });
</script>
