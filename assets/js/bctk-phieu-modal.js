/**
 * BC_TK — MODAL XEM / SỬA MỘT CHỨNG TỪ (component dùng chung).
 *
 * Bố cục bám PHẦN MỀM CŨ: thanh hành động + chứng từ ở TRÊN, dòng hàng ở GIỮA
 * (cuộn riêng), khách hàng / nhân viên / tổng cộng ở ĐÁY (3 cột). Modal gần
 * full màn, cao cố định để bố cục không nhảy.
 *
 * Gọi:  TGSBctkPhieuModal.open(payload, {
 *          title, note, actions,
 *          editable:      bool,   // cho phép Sửa GHI CHÚ (nút ✎ Sửa ghi chú)
 *          editableLines: bool,   // cho phép Sửa DÒNG HÀNG (mặc định = editable
 *                                 //   nếu bỏ trống). Đặt false khi phiếu đã có
 *                                 //   phiếu hoàn con: chỉ sửa ghi chú.
 *          lockLinesNote: string, // câu giải thích vì sao khoá dòng hàng
 *          actor: { id, name },   // người đang đăng nhập can thiệp phiếu (kế
 *                                 //   toán) — hiện ở khối "Nhân viên & ghi chú"
 *                                 //   và trong file Xuất Excel
 *          onSaveLines: fn(payload, lines, api),  // lưu dòng hàng
 *          onSaveNote:  fn(payload, noteText, api)// lưu ghi chú phiếu
 *        })
 *   Nút "⭳ Xuất Excel phiếu" luôn có sẵn (khi không ở chế độ sửa) — xuất .xls
 *   gồm khối chứng từ + bảng dòng hàng đang hiển thị.
 *   payload: dòng đã dựng ở server (TGS_BCTK_Ajax::build_vat_row), có `items`.
 *   actions[]: { id, label, cls, when(payload)->bool, run(payload, api) }
 *     api = { pdf, closePdf, toast, busy, close, applyRow(row), exitEdit() }
 *
 * SỬA DÒNG HÀNG: bấm "Sửa dòng hàng" → mỗi cột sửa được thành ô nhập, cộng
 * tổng cập nhật tạm ngay; thêm/xoá dòng; phím ↑ ↓ Enter đi giữa các dòng cùng
 * cột. Lưu → onSaveLines → server quy đổi qua TGS_Money, tính lại tổng phiếu,
 * trả về payload mới để modal cập nhật tại chỗ.
 *
 * Bảng dòng hàng gắn data-ds-no-* để Design System không chèn thanh lọc/cột.
 * Chia khối: renderToolbar / renderDoc / renderLines / renderFoot.
 */
