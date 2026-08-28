/**
 * BC_TK — MODAL XEM / CAN THIỆP MỘT CHỨNG TỪ (component dùng chung).
 *
 * Bố cục bám PHẦN MỀM CŨ (kế toán đang dùng song song, tránh ngợp):
 *
 *   ┌ tiêu đề · mã · trạng thái ································· [×] ┐
 *   ├ THANH HÀNH ĐỘNG (nút sự kiện + Đóng) ······················· ┤
 *   ├ CHỨNG TỪ  (Lý do · Kho · Số phiếu · Ngày · Số HĐ · Seri…)   │
 *   │           Bên bán: tên · MST · địa chỉ · điện thoại         │
 *   ├ DÒNG HÀNG  (bảng chính, cuộn riêng) + tổng + bằng chữ       │
 *   ├ ĐÁY 3 CỘT:  Khách hàng | Nhân viên & ghi chú | Tổng cộng    │
 *   └────────────────────────────────────────────────────────────┘
 *
 * Modal CAO CỐ ĐỊNH full màn (94vh) — không co giãn theo nội dung để bố cục
 * không nhảy; dòng hàng dài thì cuộn trong khối của nó. Responsive: khối chứng
 * từ và đáy tự xuống hàng khi hẹp.
 *
 * Gọi:  TGSBctkPhieuModal.open(payload, { title, note, actions })
 *   payload: dòng đã dựng ở server (TGS_BCTK_Ajax::build_vat_row), có `items`.
 *   actions[]: { id, label, cls, when(payload)->bool, run(payload, api) }
 *     api = { pdf(base64,name), closePdf, toast(type,msg), busy(bool), close }
 *
 * Bảng dòng hàng gắn data-ds-no-grid/-export/-colcfg/-filter để Design System
 * KHÔNG chèn thanh "Chọn cột / Xuất Excel / dòng lọc" vào; VẪN cho kéo giãn cột.
 *
 * Chia khối để lồng ghép: renderToolbar / renderDoc / renderLines / renderFoot.
 */
