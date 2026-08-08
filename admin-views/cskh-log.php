<?php

/**
 * Trang Sổ chăm sóc khách hàng (Bán hàng → BC_TK → Sổ CSKH)
 *
 * Bám theo sổ CSKH của phần mềm cũ, nhưng hơn ở hai điểm:
 *
 *   1. Phần mềm cũ mỗi lượt chỉ tra được MỘT shop. Ở đây dùng lại bộ lọc trái
 *      của BC_TK nên tra được nhiều chi nhánh / nhiều mã kho cùng lúc.
 *   2. Vì tra nhiều nơi cùng lúc nên có thêm cột KHO — nhìn một đống đơn mà
 *      không biết của shop nào thì tra cứu vô nghĩa.
 *
 * Dùng lại nguyên khung chạy theo batch trong bctk-filter.js; trang này chỉ
 * đăng ký cách vẽ bảng của riêng nó.
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
                <strong>Sổ chăm sóc khách hàng</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_today); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
            </div>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <div class="bctk-tablewrap">
            <table class="bctk-table" id="bctkTable">
                <thead>
                    <tr>
                        <th class="c-zone">Kho</th>
                        <th class="c-sku">PBH</th>
                        <th class="c-sku">Ngày mua</th>
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-num">SL</th>
                        <th class="c-unit">ĐVT</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-name">Tên KH</th>
                        <th class="c-sku">Điện thoại</th>
                        <th class="c-name">Địa chỉ</th>
                        <th class="c-sku">Ngày sinh bé</th>
                        <th class="c-name">Ghi chú</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="13">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <?php
                /*
                 * Chân bảng chỉ đếm số lượt mua và cộng số lượng.
                 *
                 * Sổ này để TRA CỨU từng lượt mua, không phải để cộng tiền, nên
                 * không dựng thêm cột tổng nào khác — bày ra chỉ tổ khiến người
                 * đọc tưởng đây là báo cáo doanh thu.
                 */
                ?>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="5">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td colspan="7"></td>
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
        action: 'tgs_bctk_fetch_cskh',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>
    };

    jQuery(function ($) {
        var B = window.TGSBctk;
        if (!B || !B.setRenderer) return;

        var esc = B.esc;
        var fmt = B.fmt;

        /* "2026-06-10 14:32:05" → "10/06/2026". Cắt bằng chuỗi chứ không qua
           Date(): chuỗi từ MySQL không có múi giờ, để Date() đọc thì máy ở múi
           khác sẽ lệch một ngày. */
        function ngay(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
            return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
        }

        /*
         * Chữ của từng cột — PHẢI khớp đúng thứ tự cột trong <thead>.
         *
         * Base dùng hàm này cho cả ba việc: vẽ dòng, lọc theo cột, và xuất
         * Excel. Khai một chỗ nên ba nơi không thể lệch nhau.
         */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.pbh || '';
                case 2:  return ngay(r.ngay);
                case 3:  return r.sku || '';
                case 4:  return r.ten || '';
                case 5:  return fmt(r.qty);
                case 6:  return r.dvt || '';
                case 7:  return r.kh_ma || '';
                case 8:  return r.kh_ten || '';
                case 9:  return r.kh_dt || '';
                case 10: return r.kh_dchi || '';
                case 11: return ngay(r.kh_ns);
                case 12: return r.ghi_chu || '';
                default: return '';
            }
        }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '">'
                + '<td class="c-zone">' + esc(r.kho)
                    + (r.no_zone ? ' <span class="bctk-warn" title="Dữ liệu chưa gán phân kho">chưa phân kho</span>' : '')
                + '</td>'
                + '<td class="c-sku">' + esc(r.pbh) + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten) + '">' + esc(r.ten) + '</td>'
                + '<td class="c-num">' + fmt(r.qty) + '</td>'
                + '<td class="c-unit">' + esc(r.dvt) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_ma) + '</td>'
                + '<td class="c-name">' + esc(r.kh_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_dt) + '</td>'
                + '<td class="c-name" title="' + esc(r.kh_dchi) + '">' + esc(r.kh_dchi) + '</td>'
                + '<td class="c-sku">' + ngay(r.kh_ns) + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var qty = 0;
            rows.forEach(function (r) { qty += (r.qty || 0); });
            $('#fQty').text(fmt(qty));
        }

        /*
         * Vẽ theo khung nhìn — sổ này có thể ra hàng trăm nghìn dòng khi quét
         * nhiều chi nhánh trong khoảng ngày rộng. Base lo cuộn, lọc và xuất
         * Excel; xem TGSDesignSystem.virtualBody.
         */
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
            /* Base gọi lại sau mỗi lần lọc — dòng tổng đã cộng trong onFilter,
               ở đây chỉ giữ cho số dòng hiển thị đúng */
            footer(viewRows);
        });
    });
</script>
