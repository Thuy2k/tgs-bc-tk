/**
 * BC_TK — Xem lại phiếu bán từ các màn BÁO CÁO BÁN HÀNG.
 *
 * Dùng ở: Sổ CSKH, Báo cáo bán hàng / Hàng bán trả lại, Tổng hợp bán hàng.
 * Bấm một dòng → mở component modal dùng chung (bctk-phieu-modal.js) đúng luồng
 * "xem chi tiết" của màn VAT: chứng từ + bên bán/bên mua + dòng hàng + tổng tiền
 * (TGS_Money) + nút Xuất Excel phiếu.
 *
 * KHÁC màn VAT: đây CHỈ ĐỌC. Cho sửa GHI CHÚ (khi phiếu chưa phát hành / bill Z),
 * KHÔNG cho sửa dòng hàng — muốn can thiệp item phải vào Bán hàng → Quản lý VAT.
 *
 * Mỗi <tr> trong bảng báo cáo đã mang sẵn data-blog / data-sale (report tự gắn).
 * Không phụ thuộc mảng viewRows nội bộ của từng màn.
 */
(function ($) {
    'use strict';

    var CFG = window.tgsBctkPhieuView || {};

    function ajaxUrl() {
        return CFG.ajaxUrl || (window.TGS_BCTK && window.TGS_BCTK.ajaxUrl) || window.ajaxurl || '';
    }
    function nonce() {
        return CFG.nonce || (window.TGS_BCTK && window.TGS_BCTK.nonce) || '';
    }

    var issued  = function (r) { return String(r.vat_state) === 'done'; };
    // Ghi chú sửa được khi: bill Z, hoặc chưa phát hành hoá đơn (giống màn VAT).
    var canNote = function (r) { return Number(r.is_z) === 1 || !issued(r); };

    function fetchPdf(r, api) {
        api.busy(true);
        api.toast('info', 'Đang lấy PDF từ Viettel…');
        var fd = new FormData();
        fd.append('action', 'tgs_bctk_vat_pdf');
        fd.append('nonce', nonce());
        fd.append('blog_id', String(r.blog_id || ''));
        fd.append('sale_ledger_id', String(r.sale_id || ''));

        fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (out) {
                if (!out || !out.success || !(out.data && out.data.file_bytes_base64)) {
                    throw new Error((out && out.data && out.data.message) || 'Không lấy được PDF.');
                }
                api.pdf(out.data.file_bytes_base64, out.data.file_name);
                api.toast('', '');
            })
            .catch(function (err) { api.toast('error', err.message || 'Không lấy được PDF hoá đơn.'); })
            .then(function () { api.busy(false); });
    }

    function saveNote(r, noteText, api) {
        var fd = new FormData();
        fd.append('action', 'tgs_bctk_vat_save_note');
        fd.append('nonce', nonce());
        fd.append('blog_id', String(r.blog_id || ''));
        fd.append('sale_id', String(r.sale_id || ''));
        fd.append('note', String(noteText || ''));

        fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (out) {
                if (!out || !out.success) {
                    throw new Error((out && out.data && out.data.message) || 'Không lưu được.');
                }
                api.applyNote(out.data.ghi_chu || '');
                api.toast('info', out.data.message || 'Đã lưu ghi chú.');
            })
            .catch(function (err) { api.toast('error', err.message || 'Không lưu được ghi chú.'); })
            .then(function () { api.busy(false); });
    }

    var ACTIONS = [
        {
            id: 'pdf', label: 'Xem PDF', cls: 'primary',
            when: function (r) { return Number(r.sale_id) > 0 && issued(r); },
            run: fetchPdf
        }
    ];

    function openModal(row) {
        if (!window.TGSBctkPhieuModal) { return; }
        window.TGSBctkPhieuModal.open(row, {
            title: 'Chi tiết phiếu bán hàng',
            actions: ACTIONS,
            editable: canNote(row),   // chỉ GHI CHÚ
            editableLines: false,     // KHÔNG cho sửa dòng hàng ở màn báo cáo
            lockLinesNote: 'Màn báo cáo chỉ để xem. Sửa dòng hàng phải vào Bán hàng → Quản lý VAT.',
            actor: { id: Number(CFG.actorId || 0), name: String(CFG.actorName || '') },
            onSaveNote: saveNote
        });
    }

    $(function () {
        if (!window.TGSBctkPhieuModal) { return; }

        $(document).on('click', '#bctkBody tr[data-sale]', function () {
            var blogId = Number(this.getAttribute('data-blog') || 0);
            var saleId = Number(this.getAttribute('data-sale') || 0);
            if (!blogId || !saleId) { return; }

            var $tr = $(this);
            if ($tr.hasClass('is-loading')) { return; }
            $tr.addClass('is-loading');

            var fd = new FormData();
            fd.append('action', 'tgs_bctk_phieu_view');
            fd.append('nonce', nonce());
            fd.append('blog_id', String(blogId));
            fd.append('sale_id', String(saleId));

            fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) {
                    if (!out || !out.success || !(out.data && out.data.row)) {
                        throw new Error((out && out.data && out.data.message) || 'Không mở được phiếu.');
                    }
                    openModal(out.data.row);
                })
                .catch(function (err) {
                    window.alert(err.message || 'Không mở được phiếu bán.');
                })
                .then(function () { $tr.removeClass('is-loading'); });
        });
    });
})(jQuery);
