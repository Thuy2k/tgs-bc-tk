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

/*
 * Tạo PO đề nghị mua hàng — dùng lại nguyên endpoint của plugin
 * tgs_po_adjustment (action tgs_poa_create, đúng cái mà màn "Quét tồn thông
 * minh" đang gọi). Không viết lại chỗ ghi bảng để hai màn không bao giờ lệch
 * nhau về cách sinh mã phiếu, gom nhóm hay snapshot tồn.
 *
 * Endpoint đó yêu cầu manage_options — KHÁC với BC_TK (đang để 'read' cho dễ
 * phát triển). Cố ý giữ nguyên: xem báo cáo thì thoải mái, nhưng ghi phiếu ra
 * hệ thống thì vẫn phải là người có quyền. Ai không đủ quyền sẽ không thấy nút.
 */
$bctk_can_po   = class_exists('TGS_POA_Ajax') && current_user_can('manage_options');
$bctk_po_nonce = $bctk_can_po ? wp_create_nonce('tgs_poa_nonce') : '';
$bctk_po_list  = class_exists('TGS_POA_Menu')
    ? admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_LIST)
    : '';

/* "Nguồn phát sinh phiếu là website đang quét" — chính là site đang mở báo cáo */
$bctk_cur_bid  = (int) get_current_blog_id();
$bctk_cur_name = get_bloginfo('name');
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
            <?php if ($bctk_can_po) : ?>
                <button type="button" class="bctk-btn-po bctk-hidden" id="bctkBtnPo">
                    <i class="bx bx-cart-add"></i> Tạo PO đề nghị mua hàng
                </button>
            <?php endif; ?>
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

