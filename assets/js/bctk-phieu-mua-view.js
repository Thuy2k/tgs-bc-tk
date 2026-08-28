/**
 * BC_TK — Xem lại phiếu NHẬP KHO từ 2 màn báo cáo mua hàng.
 *
 * Dùng ở: Báo cáo mua hàng / Hàng trả nhà cung cấp, Tổng hợp mua hàng.
 * Bấm một dòng → mở TGSBctkPhieuMuaModal (base riêng cho phần mua).
 * CHỈ ĐỌC + sửa ghi chú. Dòng trả NCC (type 16) không mở được ở đây
 * (data-import = 0) — chỉ phiếu nhập kho type 1.
 *
 * Mỗi <tr> mang sẵn data-blog / data-import (report tự gắn).
 */
(function ($) {
    'use strict';

    var CFG = window.tgsBctkPhieuMuaView || {};

    function ajaxUrl() {
        return CFG.ajaxUrl || (window.TGS_BCTK && window.TGS_BCTK.ajaxUrl) || window.ajaxurl || '';
    }
    function nonce() {
        return CFG.nonce || (window.TGS_BCTK && window.TGS_BCTK.nonce) || '';
    }

    function saveNote(r, noteText, api) {
        var fd = new FormData();
        fd.append('action', 'tgs_bctk_phieu_mua_save_note');
        fd.append('nonce', nonce());
        fd.append('blog_id', String(r.blog_id || ''));
        fd.append('import_id', String(r.import_id || ''));
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

    function openModal(row) {
        if (!window.TGSBctkPhieuMuaModal) { return; }
        window.TGSBctkPhieuMuaModal.open(row, {
            editable: true,   // sửa ghi chú phiếu nhập kho
            actor: { id: Number(CFG.actorId || 0), name: String(CFG.actorName || '') },
            onSaveNote: saveNote
        });
    }

    $(function () {
        if (!window.TGSBctkPhieuMuaModal) { return; }

        $(document).on('click', '#bctkBody tr[data-import]', function () {
            var blogId   = Number(this.getAttribute('data-blog') || 0);
            var importId = Number(this.getAttribute('data-import') || 0);
            if (!blogId || !importId) { return; }

            var $tr = $(this);
            if ($tr.hasClass('is-loading')) { return; }
            $tr.addClass('is-loading');

            var fd = new FormData();
            fd.append('action', 'tgs_bctk_phieu_mua_view');
            fd.append('nonce', nonce());
            fd.append('blog_id', String(blogId));
            fd.append('import_id', String(importId));

            fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) {
                    if (!out || !out.success || !(out.data && out.data.row)) {
                        throw new Error((out && out.data && out.data.message) || 'Không mở được phiếu.');
                    }
                    openModal(out.data.row);
                })
                .catch(function (err) { window.alert(err.message || 'Không mở được phiếu nhập kho.'); })
                .then(function () { $tr.removeClass('is-loading'); });
        });
    });
})(jQuery);
