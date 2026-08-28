/**
 * BC_TK — MODAL XEM MỘT PHIẾU NHẬP KHO (base RIÊNG cho phần MUA HÀNG).
 *
 * Vì sao tách khỏi bctk-phieu-modal.js (bên bán):
 *   - Cột mua là ĐƠN GIÁ TRƯỚC THUẾ, TRƯỚC CHIẾT KHẤU (như lúc tạo phiếu nhập),
 *     bên bán là đơn giá SAU thuế bán cho khách → nhìn khác hẳn.
 *   - Khối thông tin có NHÀ CUNG CẤP (mã / MST / địa chỉ / ĐT), không có khách.
 *   - Phiếu nhập kho KHÔNG có phiếu cha; không dính hoá đơn thuế nên không có PDF.
 *
 * CHỈ ĐỌC. Cho sửa GHI CHÚ phiếu (nút riêng). Không sửa dòng hàng — muốn can
 * thiệp item phải vào luồng sửa phiếu nhập kho gốc.
 *
 * Tiền vẫn đi sát mo-hinh-tien-va-bang-local-ledger-item.md: server tính mỗi
 * dòng qua TGS_Money::line(qty, giá_trước_thuế, ck_trước_thuế, thuế%), KHÔNG làm
 * tròn (đây là giá vốn). Modal chỉ hiển thị số server trả về.
 *
 * Gọi: TGSBctkPhieuMuaModal.open(payload, {
 *          editable: bool,                 // cho sửa ghi chú
 *          actor: { id, name },            // người đang can thiệp
 *          onSaveNote: fn(payload, text, api)
 *       })
 *   api = { toast, busy, close, applyNote(text) }
 *
 * Dùng chung CSS .pm-* trong bctk.css.
 */