(function ($) {
    'use strict';

    var built = false;
    var current = null;
    var opts = {};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    var nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });
    function fmt(n) { return (n === null || n === undefined || n === '') ? '' : nf.format(n); }
    function money(n) { return (n === null || n === undefined || n === '') ? '0' : nf.format(n); }
    function ngay(s, withTime) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m || m[1] === '0000') { return ''; }
        var d = m[3] + '/' + m[2] + '/' + m[1];
        return (withTime && m[4]) ? (d + ' ' + m[4] + ':' + m[5]) : d;
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
        +       '<h4>Nhân viên &amp; ghi chú</h4>'
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
        + '</div></div>';

        $('body').append(html);

        var $m = $('#pmModal');
        $m.on('click', '[data-pm-close]', close);
        $m.on('click', '[data-pm-pdf-close]', closePdf);
        $m.on('click', '[data-pm-action]', function () {
            var id = $(this).data('pm-action');
            var a = (opts.actions || []).filter(function (x) { return x.id === id; })[0];
            if (a && typeof a.run === 'function') { a.run(current, api); }
        });
        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape' || $m.hasClass('bctk-hidden')) { return; }
            if (!$('#pmPdf').hasClass('bctk-hidden')) { closePdf(); } else { close(); }
        });
    }

    // ── Các khối render ────────────────────────────────────────────────────
    function kv(k, v, cls) {
        return '<div class="pm-f' + (cls ? ' ' + cls : '') + '"><span class="pm-f__k">' + esc(k)
            + '</span><span class="pm-f__v">' + esc(dash(v)) + '</span></div>';
    }

    function renderToolbar(r) {
        var acts = (opts.actions || []).filter(function (a) {
            return typeof a.when !== 'function' || a.when(r);
        });
        $('#pmActions').html(acts.map(function (a) {
            return '<button type="button" class="bctk-btn'
                + (a.cls ? ' bctk-btn--' + a.cls : '')
                + '" data-pm-action="' + esc(a.id) + '">' + esc(a.label) + '</button>';
        }).join(''));
        $('#pmActionMsg').text('').removeAttr('data-type');
    }

    function renderDoc(r) {
        $('#pmDocGrid').html([
            kv('Lý do', r.ly_do),
            kv('Kho / Mã shop', r.ma_shop),
            kv('Số phiếu xuất', r.so_phieu_xuat),
            kv('Ngày xuất', ngay(r.ngay_xuat, true)),
            kv('Số hóa đơn', r.so_hd),
            kv('Seri', r.seri),
            kv('Mẫu HĐ', r.mau_hd),
            kv('Ngày hóa đơn', ngay(r.ngay_hd, true)),
            kv('Hình thức TT', r.httt),
            kv('Trạng thái VAT', r.trang_thai_vat),
            kv('Tỷ lệ thuế', rate(r.ty_le_thue)),
            kv('Số SO', r.so_so),
            kv('SL bản ghi', r.sl_ban_ghi)
        ].join(''));

        $('#pmSeller').html(
            '<span class="pm-doc__seller-k">ĐƠN VỊ BÁN HÀNG</span>'
            + '<span class="pm-doc__seller-v">' + esc(dash(r.ten_cty_ban))
            + ' <i>·</i> MST ' + esc(dash(r.mst_ban))
            + ' <i>·</i> ' + esc(dash(r.dchi_ban))
            + ' <i>·</i> ĐT ' + esc(dash(r.dt_ban)) + '</span>'
        );
    }

    function renderLines(r) {
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
        if (!body) {
            body = '<tr><td colspan="16" class="pm-lines__empty">Phiếu không có dòng hàng nào.</td></tr>';
        }
        $('#pmLinesBody').html(body);

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

    function renderFoot(r) {
        $('#pmCust').html([
            kv('Mã KH (SĐT)', r.ma_kh),
            kv('Tên khách', r.ten_kh),
            kv('Tên công ty', r.ten_cty_mua),
            kv('Mã số thuế', r.mst_mua),
            kv('Địa chỉ', r.dchi_mua),
            kv('Điện thoại', r.dt_mua),
            kv('Email', r.email_mua)
        ].join(''));

        $('#pmStaff').html([
            kv('Nhân viên xuất', r.nv_ten),
            kv('userID xuất', r.user_id)
        ].join(''));
        var note = String(r.ghi_chu || '').trim();
        $('#pmOrderNote')
            .toggleClass('bctk-hidden', note === '')
            .html('<span class="pm-f__k">Ghi chú phiếu</span><div class="pm-foot3__notebox">' + esc(note) + '</div>');

        $('#pmTotBefore').text(money(r.tt_chua_thue));
        $('#pmTotTax').text(money(r.tong_thue));
        $('#pmTotCk').text(money(r.tong_ck));
        $('#pmTotGrand').text(money(r.thanh_tien));
        $('#pmTotWords').text(r.thanh_tien_chu || '');
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
        toast: function (type, msg) {
            $('#pmActionMsg').text(msg || '').attr('data-type', type || 'info');
        },
        busy: function (on) { $('#pmActions button').prop('disabled', !!on); },
        close: close,
        payload: function () { return current; }
    };

    function open(payload, options) {
        build();
        current = payload || {};
        opts = options || {};

        $('#pmTitle').text(opts.title || 'Chi tiết chứng từ');
        $('#pmCode').text(current.so_phieu_xuat || current.code || '');
        $('#pmState')
            .attr('class', 'pm-badge ' + stateCls(current.vat_state))
            .text(current.trang_thai_vat || '');
        $('#pmZ').toggleClass('bctk-hidden', !Number(current.is_z));

        renderToolbar(current);
        renderDoc(current);
        renderLines(current);
        renderFoot(current);

        if (opts.note) {
            $('#pmNote').text(opts.note).removeClass('bctk-hidden');
        } else {
            $('#pmNote').addClass('bctk-hidden').text('');
        }

        closePdf();
        $('#pmModal').removeClass('bctk-hidden').attr('aria-hidden', 'false');
    }

    function close() {
        $('#pmModal').addClass('bctk-hidden').attr('aria-hidden', 'true');
        closePdf();
        current = null;
    }

    window.TGSBctkPhieuModal = { open: open, close: close, api: api };
})(jQuery);