(function ($) {
    'use strict';

    var built = false;
    var current = null;
    var opts = {};
    var editing = false;
    var editLines = [];     // bản làm việc khi đang sửa

    // ── bộ chọn sản phẩm (modal con, thay cho gõ tay trong ô) ──
    var pickMode = 'add';   // 'add' = thêm dòng mới · 'replace' = đổi dòng đang có
    var pickRow = -1;       // chỉ số dòng khi replace
    var pickItems = [];     // kết quả tìm hiện tại
    var pickIdx = -1;       // dòng đang chọn bằng bàn phím
    var pickTimer = null;
    var pickAdded = 0;      // đã thêm bao nhiêu (mode add)

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    var nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });
    function fmt(n) { return (n === null || n === undefined || n === '') ? '' : nf.format(n); }
    function money(n) { return (n === null || n === undefined || n === '') ? '0' : nf.format(n); }
    function num(v) { var n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? 0 : n; }
    function ngay(s, withTime) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m || m[1] === '0000') { return ''; }
        var d = m[3] + '/' + m[2] + '/' + m[1];
        return (withTime && m[4]) ? (d + ' ' + m[4] + ':' + m[5]) : d;
    }
    function ymd(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        return m ? (m[1] + '-' + m[2] + '-' + m[3]) : '';
    }
    function rate(v) {
        if (v === null || v === undefined || v === '') { return ''; }
        if (v === 'KCT' || v === 'Chưa khai') { return v; }
        return v + '%';
    }
    function stateCls(s) {
        s = String(s || '');
        if (s === 'done' || s === 'issued') { return 'pm-badge--ok'; }
        if (['issue_error', 'cqt_error', 'validate_error', 'error'].indexOf(s) !== -1) { return 'pm-badge--err'; }
        if (['pending', 'processing', 'blocked'].indexOf(s) !== -1) { return 'pm-badge--wait'; }
        return 'pm-badge--none';
    }
    function dash(v) { return (v === 0 || v) && String(v).trim() !== '' ? String(v) : '—'; }

    /* Tính tạm tiền một dòng theo giá trị kế toán vừa gõ.
       ln.sl = SL theo ĐVT bán · ln.don_gia = giá 1 ĐVT (đã gồm thuế, trước CK)
       ln.ck = chiết khấu cả dòng (đã gồm thuế) · ln.ratio = số ĐVCB / 1 ĐVT.
       Server mới là nơi chốt (TGS_Money::from_pos) — đây chỉ để hiện ngay. */
    function calcLine(ln) {
        var ratio = num(ln.ratio) || 1;
        var qtyBase = num(ln.sl) * ratio;
        var pct = (ln.thue_pct === 'KCT' || ln.thue_pct === '' || ln.thue_pct == null) ? 0 : num(ln.thue_pct);
        var heso = 1 + pct / 100;
        var priceBase = (num(ln.don_gia) / ratio) / heso;
        var disc = num(ln.ck) / heso;
        var sauCk = qtyBase * priceBase - disc;
        var thue = Math.round(sauCk * pct / 100);
        var tt = Math.round(sauCk + thue);
        return { qtyBase: qtyBase, tien_chua_thue: tt - thue, tien_thue: thue, thanh_tien: tt };
    }

    // ── Dựng khung một lần ───────────────────────────────────────────────────
    function build() {
        if (built) { return; }
        built = true;

        var html =
        '<div class="pm-modal bctk-hidden" id="pmModal" aria-hidden="true">'
        + '<div class="pm-modal__overlay" data-pm-close></div>'
        + '<div class="pm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="pmTitle">'

        +   '<header class="pm-modal__head">'
        +     '<div class="pm-modal__title">'
        +       '<strong id="pmTitle">Chi tiết chứng từ</strong>'
        +       '<span class="pm-modal__code" id="pmCode"></span>'
        +       '<span class="pm-badge" id="pmState"></span>'
        +       '<span class="pm-tag pm-tag--z bctk-hidden" id="pmZ">BILL Z — nội bộ</span>'
        +       '<span class="pm-tag pm-tag--edit bctk-hidden" id="pmEditTag">ĐANG SỬA</span>'
        +     '</div>'
        +     '<button type="button" class="pm-modal__x" data-pm-close title="Đóng (Esc)">&times;</button>'
        +   '</header>'

        +   '<div class="pm-toolbar">'
        +     '<div class="pm-actions" id="pmActions"></div>'
        +     '<div class="pm-actions__msg" id="pmActionMsg"></div>'
        +     '<button type="button" class="bctk-btn pm-toolbar__close" data-pm-close>Đóng</button>'
        +   '</div>'

        +   '<div class="pm-doc">'
        +     '<div class="pm-doc__grid" id="pmDocGrid"></div>'
        +     '<div class="pm-doc__seller" id="pmSeller"></div>'
        +   '</div>'

        +   '<div class="pm-lines" data-ds-no-export data-ds-no-colcfg data-ds-no-filter>'
        +     '<table class="pm-lines__table" data-ds-no-grid data-ds-no-export data-ds-no-colcfg data-ds-no-filter>'
        // <colgroup> bề ngang mặc định — header & body luôn thẳng hàng dù vẽ lại
        // liên tục lúc sửa. VẪN kéo giãn cột được: DS chỉnh cả <col> lẫn <th>
        // (setColElementWidth) nên colgroup + kéo cột chạy chung được.
        +       '<colgroup>'
        +         '<col style="width:46px"><col style="width:118px"><col style="width:220px">'
        +         '<col style="width:52px"><col style="width:116px"><col style="width:64px">'
        +         '<col style="width:78px"><col style="width:104px"><col style="width:92px">'
        +         '<col style="width:112px"><col style="width:82px"><col style="width:92px">'
        +         '<col style="width:104px"><col style="width:96px"><col style="width:130px">'
        +         '<col style="width:220px">'
        +       '</colgroup>'
        +       '<thead><tr>'
        +         '<th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>Kho</th><th>ĐVT</th>'
        +         '<th class="c-num">SL</th><th class="c-num">SL ĐVCB</th>'
        +         '<th class="c-num" title="Đơn giá BÁN cho khách theo ĐVT — ĐÃ GỒM THUẾ, TRƯỚC chiết khấu (đúng như giá trên bill POS)">ĐG bán</th>'
        +         '<th class="c-num" title="Chiết khấu tiền, CẢ DÒNG, tính trên giá đã gồm thuế">CK</th>'
        +         '<th class="c-num" title="Tiền hàng sau chiết khấu, trước thuế">TT chưa thuế</th>'
        +         '<th class="c-num" title="Chỉ để tham khảo — kế toán không sửa tay, tự lấy theo cấu hình mã hàng">Thuế suất</th>'
        +         '<th class="c-num">Tiền thuế</th><th class="c-num">Thành tiền</th>'
        +         '<th>Số lô</th><th>EXP</th><th>Ghi chú</th>'
        +       '</tr></thead>'
        +       '<tbody id="pmLinesBody"></tbody>'
        +       '<tfoot id="pmLinesFoot"></tfoot>'
        +     '</table>'
        +   '</div>'

        +   '<p class="pm-note bctk-hidden" id="pmNote"></p>'

        +   '<div class="pm-foot3">'
        +     '<section class="pm-foot3__box">'
        +       '<h4>Thông tin khách hàng</h4>'
        +       '<dl id="pmCust"></dl>'
        +     '</section>'
        +     '<section class="pm-foot3__box">'
        +       '<div class="pm-foot3__boxhead"><h4>Nhân viên &amp; ghi chú</h4>'
        +         '<button type="button" class="pm-linkbtn bctk-hidden" id="pmNoteEdit">✎ Sửa ghi chú</button></div>'
        +       '<dl id="pmStaff"></dl>'
        +       '<div class="pm-foot3__note" id="pmOrderNote"></div>'
        +     '</section>'
        +     '<section class="pm-foot3__box pm-foot3__total">'
        +       '<h4>Tổng cộng</h4>'
        +       '<div class="pm-total__row"><span>Tiền hàng (chưa thuế)</span><b id="pmTotBefore">0</b></div>'
        +       '<div class="pm-total__row"><span>Tiền thuế</span><b id="pmTotTax">0</b></div>'
        +       '<div class="pm-total__row"><span>Chiết khấu</span><b id="pmTotCk">0</b></div>'
        +       '<div class="pm-total__row pm-total__row--grand"><span>Tổng thanh toán</span><b id="pmTotGrand">0</b></div>'
        +       '<div class="pm-total__words" id="pmTotWords"></div>'
        +     '</section>'
        +   '</div>'

        +   '<div class="pm-pdf bctk-hidden" id="pmPdf">'
        +     '<div class="pm-pdf__head"><span id="pmPdfName"></span>'
        +       '<button type="button" class="pm-modal__x" data-pm-pdf-close title="Đóng PDF">&times;</button></div>'
        +     '<iframe id="pmPdfFrame" title="PDF hoá đơn" src="about:blank"></iframe>'
        +   '</div>'

        +   '<div class="pm-pick bctk-hidden" id="pmPick">'
        +     '<div class="pm-pick__card">'
        +       '<div class="pm-pick__head">'
        +         '<strong id="pmPickTitle">Thêm sản phẩm</strong>'
        +         '<span class="pm-pick__count" id="pmPickCount"></span>'
        +         '<button type="button" class="pm-modal__x" id="pmPickClose" title="Đóng">&times;</button>'
        +       '</div>'
        +       '<div class="pm-pick__bar">'
        +         '<input type="text" id="pmPickSearch" autocomplete="off" '
        +           'placeholder="Gõ mã hàng / tên hàng / barcode…">'
        +       '</div>'
        +       '<div class="pm-pick__wrap">'
        +         '<table class="pm-pick__table" data-ds-no-grid data-ds-no-resize '
        +           'data-ds-no-export data-ds-no-colcfg data-ds-no-filter><thead><tr>'
        +           '<th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th>'
        +           '<th class="c-num">Giá</th><th>Thuế</th>'
        +         '</tr></thead><tbody id="pmPickBody"></tbody></table>'
        +       '</div>'
        +       '<div class="pm-pick__foot">'
        +         '<span class="pm-pick__hint">↑ ↓ chọn · Enter / bấm dòng để thêm</span>'
        +         '<button type="button" class="bctk-btn bctk-btn--primary" id="pmPickDone">Xong</button>'
        +       '</div>'
        +     '</div>'
        +   '</div>'
        + '</div></div>';

        $('body').append(html);

        /*
         * ─── CẢNH BÁO TRƯỚC "SỬA DÒNG HÀNG" ──────────────────────────────────
         *
         * Từ khi BTsoft lên chính (xem docs go-live), kế toán KHÔNG còn được
         * sửa dòng hàng của phiếu xuất bán trên phần mềm nữa — việc đó làm bên
         * BTsoft. Chỉ còn "Tách / chuyển bill Z" (tách sản phẩm không tiền ra
         * khỏi phiếu để hoãn gửi thuế) là còn dùng.
         *
         * KHÔNG khoá nút: giữ nguyên chức năng sửa (phòng khi sau này vẫn cần),
         * chỉ chặn một nhịp cảnh báo đỏ trước khi vào sửa — bấm "Vẫn sửa dòng
         * hàng" là vào y như cũ.
         */
        var warnHtml =
        '<div class="pm-warn bctk-hidden" id="pmEditWarn" aria-hidden="true">'
        + '<div class="pm-warn__overlay" data-pm-warn-close></div>'
        + '<div class="pm-warn__panel" role="alertdialog" aria-modal="true" aria-labelledby="pmEditWarnTitle">'
        +   '<div class="pm-warn__head">'
        +     '<strong id="pmEditWarnTitle">⚠ Không còn hỗ trợ sửa dòng hàng</strong>'
        +   '</div>'
        +   '<div class="pm-warn__body">'
        +     'Toàn hệ thống đã chuyển sang <b>BTsoft</b> — <b>sửa dòng hàng của phiếu xuất bán không còn được hỗ trợ</b> nữa,'
        +     ' ở màn này lẫn mọi màn khác.'
        +     '<br><br>'
        +     'Ở đây chỉ còn dùng để <b>tách sản phẩm không có tiền</b> (hàng khuyến mãi, mã Z) ra khỏi phiếu —'
        +     ' để phiếu đó <b>hoãn gửi thuế</b> khi CHƯA gửi hoá đơn.'
        +     '<br><br>'
        +     'Nút sửa dòng hàng vẫn giữ lại đây (phòng khi cần), nhưng không thuộc quy trình hiện tại.'
        +   '</div>'
        +   '<div class="pm-warn__pw">'
        +     '<label for="pmEditWarnPw">Vẫn muốn sửa? Nhập mật khẩu để tiếp tục</label>'
        +     '<input type="password" id="pmEditWarnPw" autocomplete="off" placeholder="Mật khẩu">'
        +     '<div class="pm-warn__pwerr bctk-hidden" id="pmEditWarnPwErr">Sai mật khẩu.</div>'
        +   '</div>'
        +   '<div class="pm-warn__foot">'
        +     '<button type="button" class="bctk-btn" data-pm-warn-close>Đóng</button>'
        +     '<button type="button" class="bctk-btn bctk-btn--danger" id="pmEditWarnProceed">Vẫn sửa dòng hàng</button>'
        +   '</div>'
        + '</div></div>';
        $('body').append(warnHtml);

        var $m = $('#pmModal');
        $m.on('click', '[data-pm-close]', function () { if (!editing) { close(); } else { cancelEdit(); } });
        $m.on('click', '[data-pm-pdf-close]', closePdf);
        $m.on('click', '[data-pm-action]', function () {
            var id = $(this).data('pm-action');
            var a = (opts.actions || []).filter(function (x) { return x.id === id; })[0];
            if (a && typeof a.run === 'function') { a.run(current, api); }
        });
        $m.on('click', '#pmEditStart', openEditWarn);
        $(document).on('click', '#pmEditWarn [data-pm-warn-close]', closeEditWarn);
        $(document).on('click', '#pmEditWarnProceed', tryProceedEditWarn);
        $(document).on('keydown', '#pmEditWarnPw', function (e) {
            $('#pmEditWarnPwErr').addClass('bctk-hidden');
            if (e.key === 'Enter') { e.preventDefault(); tryProceedEditWarn(); }
        });
        $m.on('click', '#pmEditSave', saveEdit);
        $m.on('click', '#pmEditCancel', cancelEdit);
        $m.on('click', '#pmEditAdd', function () { openPick('add', -1); });
        $m.on('click', '.pm-line__del', function () { delLine($(this).data('i')); });
        $m.on('input change', '.pm-line__in', onLineInput);
        $m.on('keydown', '.pm-line__in', onLineKey);
        $m.on('click', '#pmNoteEdit', startNoteEdit);
        $m.on('click', '#pmExport', exportPhieu);

        // ── chọn sản phẩm từ danh mục (thay cho gõ tay + gợi ý trong ô) ──
        $m.on('click', '.pm-pick-cell', function () {
            openPick('replace', parseInt($(this).data('i'), 10));
        });
        $m.on('click', '#pmPickClose, #pmPickDone', closePick);
        $m.on('input', '#pmPickSearch', schedulePickSearch);
        $m.on('keydown', '#pmPickSearch', onPickKey);
        $m.on('click', '#pmPickBody tr[data-i]', function () {
            pickChoose(pickItems[parseInt(this.getAttribute('data-i'), 10)]);
        });

        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            if (!$('#pmEditWarn').hasClass('bctk-hidden')) { closeEditWarn(); return; }
            if ($m.hasClass('bctk-hidden')) { return; }
            if (!$('#pmPick').hasClass('bctk-hidden')) { closePick(); }
            else if (!$('#pmPdf').hasClass('bctk-hidden')) { closePdf(); }
            else if (editing) { cancelEdit(); }
            else { close(); }
        });
    }

    /* Cảnh báo trước khi vào sửa dòng hàng — xem chú thích ở build(). */
    // Chỉ để cản bấm nhầm — KHÔNG phải hàng rào bảo mật (mật khẩu nằm ngay
    // trong JS gửi về trình duyệt, ai xem mã nguồn cũng đọc được).
    var EDIT_WARN_PASSWORD = 'Thuy!@#';

    function openEditWarn() {
        $('#pmEditWarnPw').val('');
        $('#pmEditWarnPwErr').addClass('bctk-hidden');
        $('#pmEditWarn').removeClass('bctk-hidden').attr('aria-hidden', 'false');
        setTimeout(function () { $('#pmEditWarnPw').trigger('focus'); }, 30);
    }
    function closeEditWarn() { $('#pmEditWarn').addClass('bctk-hidden').attr('aria-hidden', 'true'); }
    function tryProceedEditWarn() {
        var pw = String($('#pmEditWarnPw').val() || '');
        if (pw !== EDIT_WARN_PASSWORD) {
            $('#pmEditWarnPwErr').removeClass('bctk-hidden');
            $('#pmEditWarnPw').trigger('select');
            return;
        }
        closeEditWarn();
        startEdit();
    }

    // ── render: đầu / chứng từ ─────────────────────────────────────────────
    function kv(k, v, cls) {
        return '<div class="pm-f' + (cls ? ' ' + cls : '') + '"><span class="pm-f__k">' + esc(k)
            + '</span><span class="pm-f__v">' + esc(dash(v)) + '</span></div>';
    }

    function renderToolbar(r) {
        var acts = (opts.actions || []).filter(function (a) {
            return typeof a.when !== 'function' || a.when(r);
        }).map(function (a) {
            return '<button type="button" class="bctk-btn' + (a.cls ? ' bctk-btn--' + a.cls : '')
                + '" data-pm-action="' + esc(a.id) + '">' + esc(a.label) + '</button>';
        });

        // Cho sửa DÒNG HÀNG: ưu tiên cờ editableLines; nếu thiếu (component
        // dùng ở màn khác) thì fallback về editable như trước.
        var canLines = (typeof opts.editableLines === 'boolean' ? opts.editableLines : !!opts.editable);
        if (canLines && typeof opts.onSaveLines === 'function') {
            if (editing) {
                acts.push('<span class="pm-actions__sep"></span>');
                acts.push('<button type="button" class="bctk-btn" id="pmEditAdd">+ Thêm sản phẩm</button>');
                acts.push('<button type="button" class="bctk-btn bctk-btn--primary" id="pmEditSave">💾 Lưu</button>');
                acts.push('<button type="button" class="bctk-btn" id="pmEditCancel">Huỷ</button>');
            } else {
                acts.push('<button type="button" class="bctk-btn" id="pmEditStart">✎ Sửa dòng hàng</button>');
            }
        }

        if (!editing) {
            if (acts.length) { acts.push('<span class="pm-actions__sep"></span>'); }
            acts.push('<button type="button" class="bctk-btn" id="pmExport">⭳ Xuất Excel phiếu</button>');
        }

        $('#pmActions').html(acts.join(''));
        $('#pmActionMsg').text('').removeAttr('data-type');
        if (!editing && !canLines && opts.lockLinesNote) {
            $('#pmActionMsg').text('🔒 ' + opts.lockLinesNote).attr('data-type', 'warn');
        }
        $('#pmEditTag').toggleClass('bctk-hidden', !editing);
    }

    function renderDoc(r) {
        $('#pmDocGrid').html([
            kv('Lý do', r.ly_do), kv('Kho / Mã shop', r.ma_shop),
            kv('Số phiếu xuất', r.so_phieu_xuat), kv('Ngày xuất', ngay(r.ngay_xuat, true)),
            kv('Số hóa đơn', r.so_hd), kv('Seri', r.seri), kv('Mẫu HĐ', r.mau_hd),
            kv('Ngày hóa đơn', ngay(r.ngay_hd, true)), kv('Hình thức TT', r.httt),
            kv('Trạng thái VAT', r.trang_thai_vat), kv('Tỷ lệ thuế', rate(r.ty_le_thue)),
            kv('SL bản ghi', (r.items || []).length)
        ].join(''));

        $('#pmSeller').html(
            '<span class="pm-doc__seller-k">ĐƠN VỊ BÁN HÀNG</span>'
            + '<span class="pm-doc__seller-v">' + esc(dash(r.ten_cty_ban))
            + ' <i>·</i> MST ' + esc(dash(r.mst_ban))
            + ' <i>·</i> ' + esc(dash(r.dchi_ban))
            + ' <i>·</i> ĐT ' + esc(dash(r.dt_ban)) + '</span>'
        );
    }

    // ── render: dòng hàng ─────────────────────────────────────────────────
    /* Thuế suất KHÔNG cho sửa tay: dòng cũ theo item đã lưu, dòng mới tự lấy
       theo cấu hình mã hàng (Cấu hình → Hàng hoá → Quản lý thuế suất). */
    function taxDisplay(v) {
        if (v === 'KCT') { return 'KCT'; }
        if (v === 'Chưa khai') { return 'Chưa khai'; }
        if (v === '' || v == null) { return '—'; }
        return (num(v) || 0) + '%';
    }

    function renderLines(r) {
        if (!editing) {
            var body = (r.items || []).map(function (it) {
                return '<tr' + (it.is_gift ? ' class="pm-lines__gift"' : '') + '>'
                    + '<td>' + esc(it.stt) + '</td>'
                    + '<td>' + esc(it.ma_hang || '') + '</td>'
                    + '<td>' + esc(it.ten || '') + (it.is_gift ? ' <em>(KM)</em>' : '') + '</td>'
                    + '<td>' + esc(it.kho || '') + '</td>'
                    + '<td>' + esc(it.dvt || '') + '</td>'
                    + '<td class="c-num">' + fmt(it.sl) + '</td>'
                    + '<td class="c-num">' + fmt(it.sl_dvcb) + '</td>'
                    + '<td class="c-num">' + fmt(it.don_gia) + '</td>'
                    + '<td class="c-num">' + fmt(it.ck) + '</td>'
                    + '<td class="c-num">' + fmt(it.tien_chua_thue) + '</td>'
                    + '<td class="c-num">' + esc(rate(it.thue_suat)) + '</td>'
                    + '<td class="c-num">' + fmt(it.tien_thue) + '</td>'
                    + '<td class="c-num">' + fmt(it.thanh_tien) + '</td>'
                    + '<td>' + esc(it.so_lo || '') + '</td>'
                    + '<td>' + esc(ngay(it.exp)) + '</td>'
                    + '<td>' + esc(it.ghi_chu || '') + '</td>'
                    + '</tr>';
            }).join('');
            if (!body) { body = '<tr><td colspan="16" class="pm-lines__empty">Phiếu không có dòng hàng nào.</td></tr>'; }
            $('#pmLinesBody').html(body);
            renderLinesFoot(r);
            return;
        }

        // ── chế độ SỬA ──
        var rows = editLines.map(function (ln, i) {
            if (ln._del) { return ''; }
            var c = calcLine(ln);
            function inp(col, v, extra) {
                return '<input class="pm-line__in" data-col="' + col + '" data-i="' + i + '" '
                    + (extra || '') + ' value="' + esc(v == null ? '' : v) + '">';
            }
            function dvtCell() {
                var us = ln.units || [];
                if (!us.length) { return inp('dvt', ln.dvt); }
                return '<select class="pm-line__in" data-col="dvt" data-i="' + i + '">'
                    + us.map(function (u) {
                        return '<option value="' + esc(u.unit) + '"'
                            + (u.unit === ln.dvt ? ' selected' : '') + '>' + esc(u.unit)
                            + (u.is_default ? ' ★' : '') + '</option>';
                    }).join('') + '</select>';
            }
            return '<tr data-i="' + i + '">'
                + '<td><button type="button" class="pm-line__del" data-i="' + i + '" title="Xoá dòng">✕</button> ' + (i + 1) + '</td>'
                // Mã hàng + Tên hàng CHỈ HIỂN THỊ — bấm để chọn từ danh mục (không gõ tay)
                + '<td class="pm-ro pm-pick-cell" data-i="' + i + '" title="Bấm để chọn sản phẩm khác">'
                    + (ln.ma_hang ? esc(ln.ma_hang) : '<span class="pm-pick-cell__ph">🔍 chọn…</span>') + '</td>'
                + '<td class="pm-ro" title="' + esc(ln.ten || '') + '">' + esc(ln.ten || '—') + '</td>'
                + '<td>' + esc(ln.kho || current.ma_shop || '') + '</td>'
                + '<td>' + dvtCell() + '</td>'
                + '<td class="c-num">' + inp('sl', ln.sl, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num pm-ro" data-out="qtyBase">' + fmt(c.qtyBase) + '</td>'
                + '<td class="c-num">' + inp('don_gia', ln.don_gia, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num">' + inp('ck', ln.ck, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num pm-ro" data-out="tien_chua_thue">' + fmt(c.tien_chua_thue) + '</td>'
                + '<td class="c-num pm-ro" title="Tự lấy theo cấu hình mã hàng — không sửa tay">'
                    + esc(taxDisplay(ln.thue_pct)) + '</td>'
                + '<td class="c-num pm-ro" data-out="tien_thue">' + fmt(c.tien_thue) + '</td>'
                + '<td class="c-num pm-ro" data-out="thanh_tien">' + fmt(c.thanh_tien) + '</td>'
                + '<td>' + inp('so_lo', ln.so_lo) + '</td>'
                + '<td>' + inp('exp', ymd(ln.exp), 'type="date"') + '</td>'
                + '<td>' + inp('ghi_chu', ln.ghi_chu) + '</td>'
                + '</tr>';
        }).join('');
        $('#pmLinesBody').html(rows || '<tr><td colspan="16" class="pm-lines__empty">Bấm "+ Thêm sản phẩm".</td></tr>');
        renderEditFoot();
    }

    function renderLinesFoot(r) {
        $('#pmLinesFoot').html(
            '<tr>'
            + '<td colspan="8">Tổng cộng (' + (r.items || []).length + ' dòng)</td>'
            + '<td class="c-num">' + fmt(r.tong_ck) + '</td>'
            + '<td class="c-num">' + fmt(r.tt_chua_thue) + '</td>'
            + '<td></td>'
            + '<td class="c-num">' + fmt(r.tong_thue) + '</td>'
            + '<td class="c-num">' + fmt(r.thanh_tien) + '</td>'
            + '<td colspan="3"></td>'
            + '</tr>'
        );
    }

    function renderEditFoot() {
        var t = { ck: 0, before: 0, tax: 0, grand: 0, n: 0 };
        editLines.forEach(function (ln) {
            if (ln._del) { return; }
            var c = calcLine(ln);
            t.n++;
            t.ck += num(ln.ck);
            t.before += c.tien_chua_thue;
            t.tax += c.tien_thue;
            t.grand += c.thanh_tien;
        });
        $('#pmLinesFoot').html(
            '<tr>'
            + '<td colspan="8">Tạm tính (' + t.n + ' dòng) — server chốt lại khi Lưu</td>'
            + '<td class="c-num">' + fmt(t.ck) + '</td>'
            + '<td class="c-num">' + fmt(t.before) + '</td>'
            + '<td></td>'
            + '<td class="c-num">' + fmt(t.tax) + '</td>'
            + '<td class="c-num">' + fmt(t.grand) + '</td>'
            + '<td colspan="3"></td>'
            + '</tr>'
        );
        $('#pmTotBefore').text(money(t.before));
        $('#pmTotTax').text(money(t.tax));
        $('#pmTotCk').text(money(t.ck));
        $('#pmTotGrand').text(money(t.grand));
    }

    // ── render: đáy 3 cột ─────────────────────────────────────────────────
    function renderFoot(r) {
        $('#pmCust').html([
            kv('Mã KH (SĐT)', r.ma_kh), kv('Tên khách', r.ten_kh), kv('Tên công ty', r.ten_cty_mua),
            kv('Mã số thuế', r.mst_mua), kv('Địa chỉ', r.dchi_mua), kv('Điện thoại', r.dt_mua),
            kv('Email', r.email_mua)
        ].join(''));

        var actor = opts.actor || {};
        $('#pmStaff').html([
            kv('Nhân viên xuất', r.nv_ten), kv('userID xuất', r.user_id),
            (actor.name || actor.id)
                ? kv('Người thao tác (đang can thiệp)', actor.name) + kv('userID thao tác', actor.id)
                : ''
        ].join(''));

        var note = String(r.ghi_chu || '').trim();
        $('#pmOrderNote').html('<span class="pm-f__k">Ghi chú phiếu</span>'
            + '<div class="pm-foot3__notebox" id="pmNoteBox">' + (note ? esc(note) : '<i>—</i>') + '</div>');
        $('#pmNoteEdit').toggleClass('bctk-hidden', typeof opts.onSaveNote !== 'function' || !opts.editable);

        $('#pmTotBefore').text(money(r.tt_chua_thue));
        $('#pmTotTax').text(money(r.tong_thue));
        $('#pmTotCk').text(money(r.tong_ck));
        $('#pmTotGrand').text(money(r.thanh_tien));
        $('#pmTotWords').text(r.thanh_tien_chu || '');
    }

    // ── Xuất Excel một phiếu (header + dòng hàng) ────────────────────────
    // .xls dạng bảng HTML — Excel mở thẳng, không cần thư viện. Lấy đúng số
    // liệu đang hiển thị trên modal (đã theo TGS_Money từ server).
    function exportPhieu() {
        var r = current;
        if (!r) { return; }
        var actor = opts.actor || {};

        function hRow(k, v) {
            return '<tr><td style="font-weight:bold;background:#f1f5f9">' + esc(k)
                + '</td><td>' + esc(dash(v)) + '</td></tr>';
        }
        var head =
            '<table border="1"><tbody>'
            + hRow('Loại phiếu', (opts.title || 'Phiếu') + ' — Lý do ' + (r.ly_do || ''))
            + hRow('Số phiếu xuất', r.so_phieu_xuat) + hRow('Kho / Mã shop', r.ma_shop)
            + hRow('Ngày xuất', ngay(r.ngay_xuat, true))
            + hRow('Số hóa đơn', r.so_hd) + hRow('Seri', r.seri) + hRow('Mẫu HĐ', r.mau_hd)
            + hRow('Ngày hóa đơn', ngay(r.ngay_hd, true)) + hRow('Hình thức TT', r.httt)
            + hRow('Trạng thái VAT', r.trang_thai_vat) + hRow('Tỷ lệ thuế', rate(r.ty_le_thue))
            + hRow('Đơn vị bán hàng', dash(r.ten_cty_ban) + ' · MST ' + dash(r.mst_ban)
                + ' · ' + dash(r.dchi_ban) + ' · ĐT ' + dash(r.dt_ban))
            + hRow('Khách hàng', r.ten_kh) + hRow('Mã KH (SĐT)', r.ma_kh)
            + hRow('Tên công ty bên mua', r.ten_cty_mua) + hRow('MST bên mua', r.mst_mua)
            + hRow('Địa chỉ bên mua', r.dchi_mua) + hRow('Điện thoại bên mua', r.dt_mua)
            + hRow('Email bên mua', r.email_mua)
            + hRow('Nhân viên xuất', r.nv_ten + ' (uID ' + (r.user_id || '') + ')')
            + (actor.name || actor.id
                ? hRow('Người thao tác (đang can thiệp)', actor.name + ' (uID ' + (actor.id || '') + ')')
                : '')
            + hRow('Ghi chú phiếu', r.ghi_chu)
            + hRow('Tiền hàng (chưa thuế)', money(r.tt_chua_thue))
            + hRow('Tiền thuế', money(r.tong_thue)) + hRow('Chiết khấu', money(r.tong_ck))
            + hRow('Tổng thanh toán', money(r.thanh_tien) + ' (' + (r.thanh_tien_chu || '') + ')')
            + '</tbody></table>';

        var cols = ['STT', 'Mã hàng', 'Tên hàng', 'Kho', 'ĐVT', 'SL', 'SL ĐVCB',
            'ĐG bán (gồm thuế, trước CK)', 'CK', 'TT chưa thuế', 'Thuế suất',
            'Tiền thuế', 'Thành tiền', 'Số lô', 'EXP', 'Ghi chú'];
        var body = '<table border="1"><thead><tr>'
            + cols.map(function (c) { return '<th style="background:#e2e8f0">' + esc(c) + '</th>'; }).join('')
            + '</tr></thead><tbody>';
        (r.items || []).forEach(function (it, i) {
            var cells = [
                it.stt || (i + 1), it.ma_hang || '', it.ten || '', it.kho || '', it.dvt || '',
                num(it.sl), num(it.sl_dvcb), num(it.don_gia), num(it.ck), num(it.tien_chua_thue),
                taxDisplay(it.thue_suat), num(it.tien_thue), num(it.thanh_tien),
                it.so_lo || '', it.exp || '', it.ghi_chu || ''
            ];
            body += '<tr>' + cells.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>';
        });
        body += '</tbody></table>';

        var html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head>'
            + '<meta charset="UTF-8"></head><body>'
            + '<h3>Chi tiết phiếu ' + esc(r.so_phieu_xuat || '') + '</h3>'
            + head + '<br>' + body + '</body></html>';

        var blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'phieu-' + (String(r.so_phieu_xuat || 'export').replace(/[^\w.-]+/g, '_')) + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
        api.toast('info', 'Đã xuất Excel phiếu ' + (r.so_phieu_xuat || '') + '.');
    }

    // ── sửa dòng hàng ─────────────────────────────────────────────────────
    function startEdit() {
        editLines = (current.items || []).map(function (it) {
            return {
                item_id: it.item_id || 0, _del: false,
                ma_hang: it.ma_hang || '', ten: it.ten || '', dvt: it.dvt || '', kho: it.kho || '',
                sl: it.sl, ratio: num(it.ratio) || 1, don_gia: it.don_gia,
                ck: it.ck, thue_pct: it.thue_suat, /* chỉ để hiển thị + tính tạm; server chốt lại */
                so_lo: it.so_lo || '', exp: it.exp || '', ghi_chu: it.ghi_chu || '',
                units: []
            };
        });
        editing = true;
        renderToolbar(current);
        renderLines(current);
        renderFoot(current);
        $('#pmActionMsg').text('Thuế suất & ĐVT theo cấu hình mã hàng (bảng giá website hiện tại). Bấm "+ Thêm sản phẩm" hoặc ô Mã hàng để chọn từ danh mục.');
        setTimeout(function () { $('#pmLinesBody .pm-line__in[data-col="sl"]').first().focus(); }, 30);

        // Nạp cấu hình ĐVT cho các mã hàng đang có → ĐVT thành ô chọn
        if (typeof opts.onLoadUnits === 'function') {
            var skus = [];
            editLines.forEach(function (l) {
                var s = String(l.ma_hang || '').trim();
                if (s && skus.indexOf(s) === -1) { skus.push(s); }
            });
            if (skus.length) {
                opts.onLoadUnits(skus, function (map) {
                    if (!editing) { return; }
                    editLines.forEach(function (l) {
                        var us = (map && map[String(l.ma_hang || '').trim()]) || [];
                        if (us.length) {
                            l.units = us;
                            var cur = pickUnit(us, l.dvt);
                            if (cur) { l.ratio = cur.ratio; }
                        }
                    });
                    renderLines(current);
                });
            }
        }
    }

    /* Chọn ĐVT: đúng tên đang có → giữ; không thì ĐVT ưu tiên (★) → tỷ lệ 1 → đầu */
    function pickUnit(units, name) {
        units = units || [];
        var byName = name ? units.filter(function (u) { return u.unit === name; })[0] : null;
        if (byName) { return byName; }
        return units.filter(function (u) { return u.is_default; })[0]
            || units.filter(function (u) { return Math.abs(u.ratio - 1) < 0.0005; })[0]
            || units[0] || null;
    }

    /* Giá 1 ĐVT: ưu tiên unit_price cấu hình; null thì suy từ đơn vị khác có giá */
    function unitPrice(units, u) {
        if (u && u.price != null) { return u.price; }
        var withPrice = (units || []).filter(function (x) { return x.price != null && x.ratio > 0; })[0];
        if (withPrice && u) { return Math.round(withPrice.price / withPrice.ratio * u.ratio); }
        return 0;
    }

    function cancelEdit() {
        closePick();
        editing = false;
        editLines = [];
        renderToolbar(current);
        renderLines(current);
        renderFoot(current);
    }

    function delLine(i) {
        i = parseInt(i, 10);
        if (!editLines[i]) { return; }
        if (editLines[i].item_id > 0) { editLines[i]._del = true; }
        else { editLines.splice(i, 1); }
        renderLines(current);
    }

    function onLineInput(e) {
        var $in = $(e.target);
        var i = parseInt($in.data('i'), 10);
        var col = $in.data('col');
        var ln = editLines[i];
        if (!ln) { return; }
        ln[col] = $in.val();

        // Đổi ĐVT → tỷ lệ + đơn giá theo cấu hình, rồi vẽ lại cả bảng
        if (col === 'dvt' && (ln.units || []).length) {
            var u = pickUnit(ln.units, ln.dvt);
            if (u) {
                ln.ratio = u.ratio;
                ln.don_gia = unitPrice(ln.units, u);   // hệ thống tự set; kế toán sửa tiếp được
            }
            renderLines(current);
            return;
        }

        // cập nhật ô tính của đúng dòng đó
        var c = calcLine(ln);
        var $tr = $in.closest('tr');
        $tr.find('[data-out="qtyBase"]').text(fmt(c.qtyBase));
        $tr.find('[data-out="tien_chua_thue"]').text(fmt(c.tien_chua_thue));
        $tr.find('[data-out="tien_thue"]').text(fmt(c.tien_thue));
        $tr.find('[data-out="thanh_tien"]').text(fmt(c.thanh_tien));
        renderEditFoot();
    }

    function onLineKey(e) {
        var k = e.key;
        if (k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'Enter') { return; }
        var $in = $(e.target);
        var col = $in.data('col');
        var i = parseInt($in.data('i'), 10);
        e.preventDefault();

        if (k === 'ArrowUp') {
            focusCell(i - 1, col);
        } else {
            // Enter / ArrowDown — cuối bảng thì mở bộ chọn sản phẩm
            var visible = editLines.map(function (l, idx) { return l._del ? -1 : idx; }).filter(function (x) { return x >= 0; });
            var pos = visible.indexOf(i);
            if (pos === visible.length - 1) { openPick('add', -1); return; }
            focusCell(visible[pos + 1], col);
        }
    }

    function focusCell(i, col) {
        var $c = $('#pmLinesBody tr[data-i="' + i + '"] .pm-line__in[data-col="' + col + '"]');
        if ($c.length) { $c.focus().select && $c.select(); }
    }

    // ── BỘ CHỌN SẢN PHẨM (modal con) — thay cho gõ tay + gợi ý trong ô ──
    //
    // Bám cách "Thêm sản phẩm" ở màn tạo phiếu: mở lên, tìm, bấm để thêm. Đỡ
    // nhầm vì Mã hàng / Tên hàng không còn gõ tay được.

    function openPick(mode, rowIdx) {
        if (typeof opts.onSearchProduct !== 'function') {
            api.toast('error', 'Màn này chưa bật tìm sản phẩm.');
            return;
        }
        pickMode = (mode === 'replace') ? 'replace' : 'add';
        pickRow = (pickMode === 'replace') ? parseInt(rowIdx, 10) : -1;
        pickItems = [];
        pickIdx = -1;
        pickAdded = 0;

        $('#pmPickTitle').text(pickMode === 'replace' ? 'Đổi sản phẩm dòng ' + (pickRow + 1) : 'Thêm sản phẩm');
        $('#pmPickCount').text('');
        $('#pmPickBody').html('<tr><td colspan="5" class="pm-pick__empty">Gõ từ khoá để tìm…</td></tr>');
        $('#pmPickDone').text(pickMode === 'replace' ? 'Đóng' : 'Xong');
        $('#pmPick').removeClass('bctk-hidden');
        var $s = $('#pmPickSearch').val('');
        setTimeout(function () { $s.focus(); }, 20);
    }

    function closePick() {
        if (pickTimer) { clearTimeout(pickTimer); pickTimer = null; }
        $('#pmPick').addClass('bctk-hidden');
        pickItems = [];
        pickIdx = -1;
    }

    function schedulePickSearch() {
        var term = String($('#pmPickSearch').val() || '').trim();
        if (pickTimer) { clearTimeout(pickTimer); }
        if (term.length < 2) {
            $('#pmPickBody').html('<tr><td colspan="5" class="pm-pick__empty">Gõ ít nhất 2 ký tự…</td></tr>');
            return;
        }
        pickTimer = setTimeout(function () {
            opts.onSearchProduct(term, function (items) {
                renderPickResults(items || []);
            });
        }, 220);
    }

    function renderPickResults(items) {
        pickItems = items;
        pickIdx = items.length ? 0 : -1;
        if (!items.length) {
            $('#pmPickBody').html('<tr><td colspan="5" class="pm-pick__empty">Không tìm thấy sản phẩm.</td></tr>');
            return;
        }
        $('#pmPickBody').html(items.map(function (it, i) {
            return '<tr data-i="' + i + '"' + (i === pickIdx ? ' class="is-sel"' : '') + '>'
                + '<td>' + esc(it.sku) + '</td>'
                + '<td>' + esc(it.name) + '</td>'
                + '<td>' + esc(it.unit || '—') + '</td>'
                + '<td class="c-num">' + fmt(it.price) + '</td>'
                + '<td>' + (it.is_kct ? 'KCT' : (it.tax != null ? it.tax + '%' : '?')) + '</td>'
                + '</tr>';
        }).join(''));
    }

    function pickMove(d) {
        var n = pickItems.length;
        if (!n) { return; }
        pickIdx = (pickIdx + d + n) % n;
        $('#pmPickBody tr').removeClass('is-sel')
            .eq(pickIdx).addClass('is-sel')[0].scrollIntoView({ block: 'nearest' });
    }

    function onPickKey(e) {
        var k = e.key;
        if (k === 'ArrowDown') { e.preventDefault(); pickMove(1); }
        else if (k === 'ArrowUp') { e.preventDefault(); pickMove(-1); }
        else if (k === 'Enter') {
            e.preventDefault();
            if (pickIdx >= 0 && pickItems[pickIdx]) { pickChoose(pickItems[pickIdx]); }
        } else if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); closePick(); }
    }

    /* Gán sản phẩm vào một dòng: SKU · tên · thuế · ĐVT/giá theo bảng giá */
    function applyProductToLine(ln, it) {
        ln.ma_hang = it.sku;
        ln.ten = it.name;
        ln.thue_pct = it.is_kct ? 'KCT' : (it.tax != null ? it.tax : ln.thue_pct);
        var us = it.units || [];
        ln.units = us;
        if (us.length) {
            var def = pickUnit(us, null);
            ln.dvt = def.unit;
            ln.ratio = def.ratio;
            ln.don_gia = unitPrice(us, def);
        } else {
            if (it.unit) { ln.dvt = it.unit; }
            ln.ratio = 1;
            if (!num(ln.don_gia)) { ln.don_gia = it.price || 0; }
        }
    }

    function pickChoose(it) {
        if (!it) { return; }

        if (pickMode === 'replace') {
            var ln = editLines[pickRow];
            if (!ln) { closePick(); return; }
            applyProductToLine(ln, it);
            closePick();
            renderLines(current);
            setTimeout(function () { focusCell(pickRow, 'sl'); }, 20);
            return;
        }

        // mode 'add' — thêm một dòng mới, GIỮ modal mở để thêm tiếp
        var line = {
            item_id: 0, _del: false, ma_hang: '', ten: '', dvt: '', kho: current.ma_shop || '',
            sl: 1, ratio: 1, don_gia: 0, ck: 0, thue_pct: '', so_lo: '', exp: '', ghi_chu: '', units: []
        };
        applyProductToLine(line, it);
        editLines.push(line);
        pickAdded++;
        $('#pmPickCount').text('Đã thêm ' + pickAdded);
        renderLines(current);   // cập nhật bảng nền
        $('#pmPickSearch').val('').focus();
        $('#pmPickBody').html('<tr><td colspan="5" class="pm-pick__empty">Gõ từ khoá để tìm tiếp…</td></tr>');
        pickItems = [];
        pickIdx = -1;
    }

    function saveEdit() {
        if (typeof opts.onSaveLines !== 'function') { return; }
        // Dòng MỚI mà chưa chọn mã hàng ⇒ dòng thừa, bỏ luôn (không gửi lên).
        // Dòng cũ vẫn gửi (server tự xoá nếu bị làm trống).
        var payload = editLines
            .filter(function (l) { return l._del || l.item_id > 0 || String(l.ma_hang || '').trim() !== ''; })
            .map(function (l) {
                return {
                    item_id: l.item_id || 0, del: l._del ? 1 : 0,
                    ma_hang: l.ma_hang, ten: l.ten, dvt: l.dvt, ratio: num(l.ratio) || 1,
                    sl: num(l.sl), don_gia: num(l.don_gia), ck: num(l.ck),
                    thue_pct: l.thue_pct, so_lo: l.so_lo, exp: l.exp, ghi_chu: l.ghi_chu
                };
            });
        api.busy(true);
        api.toast('info', 'Đang lưu…');
        opts.onSaveLines(current, payload, api);
    }

    // ── sửa ghi chú ──────────────────────────────────────────────────────
    function startNoteEdit() {
        var cur = String(current.ghi_chu || '');
        $('#pmOrderNote').html(
            '<span class="pm-f__k">Ghi chú phiếu</span>'
            + '<textarea class="pm-note__ta" id="pmNoteTa" rows="3">' + esc(cur) + '</textarea>'
            + '<div class="pm-note__act">'
            + '<button type="button" class="bctk-btn bctk-btn--primary" id="pmNoteSave">Lưu</button>'
            + '<button type="button" class="bctk-btn" id="pmNoteCancel">Huỷ</button>'
            + '</div>'
        );
        $('#pmNoteEdit').addClass('bctk-hidden');
        $('#pmNoteTa').focus();
        $('#pmNoteSave').on('click', function () {
            if (typeof opts.onSaveNote !== 'function') { return; }
            api.busy(true);
            api.toast('info', 'Đang lưu ghi chú…');
            opts.onSaveNote(current, $('#pmNoteTa').val(), api);
        });
        $('#pmNoteCancel').on('click', function () { renderFoot(current); });
    }

    // ── PDF ────────────────────────────────────────────────────────────────
    function showPdf(base64, name) {
        $('#pmPdfName').text(name || 'invoice.pdf');
        document.getElementById('pmPdfFrame').src = 'data:application/pdf;base64,' + base64;
        $('#pmPdf').removeClass('bctk-hidden');
    }
    function closePdf() {
        $('#pmPdf').addClass('bctk-hidden');
        document.getElementById('pmPdfFrame').src = 'about:blank';
    }

    var api = {
        pdf: showPdf,
        closePdf: closePdf,
        toast: function (type, msg) { $('#pmActionMsg').text(msg || '').attr('data-type', type || 'info'); },
        busy: function (on) { $('#pmActions button').prop('disabled', !!on); },
        close: close,
        payload: function () { return current; },
        exitEdit: cancelEdit,
        /* Sau khi server lưu xong: nạp payload mới, thoát chế độ sửa, vẽ lại */
        applyRow: function (row) {
            if (row && typeof row === 'object') {
                current = row;
            }
            editing = false;
            editLines = [];
            renderToolbar(current);
            renderDoc(current);
            renderLines(current);
            renderFoot(current);
            if (typeof opts.onRowUpdated === 'function') { opts.onRowUpdated(current); }
        },
        applyNote: function (noteText) {
            current.ghi_chu = noteText || '';
            renderFoot(current);
        }
    };

    function open(payload, options) {
        build();
        current = payload || {};
        opts = options || {};
        editing = false;
        editLines = [];

        $('#pmTitle').text(opts.title || 'Chi tiết chứng từ');
        $('#pmCode').text(current.so_phieu_xuat || current.code || '');
        $('#pmState').attr('class', 'pm-badge ' + stateCls(current.vat_state)).text(current.trang_thai_vat || '');
        $('#pmZ').toggleClass('bctk-hidden', !Number(current.is_z));

        renderToolbar(current);
        renderDoc(current);
        renderLines(current);
        renderFoot(current);

        if (opts.note) { $('#pmNote').text(opts.note).removeClass('bctk-hidden'); }
        else { $('#pmNote').addClass('bctk-hidden').text(''); }

        closePdf();
        $('#pmModal').removeClass('bctk-hidden').attr('aria-hidden', 'false');
    }

    function close() {
        $('#pmModal').addClass('bctk-hidden').attr('aria-hidden', 'true');
        closePdf();
        closePick();
        editing = false;
        editLines = [];
        current = null;
    }

    window.TGSBctkPhieuModal = { open: open, close: close, api: api };
})(jQuery);
