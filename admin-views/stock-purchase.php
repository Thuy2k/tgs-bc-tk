<?php

/**
 * Trang Phân tích mua hàng (BC_TK → Phân tích mua hàng)
 *
 * Ghép ba nguồn đã có sẵn, không tính lại từ đầu:
 *   - Sổ kho          → tồn đầu, các cột phát sinh, tồn cuối
 *   - Báo cáo tồn kho → tồn max / tồn min (khai theo website)
 *   - Hàng đi đường   → phiếu nhập tự động còn chờ duyệt
 *
 * Cộng thêm hai thứ của riêng màn này: gợi ý số lượng cần mua, và ghi chú giải
 * thích vì sao ra con số đó.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_boot  = TGS_BCTK_Sites::filter_bootstrap();
$bctk_today = current_time('Y-m-d');
$bctk_first = current_time('Y-m-01');
?>

<div class="bctk-page" id="bctkPage">

    <?php include __DIR__ . '/partials/filter-sidebar.php'; ?>

    <section class="bctk-result">
        <div class="bctk-result__head">
            <div class="bctk-headline">
                <strong>Phân tích mua hàng</strong>
                <span class="bctk-daterange">
                    Từ
                    <input type="date" id="bctkDateFrom" value="<?php echo esc_attr($bctk_first); ?>">
                    đến
                    <input type="date" id="bctkDateTo" value="<?php echo esc_attr($bctk_today); ?>">
                </span>
                <span class="bctk-selinfo bctk-hidden" id="bctkSelInfo"></span>
            </div>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <div class="bctk-tablewrap">
            <table class="bctk-table" id="bctkTable">
                <thead>
                    <tr>
                        <th class="c-pick" data-ds-filter="off">
                            <input class="form-check-input" type="checkbox" id="bctkPickAll"
                                   title="Chọn / bỏ chọn toàn bộ dòng đang hiển thị">
                        </th>
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-sup">Mã NCC</th>
                        <th class="c-name">Tên NCC</th>

                        <th class="c-num col-open">Tồn đầu</th>
                        <th class="c-num col-in">Nhập</th>
                        <th class="c-num col-inret" title="Khách hoàn trả lại cửa hàng — tồn TĂNG">Nhập trả</th>
                        <th class="c-num col-inb">Nhập nội bộ</th>
                        <th class="c-num col-outb">Xuất nội bộ</th>
                        <th class="c-num col-sell">Xuất bán</th>
                        <th class="c-num col-ret" title="Kho trả hàng về nhà cung cấp — tồn GIẢM">Xuất trả</th>
                        <th class="c-num col-close">Tồn cuối</th>

                        <th class="c-num col-transit"
                            title="Phiếu nhập tự động còn chờ duyệt — hàng đã rời nơi gửi nhưng chưa nhận">SL đi đường</th>
                        <th class="c-num col-mm">Tồn max</th>
                        <th class="c-num col-mm">Tồn min</th>
                        <th class="c-num col-need">Gợi ý nhập</th>
                        <th class="c-note" data-ds-filter="off">Ghi chú</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="18">Chọn chi nhánh bên trái rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="5">Tổng cộng</td>
                        <td class="c-num" id="fOpen">0</td>
                        <td class="c-num" id="fIn">0</td>
                        <td class="c-num" id="fInRet">0</td>
                        <td class="c-num" id="fInb">0</td>
                        <td class="c-num" id="fOutb">0</td>
                        <td class="c-num" id="fSell">0</td>
                        <td class="c-num" id="fRet">0</td>
                        <td class="c-num" id="fClose">0</td>
                        <td class="c-num" id="fTransit">0</td>
                        <td class="c-num" id="fMax">0</td>
                        <td class="c-num" id="fMin">0</td>
                        <td class="c-num" id="fNeed">0</td>
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
        action: 'tgs_bctk_fetch_purchase',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>
    };

    jQuery(function ($) {
        var B = window.TGSBctk;
        if (!B || !B.setRenderer) return;

        var SUM = ['ton_dau','nhap','nhap_lai','nhap_nb','xuat_nb','xuat_ban','xuat_tra',
                   'ton_cuoi','in_transit','max','min'];

        /*
         * Gộp các chi nhánh về cùng một mã hàng.
         *
         * Tồn max/min cộng dồn theo website — mỗi site khai riêng nên tổng của
         * phạm vi đang lọc chính là tổng các mức đã khai.
         *
         * NCC gợi ý lấy cái ĐẦU TIÊN gặp: đây là thông tin tham khảo, gộp nhiều
         * site mà liệt kê hết NCC thì cột dài mà không giúp quyết định nhanh hơn.
         */
        function mergeBySku(rows) {
            var map = {}, order = [];

            rows.forEach(function (r) {
                var m = map[r.sku];
                if (!m) {
                    m = map[r.sku] = { sku: r.sku, name: r.name, unit: r.unit,
                                       sup_code: '', sup_name: '', has_minmax: false };
                    SUM.forEach(function (k) { m[k] = 0; });
                    order.push(m);
                }
                SUM.forEach(function (k) { m[k] += (r[k] || 0); });

                if (!m.name && r.name) { m.name = r.name; }
                if (!m.sup_code && r.sup_code) { m.sup_code = r.sup_code; m.sup_name = r.sup_name; }
                if (r.has_minmax) { m.has_minmax = true; }
            });

            /*
             * Gợi ý nhập PHẢI tính SAU khi gộp, không cộng gợi ý của từng site.
             * Site A thừa 10, site B thiếu 10 thì tổng nhu cầu thực là 0; cộng
             * gợi ý từng site sẽ ra 10 và mua thừa.
             */
            order.forEach(function (m) {
                var covered = m.ton_cuoi + m.in_transit;   // hàng đang có + đang về
                m.need = m.has_minmax ? Math.max(0, m.max - covered) : 0;
                m.note = buildNote(m, covered);
            });

            return order;
        }

        /* Ghi chú tự sinh — nói rõ vì sao ra con số gợi ý, đỡ phải tự nhẩm lại */
        function buildNote(m, covered) {
            if (!m.has_minmax) {
                return 'Chưa khai tồn max/min — không gợi ý được';
            }
            if (m.need > 0) {
                var s = 'Thiếu ' + B.fmt(m.need) + ' so với tồn max ' + B.fmt(m.max);
                if (m.in_transit > 0) {
                    s += ' (đã trừ ' + B.fmt(m.in_transit) + ' đang đi đường)';
                }
                if (m.min > 0 && m.ton_cuoi < m.min) {
                    s += ' · DƯỚI tồn min ' + B.fmt(m.min);
                }
                return s;
            }
            if (m.min > 0 && covered < m.min) {
                return 'Đủ so với max nhưng dưới tồn min ' + B.fmt(m.min);
            }
            var du = covered - m.max;
            return du > 0 ? 'Đủ hàng, dư ' + B.fmt(du) : 'Đủ hàng';
        }

        var merged = [];

        B.setRenderer(function (rows) {
            merged = mergeBySku(rows);
            // Thiếu nhiều nhất lên đầu — đó là việc cần xử lý trước
            merged.sort(function (a, b) { return b.need - a.need; });
            B.setRowsSilent(merged);

            var html = merged.map(function (r, i) {
                function n(v, cls) {
                    return '<td class="c-num ' + (cls || '') + (v < 0 ? ' neg' : '') + '">'
                         + (v ? B.fmt(v) : '') + '</td>';
                }
                return '<tr data-i="' + i + '"' + (r.need > 0 ? ' class="is-need"' : '') + '>'
                    + '<td class="c-pick"><input class="form-check-input bctk-pick" type="checkbox"'
                        + ' value="' + B.esc(r.sku) + '"></td>'
                    + '<td class="c-sku">' + B.esc(r.sku) + '</td>'
                    + '<td class="c-name">' + B.esc(r.name) + '</td>'
                    + '<td class="c-sup">' + B.esc(r.sup_code) + '</td>'
                    + '<td class="c-name">' + B.esc(r.sup_name) + '</td>'
                    + n(r.ton_dau, 'col-open')
                    + n(r.nhap, 'col-in')
                    + n(r.nhap_lai, 'col-inret')
                    + n(r.nhap_nb, 'col-inb')
                    + n(r.xuat_nb, 'col-outb')
                    + n(r.xuat_ban, 'col-sell')
                    + n(r.xuat_tra, 'col-ret')
                    + n(r.ton_cuoi, 'col-close')
                    + n(r.in_transit, 'col-transit')
                    + n(r.max, 'col-mm')
                    + n(r.min, 'col-mm')
                    + n(r.need, 'col-need')
                    + '<td class="c-note">' + B.esc(r.note) + '</td>'
                    + '</tr>';
            }).join('');

            $('#bctkBody').html(html || '<tr class="bctk-empty"><td colspan="18">Không có dữ liệu trong khoảng ngày đã chọn.</td></tr>');
            $('#bctkPickAll').prop('checked', false);
            updateSelInfo();
        }, function (visible) {
            var t = {};
            SUM.concat(['need']).forEach(function (k) { t[k] = 0; });
            visible.forEach(function (r) {
                SUM.concat(['need']).forEach(function (k) { t[k] += (r[k] || 0); });
            });

            $('#fOpen').text(B.fmt(t.ton_dau));
            $('#fIn').text(B.fmt(t.nhap));
            $('#fInRet').text(B.fmt(t.nhap_lai));
            $('#fInb').text(B.fmt(t.nhap_nb));
            $('#fOutb').text(B.fmt(t.xuat_nb));
            $('#fSell').text(B.fmt(t.xuat_ban));
            $('#fRet').text(B.fmt(t.xuat_tra));
            $('#fClose').text(B.fmt(t.ton_cuoi));
            $('#fTransit').text(B.fmt(t.in_transit));
            $('#fMax').text(B.fmt(t.max));
            $('#fMin').text(B.fmt(t.min));
            $('#fNeed').text(B.fmt(t.need));
        });

        // ── Chọn dòng ───────────────────────────────────────────────────────

        function updateSelInfo() {
            var picked = $('.bctk-pick:checked');
            if (!picked.length) {
                $('#bctkSelInfo').addClass('bctk-hidden');
                return;
            }
            var qty = 0;
            picked.each(function () {
                var r = merged[parseInt($(this).closest('tr').attr('data-i'), 10)];
                if (r) { qty += r.need; }
            });
            $('#bctkSelInfo')
                .removeClass('bctk-hidden')
                .text('Đã chọn ' + picked.length + ' mã · gợi ý nhập ' + B.fmt(qty));
        }

        /* Chỉ tích những dòng ĐANG HIỆN — đang lọc mà tích cả dòng ẩn thì người
           dùng không thấy mình vừa chọn thêm gì */
        $('#bctkPickAll').on('change', function () {
            var on = this.checked;
            $('#bctkBody tr:visible .bctk-pick').prop('checked', on);
            updateSelInfo();
        });

        $(document).on('change', '.bctk-pick', updateSelInfo);

        /* API để lượt sau gắn hành động (tạo PO, xuất Excel riêng…) */
        window.TGSBctkPurchase = {
            getSelected: function () {
                return $('.bctk-pick:checked').map(function () {
                    return merged[parseInt($(this).closest('tr').attr('data-i'), 10)];
                }).get();
            }
        };
    });
</script>
