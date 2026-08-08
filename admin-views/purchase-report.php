<?php

/**
 * Báo cáo mua hàng / Hàng trả nhà cung cấp (Mua hàng → BC_TK)
 *
 * Chi tiết từng dòng hàng đã nhập từ nhà cung cấp, kèm chiều ngược lại là hàng
 * trả lại nhà cung cấp.
 *
 * ⚠️ CHIỀU TIỀN NGƯỢC VỚI BÁO CÁO BÁN HÀNG: mua là mình CHI tiền, trả nhà cung
 * cấp là mình NHẬN lại tiền. Cột "Chi thuần" vì thế lấy mua trừ trả.
 *
 * Hai chiều đi hai đường khác nhau trong dữ liệu — xem
 * TGS_BCTK_Report::site_purchase_rows().
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
                <strong>Báo cáo mua hàng / Hàng trả nhà cung cấp</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <span class="bctk-daterange">
                    Loại
                    <select id="bctkPurchaseKind">
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
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-sku">Ngày tạo</th>
                        <th class="c-unit">ĐVCB</th>
                        <th class="c-name">Nhóm hàng</th>
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-sku">Số HĐ</th>
                        <th class="c-sku">Lý do</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num" title="Đơn giá nhập theo đơn vị nhỏ nhất, TRƯỚC thuế, trước chiết khấu">ĐG trước thuế</th>
                        <th class="c-num" title="Đơn giá theo đơn vị nhỏ nhất (giá 1 lẻ), đã gồm thuế">Đơn giá</th>
                        <th class="c-num" title="Tiền hàng trước chiết khấu, trước thuế">TT chưa CK</th>
                        <th class="c-num">Chiết khấu</th>
                        <th class="c-num" title="Tỉ lệ chiết khấu, suy từ tiền chiết khấu">CK(%)</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num" title="Chi thuần = tiền mua trừ tiền trả lại NCC. Dòng trả lại hiện số âm.">Chi thuần</th>
                        <th class="c-name">Nhà cung cấp</th>
                        <th class="c-sku">Mã NCC</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Mã NV</th>
                        <th class="c-name">Ghi chú</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-num">Thuế</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-num">SL ĐVMR</th>
                        <th class="c-num" title="Đơn giá theo đơn vị bán trên phiếu (lốc, thùng, vỉ...), đã gồm thuế">Đơn giá ĐVT</th>
                        <th class="c-num" title="Đơn giá sau chiết khấu, trước thuế — con số gửi cơ quan thuế">Giá Net</th>
                        <th class="c-sku">Số lô</th>
                        <th class="c-sku">EXPDATE</th>
                        <th class="c-num">TT trước thuế</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="31">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <?php
                /*
                 * Tổng số ô ở đây PHẢI bằng đúng 31 — số cột trong <thead>.
                 *
                 * Thiếu một ô là mọi con số phía sau chỗ hở bị đẩy sang trái
                 * một cột: số vẫn đúng nên nhìn lướt không thấy gì sai, chỉ
                 * đọc nhầm cột.
                 *
                 * Cộng cho khớp: 9 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 6 + 1 + 6 + 1 = 31
                 *
                 * Các cột đơn giá KHÔNG cộng tổng: cộng giá lại với nhau ra một
                 * con số vô nghĩa.
                 */
                ?>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="9">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td colspan="2"></td>
                        <td class="c-num" id="fChuaCk">0</td>
                        <td class="c-num" id="fCk">0</td>
                        <td></td>
                        <td class="c-num" id="fTien">0</td>
                        <td class="c-num" id="fChiThuan">0</td>
                        <td colspan="6"></td>
                        <td class="c-num" id="fThue">0</td>
                        <td colspan="6"></td>
                        <td class="c-num" id="fTruocThue">0</td>
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
        action: 'tgs_bctk_fetch_purchase_report',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,

        extraParams: function () {
            return { loai: document.getElementById('bctkPurchaseKind').value };
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

        /* Đổi loại là dữ liệu cũ không còn đúng nữa → chạy lại luôn cho khỏi
           nhìn nhầm số của loại trước */
        $('#bctkPurchaseKind').on('change', function () {
            if ($('.bctk-site:checked').length) $(document).trigger('bctk:search');
        });

        /* Chữ của từng cột — PHẢI khớp thứ tự cột trong <thead>. Base dùng hàm
           này cho cả lọc theo cột lẫn xuất Excel. */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.sku || '';
                case 2:  return r.ten || '';
                case 3:  return ngay(r.ngay);
                case 4:  return r.dvcb || '';
                case 5:  return r.nhom || '';
                case 6:  return r.pnk || '';
                case 7:  return r.so_hd || '';
                case 8:  return r.ly_do || '';
                case 9:  return fmt(r.qty);
                case 10: return fmt(r.gia_truoc_thue);
                case 11: return fmt(r.gia);
                case 12: return fmt(r.tt_chua_ck);
                case 13: return fmt(r.ck);
                case 14: return fmt(r.ck_pct);
                case 15: return fmt(r.tien);
                case 16: return fmt(r.chi_thuan);
                case 17: return r.ncc_ten || '';
                case 18: return r.ncc_ma || '';
                case 19: return r.nv_ten || '';
                case 20: return r.nv_ma || '';
                case 21: return r.ghi_chu || '';
                case 22: return r.tra_lai ? 'x' : '';
                case 23: return fmt(r.thue);
                case 24: return r.dvt || '';
                case 25: return fmt(r.sl_dvmr);
                case 26: return fmt(r.gia_dvt);
                case 27: return fmt(r.gia_net);
                case 28: return r.so_lo || '';
                case 29: return ngay(r.exp);
                case 30: return fmt(r.truoc_thue);
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '"' + (r.tra_lai ? ' class="bctk-row-return"' : '') + '>'
                + '<td class="c-zone">' + esc(r.kho)
                    + (r.no_zone ? ' <span class="bctk-warn" title="Dữ liệu chưa gán phân kho">chưa phân kho</span>' : '')
                + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten) + '">' + esc(r.ten) + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-unit">' + esc(r.dvcb) + '</td>'
                + '<td class="c-name" title="' + esc(r.nhom) + '">' + esc(r.nhom) + '</td>'
                + '<td class="c-sku">' + esc(r.pnk) + '</td>'
                + '<td class="c-sku">' + esc(r.so_hd) + '</td>'
                + '<td class="c-sku" title="' + esc(r.ly_do_ten) + '">' + esc(r.ly_do) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_truoc_thue) + '</td>'
                + '<td class="c-num">' + fmt(r.gia) + '</td>'
                + '<td class="c-num">' + fmt(r.tt_chua_ck) + '</td>'
                + '<td class="c-num">' + fmt(r.ck) + '</td>'
                + '<td class="c-num">' + fmt(r.ck_pct) + '</td>'
                + '<td class="c-num">' + fmt(r.tien) + '</td>'
                + '<td class="c-num' + (r.chi_thuan < 0 ? ' neg' : '') + '">' + fmt(r.chi_thuan) + '</td>'
                + '<td class="c-name" title="' + esc(r.ncc_ten) + '">' + esc(r.ncc_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.ncc_ma) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.nv_ma) + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.sl_dvmr) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_net) + '</td>'
                + '<td class="c-sku">' + esc(r.so_lo) + '</td>'
                + '<td class="c-sku">' + ngay(r.exp) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var t = { qty: 0, chuack: 0, ck: 0, tien: 0, chi: 0, thue: 0, truoc: 0 };
            rows.forEach(function (r) {
                t.qty   += (r.qty || 0);
                t.chuack += (r.tt_chua_ck || 0);
                t.ck    += (r.ck || 0);
                t.tien  += (r.tien || 0);
                t.chi   += (r.chi_thuan || 0);
                t.thue  += (r.thue || 0);
                t.truoc += (r.truoc_thue || 0);
            });
            $('#fQty').text(fmt(t.qty));
            $("#fChuaCk").text(fmt(t.chuack));
            $("#fCk").text(fmt(t.ck));
            $('#fTien').text(fmt(t.tien));
            $('#fChiThuan').text(fmt(t.chi)).toggleClass('neg', t.chi < 0);
            $('#fThue').text(fmt(t.thue));
            $('#fTruocThue').text(fmt(t.truoc));
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
