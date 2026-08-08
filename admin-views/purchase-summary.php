<?php

/**
 * Tổng hợp mua hàng (Mua hàng → BC_TK)
 *
 * Mỗi PHIẾU một dòng, để theo dõi CÔNG NỢ với nhà cung cấp: nợ bao nhiêu, đã
 * trả bao nhiêu, còn bao nhiêu, có quá hạn không.
 *
 * ⚠️ NGƯỢC CHIỀU VỚI TỔNG HỢP BÁN HÀNG:
 *   Mua (type 1)      mình nợ NCC   → trả bằng PHIẾU CHI (type 8)
 *   Trả NCC (type 16) NCC trả lại   → nhận bằng PHIẾU THU (type 7)
 *
 * Hạn thanh toán lấy từ ô HẠN TT trên màn tạo phiếu nhập kho.
 * Trạng thái thanh toán SUY RA từ số còn nợ, không lưu cột riêng.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_boot  = TGS_BCTK_Sites::filter_bootstrap();
$bctk_today = current_time('Y-m-d');
?>

<div class="bctk-page" id="bctkPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="bctk-result">
        <div class="bctk-result__head">
            <div class="bctk-headline">
                <strong>Tổng hợp mua hàng</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <span class="bctk-daterange">
                    Loại
                    <select id="bctkPurchaseSumKind">
                        <option value="buy" selected>Phiếu nhập kho</option>
                        <option value="return">Hàng trả nhà cung cấp</option>
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
                        <th class="c-zone">Kho</th>
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-sku">Ngày lập</th>
                        <th class="c-sku" title="Hạn thanh toán nhập lúc tạo phiếu nhập kho">Hạn TT</th>
                        <th class="c-sku">Lý do</th>
                        <th class="c-sku">Mã NCC</th>
                        <th class="c-name">Tên NCC</th>
                        <th class="c-sku">Số HĐ</th>
                        <th class="c-sku" title="Ký hiệu hoá đơn — số HĐ một mình không định danh đủ">Ký hiệu HĐ</th>
                        <th class="c-sku">Ngày HĐ</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Mã NV</th>
                        <th class="c-num" title="Tổng tiền phiếu — số phải trả nhà cung cấp">Tổng nợ</th>
                        <th class="c-num" title="Chi thuần = tiền mua trừ tiền trả lại NCC. Phiếu trả hiện số âm.">Chi thuần</th>
                        <th class="c-num" title="Tiền đã chi/thu qua phiếu thu chi đã duyệt">Số trả</th>
                        <th class="c-num">Còn nợ</th>
                        <th class="c-sku" title="Suy từ số còn nợ và hạn thanh toán">Trạng thái TT</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-name">Ghi chú</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="19">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <?php /* 12 + 1 + 1 + 1 + 1 + 3 = 19, khớp đúng số cột ở <thead> */ ?>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="12">Tổng cộng</td>
                        <td class="c-num" id="fTong">0</td>
                        <td class="c-num" id="fChiThuan">0</td>
                        <td class="c-num" id="fTra">0</td>
                        <td class="c-num" id="fNo">0</td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<script>
    window.TGS_BCTK = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_BCTK_Ajax::NONCE)); ?>',
        action: 'tgs_bctk_fetch_purchase_sum',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,

        extraParams: function () {
            return { loai: document.getElementById('bctkPurchaseSumKind').value };
        }
    };

    jQuery(function ($) {
        var B = window.TGSBctk;
        if (!B || !B.setRenderer) return;

        var esc = B.esc;
        var fmt = B.fmt;

        function ngay(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
            return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
        }

        $('#bctkPurchaseSumKind').on('change', function () {
            if ($('.bctk-site:checked').length) $(document).trigger('bctk:search');
        });

        /* Chữ của từng cột — PHẢI khớp thứ tự cột trong <thead> */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.pnk || '';
                case 2:  return ngay(r.ngay);
                case 3:  return ngay(r.han_tt);
                case 4:  return r.ly_do || '';
                case 5:  return r.ncc_ma || '';
                case 6:  return r.ncc_ten || '';
                case 7:  return r.so_hd || '';
                case 8:  return r.hd_ky_hieu || '';
                case 9:  return ngay(r.hd_ngay);
                case 10: return r.nv_ten || '';
                case 11: return r.nv_ma || '';
                case 12: return fmt(r.tong);
                case 13: return fmt(r.chi_thuan);
                case 14: return fmt(r.da_tra);
                case 15: return fmt(r.con_no);
                case 16: return r.tt_tt || '';
                case 17: return r.tra_lai ? 'x' : '';
                case 18: return r.ghi_chu || '';
                default: return '';
            }
        }

        function rowHtml(r, i) {
            /* Còn nợ khác 0 thì tô đỏ — đó là thứ người xem tìm trên màn này */
            var noClass = 'c-num' + (Math.abs(r.con_no) > 0.5 ? ' neg' : '');

            return '<tr data-i="' + i + '"' + (r.tra_lai ? ' class="bctk-row-return"' : '') + '>'
                + '<td class="c-zone">' + esc(r.kho)
                    + (r.no_zone ? ' <span class="bctk-warn" title="Dữ liệu chưa gán phân kho">chưa phân kho</span>' : '')
                + '</td>'
                + '<td class="c-sku">' + esc(r.pnk) + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-sku' + (r.qua_han ? ' neg' : '') + '">' + ngay(r.han_tt) + '</td>'
                + '<td class="c-sku" title="' + esc(r.ly_do_ten) + '">' + esc(r.ly_do) + '</td>'
                + '<td class="c-sku">' + esc(r.ncc_ma) + '</td>'
                + '<td class="c-name" title="' + esc(r.ncc_ten) + '">' + esc(r.ncc_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.so_hd) + '</td>'
                + '<td class="c-sku">' + esc(r.hd_ky_hieu) + '</td>'
                + '<td class="c-sku">' + ngay(r.hd_ngay) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.nv_ma) + '</td>'
                + '<td class="c-num">' + fmt(r.tong) + '</td>'
                + '<td class="c-num' + (r.chi_thuan < 0 ? ' neg' : '') + '">' + fmt(r.chi_thuan) + '</td>'
                + '<td class="c-num">' + fmt(r.da_tra) + '</td>'
                + '<td class="' + noClass + '">' + fmt(r.con_no) + '</td>'
                + '<td class="c-sku' + (r.qua_han ? ' neg' : '') + '">' + esc(r.tt_tt) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var t = { tong: 0, chi: 0, tra: 0, no: 0 };
            rows.forEach(function (r) {
                t.tong += (r.tong || 0);
                t.chi  += (r.chi_thuan || 0);
                t.tra  += (r.da_tra || 0);
                t.no   += (r.con_no || 0);
            });
            $('#fTong').text(fmt(t.tong));
            $('#fChiThuan').text(fmt(t.chi)).toggleClass('neg', t.chi < 0);
            $('#fTra').text(fmt(t.tra));
            $('#fNo').text(fmt(t.no));
        }

        B.setRenderer(function (rows) {
            var table = document.getElementById('bctkTable');
            var ds = window.TGSDesignSystem;

            viewRows = rows;

            if (ds && ds.virtualBody && table) {
                ds.virtualBody({
                    table: table,
                    rows: rows,
                    rowHtml: rowHtml,
                    cellText: cellText,
                    onFilter: function (daLoc) { viewRows = daLoc; footer(daLoc); }
                });
            } else {
                var buf = [];
                for (var i = 0; i < rows.length; i++) buf.push(rowHtml(rows[i], i));
                $('#bctkBody').html(buf.join(''));
            }

            footer(rows);
        }, function () {
            footer(viewRows);
        });
    });
</script>
