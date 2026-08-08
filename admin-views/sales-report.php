<?php

/**
 * Báo cáo bán hàng / Hàng bán trả lại (Bán hàng → BC_TK)
 *
 * Chi tiết từng dòng hàng đã bán, kèm chiều ngược lại là hàng khách trả.
 *
 * Bán và trả cùng treo dưới MỘT phiếu bán nên một lượt quét lo được cả hai —
 * xem TGS_BCTK_Report::site_sales_rows(). Ô chọn loại phía trên quyết định lấy
 * chiều nào; mặc định là phiếu bán vì đó là thứ hay xem nhất.
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
                <strong>Báo cáo bán hàng / Hàng bán trả lại</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <span class="bctk-daterange">
                    Loại
                    <select id="bctkSalesKind">
                        <option value="sale" selected>Phiếu bán hàng</option>
                        <option value="return">Hàng bán trả lại</option>
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
                        <th class="c-sku">Lý do</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num" title="Đơn giá theo đơn vị nhỏ nhất (giá 1 lẻ), đã gồm thuế">Đơn giá</th>
                        <th class="c-num">Chiết khấu</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Mã NV</th>
                        <th class="c-name">Khách hàng</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-sku">SĐT khách</th>
                        <th class="c-name">Ghi chú</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-num">Thuế</th>
                        <th class="c-num">Giá vốn</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-num">SL ĐVMR</th>
                        <th class="c-num" title="Đơn giá theo đơn vị bán trên phiếu (lốc, thùng, vỉ...), đã gồm thuế">Đơn giá ĐVT</th>
                        <th class="c-sku">Hình thức TT</th>
                        <th class="c-sku">Số lô</th>
                        <th class="c-sku">EXPDATE</th>
                        <th class="c-sku">Kênh bán hàng</th>
                        <th class="c-num">TT trước thuế</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="29">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <?php
                /*
                 * Tổng số ô ở đây PHẢI bằng đúng 29 — số cột trong <thead>.
                 *
                 * Thiếu một ô là mọi con số phía sau chỗ hở bị đẩy sang trái
                 * một cột: tổng "TT trước thuế" nằm dưới "Kênh bán hàng". Số
                 * vẫn đúng nên nhìn lướt không thấy gì sai, chỉ đọc nhầm cột.
                 *
                 * Cộng cho khớp: 8 + 1 + 1 + 1 + 1 + 7 + 1 + 8 + 1 = 29
                 *
                 * Hai cột đơn giá KHÔNG cộng tổng: cộng giá lại với nhau ra một
                 * con số vô nghĩa.
                 */
                ?>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="8">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td></td>
                        <td class="c-num" id="fCk">0</td>
                        <td class="c-num" id="fTien">0</td>
                        <td colspan="7"></td>
                        <td class="c-num" id="fThue">0</td>
                        <td colspan="8"></td>
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
        action: 'tgs_bctk_fetch_sales',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,

        /*
         * Tham số riêng của màn này, gửi kèm mọi lượt gọi.
         * bctk-filter.js đọc extraParams nên trang không phải tự viết lại phần
         * chạy theo batch.
         */
        extraParams: function () {
            return { loai: document.getElementById('bctkSalesKind').value };
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
        $('#bctkSalesKind').on('change', function () {
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
                case 6:  return r.pbh || '';
                case 7:  return r.ly_do || '';
                case 8:  return fmt(r.qty);
                case 9:  return fmt(r.gia);
                case 10: return fmt(r.ck);
                case 11: return fmt(r.tien);
                case 12: return r.nv_ten || '';
                case 13: return r.nv_ma || '';
                case 14: return r.kh_ten || '';
                case 15: return r.kh_ma || '';
                case 16: return r.kh_dt || '';
                case 17: return r.ghi_chu || '';
                case 18: return r.tra_lai ? 'x' : '';
                case 19: return fmt(r.thue);
                case 20: return r.gia_von || '';
                case 21: return r.dvt || '';
                case 22: return fmt(r.sl_dvmr);
                case 23: return fmt(r.gia_dvt);
                case 24: return r.httt || '';
                case 25: return r.so_lo || '';
                case 26: return ngay(r.exp);
                case 27: return r.kenh || '';
                case 28: return fmt(r.truoc_thue);
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
                + '<td class="c-sku">' + esc(r.pbh) + '</td>'
                + '<td class="c-sku">' + esc(r.ly_do) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.gia) + '</td>'
                + '<td class="c-num">' + fmt(r.ck) + '</td>'
                + '<td class="c-num">' + fmt(r.tien) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.nv_ma) + '</td>'
                + '<td class="c-name">' + esc(r.kh_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_ma) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_dt) + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-num">' + fmt(r.thue) + '</td>'
                + '<td class="c-num">' + esc(r.gia_von) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-num">' + fmt(r.sl_dvmr) + '</td>'
                + '<td class="c-num">' + fmt(r.gia_dvt) + '</td>'
                + '<td class="c-sku">' + esc(r.httt) + '</td>'
                + '<td class="c-sku">' + esc(r.so_lo) + '</td>'
                + '<td class="c-sku">' + ngay(r.exp) + '</td>'
                + '<td class="c-sku">' + esc(r.kenh) + '</td>'
                + '<td class="c-num">' + fmt(r.truoc_thue) + '</td>'
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var t = { qty: 0, ck: 0, tien: 0, thue: 0, truoc: 0 };
            rows.forEach(function (r) {
                t.qty   += (r.qty || 0);
                t.ck    += (r.ck || 0);
                t.tien  += (r.tien || 0);
                t.thue  += (r.thue || 0);
                t.truoc += (r.truoc_thue || 0);
            });
            $('#fQty').text(fmt(t.qty));
            $('#fCk').text(fmt(t.ck));
            $('#fTien').text(fmt(t.tien));
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