(function ($) {
    'use strict';

    var built = false;
    var current = null;
    var opts = {};
    var nf = new Intl.NumberFormat('vi-VN');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? 0 : n;
    }
    function money(n) { return (n === null || n === undefined || n === '') ? '0' : nf.format(Math.round(num(n))); }
    function money2(n) {
        n = num(n);
        return Number.isInteger(n) ? nf.format(n) : nf.format(Math.round(n * 100) / 100);
    }
    function dash(v) { return (v === 0 || v) && String(v).trim() !== '' ? String(v) : '—'; }
    function ngay(s, withTime) {
        s = String(s || '');
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(s)) { return s; }   // đã là dd/mm/yyyy
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(s);
        if (!m || m[1] === '0000') { return ''; }
        var d = m[3] + '/' + m[2] + '/' + m[1];
        return (withTime && m[4]) ? (d + ' ' + m[4] + ':' + m[5]) : d;
    }
    function taxDisplay(v) {
        if (v === 'KCT') { return 'KCT'; }
        if (v === '' || v == null) { return '—'; }
        return (num(v) || 0) + '%';
    }
    function kv(k, v) {
        return '<div class="pm-f"><span class="pm-f__k">' + esc(k) + '</span>'
            + '<span class="pm-f__v">' + esc(dash(v)) + '</span></div>';
    }

    function build() {
        if (built) { return; }
        built = true;

        var html =
        '<div class="pm-modal bctk-hidden" id="pmmModal" aria-hidden="true">'
        + '<div class="pm-modal__overlay" data-pmm-close></div>'
        + '<div class="pm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="pmmTitle">'

        +   '<header class="pm-modal__head">'
        +     '<div class="pm-modal__title">'
        +       '<strong id="pmmTitle">Chi tiết phiếu nhập kho</strong>'
        +       '<span class="pm-modal__code" id="pmmCode"></span>'
        +     '</div>'
        +     '<button type="button" class="pm-modal__x" data-pmm-close title="Đóng (Esc)">&times;</button>'
        +   '</header>'

        +   '<div class="pm-toolbar">'
        +     '<div class="pm-actions" id="pmmActions">'
        +       '<button type="button" class="bctk-btn" id="pmmExport">⭳ Xuất Excel phiếu</button>'
        +     '</div>'
        +     '<div class="pm-actions__msg" id="pmmMsg"></div>'
        +     '<button type="button" class="bctk-btn pm-toolbar__close" data-pmm-close>Đóng</button>'
        +   '</div>'

        +   '<div class="pm-doc">'
        +     '<div class="pm-doc__grid" id="pmmDocGrid"></div>'
        +     '<div class="pm-doc__seller" id="pmmSup"></div>'
        +   '</div>'

        +   '<div class="pm-lines" data-ds-no-export data-ds-no-colcfg data-ds-no-filter>'
        +     '<table class="pm-lines__table" data-ds-no-grid data-ds-no-export data-ds-no-colcfg data-ds-no-filter>'
        +       '<colgroup>'
        +         '<col style="width:44px"><col style="width:116px"><col style="width:210px">'
        +         '<col style="width:60px"><col style="width:60px"><col style="width:70px">'
        +         '<col style="width:78px"><col style="width:104px"><col style="width:110px">'
        +         '<col style="width:96px"><col style="width:64px"><col style="width:64px">'
        +         '<col style="width:96px"><col style="width:110px"><col style="width:96px">'
        +         '<col style="width:96px"><col style="width:160px">'
        +       '</colgroup>'
        +       '<thead><tr>'
        +         '<th>STT</th><th>Mã hàng</th><th>Tên hàng</th><th>Kho</th>'
        +         '<th class="c-num">SL</th><th>ĐVT</th><th class="c-num">SL ĐVCB</th>'
        +         '<th class="c-num" title="Đơn giá 1 ĐVCB — TRƯỚC thuế, TRƯỚC chiết khấu">Đơn giá</th>'
        +         '<th class="c-num" title="Tiền hàng = SL ĐVCB × Đơn giá (trước thuế, trước CK)">TT không VAT</th>'
        +         '<th class="c-num">CK (VNĐ)</th><th class="c-num">CK %</th>'
        +         '<th class="c-num">Thuế %</th><th class="c-num">Thuế VNĐ</th>'
        +         '<th class="c-num">Thành tiền</th><th>Số lô</th><th>EXP</th><th>Ghi chú</th>'
        +       '</tr></thead>'
        +       '<tbody id="pmmBody"></tbody>'
        +       '<tfoot id="pmmFoot"></tfoot>'
        +     '</table>'
        +   '</div>'

        +   '<div class="pm-foot3">'
        +     '<section class="pm-foot3__box">'
        +       '<h4>Nhà cung cấp</h4><dl id="pmmSupDl"></dl>'
        +     '</section>'
        +     '<section class="pm-foot3__box">'
        +       '<div class="pm-foot3__boxhead"><h4>Nhân viên &amp; ghi chú</h4>'
        +         '<button type="button" class="pm-linkbtn bctk-hidden" id="pmmNoteEdit">✎ Sửa ghi chú</button></div>'
        +       '<dl id="pmmStaff"></dl>'
        +       '<div class="pm-foot3__note" id="pmmNote"></div>'
        +     '</section>'
        +     '<section class="pm-foot3__box pm-foot3__total">'
        +       '<h4>Tổng cộng</h4>'
        +       '<div class="pm-total__row"><span>Tiền hàng (chưa thuế)</span><b id="pmmTotBefore">0</b></div>'
        +       '<div class="pm-total__row"><span>Tiền thuế</span><b id="pmmTotTax">0</b></div>'
        +       '<div class="pm-total__row"><span>Chiết khấu</span><b id="pmmTotCk">0</b></div>'
        +       '<div class="pm-total__row pm-total__row--grand"><span>Tổng thanh toán</span><b id="pmmTotGrand">0</b></div>'
        +       '<div class="pm-total__words" id="pmmTotWords"></div>'
        +     '</section>'
        +   '</div>'

        + '</div></div>';

        $('body').append(html);

        var $m = $('#pmmModal');
        $m.on('click', '[data-pmm-close]', function () { close(); });
        $m.on('click', '#pmmExport', exportPhieu);
        $m.on('click', '#pmmNoteEdit', startNoteEdit);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$m.hasClass('bctk-hidden')) { close(); }
        });
    }

    function renderDoc(r) {
        $('#pmmDocGrid').html([
            kv('Lý do nhập', (r.ly_do || '') + (r.ly_do_ten ? ' — ' + r.ly_do_ten : '')),
            kv('Kho / Mã shop', r.ma_shop || r.kho),
            kv('Số phiếu nhập', r.so_phieu), kv('Ngày c.từ', ngay(r.ngay_ct, true)),
            kv('Hạn TT', ngay(r.han_tt)), kv('Số hóa đơn', r.so_hd),
            kv('Ký hiệu HĐ', r.hd_ky_hieu), kv('Ngày HĐ', ngay(r.hd_ngay)),
            kv('SL bản ghi', (r.items || []).length)
        ].join(''));

        $('#pmmSup').html(
            '<span class="pm-doc__seller-k">NHÀ CUNG CẤP</span>'
            + '<span class="pm-doc__seller-v">' + esc(dash(r.ncc_ten))
            + ' <i>·</i> Mã ' + esc(dash(r.ncc_ma))
            + ' <i>·</i> MST ' + esc(dash(r.ncc_mst))
            + ' <i>·</i> ' + esc(dash(r.ncc_dchi))
            + ' <i>·</i> ĐT ' + esc(dash(r.ncc_dt)) + '</span>'
        );
    }

    function renderLines(r) {
        var its = r.items || [];
        var buf = its.map(function (it) {
            return '<tr>'
                + '<td class="c-num">' + esc(it.stt) + '</td>'
                + '<td>' + esc(it.ma_hang || '—') + '</td>'
                + '<td title="' + esc(it.ten) + '">' + esc(it.ten || '—') + '</td>'
                + '<td>' + esc(it.kho || '—') + '</td>'
                + '<td class="c-num">' + money2(it.sl) + '</td>'
                + '<td>' + esc(it.dvt || '—') + '</td>'
                + '<td class="c-num">' + money2(it.sl_dvcb) + '</td>'
                + '<td class="c-num">' + money2(it.don_gia) + '</td>'
                + '<td class="c-num">' + money2(it.tt_chua_ck) + '</td>'
                + '<td class="c-num">' + money2(it.ck) + '</td>'
                + '<td class="c-num">' + (num(it.ck_pct) ? money2(it.ck_pct) + '%' : '—') + '</td>'
                + '<td class="c-num">' + taxDisplay(it.thue_pct) + '</td>'
                + '<td class="c-num">' + money2(it.tien_thue) + '</td>'
                + '<td class="c-num">' + money2(it.thanh_tien) + '</td>'
                + '<td>' + esc(it.so_lo || '—') + '</td>'
                + '<td>' + esc(ngay(it.exp) || '—') + '</td>'
                + '<td title="' + esc(it.ghi_chu) + '">' + esc(it.ghi_chu || '') + '</td>'
                + '</tr>';
        }).join('');
        $('#pmmBody').html(buf || '<tr><td colspan="17" style="text-align:center;padding:14px">Phiếu không có dòng hàng.</td></tr>');

        $('#pmmFoot').html('<tr>'
            + '<td colspan="8" style="text-align:right;font-weight:600">Tổng cộng (' + its.length + ' dòng)</td>'
            + '<td class="c-num">' + money2(r.tt_chua_ck) + '</td>'
            + '<td class="c-num">' + money2(r.tong_ck) + '</td><td></td><td></td>'
            + '<td class="c-num">' + money2(r.tong_thue) + '</td>'
            + '<td class="c-num">' + money2(r.thanh_tien) + '</td>'
            + '<td colspan="3"></td></tr>');
    }

    function renderFoot(r) {
        $('#pmmSupDl').html([
            kv('Mã NCC', r.ncc_ma), kv('Tên NCC', r.ncc_ten), kv('Mã số thuế', r.ncc_mst),
            kv('Địa chỉ', r.ncc_dchi), kv('Điện thoại', r.ncc_dt), kv('Email', r.ncc_email)
        ].join(''));

        var actor = opts.actor || {};
        $('#pmmStaff').html([
            kv('Nhân viên nhập', r.nv_ten), kv('userID nhập', r.user_id),
            (actor.name || actor.id)
                ? kv('Người thao tác (đang can thiệp)', actor.name) + kv('userID thao tác', actor.id)
                : ''
        ].join(''));

        var note = String(r.ghi_chu || '').trim();
        $('#pmmNote').html('<span class="pm-f__k">Ghi chú phiếu</span>'
            + '<div class="pm-foot3__notebox" id="pmmNoteBox">' + (note ? esc(note) : '<i>—</i>') + '</div>');
        $('#pmmNoteEdit').toggleClass('bctk-hidden', typeof opts.onSaveNote !== 'function' || !opts.editable);

        $('#pmmTotBefore').text(money2(r.tt_chua_thue));
        $('#pmmTotTax').text(money2(r.tong_thue));
        $('#pmmTotCk').text(money2(r.tong_ck));
        $('#pmmTotGrand').text(money(r.thanh_tien));
        $('#pmmTotWords').text(r.thanh_tien_chu || '');
    }

    function renderAll(r) {
        $('#pmmCode').text(r.so_phieu ? '· ' + r.so_phieu : '');
        renderDoc(r);
        renderLines(r);
        renderFoot(r);
    }

    // ── Sửa ghi chú ─────────────────────────────────────────────────────
    function startNoteEdit() {
        var cur = String(current.ghi_chu || '');
        $('#pmmNote').html(
            '<span class="pm-f__k">Ghi chú phiếu</span>'
            + '<textarea class="pm-note__ta" id="pmmNoteTa" rows="3">' + esc(cur) + '</textarea>'
            + '<div class="pm-note__act">'
            + '<button type="button" class="bctk-btn bctk-btn--primary" id="pmmNoteSave">Lưu</button> '
            + '<button type="button" class="bctk-btn" id="pmmNoteCancel">Huỷ</button></div>'
        );
        $('#pmmNoteEdit').addClass('bctk-hidden');
        $('#pmmNoteTa').focus();
        $('#pmmNoteSave').on('click', function () {
            if (typeof opts.onSaveNote !== 'function') { return; }
            api.busy(true);
            api.toast('info', 'Đang lưu ghi chú…');
            opts.onSaveNote(current, $('#pmmNoteTa').val(), api);
        });
        $('#pmmNoteCancel').on('click', function () { renderFoot(current); });
    }

    // ── Xuất Excel một phiếu ────────────────────────────────────────────
    function exportPhieu() {
        var r = current;
        if (!r) { return; }
        var actor = opts.actor || {};

        function hRow(k, v) {
            return '<tr><td style="font-weight:bold;background:#f1f5f9">' + esc(k) + '</td><td>' + esc(dash(v)) + '</td></tr>';
        }
        var head = '<table border="1"><tbody>'
            + hRow('Loại phiếu', 'Phiếu nhập kho — Lý do ' + (r.ly_do || '') + (r.ly_do_ten ? ' (' + r.ly_do_ten + ')' : ''))
            + hRow('Số phiếu nhập', r.so_phieu) + hRow('Kho / Mã shop', r.ma_shop || r.kho)
            + hRow('Ngày c.từ', ngay(r.ngay_ct, true)) + hRow('Hạn TT', ngay(r.han_tt))
            + hRow('Số hóa đơn', r.so_hd) + hRow('Ký hiệu HĐ', r.hd_ky_hieu) + hRow('Ngày HĐ', ngay(r.hd_ngay))
            + hRow('Nhà cung cấp', dash(r.ncc_ten) + ' · Mã ' + dash(r.ncc_ma) + ' · MST ' + dash(r.ncc_mst)
                + ' · ' + dash(r.ncc_dchi) + ' · ĐT ' + dash(r.ncc_dt))
            + hRow('Nhân viên nhập', r.nv_ten + ' (uID ' + (r.user_id || '') + ')')
            + (actor.name || actor.id ? hRow('Người thao tác', actor.name + ' (uID ' + (actor.id || '') + ')') : '')
            + hRow('Ghi chú phiếu', r.ghi_chu)
            + hRow('Tiền hàng (chưa thuế)', money2(r.tt_chua_thue))
            + hRow('Tiền thuế', money2(r.tong_thue)) + hRow('Chiết khấu', money2(r.tong_ck))
            + hRow('Tổng thanh toán', money(r.thanh_tien) + ' (' + (r.thanh_tien_chu || '') + ')')
            + '</tbody></table>';

        var cols = ['STT', 'Mã hàng', 'Tên hàng', 'Kho', 'SL', 'ĐVT', 'SL ĐVCB', 'Đơn giá (trước thuế/CK)',
            'TT chưa CK', 'TT chưa VAT', 'CK (VNĐ)', 'CK %', 'Thuế %', 'Thuế VNĐ', 'Thành tiền', 'Số lô', 'EXP', 'Ghi chú'];
        var body = '<table border="1"><thead><tr>'
            + cols.map(function (c) { return '<th style="background:#e2e8f0">' + esc(c) + '</th>'; }).join('')
            + '</tr></thead><tbody>';
        (r.items || []).forEach(function (it) {
            var cells = [it.stt, it.ma_hang, it.ten, it.kho, num(it.sl), it.dvt, num(it.sl_dvcb),
                num(it.don_gia), num(it.tt_chua_ck), num(it.tt_chua_thue), num(it.ck),
                (num(it.ck_pct) ? Math.round(num(it.ck_pct) * 100) / 100 + '%' : ''),
                taxDisplay(it.thue_pct), num(it.tien_thue), num(it.thanh_tien),
                it.so_lo || '', ngay(it.exp) || '', it.ghi_chu || ''];
            body += '<tr>' + cells.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>';
        });
        body += '</tbody></table>';

        var htmlDoc = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body>'
            + '<h3>Phiếu nhập kho ' + esc(r.so_phieu || '') + '</h3>' + head + '<br>' + body + '</body></html>';

        var blob = new Blob(['﻿', htmlDoc], { type: 'application/vnd.ms-excel' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'phieu-nhap-' + (String(r.so_phieu || 'export').replace(/[^\w.-]+/g, '_')) + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
        api.toast('info', 'Đã xuất Excel phiếu ' + (r.so_phieu || '') + '.');
    }

    function close() {
        $('#pmmModal').addClass('bctk-hidden').attr('aria-hidden', 'true');
        current = null;
    }

    var api = {
        toast: function (type, msg) { $('#pmmMsg').text(msg || '').attr('data-type', type || 'info'); },
        busy: function (on) { $('#pmmModal .pm-toolbar button, #pmmNoteSave, #pmmNoteCancel').prop('disabled', !!on); },
        close: close,
        applyNote: function (text) {
            if (current) { current.ghi_chu = text; }
            renderFoot(current);
            api.busy(false);
        }
    };

    window.TGSBctkPhieuMuaModal = {
        open: function (payload, options) {
            build();
            current = payload || {};
            opts = options || {};
            renderAll(current);
            api.toast('', '');
            $('#pmmModal').removeClass('bctk-hidden').attr('aria-hidden', 'false');
        },
        close: close
    };
})(jQuery);
