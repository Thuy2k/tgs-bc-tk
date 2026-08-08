<?php

/**
 * Tổng hợp bán hàng (Bán hàng → BC_TK)
 *
 * Mỗi PHIẾU một dòng — khác màn "Báo cáo bán hàng" vốn soi từng mặt hàng.
 * Nhìn ở mức chứng từ để biết phiếu nào đã thu đủ, phiếu nào còn nợ.
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
                <strong>Tổng hợp bán hàng</strong>
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
                        <th class="c-sku">Số phiếu</th>
                        <th class="c-sku">Ngày lập</th>
                        <th class="c-sku">Lý do</th>
                        <th class="c-sku">Mã KH</th>
                        <th class="c-name">Tên KH</th>
                        <th class="c-sku">SĐT khách</th>
                        <th class="c-name">Nhân viên</th>
                        <th class="c-sku">Mã NV</th>
                        <th class="c-sku">Hình thức TT</th>
                        <th class="c-num">Tổng tiền</th>
                        <th class="c-num" title="Doanh thu thuần = tiền bán trừ tiền trả lại. Phiếu trả lại hiện số âm.">Doanh thu thuần</th>
                        <th class="c-num">Số trả</th>
                        <th class="c-num">Còn nợ</th>
                        <th class="c-unit">Trả lại</th>
                        <th class="c-name">Ghi chú</th>
                        <th class="c-sku">Kênh bán hàng</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="17">Chọn chi nhánh bên trái, chọn khoảng ngày rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <?php /* 10 + 1 + 1 + 1 + 1 + 3 = 17, khớp đúng số cột ở <thead> */ ?>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="10">Tổng cộng</td>
                        <td class="c-num" id="fTong">0</td>
                        <td class="c-num" id="fDoanhThu">0</td>
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
        action: 'tgs_bctk_fetch_sales_sum',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>,
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

        $('#bctkSalesKind').on('change', function () {
            if ($('.bctk-site:checked').length) $(document).trigger('bctk:search');
        });

        /* Chữ của từng cột — PHẢI khớp thứ tự cột trong <thead> */
        function cellText(r, col) {
            switch (col) {
                case 0:  return r.kho || '';
                case 1:  return r.pbh || '';
                case 2:  return ngay(r.ngay);
                case 3:  return r.ly_do || '';
                case 4:  return r.kh_ma || '';
                case 5:  return r.kh_ten || '';
                case 6:  return r.kh_dt || '';
                case 7:  return r.nv_ten || '';
                case 8:  return r.nv_ma || '';
                case 9:  return r.httt || '';
                case 10: return fmt(r.tong);
                case 11: return fmt(r.doanh_thu);
                case 12: return fmt(r.da_tra);
                case 13: return fmt(r.con_no);
                case 14: return r.tra_lai ? 'x' : '';
                case 15: return r.ghi_chu || '';
                case 16: return r.kenh || '';
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
                + '<td class="c-sku">' + esc(r.pbh) + '</td>'
                + '<td class="c-sku">' + ngay(r.ngay) + '</td>'
                + '<td class="c-sku">' + esc(r.ly_do) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_ma) + '</td>'
                + '<td class="c-name">' + esc(r.kh_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.kh_dt) + '</td>'
                + '<td class="c-name">' + esc(r.nv_ten) + '</td>'
                + '<td class="c-sku">' + esc(r.nv_ma) + '</td>'
                + '<td class="c-sku">' + esc(r.httt) + '</td>'
                + '<td class="c-num">' + fmt(r.tong) + '</td>'
                + '<td class="c-num' + (r.doanh_thu < 0 ? ' neg' : '') + '">' + fmt(r.doanh_thu) + '</td>'
                + '<td class="c-num">' + fmt(r.da_tra) + '</td>'
                + '<td class="' + noClass + '">' + fmt(r.con_no) + '</td>'
                + '<td class="c-unit">' + (r.tra_lai ? '&#10003;' : '') + '</td>'
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + '<td class="c-sku">' + esc(r.kenh) + '</td>'
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var t = { tong: 0, dt: 0, tra: 0, no: 0 };
            rows.forEach(function (r) {
                t.tong += (r.tong || 0);
                t.dt   += (r.doanh_thu || 0);
                t.tra  += (r.da_tra || 0);
                t.no   += (r.con_no || 0);
            });
            $('#fTong').text(fmt(t.tong));
            $('#fDoanhThu').text(fmt(t.dt)).toggleClass('neg', t.dt < 0);
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
