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
                        <th class="c-sku">Mã shop</th>
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
                        <td colspan="33">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
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

        function esc(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m];
            });
        }

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
                case 0:  return r.site_code || '';
                case 1:  return r.invoice_series || '';
                case 2:  return r.template_code || '';
                case 3:  return r.payment_method || 'Tiền mặt';
                case 4:  return r.customer_phone || '';
                case 5:  return r.adjustment_invoice_no || r.local_ledger_code || '';
                case 6:  return r.invoice_note || r.return_reason || '';
                case 7:  return fmt(Math.abs(r.total_before_tax || 0));
                case 8:  return ngay(r.created_at);
                case 9:  return fmt(Math.abs(r.total_tax_amount || 0));
                case 10: return fmt(Math.abs(r.total_after_tax || 0));
                case 11: return numberToVietnamese(r.total_after_tax);
                case 12: return fmt(Math.abs(r.total_discount || 0));
                case 13: return r.tax_percent ? r.tax_percent + '%' : '';
                case 14: return r.buyer_company_name || '';
                case 15: return r.buyer_name || 'Khách lẻ';
                case 16: return r.buyer_address || '';
                case 17: return r.buyer_email || '';
                case 18: return r.buyer_phone || '';
                case 19: return r.buyer_tax_code || '';
                case 20: return r.seller_address || '';
                case 21: return r.seller_company_name || '';
                case 22: return r.seller_phone || '';
                case 23: return r.seller_tax_code || '';
                case 24: return ngay(r.created_at);
                case 25: return r.local_ledger_code || '';
                case 26: return r.cashier_name || '';
                case 27: return r.return_reason || 'Điều chỉnh giảm';
                case 28: return getVatStatus(r);
                case 29: return '';
                case 30: return String(r.item_count || 0);
                case 31: return r.created_by || '';
                case 32: return r.original_invoice_no || r.original_sale_code || '';
                default: return '';
            }
        }

        function rowHtml(row, i) {
            return '<tr data-idx="' + i + '" style="cursor:pointer;">' +
                '<td class="c-sku">' + esc(row.site_code || '-') + '</td>' +
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
                    buf.push(rowHtml(rows[i], i));
                }
                document.getElementById('bctkBody').innerHTML = buf.join('');
            }

            // Gán sự kiện click vào từng dòng
            $('#bctkBody tr').off('click').on('click', function() {
                var idx = $(this).data('idx');
                if (idx !== undefined && rows[idx]) {
                    showDetailModal(rows[idx]);
                }
            });
        });

        // Click vào dòng để xem chi tiết
        function showDetailModal(row) {
            var modal = document.getElementById('bctkDetailModal');
            if (!modal) {
                alert('Modal chưa được khởi tạo');
                return;
            }

            // Load chi tiết qua AJAX (dùng return_ledger_id thay vì sale_ledger_id)
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                action: 'tgs_bctk_get_adjustment_detail',
                nonce: '<?php echo wp_create_nonce(TGS_BCTK_Ajax::NONCE); ?>',
                blog_id: row.blog_id || <?php echo get_current_blog_id(); ?>,
                ledger_id: row.return_ledger_id
            }).then(function(res) {
                if (res.success && res.data) {
                    // Lưu thông tin để dùng cho PDF
                    window.currentLedgerData = res.data;
                    window.currentLedgerId = row.return_ledger_id;
                    window.currentBlogId = row.blog_id || <?php echo get_current_blog_id(); ?>;

                    renderDetailModal(res.data);
                    // Dùng Bootstrap 5 API
                    var bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                } else {
                    alert('Không thể tải chi tiết: ' + (res.data?.message || 'Lỗi không xác định'));
                }
            }).fail(function() {
                alert('Lỗi kết nối');
            });
        }

        function renderDetailModal(data) {
            var ledger = data.ledger;
            var items = data.items || [];
            var customer = data.customer;
            var vatInfo = data.vat_info;
            var productInfo = data.product_info || {};
            var isBillZ = data.is_bill_z;
            var hasVat = data.has_vat;

            // Format tiền
            var formatMoney = function(num) {
                return Math.round(Math.abs(num || 0)).toLocaleString('vi-VN') + 'đ';
            };

            // Format ngày
            var formatDate = function(str) {
                if (!str) return '-';
                var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(str);
                return m ? m[3] + '/' + m[2] + '/' + m[1] : str;
            };

            var html = '<div class="invoice-detail">';

            // Header phiếu
            html += '<div class="row mb-3">';
            html += '<div class="col-md-6">';
            html += '<h6>Phiếu điều chỉnh giảm</h6>';
            html += '<p class="mb-1"><strong>Số phiếu:</strong> ' + esc(ledger.local_ledger_code) + '</p>';
            html += '<p class="mb-1"><strong>Ngày:</strong> ' + formatDate(ledger.created_at) + '</p>';
            html += '<p class="mb-1"><strong>NV xuất:</strong> ' + esc(ledger.cashier_name || '-') + '</p>';
            if (isBillZ) {
                html += '<p class="mb-0"><span class="badge bg-warning">Phiếu nội bộ (Bill Z)</span></p>';
            }
            if (data.original_sale_code) {
                html += '<p class="mb-1"><strong>HĐ gốc:</strong> ' + esc(data.original_sale_code) + '</p>';
            }
            html += '</div>';

            html += '<div class="col-md-6 text-end">';
            html += '<h6>Khách hàng</h6>';

            // Parse local_ledger_person_meta để lấy thông tin khách
            var personMeta = null;
            try {
                if (ledger.local_ledger_person_meta) {
                    personMeta = JSON.parse(ledger.local_ledger_person_meta);
                }
            } catch (e) {
                // Parse failed, ignore
            }

            if (personMeta) {
                html += '<p class="mb-1"><strong>' + esc(personMeta.name || 'Khách lẻ') + '</strong></p>';
                if (personMeta.phone) html += '<p class="mb-1">ĐT: ' + esc(personMeta.phone) + '</p>';
                if (personMeta.address) html += '<p class="mb-1">Địa chỉ: ' + esc(personMeta.address) + '</p>';
                if (personMeta.email) html += '<p class="mb-1">Email: ' + esc(personMeta.email) + '</p>';
            } else {
                html += '<p class="mb-1">Khách lẻ</p>';
            }
            html += '</div>';
            html += '</div>';

            // Bảng items
            html += '<div class="table-responsive mb-3">';
            html += '<table class="table table-sm table-bordered">';
            html += '<thead class="table-light">';
            html += '<tr>';
            html += '<th>STT</th>';
            html += '<th>Mã hàng</th>';
            html += '<th>Tên hàng</th>';
            html += '<th>SL</th>';
            html += '<th>ĐVT</th>';
            html += '<th>Đơn giá</th>';
            html += '<th>CK</th>';
            html += '<th>Thuế</th>';
            html += '<th>Thành tiền</th>';
            html += '</tr>';
            html += '</thead>';
            html += '<tbody>';

            var totalMoney = 0;
            var totalDiscount = 0;
            var totalTax = 0;

            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var sku = item.local_product_sku || item.sku || '-';
                var productName = productInfo[sku] || sku;

                // Tính toán theo đúng mô hình tiền (số âm cho phiếu hoàn hàng)
                var qty = Math.abs(parseFloat(item.quantity || 0));
                var price = parseFloat(item.price || 0);
                var discount = Math.abs(parseFloat(item.local_ledger_item_discount_amount || 0));
                var taxAmount = Math.abs(parseFloat(item.local_ledger_item_tax_amount || 0));
                var taxPercent = parseFloat(item.local_ledger_item_tax_percent || 0);

                // Tiền hàng trước CK = quantity × price
                var moneyBeforeDiscount = qty * price;
                // Tiền hàng sau CK = moneyBeforeDiscount - discount
                var moneyAfterDiscount = moneyBeforeDiscount - discount;
                // Thành tiền = sau CK + thuế
                var itemTotal = moneyAfterDiscount + taxAmount;

                totalMoney += moneyAfterDiscount;
                totalDiscount += discount;
                totalTax += taxAmount;

                html += '<tr>';
                html += '<td>' + (i + 1) + '</td>';
                html += '<td>' + esc(sku) + '</td>';
                html += '<td>' + esc(productName) + '</td>';
                html += '<td>' + qty + '</td>';
                html += '<td>' + esc(item.local_ledger_item_unit_name || '-') + '</td>';
                html += '<td class="text-end">' + formatMoney(price) + '</td>';
                html += '<td class="text-end">' + formatMoney(discount) + '</td>';
                html += '<td class="text-end">' + formatMoney(taxAmount) + ' (' + taxPercent + '%)</td>';
                html += '<td class="text-end">' + formatMoney(itemTotal) + '</td>';
                html += '</tr>';
            }

            html += '</tbody>';
            html += '<tfoot>';
            html += '<tr>';
                html += '<th colspan="8" class="text-end">Tổng tiền hàng (sau CK, trước thuế):</th>';
                html += '<th class="text-end">' + formatMoney(totalMoney) + '</th>';
            html += '</tr>';
            html += '<tr>';
                html += '<th colspan="8" class="text-end">Tổng chiết khấu:</th>';
                html += '<th class="text-end">' + formatMoney(totalDiscount) + '</th>';
            html += '</tr>';
            html += '<tr>';
                html += '<th colspan="8" class="text-end">Tổng thuế:</th>';
                html += '<th class="text-end">' + formatMoney(totalTax) + '</th>';
            html += '</tr>';
            html += '<tr class="table-active">';
                html += '<th colspan="8" class="text-end">TỔNG ĐIỀU CHỈNH GIẢM:</th>';
                html += '<th class="text-end">' + formatMoney(totalMoney + totalTax) + '</th>';
            html += '</tr>';
            html += '</tfoot>';
            html += '</table>';
            html += '</div>';

            // Thông tin VAT
            if (vatInfo) {
                html += '<div class="alert alert-info">';
                html += '<h6>Thông tin VAT</h6>';
                if (hasVat) {
                    html += '<p class="mb-1"><strong>Số hóa đơn:</strong> ' + esc(vatInfo.adjustment_invoice_no || '-') + '</p>';
                    html += '<p class="mb-1"><strong>Seri:</strong> ' + esc(vatInfo.invoice_series || '-') + '</p>';
                    html += '<p class="mb-0"><span class="badge bg-success">Đã có chữ ký số</span></p>';
                } else {
                    html += '<p class="mb-0"><span class="badge bg-warning">Chưa có thông tin VAT</span></p>';
                }
                html += '</div>';
            }

            // Lý do
            if (ledger.local_ledger_note) {
                html += '<div class="alert alert-secondary">';
                html += '<strong>Lý do:</strong> ' + esc(ledger.local_ledger_note);
                html += '</div>';
            }

            html += '</div>';

            $('#bctkDetailModalContent').html(html);

            // Hiển thị/ẩn các nút
            $('#bctkDetailPrintBtn').show();
            $('#bctkDetailPdfBtn').toggle(hasVat);
        }

        // Gán event handler cho nút Xem PDF (sử dụng event delegation)
        $(document).off('click', '#bctkDetailPdfBtn').on('click', '#bctkDetailPdfBtn', function() {
            console.log('Adjustment PDF button clicked!');

            var pdfModal = document.getElementById('bctkPdfModal');
            if (!pdfModal) {
                alert('PDF modal chưa được khởi tạo');
                return;
            }

            // Hiển thị loading
            $('#bctkPdfModalContent').html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Đang tải PDF...</span></div><p class="mt-3">Đang lấy file PDF từ Viettel...</p></div>');

            // Mở modal
            var bsPdfModal = new bootstrap.Modal(pdfModal);
            bsPdfModal.show();

            // Debug log
            console.log('Adjustment PDF button clicked - currentLedgerId:', window.currentLedgerId);
            console.log('Adjustment PDF button clicked - currentBlogId:', window.currentBlogId);
            console.log('Adjustment PDF button clicked - currentLedgerData:', window.currentLedgerData);

            if (!window.currentLedgerId) {
                alert('Không tìm thấy ID phiếu. Vui lòng đóng modal và thử lại.');
                return;
            }

            // Gọi AJAX để lấy PDF
            $.ajax({
                url: "<?php echo admin_url('admin-ajax.php'); ?>",
                type: 'POST',
                data: {
                    action: 'tgs_bctk_preview_adjustment_pdf',
                    nonce: '<?php echo wp_create_nonce(TGS_BCTK_Ajax::NONCE); ?>',
                    blog_id: window.currentBlogId || <?php echo get_current_blog_id(); ?>,
                    ledger_id: window.currentLedgerId
                },
                success: function(res) {
                    console.log('AJAX response:', res);
                    if (res.success && res.data && res.data.file_bytes_base64) {
                        var base64 = res.data.file_bytes_base64;
                        var pdfDataUrl = 'data:application/pdf;base64,' + base64;
                        var fileName = res.data.file_name || 'adjustment_invoice.pdf';

                        var pdfHtml = '<div class="pdf-viewer-container">';
                        pdfHtml += '<div class="mb-3"><strong>File:</strong> ' + esc(fileName) + '</div>';
                        pdfHtml += '<iframe src="' + pdfDataUrl + '" style="width:100%; height:70vh; border:1px solid #ddd;"></iframe>';
                        pdfHtml += '</div>';

                        $('#bctkPdfModalContent').html(pdfHtml);
                    } else {
                        var errorMsg = (res.data && res.data.message) ? res.data.message : 'Không thể tải file PDF';
                        $('#bctkPdfModalContent').html('<div class="alert alert-danger"><i class="bx bx-error"></i> ' + esc(errorMsg) + '</div>');
                    }
                },
                error: function() {
                    $('#bctkPdfModalContent').html('<div class="alert alert-danger"><i class="bx bx-error"></i> Lỗi kết nối khi tải PDF</div>');
                }
            });
        });
    });
</script>

<!-- Modal xem chi tiết phiếu điều chỉnh giảm VAT -->
<div class="modal fade" id="bctkDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chi tiết phiếu điều chỉnh giảm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body" id="bctkDetailModalContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Đang tải...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                <button type="button" class="btn btn-primary" id="bctkDetailPrintBtn" style="display:none;">
                    <i class="bx bx-printer"></i> In phiếu
                </button>
                <button type="button" class="btn btn-info" id="bctkDetailPdfBtn" style="display:none;">
                    <i class="bx bx-file-blank"></i> Xem PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal xem PDF hóa đơn điều chỉnh -->
<div class="modal fade" id="bctkPdfModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Xem PDF hóa đơn điều chỉnh</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body" id="bctkPdfModalContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Đang tải...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>
