<?php

/**
 * Trang Sổ kho theo mặt hàng (BC_TK → Sổ kho)
 *
 * Dùng lại nguyên bộ lọc trái của báo cáo tồn kho (partials/filter-sidebar.php)
 * và toàn bộ phần chạy theo batch trong bctk-filter.js. Trang này chỉ đăng ký
 * thêm cách vẽ bảng của riêng nó qua TGSBctk.setRenderer().
 *
 * KHÔNG có cột Kho: báo cáo cộng dồn toàn bộ phạm vi đã lọc.
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
                <strong>Sổ kho theo mặt hàng</strong>
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
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-num col-open">Σ tồn đầu</th>
                        <th class="c-num col-in">Σ nhập</th>
                        <th class="c-num col-inb">Σ nhập nội bộ</th>
                        <th class="c-num col-ret">Σ xuất trả</th>
                        <th class="c-num col-sell">Σ xuất bán</th>
                        <th class="c-num col-outb">Σ xuất nội bộ</th>
                        <th class="c-num col-adj">Σ xuất điều chỉnh</th>
                        <th class="c-num col-adj">SL điều chỉnh (±)</th>
                        <th class="c-num col-close">Σ tồn cuối</th>
                        <th class="c-unit">ĐVT</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="12">Chọn chi nhánh bên trái rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="2">Tổng cộng</td>
                        <td class="c-num" id="fOpen">0</td>
                        <td class="c-num" id="fIn">0</td>
                        <td class="c-num" id="fInb">0</td>
                        <td class="c-num" id="fRet">0</td>
                        <td class="c-num" id="fSell">0</td>
                        <td class="c-num" id="fOutb">0</td>
                        <td class="c-num" id="fAdj">0</td>
                        <td class="c-num" id="fAdjS">0</td>
                        <td class="c-num" id="fClose">0</td>
                        <td></td>
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
        action: 'tgs_bctk_fetch_ledger',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>
    };

    jQuery(function ($) {
        var B = window.TGSBctk;
        if (!B || !B.setRenderer) return;

        /*
         * Cùng một mã hàng có thể xuất hiện ở nhiều chi nhánh. Báo cáo này cộng
         * dồn nên phải GỘP LẠI theo mã trước khi vẽ, nếu không sẽ ra nhiều dòng
         * cùng mã mà người xem không biết dòng nào là số thật.
         */
        function mergeBySku(rows) {
            var map = {}, order = [];

            rows.forEach(function (r) {
                var m = map[r.sku];
                if (!m) {
                    m = map[r.sku] = {
                        sku: r.sku, name: r.name, unit: r.unit,
                        ton_dau: 0, nhap: 0, nhap_nb: 0, xuat_tra: 0,
                        xuat_ban: 0, xuat_nb: 0, xuat_dc: 0, dc_signed: 0, ton_cuoi: 0
                    };
                    order.push(m);
                }
                ['ton_dau','nhap','nhap_nb','xuat_tra','xuat_ban','xuat_nb','xuat_dc','dc_signed','ton_cuoi']
                    .forEach(function (k) { m[k] += (r[k] || 0); });

                if (!m.name && r.name) { m.name = r.name; }
                if (!m.unit && r.unit) { m.unit = r.unit; }
            });

            return order;
        }

        var merged = [];

        B.setRenderer(function (rows) {
            merged = mergeBySku(rows);
            merged.sort(function (a, b) { return b.ton_cuoi - a.ton_cuoi; });

            // Thay dữ liệu gốc bằng bản đã gộp để phần cộng tổng dùng chung chỉ số
            B.setRowsSilent(merged);

            var html = merged.map(function (r, i) {
                function n(v, cls) {
                    return '<td class="c-num ' + (cls || '') + (v < 0 ? ' neg' : '') + '">'
                         + (v ? B.fmt(v) : '') + '</td>';
                }
                return '<tr data-i="' + i + '">'
                    + '<td class="c-sku">' + B.esc(r.sku) + '</td>'
                    + '<td class="c-name">' + B.esc(r.name) + '</td>'
                    + n(r.ton_dau, 'col-open')
                    + n(r.nhap, 'col-in')
                    + n(r.nhap_nb, 'col-inb')
                    + n(r.xuat_tra, 'col-ret')
                    + n(r.xuat_ban, 'col-sell')
                    + n(r.xuat_nb, 'col-outb')
                    + n(r.xuat_dc, 'col-adj')
                    + n(r.dc_signed, 'col-adj')
                    + n(r.ton_cuoi, 'col-close')
                    + '<td class="c-unit">' + B.esc(r.unit) + '</td>'
                    + '</tr>';
            }).join('');

            $('#bctkBody').html(html || '<tr class="bctk-empty"><td colspan="12">Không có phát sinh trong khoảng ngày đã chọn.</td></tr>');
        }, function (visible) {
            var t = { ton_dau:0, nhap:0, nhap_nb:0, xuat_tra:0, xuat_ban:0, xuat_nb:0, xuat_dc:0, dc_signed:0, ton_cuoi:0 };
            visible.forEach(function (r) {
                Object.keys(t).forEach(function (k) { t[k] += (r[k] || 0); });
            });

            $('#fOpen').text(B.fmt(t.ton_dau));
            $('#fIn').text(B.fmt(t.nhap));
            $('#fInb').text(B.fmt(t.nhap_nb));
            $('#fRet').text(B.fmt(t.xuat_tra));
            $('#fSell').text(B.fmt(t.xuat_ban));
            $('#fOutb').text(B.fmt(t.xuat_nb));
            $('#fAdj').text(B.fmt(t.xuat_dc));
            $('#fAdjS').text(B.fmt(t.dc_signed));
            $('#fClose').text(B.fmt(t.ton_cuoi));
        });
    });
</script>