<?php if ($bctk_can_po) : ?>
    <?php
    /*
     * Modal soát lại trước khi ghi phiếu — dựng theo đúng mạch của
     * "Xem lại & chỉnh số lượng trước khi tạo PO" bên Quét tồn thông minh, để
     * người dùng quen tay một lần là dùng được cả hai màn.
     */
    ?>
    <div class="modal fade" id="bctkPoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bx bx-cart-add me-1"></i> Soát lại đề nghị mua hàng trước khi tạo PO
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        Loại đề xuất <b>Kho mua thêm</b> · nguồn phát sinh phiếu là
                        <b><?php echo esc_html($bctk_cur_name); ?></b> · không có nguồn chuyển hàng.
                        <br>Chỉnh lại <b>SL đề nghị</b> nếu cần (mặc định = gợi ý nhập).
                        Bỏ tick hoặc đặt SL = 0 để loại dòng đó khỏi phiếu.
                    </div>

                    <?php
                    /*
                     * Nguồn nhận hàng — chỉ đích danh chi nhánh sẽ nhận phiếu.
                     *
                     * Không chỉ để ghi cho biết: danh sách PO lọc mặc định theo
                     * (request_blog_id OR transfer_blog_id OR receive_blog_id) = site
                     * đang mở. Chọn ở đây nghĩa là chi nhánh đó MỞ RA LÀ THẤY phiếu,
                     * bỏ trống thì chỉ site lập phiếu nhìn thấy.
                     *
                     * Danh sách site lấy từ cấu hình phân cấp Multisite (giống bộ lọc
                     * trái), không phải wp_blogs — khỏi lòi ra site rác.
                     */
                    ?>
                    <div class="bctk-po-recv mb-3">
                        <label class="form-label fw-semibold mb-1" for="bctkPoRecv">Nguồn nhận hàng</label>
                        <select id="bctkPoRecv" class="form-select form-select-sm">
                            <option value="">— Không chọn (chỉ site lập phiếu nhìn thấy) —</option>
                        </select>
                        <div class="form-text">
                            Chọn chi nhánh sẽ nhận phiếu PO này. Chi nhánh được chọn sẽ thấy phiếu
                            trong danh sách PO của họ.
                        </div>
                    </div>

                    <div class="bctk-modal-tablewrap">
                        <table class="bctk-table bctk-modal-table" id="bctkPoTable">
                            <thead>
                                <tr>
                                    <th class="c-pick">
                                        <input class="form-check-input" type="checkbox" id="bctkPoAll" checked>
                                    </th>
                                    <th class="c-sku">Mã hàng</th>
                                    <th class="c-name">Tên hàng</th>
                                    <th class="c-sup">NCC gợi ý</th>
                                    <th class="c-num">Tồn cuối</th>
                                    <th class="c-num">Đi đường</th>
                                    <th class="c-num">Tồn min</th>
                                    <th class="c-num">Tồn max</th>
                                    <th class="c-num c-qty">SL đề nghị</th>
                                    <th class="c-note">Ghi chú dòng</th>
                                </tr>
                            </thead>
                            <tbody id="bctkPoBody"></tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold" for="bctkPoNote">Ghi chú chung (ghi vào phiếu)</label>
                        <textarea id="bctkPoNote" class="form-control" rows="3"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="me-auto small text-muted" id="bctkPoSummary">—</div>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-primary" id="bctkPoConfirm">
                        <i class="bx bx-check-double me-1"></i> Xác nhận tạo PO
                    </button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    window.TGS_BCTK = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_BCTK_Ajax::NONCE)); ?>',
        action: 'tgs_bctk_fetch_purchase',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>
    };

    window.TGS_BCTK_PO = {
        enabled: <?php echo $bctk_can_po ? 'true' : 'false'; ?>,
        nonce: <?php echo wp_json_encode($bctk_po_nonce); ?>,
        listUrl: <?php echo wp_json_encode($bctk_po_list); ?>,
        blogId: <?php echo (int) $bctk_cur_bid; ?>,
        blogName: <?php echo wp_json_encode($bctk_cur_name); ?>
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
            toggleSelUi(picked.length);
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

        /* Nút tạo PO chỉ hiện khi thực sự có dòng được chọn — không có gì để
           soát thì nút chỉ tổ gây bấm nhầm */
        function toggleSelUi(n) {
            $('#bctkBtnPo').toggleClass('bctk-hidden', n === 0);
        }

        /* Chỉ tích những dòng ĐANG HIỆN — đang lọc mà tích cả dòng ẩn thì người
           dùng không thấy mình vừa chọn thêm gì */
        $('#bctkPickAll').on('change', function () {
            var on = this.checked;
            $('#bctkBody tr:visible .bctk-pick').prop('checked', on);
            updateSelInfo();
        });

        $(document).on('change', '.bctk-pick', updateSelInfo);

        function selectedRows() {
            return $('.bctk-pick:checked').map(function () {
                return merged[parseInt($(this).closest('tr').attr('data-i'), 10)];
            }).get();
        }

        /* API để lượt sau gắn thêm hành động khác (xuất Excel riêng…) */
        window.TGSBctkPurchase = { getSelected: selectedRows };

        // ── Tạo PO đề nghị mua hàng ─────────────────────────────────────────

        var PO = window.TGS_BCTK_PO || {};
        if (!PO.enabled) { return; }

        var poRows = [];

        function poModal() {
            return bootstrap.Modal.getOrCreateInstance(document.getElementById('bctkPoModal'));
        }

        /*
         * Nạp select nguồn nhận một lần. Tách Kho / Cửa hàng thành hai nhóm vì
         * phiếu mua thêm hầu như luôn về kho — để lẫn vào danh sách 70 shop thì
         * mỗi lần lập phiếu lại phải đi tìm.
         */
        function fillRecvOptions() {
            var $s = $('#bctkPoRecv');
            if ($s.data('filled')) { return; }

            var sites = (window.TGS_BCTK && window.TGS_BCTK.sites) || [];
            var groups = [
                ['Kho', sites.filter(function (s) { return s.type === 'warehouse'; })],
                ['Cửa hàng', sites.filter(function (s) { return s.type !== 'warehouse'; })]
            ];

            groups.forEach(function (g) {
                if (!g[1].length) { return; }
                var $og = $('<optgroup>').attr('label', g[0]);
                g[1].forEach(function (s) {
                    $og.append($('<option>').val(s.blog_id).text(s.label || s.name));
                });
                $s.append($og);
            });

            $s.data('filled', true);
        }

        /*
         * Ghi chú chung tự sinh: ghi lại BỐI CẢNH quét (kỳ nào, bao nhiêu mã),
         * thứ mà đọc phiếu sau này không còn suy ra được. Khác hẳn ghi chú từng
         * dòng ở bảng ngoài — cái đó chỉ giải thích con số gợi ý, xem tại chỗ là
         * đủ, nên KHÔNG mang vào phiếu (đúng yêu cầu: mở modal thì xóa đi cho
         * đỡ rối). Người dùng vẫn sửa lại được trước khi tạo.
         */
        function buildCommonNote(rows) {
            var from = $('#bctkDateFrom').val() || '';
            var to   = $('#bctkDateTo').val() || '';
            var qty  = 0;
            rows.forEach(function (r) { qty += (r.need || 0); });

            return 'Đề nghị mua hàng lập từ Phân tích mua hàng (BC_TK)'
                 + ' · kỳ ' + from + ' → ' + to
                 + ' · ' + rows.length + ' mã · tổng SL đề nghị ' + B.fmt(qty)
                 + '.';
        }

        function renderPoRows() {
            var html = poRows.map(function (r, i) {
                return '<tr data-p="' + i + '">'
                    + '<td class="c-pick"><input class="form-check-input bctk-po-pick" type="checkbox" checked></td>'
                    + '<td class="c-sku">' + B.esc(r.sku) + '</td>'
                    + '<td class="c-name">' + B.esc(r.name) + '</td>'
                    + '<td class="c-sup">' + B.esc(r.sup_code || r.sup_name || '') + '</td>'
                    + '<td class="c-num">' + B.fmt(r.ton_cuoi) + '</td>'
                    + '<td class="c-num col-transit">' + (r.in_transit ? B.fmt(r.in_transit) : '') + '</td>'
                    + '<td class="c-num col-mm">' + (r.min ? B.fmt(r.min) : '') + '</td>'
                    + '<td class="c-num col-mm">' + (r.max ? B.fmt(r.max) : '') + '</td>'
                    + '<td class="c-num c-qty"><input type="number" class="form-control form-control-sm bctk-po-qty"'
                        + ' min="0" step="1" value="' + (r.need || 0) + '"></td>'
                    // Ghi chú dòng để TRỐNG có chủ đích — người dùng tự ghi nếu cần
                    + '<td class="c-note"><input type="text" class="form-control form-control-sm bctk-po-note"'
                        + ' placeholder="—"></td>'
                    + '</tr>';
            }).join('');

            $('#bctkPoBody').html(html);
            $('#bctkPoAll').prop('checked', true);
            updatePoSummary();
        }

        function recvPick() {
            var id  = parseInt($('#bctkPoRecv').val(), 10) || 0;
            var opt = $('#bctkPoRecv option:selected');
            return { id: id, name: id ? (opt.text() || '') : '' };
        }

        function collectPoItems() {
            var out = [];
            var recv = recvPick();
            $('#bctkPoBody tr').each(function () {
                var $tr = $(this);
                if (!$tr.find('.bctk-po-pick').prop('checked')) { return; }

                var r   = poRows[parseInt($tr.attr('data-p'), 10)];
                var qty = parseFloat($tr.find('.bctk-po-qty').val());
                if (!r || !(qty > 0)) { return; }

                out.push({
                    /*
                     * Loại đề xuất là "kho mua thêm", nguồn phát sinh là website
                     * đang quét, không có nguồn chuyển. Nguồn nhận do người lập
                     * chọn (có thể để trống).
                     *
                     * Endpoint gom nhóm theo intent|transfer|receive — cả rổ dùng
                     * chung một nguồn nhận nên rơi vào đúng MỘT phiếu.
                     */
                    intent: 'warehouse_purchase_more',
                    request_blog_id: PO.blogId,
                    request_blog_name: PO.blogName,
                    transfer_blog_id: 0,
                    transfer_blog_name: '',
                    receive_blog_id: recv.id,
                    receive_blog_name: recv.name,

                    sku: r.sku,
                    name: r.name,
                    quantity: qty,
                    current_stock: r.ton_cuoi || 0,
                    min_qty: r.min || 0,
                    max_qty: r.max || 0,
                    reason: $tr.find('.bctk-po-note').val() || ''
                });
            });
            return out;
        }

        function updatePoSummary() {
            var n = 0, qty = 0;
            $('#bctkPoBody tr').each(function () {
                var $tr = $(this);
                if (!$tr.find('.bctk-po-pick').prop('checked')) { return; }
                var v = parseFloat($tr.find('.bctk-po-qty').val());
                if (!(v > 0)) { return; }
                n++;
                qty += v;
            });
            var recv = recvPick();
            $('#bctkPoSummary').text(n
                ? ('Sẽ tạo 1 phiếu · ' + n + ' mã · tổng SL ' + B.fmt(qty)
                   + ' · nơi nhận: ' + (recv.id ? recv.name : 'để trống'))
                : 'Chưa có dòng nào hợp lệ');
            $('#bctkPoConfirm').prop('disabled', n === 0);
        }

        $('#bctkBtnPo').on('click', function () {
            poRows = selectedRows();
            if (!poRows.length) { return; }

            fillRecvOptions();
            renderPoRows();
            $('#bctkPoNote').val(buildCommonNote(poRows));
            poModal().show();
        });

        $('#bctkPoRecv').on('change', updatePoSummary);
        $(document).on('change', '.bctk-po-pick', updatePoSummary);
        $(document).on('input change', '.bctk-po-qty', updatePoSummary);

        $('#bctkPoAll').on('change', function () {
            $('#bctkPoBody .bctk-po-pick').prop('checked', this.checked);
            updatePoSummary();
        });

        $('#bctkPoConfirm').on('click', function () {
            var items = collectPoItems();
            if (!items.length) {
                alert('Không còn dòng nào hợp lệ (đã bỏ tick hoặc SL = 0).');
                return;
            }

            var $btn = $(this), old = $btn.html();
            $btn.prop('disabled', true)
                .html('<span class="spinner-border spinner-border-sm me-1"></span> Đang tạo...');

            $.post(window.TGS_BCTK.ajaxUrl, {
                action: 'tgs_poa_create',
                nonce: PO.nonce,
                items: JSON.stringify(items),
                note: $('#bctkPoNote').val() || ''
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    alert((resp && resp.data && resp.data.message) || 'Tạo PO thất bại.');
                    return;
                }
                var d = resp.data || {};
                poModal().hide();

                var code = (d.created && d.created[0]) ? d.created[0].code : '';
                var msg  = 'Đã tạo phiếu ' + (code || 'PO') + '.';

                if (PO.listUrl && confirm(msg + '\n\nMở danh sách PO ngay?')) {
                    window.open(PO.listUrl, '_blank');
                }
                // Bỏ tick sau khi tạo xong để không lỡ tay tạo trùng phiếu
                $('.bctk-pick').prop('checked', false);
                $('#bctkPickAll').prop('checked', false);
                updateSelInfo();
            }).fail(function () {
                alert('Không gọi được máy chủ. Thử lại giúp mình.');
            }).always(function () {
                $btn.prop('disabled', false).html(old);
            });
        });
    });
</script>
