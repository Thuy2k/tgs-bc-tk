/**
 * BC_TK — MODAL XEM / SỬA MỘT CHỨNG TỪ (component dùng chung).
 *
 * Bố cục bám PHẦN MỀM CŨ: thanh hành động + chứng từ ở TRÊN, dòng hàng ở GIỮA
 * (cuộn riêng), khách hàng / nhân viên / tổng cộng ở ĐÁY (3 cột). Modal gần
 * full màn, cao cố định để bố cục không nhảy.
 *
 * Gọi:  TGSBctkPhieuModal.open(payload, {
 *          title, note, actions,
 *          editable:    bool,                     // cho phép Sửa dòng hàng
 *          onSaveLines: fn(payload, lines, api),  // lưu dòng hàng
 *          onSaveNote:  fn(payload, noteText, api)// lưu ghi chú phiếu
 *        })
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
    var acItems = [];       // kết quả tìm sản phẩm đang hiện
    var acTarget = null;    // $input đang gõ
    var acIdx = -1;
    var acTimer = null;

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

    /* Tính tạm tiền một dòng theo giá trị kế toán vừa gõ (POS: sau thuế, trước CK).
       Server mới là nơi chốt (TGS_Money::from_pos) — đây chỉ để hiện ngay. */
    function calcLine(ln) {
        var qty = num(ln.sl);
        var pct = (ln.thue_pct === 'KCT' || ln.thue_pct === '' || ln.thue_pct == null) ? 0 : num(ln.thue_pct);
        var heso = 1 + pct / 100;
        var price = num(ln.don_gia) / heso;
        var disc = num(ln.ck) / heso;
        var sauCk = qty * price - disc;
        var thue = Math.round(sauCk * pct / 100);
        var tt = Math.round(sauCk + thue);
        return { tien_chua_thue: tt - thue, tien_thue: thue, thanh_tien: tt };
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
        +       '<thead><tr>'
        +         '<th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>Kho</th><th>ĐVT</th>'
        +         '<th class="c-num">SL</th><th class="c-num">SL ĐVT</th>'
        +         '<th class="c-num">Đơn giá</th><th class="c-num">CK</th>'
        +         '<th class="c-num">TT chưa thuế</th><th class="c-num">Thuế suất</th>'
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

        +   '<div class="pm-ac bctk-hidden" id="pmAc"></div>'
        + '</div></div>';

        $('body').append(html);

        var $m = $('#pmModal');
        $m.on('click', '[data-pm-close]', function () { if (!editing) { close(); } else { cancelEdit(); } });
        $m.on('click', '[data-pm-pdf-close]', closePdf);
        $m.on('click', '[data-pm-action]', function () {
            var id = $(this).data('pm-action');
            var a = (opts.actions || []).filter(function (x) { return x.id === id; })[0];
            if (a && typeof a.run === 'function') { a.run(current, api); }
        });
        $m.on('click', '#pmEditStart', startEdit);
        $m.on('click', '#pmEditSave', saveEdit);
        $m.on('click', '#pmEditCancel', cancelEdit);
        $m.on('click', '#pmEditAdd', addLine);
        $m.on('click', '.pm-line__del', function () { delLine($(this).data('i')); });
        $m.on('input change', '.pm-line__in', onLineInput);
        $m.on('input', '.pm-line__in[data-col="ma_hang"], .pm-line__in[data-col="ten"]', onSearchInput);
        $m.on('keydown', '.pm-line__in', onLineKey);
        $m.on('blur', '.pm-line__in[data-col="ma_hang"], .pm-line__in[data-col="ten"]',
            function () { setTimeout(acHide, 150); });
        $m.on('mousedown', '.pm-ac__row', function (e) {
            e.preventDefault();
            acPick(acItems[parseInt($(this).data('i'), 10)]);
        });
        $m.on('click', '#pmNoteEdit', startNoteEdit);
        $m.on('scroll', '.pm-lines', acHide);

        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape' || $m.hasClass('bctk-hidden')) { return; }
            if (!$('#pmPdf').hasClass('bctk-hidden')) { closePdf(); }
            else if (editing) { cancelEdit(); }
            else { close(); }
        });
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

        if (opts.editable && typeof opts.onSaveLines === 'function') {
            if (editing) {
                acts.push('<span class="pm-actions__sep"></span>');
                acts.push('<button type="button" class="bctk-btn" id="pmEditAdd">+ Thêm dòng</button>');
                acts.push('<button type="button" class="bctk-btn bctk-btn--primary" id="pmEditSave">💾 Lưu</button>');
                acts.push('<button type="button" class="bctk-btn" id="pmEditCancel">Huỷ</button>');
            } else {
                acts.push('<button type="button" class="bctk-btn" id="pmEditStart">✎ Sửa dòng hàng</button>');
            }
        }

        $('#pmActions').html(acts.join(''));
        $('#pmActionMsg').text('').removeAttr('data-type');
        $('#pmEditTag').toggleClass('bctk-hidden', !editing);
    }

    function renderDoc(r) {
        $('#pmDocGrid').html([
            kv('Lý do', r.ly_do), kv('Kho / Mã shop', r.ma_shop),
            kv('Số phiếu xuất', r.so_phieu_xuat), kv('Ngày xuất', ngay(r.ngay_xuat, true)),
            kv('Số hóa đơn', r.so_hd), kv('Seri', r.seri), kv('Mẫu HĐ', r.mau_hd),
            kv('Ngày hóa đơn', ngay(r.ngay_hd, true)), kv('Hình thức TT', r.httt),
            kv('Trạng thái VAT', r.trang_thai_vat), kv('Tỷ lệ thuế', rate(r.ty_le_thue)),
            kv('Số SO', r.so_so), kv('SL bản ghi', (r.items || []).length)
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
    function taxSelect(val) {
        var opt = ['0', '5', '8', '10', 'KCT'];
        var cur = (val === 'KCT') ? 'KCT' : String(num(val) || 0);
        return '<select class="pm-line__in" data-col="thue_pct">'
            + opt.map(function (o) {
                return '<option value="' + o + '"' + (o === cur ? ' selected' : '') + '>'
                    + (o === 'KCT' ? 'KCT' : o + '%') + '</option>';
            }).join('') + '</select>';
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
                    + '<td class="c-num">' + fmt(it.sl_dvt) + '</td>'
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
            return '<tr data-i="' + i + '">'
                + '<td><button type="button" class="pm-line__del" data-i="' + i + '" title="Xoá dòng">✕</button> ' + (i + 1) + '</td>'
                + '<td>' + inp('ma_hang', ln.ma_hang) + '</td>'
                + '<td>' + inp('ten', ln.ten) + '</td>'
                + '<td>' + esc(ln.kho || current.ma_shop || '') + '</td>'
                + '<td>' + inp('dvt', ln.dvt) + '</td>'
                + '<td class="c-num">' + inp('sl', ln.sl, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num pm-ro">—</td>'
                + '<td class="c-num">' + inp('don_gia', ln.don_gia, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num">' + inp('ck', ln.ck, 'type="number" step="any" inputmode="decimal"') + '</td>'
                + '<td class="c-num pm-ro" data-out="tien_chua_thue">' + fmt(c.tien_chua_thue) + '</td>'
                + '<td class="c-num">' + taxSelect(ln.thue_pct) + '</td>'
                + '<td class="c-num pm-ro" data-out="tien_thue">' + fmt(c.tien_thue) + '</td>'
                + '<td class="c-num pm-ro" data-out="thanh_tien">' + fmt(c.thanh_tien) + '</td>'
                + '<td>' + inp('so_lo', ln.so_lo) + '</td>'
                + '<td>' + inp('exp', ymd(ln.exp), 'type="date"') + '</td>'
                + '<td>' + inp('ghi_chu', ln.ghi_chu) + '</td>'
                + '</tr>';
        }).join('');
        $('#pmLinesBody').html(rows || '<tr><td colspan="16" class="pm-lines__empty">Bấm "+ Thêm dòng".</td></tr>');
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

        $('#pmStaff').html([kv('Nhân viên xuất', r.nv_ten), kv('userID xuất', r.user_id)].join(''));

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

    // ── sửa dòng hàng ─────────────────────────────────────────────────────
    function startEdit() {
        editLines = (current.items || []).map(function (it) {
            return {
                item_id: it.item_id || 0, _del: false,
                ma_hang: it.ma_hang || '', ten: it.ten || '', dvt: it.dvt || '', kho: it.kho || '',
                sl: it.sl, don_gia: it.don_gia,
                ck: it.ck, thue_pct: (it.thue_suat === 'KCT' ? 'KCT' : (it.thue_suat === 'Chưa khai' ? 8 : it.thue_suat)),
                so_lo: it.so_lo || '', exp: it.exp || '', ghi_chu: it.ghi_chu || ''
            };
        });
        editing = true;
        renderToolbar(current);
        renderLines(current);
        renderFoot(current);
        setTimeout(function () { $('#pmLinesBody .pm-line__in[data-col="sl"]').first().focus(); }, 30);
    }

    function cancelEdit() {
        acHide();
        editing = false;
        editLines = [];
        renderToolbar(current);
        renderLines(current);
        renderFoot(current);
    }

    function addLine() {
        editLines.push({
            item_id: 0, _del: false, ma_hang: '', ten: '', dvt: '', kho: current.ma_shop || '',
            sl: 1, don_gia: 0, ck: 0, thue_pct: 8, so_lo: '', exp: '', ghi_chu: ''
        });
        renderLines(current);
        setTimeout(function () {
            $('#pmLinesBody tr[data-i="' + (editLines.length - 1) + '"] .pm-line__in[data-col="ma_hang"]').focus();
        }, 20);
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
        if (!editLines[i]) { return; }
        editLines[i][col] = $in.val();

        // cập nhật ô tính của đúng dòng đó
        var c = calcLine(editLines[i]);
        var $tr = $in.closest('tr');
        $tr.find('[data-out="tien_chua_thue"]').text(fmt(c.tien_chua_thue));
        $tr.find('[data-out="tien_thue"]').text(fmt(c.tien_thue));
        $tr.find('[data-out="thanh_tien"]').text(fmt(c.thanh_tien));
        renderEditFoot();
    }

    function onLineKey(e) {
        var k = e.key;
        var $in = $(e.target);
        var col = $in.data('col');
        var i = parseInt($in.data('i'), 10);

        // Đang mở gợi ý sản phẩm ở ô Mã hàng / Tên hàng → phím điều hướng gợi ý
        if (!$('#pmAc').hasClass('bctk-hidden') && (col === 'ma_hang' || col === 'ten')) {
            if (k === 'ArrowDown') { e.preventDefault(); acMove(1); return; }
            if (k === 'ArrowUp') { e.preventDefault(); acMove(-1); return; }
            if (k === 'Enter') {
                e.preventDefault();
                if (acIdx >= 0 && acItems[acIdx]) { acPick(acItems[acIdx]); }
                return;
            }
            if (k === 'Escape') { acHide(); return; }
        }

        if (k !== 'ArrowDown' && k !== 'ArrowUp' && k !== 'Enter') { return; }
        e.preventDefault();

        if (k === 'ArrowUp') {
            focusCell(i - 1, col);
        } else {
            // Enter / ArrowDown — cuối bảng thì thêm dòng mới
            var visible = editLines.map(function (l, idx) { return l._del ? -1 : idx; }).filter(function (x) { return x >= 0; });
            var pos = visible.indexOf(i);
            if (pos === visible.length - 1) { addLine(); return; }
            focusCell(visible[pos + 1], col);
        }
    }

    function focusCell(i, col) {
        var $c = $('#pmLinesBody tr[data-i="' + i + '"] .pm-line__in[data-col="' + col + '"]');
        if ($c.length) { $c.focus().select && $c.select(); }
    }

    // ── gợi ý sản phẩm (truy vấn catalog global) ─────────────────────────
    function onSearchInput(e) {
        if (typeof opts.onSearchProduct !== 'function') { return; }
        var $in = $(e.target);
        var term = String($in.val() || '').trim();
        acTarget = $in;
        if (acTimer) { clearTimeout(acTimer); }
        if (term.length < 2) { acHide(); return; }
        acTimer = setTimeout(function () {
            opts.onSearchProduct(term, function (items) {
                if (acTarget && acTarget.is(':focus')) { acShow(items || [], acTarget); }
            });
        }, 220);
    }

    function acShow(items, $in) {
        acItems = items;
        acIdx = -1;
        if (!items.length) { acHide(); return; }

        var $ac = $('#pmAc');
        $ac.html(items.map(function (it, i) {
            return '<div class="pm-ac__row" data-i="' + i + '">'
                + '<span class="pm-ac__sku">' + esc(it.sku) + '</span>'
                + '<span class="pm-ac__name">' + esc(it.name) + '</span>'
                + '<span class="pm-ac__meta">' + esc(it.unit || '') + ' · '
                + (it.is_kct ? 'KCT' : (it.tax != null ? it.tax + '%' : '?')) + ' · '
                + fmt(it.price) + '</span>'
                + '</div>';
        }).join(''));

        var r = $in[0].getBoundingClientRect();
        $ac.css({
            top: (r.bottom + 2) + 'px',
            left: r.left + 'px',
            minWidth: Math.max(320, r.width) + 'px'
        }).removeClass('bctk-hidden');
    }

    function acMove(d) {
        var n = acItems.length;
        if (!n) { return; }
        acIdx = (acIdx + d + n) % n;
        $('#pmAc .pm-ac__row').removeClass('is-sel')
            .eq(acIdx).addClass('is-sel')[0].scrollIntoView({ block: 'nearest' });
    }

    function acHide() {
        $('#pmAc').addClass('bctk-hidden').empty();
        acItems = [];
        acIdx = -1;
    }

    function acPick(it) {
        if (!it || !acTarget) { return; }
        var i = parseInt(acTarget.data('i'), 10);
        if (!editLines[i]) { return; }
        editLines[i].ma_hang = it.sku;
        editLines[i].ten = it.name;
        if (it.unit) { editLines[i].dvt = it.unit; }
        if (!num(editLines[i].don_gia)) { editLines[i].don_gia = it.price || 0; }
        editLines[i].thue_pct = it.is_kct ? 'KCT' : (it.tax != null ? it.tax : editLines[i].thue_pct);
        acHide();
        renderLines(current);
        setTimeout(function () { focusCell(i, 'sl'); }, 20);
    }

    function saveEdit() {
        if (typeof opts.onSaveLines !== 'function') { return; }
        var payload = editLines
            .filter(function (l) { return l._del || l.item_id > 0 || String(l.ma_hang || l.ten || '').trim() !== '' || num(l.sl) > 0; })
            .map(function (l) {
                return {
                    item_id: l.item_id || 0, del: l._del ? 1 : 0,
                    ma_hang: l.ma_hang, ten: l.ten, dvt: l.dvt,
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
        acHide();
        editing = false;
        editLines = [];
        current = null;
    }

    window.TGSBctkPhieuModal = { open: open, close: close, api: api };
})(jQuery);
