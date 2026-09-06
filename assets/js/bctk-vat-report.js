/**
 * BC_TK — Báo cáo VAT: Phiếu xuất bán (VAT) & Phiếu điều chỉnh giảm (VAT).
 *
 * Dùng lại bộ máy của bctk-filter.js (chạy batch từng site, tiến độ, xin nonce).
 * File này chỉ khai:
 *   - cách vẽ 32 cột (rowHtml + cellText) + cộng 4 cột tiền
 *   - hai <select> lọc riêng (loại phiếu / thông tin VAT) → đổi là chạy lại
 *   - bấm dòng → mở TGSBctkPhieuModal, đăng ký các hành động cho kế toán
 *
 * Modal + các khối bên trong nằm ở component riêng bctk-phieu-modal.js — file
 * này chỉ TRUYỀN payload + danh sách hành động vào.
 *
 * Xem docs/bao-cao-vat-phieu-xuat-ban-va-dieu-chinh.md.
 */
(function ($) {
    'use strict';

    var CFG = window.tgsBctkVatReport || {};
    var ERR_STATES = ['issue_error', 'cqt_error', 'validate_error', 'error'];

    function ngay(s, withTime) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m || m[1] === '0000') { return ''; }
        var d = m[3] + '/' + m[2] + '/' + m[1];
        return (withTime && m[4]) ? (d + ' ' + m[4] + ':' + m[5]) : d;
    }
    function rateText(v) {
        if (v === null || v === undefined || v === '') { return ''; }
        if (v === 'KCT' || v === 'Chưa khai') { return v; }
        return v + '%';
    }

    $(function () {
        var table = document.getElementById('bctkTable');
        if (!table) { return; }   // shop count 0 → chỉ có placeholder

        var B = window.TGSBctk;
        if (!B || !B.setRenderer) { return; }

        var esc = B.esc;
        var fmt = B.fmt;
        var isAdjust = CFG.kind === 'adjust';

        // ── Đổi bộ lọc riêng → chạy lại ─────────────────────────────────────
        $('#bctkVatBillScope, #bctkVatInfoFilter').on('change', function () {
            if ($('.bctk-site:checked').length) { $(document).trigger('bctk:search'); }
        });

        // ── Vẽ bảng 32 cột ─────────────────────────────────────────────────
        function cellText(r, c) {
            switch (c) {
                case 0:  return r.ma_shop || '';
                case 1:  return r.seri || '';
                case 2:  return r.mau_hd || '';
                case 3:  return r.httt || '';
                case 4:  return r.ma_kh || '';
                case 5:  return r.so_hd || '';
                case 6:  return r.ghi_chu || '';
                case 7:  return fmt(r.tt_chua_thue);
                case 8:  return ngay(r.ngay_hd, true);
                case 9:  return fmt(r.tong_thue);
                case 10: return fmt(r.thanh_tien);
                case 11: return r.thanh_tien_chu || '';
                case 12: return fmt(r.tong_ck);
                case 13: return rateText(r.ty_le_thue);
                case 14: return r.ten_cty_mua || '';
                case 15: return r.ten_kh || '';
                case 16: return r.dchi_mua || '';
                case 17: return r.email_mua || '';
                case 18: return r.dt_mua || '';
                case 19: return r.mst_mua || '';
                case 20: return r.dchi_ban || '';
                case 21: return r.ten_cty_ban || '';
                case 22: return r.dt_ban || '';
                case 23: return r.mst_ban || '';
                case 24: return ngay(r.ngay_xuat, true);
                case 25: return r.so_phieu_xuat || '';
                case 26: return r.nv_ten || '';
                case 27: return r.ly_do || '';
                case 28: return r.trang_thai_vat || '';
                case 29: return r.so_so || '';
                case 30: return fmt(r.sl_ban_ghi);
                case 31: return r.user_id ? String(r.user_id) : '';
                default: return '';
            }
        }

        function stateClass(r) {
            var s = String(r.vat_state || '');
            if (s === 'done' || s === 'issued') { return 'bctk-vat-st--ok'; }
            if (ERR_STATES.indexOf(s) !== -1) { return 'bctk-vat-st--err'; }
            if (['pending', 'processing', 'blocked'].indexOf(s) !== -1) { return 'bctk-vat-st--wait'; }
            return 'bctk-vat-st--none';
        }

        function td(v, cls) { return '<td class="' + (cls || '') + '">' + esc(v) + '</td>'; }
        function tdNum(v) { return '<td class="c-num' + (v < 0 ? ' neg' : '') + '">' + fmt(v) + '</td>'; }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '" class="bctk-vat-row' + (r.is_z ? ' bctk-vat-row--z' : '') + '"'
                + ' title="Bấm để xem chi tiết chứng từ">'
                + td(r.ma_shop, 'c-sku') + td(r.seri, 'c-sku') + td(r.mau_hd, 'c-sku')
                + td(r.httt, 'c-sku') + td(r.ma_kh, 'c-sku') + td(r.so_hd, 'c-sku')
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + tdNum(r.tt_chua_thue) + td(ngay(r.ngay_hd, true), 'c-sku')
                + tdNum(r.tong_thue) + tdNum(r.thanh_tien)
                + '<td class="c-name">' + esc(r.thanh_tien_chu) + '</td>'
                + tdNum(r.tong_ck) + td(rateText(r.ty_le_thue), 'c-num')
                + '<td class="c-name" title="' + esc(r.ten_cty_mua) + '">' + esc(r.ten_cty_mua) + '</td>'
                + td(r.ten_kh, 'c-name')
                + '<td class="c-name" title="' + esc(r.dchi_mua) + '">' + esc(r.dchi_mua) + '</td>'
                + td(r.email_mua, 'c-sku') + td(r.dt_mua, 'c-sku') + td(r.mst_mua, 'c-sku')
                + '<td class="c-name" title="' + esc(r.dchi_ban) + '">' + esc(r.dchi_ban) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten_cty_ban) + '">' + esc(r.ten_cty_ban) + '</td>'
                + td(r.dt_ban, 'c-sku') + td(r.mst_ban, 'c-sku')
                + td(ngay(r.ngay_xuat, true), 'c-sku') + td(r.so_phieu_xuat, 'c-sku')
                + td(r.nv_ten, 'c-name') + td(r.ly_do, 'c-sku')
                + '<td class="c-sku"><span class="bctk-vat-st ' + stateClass(r) + '">' + esc(r.trang_thai_vat) + '</span></td>'
                + td(r.so_so, 'c-sku')
                + '<td class="c-num">' + fmt(r.sl_ban_ghi) + '</td>'
                + td(r.user_id ? String(r.user_id) : '', 'c-sku')
                + '</tr>';
        }

        var viewRows = [];

        function footer(rows) {
            var t = { a: 0, b: 0, c: 0, d: 0 };
            rows.forEach(function (r) {
                t.a += (r.tt_chua_thue || 0);
                t.b += (r.tong_thue || 0);
                t.c += (r.thanh_tien || 0);
                t.d += (r.tong_ck || 0);
            });
            $('#fTtChuaThue').text(fmt(t.a)).toggleClass('neg', t.a < 0);
            $('#fTongThue').text(fmt(t.b)).toggleClass('neg', t.b < 0);
            $('#fThanhTien').text(fmt(t.c)).toggleClass('neg', t.c < 0);
            $('#fTongCk').text(fmt(t.d)).toggleClass('neg', t.d < 0);
        }

        B.setRenderer(function (rows) {
            viewRows = rows;
            var ds = window.TGSDesignSystem;
            if (ds && ds.virtualBody) {
                ds.virtualBody({
                    table: table, rows: rows, rowHtml: rowHtml, cellText: cellText,
                    onFilter: function (kept) { viewRows = kept; footer(kept); }
                });
            } else {
                var buf = [];
                for (var i = 0; i < rows.length; i++) { buf.push(rowHtml(rows[i], i)); }
                $('#bctkBody').html(buf.join(''));
            }
            footer(rows);
        }, function () { footer(viewRows); });

        // ── Hành động cho kế toán ──────────────────────────────────────────
        //
        // Điều kiện hiện nút bám theo bill-z-va-hang-tang.md + yêu cầu:
        //   chưa phát hành  → sửa phiếu, tách bill Z, gửi hoá đơn
        //   đang lỗi        → gửi lại
        //   đã phát hành    → điều chỉnh / thay thế
        //   bill Z (nội bộ) → sửa thoải mái
        //
        // ⚠️ Luồng gửi lại / tách bill / chuyển bill / sửa phiếu của tgs_pos →
        // tgs-viettel-invoice bám HẰNG SỐ BẢNG theo site (TGS_TABLE_*), không
        // chạy chéo site từ màn tổng được (xem TGS_BCTK_Ajax::vat_pdf). Nên các
        // hành động "chưa phát hành" MỞ ĐÚNG màn "DS Gửi Thuế" của shop đó —
        // nơi luồng chạy y hệt, native. "Xem PDF" thì bc-tk tự lấy được.
        var issued = function (r) { return String(r.vat_state) === 'done'; };
        var errored = function (r) { return ERR_STATES.indexOf(String(r.vat_state)) !== -1; };
        var notIssued = function (r) { return !issued(r) && !isAdjust; };

        function openPosTax(r, api) {
            if (!r.pos_tax_url) {
                api.toast('error', 'Không có đường dẫn màn Gửi Thuế của shop này.');
                return;
            }
            // Bung sẵn đúng bill trên màn "DS Gửi Thuế" (deep-link ?open_code=&open_date=).
            var q = '?open_code=' + encodeURIComponent(r.so_phieu_xuat || '');
            var ymd = /^(\d{4}-\d{2}-\d{2})/.exec(String(r.ngay_xuat || ''));
            if (ymd) { q += '&open_date=' + encodeURIComponent(ymd[1]); }
            window.open(r.pos_tax_url + q, '_blank', 'noopener');
            api.toast('info', 'Đã mở màn "DS Gửi Thuế" của shop kèm bill ' + (r.so_phieu_xuat || '')
                + ' ở tab mới — thao tác xong bấm "Tìm kiếm" lại để cập nhật.');
        }

        // "Hoàn hàng" / "Điều chỉnh" → mở màn "Lịch sử đơn hàng" của shop, bung
        // sẵn đúng đơn. tgs_pos đọc ?open_code + ?open_date để đặt bộ lọc rồi tự
        // viewOrderDetail. Đơn ĐÃ phát hành: hoàn ở đó = sinh phiếu điều chỉnh
        // giảm (hoặc kế toán chọn hoá đơn thay thế).
        function openPosOrders(msg) {
            return function (r, api) {
                if (!r.pos_orders_url) {
                    api.toast('error', 'Không có đường dẫn màn Lịch sử đơn hàng của shop này.');
                    return;
                }
                var q = '?open_code=' + encodeURIComponent(r.so_phieu_xuat || '');
                var ymd = /^(\d{4}-\d{2}-\d{2})/.exec(String(r.ngay_xuat || ''));
                if (ymd) { q += '&open_date=' + encodeURIComponent(ymd[1]); }
                window.open(r.pos_orders_url + q, '_blank', 'noopener');
                api.toast('info', (msg || 'Đã mở "Lịch sử đơn hàng" của shop kèm đơn ')
                    + (r.so_phieu_xuat || '') + ' ở tab mới.');
            };
        }

        function fetchPdf(r, api) {
            api.busy(true);
            api.toast('info', 'Đang lấy PDF từ Viettel…');
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_vat_pdf');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('blog_id', String(r.blog_id || ''));
            fd.append('sale_ledger_id', String(r.sale_id || ''));

            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
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

        // "Sửa được" (ghi chú) = bill Z, hoặc chưa phát hành hoá đơn (không phải màn điều chỉnh)
        function canEdit(r) {
            return !isAdjust && (Number(r.is_z) === 1 || !issued(r));
        }
        // "Sửa được DÒNG HÀNG" = như trên NHƯNG chưa có phiếu hoàn con.
        // Đã hoàn một phần / toàn phần → khoá dòng hàng, ghi chú vẫn cho.
        function canEditLines(r) {
            return canEdit(r) && Number(r.has_return) !== 1;
        }

        var ACTIONS = [
            {
                id: 'pdf', label: 'Xem PDF', cls: 'primary',
                when: function (r) { return Number(r.sale_id) > 0 && (isAdjust || issued(r)); },
                run: fetchPdf
            },
            {
                id: 'split', label: 'Tách / chuyển bill Z ↗',
                when: function (r) { return notIssued(r) && !r.is_z; },
                run: openPosTax
            },
            {
                id: 'issue', label: 'Gửi hoá đơn thuế ↗',
                when: function (r) { return notIssued(r) && !r.is_z; },
                run: openPosTax
            },
            {
                id: 'resend', label: 'Gửi lại ↗',
                when: function (r) { return errored(r); },
                run: openPosTax
            },
            {
                // Chưa phát hành: hoàn hàng thường (chưa dính hoá đơn thuế).
                id: 'return', label: 'Hoàn hàng ↗',
                when: function (r) { return notIssued(r) && Number(r.sale_id) > 0; },
                run: openPosOrders('Đã mở "Lịch sử đơn hàng" để hoàn đơn ' )
            },
            {
                // ĐÃ phát hành: bắt buộc phiếu điều chỉnh giảm (hoặc hoá đơn thay
                // thế). Mở luôn màn đơn hàng của shop, bung sẵn đơn để kế toán
                // bấm "Hoàn hàng" ngay tại đó → sinh phiếu điều chỉnh.
                id: 'adjust', label: 'Điều chỉnh (hoàn hàng) ↗',
                when: function (r) { return !isAdjust && issued(r); },
                run: openPosOrders('Đã mở "Lịch sử đơn hàng" để lập phiếu điều chỉnh giảm cho đơn ')
            },
            {
                // ĐÃ phát hành mà SAI thông tin bên mua (MST, tên đơn vị…): sửa
                // buyerInfo → phát hành hoá đơn THAY THẾ toàn bộ qua Viettel
                // (adjustmentType 3) + tự gửi CQT. Không đổi hàng hoá / tiền.
                id: 'replace', label: 'Lập HĐ thay thế',
                when: function (r) { return !isAdjust && issued(r); },
                run: function (r, api) { api.openReplForm(); }
            }
        ];

        // ── Phát hành hoá đơn thay thế ───────────────────────────────────────
        function issueReplacement(r, data, api) {
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_vat_issue_replacement');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('blog_id', String(r.blog_id || ''));
            fd.append('sale_id', String(r.sale_id || ''));
            fd.append('buyer', JSON.stringify(data.buyer || {}));
            fd.append('reason', String(data.reason || ''));

            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) {
                    if (!out || !out.success) {
                        var m = (out && out.data && out.data.message) || 'Không phát hành được hoá đơn thay thế.';
                        throw new Error(m);
                    }
                    api.closeReplForm();
                    if (out.data && out.data.row) { api.applyRow(out.data.row); }
                    api.toast('info', (out.data && out.data.message) || 'Đã phát hành hoá đơn thay thế và gửi CQT.');
                })
                .catch(function (err) { api.replError(err.message || 'Không phát hành được.'); });
        }

        // ── Lưu dòng hàng đã sửa ──────────────────────────────────────────
        function saveLines(r, lines, api) {
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_vat_save_lines');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('blog_id', String(r.blog_id || ''));
            fd.append('sale_id', String(r.sale_id || ''));
            fd.append('lines', JSON.stringify(lines));

            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) {
                    if (!out || !out.success) {
                        throw new Error((out && out.data && out.data.message) || 'Không lưu được.');
                    }
                    api.applyRow(out.data.row || null);
                    api.toast(out.data.warning ? 'error' : 'info',
                        (out.data.message || 'Đã lưu.') + (out.data.warning ? ' ⚠ ' + out.data.warning : ''));
                })
                .catch(function (err) { api.toast('error', err.message || 'Không lưu được dòng hàng.'); })
                .then(function () { api.busy(false); });
        }

        function searchProduct(term, cb) {
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_product_search');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('q', String(term || ''));
            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) { cb((out && out.success && out.data.items) || []); })
                .catch(function () { cb([]); });
        }

        function loadUnits(skus, cb) {
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_product_units');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('skus', JSON.stringify(skus || []));
            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (out) { cb((out && out.success && out.data.units) || {}); })
                .catch(function () { cb({}); });
        }

        function saveNote(r, noteText, api) {
            var fd = new FormData();
            fd.append('action', 'tgs_bctk_vat_save_note');
            fd.append('nonce', (window.TGS_BCTK && window.TGS_BCTK.nonce) || '');
            fd.append('blog_id', String(r.blog_id || ''));
            fd.append('sale_id', String(r.sale_id || ''));
            fd.append('note', String(noteText || ''));

            fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
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

        $(document).on('click', '#bctkBody tr[data-i]', function () {
            var r = viewRows[parseInt(this.getAttribute('data-i'), 10)];
            if (!r || !window.TGSBctkPhieuModal) { return; }
            window.TGSBctkPhieuModal.open(r, {
                title: isAdjust ? 'Chi tiết phiếu điều chỉnh giảm (VAT)' : 'Chi tiết phiếu xuất bán (VAT)',
                note: isAdjust
                    ? 'Phiếu điều chỉnh giảm — số tiền mang dấu âm (phần khai giảm so với hoá đơn gốc). "Xem PDF hoá đơn" mở hoá đơn GỐC để đối chiếu.'
                    : '',
                actions: ACTIONS,
                editable: canEdit(r),            // ghi chú
                editableLines: canEditLines(r),  // dòng hàng (khoá nếu đã có phiếu hoàn con)
                lockLinesNote: canEdit(r) && !canEditLines(r)
                    ? 'Phiếu đã có phiếu hoàn con — chỉ sửa được ghi chú, không sửa dòng hàng.'
                    : '',
                // Người đang đăng nhập can thiệp phiếu (kế toán) — khác nhân viên xuất gốc.
                actor: { id: Number(CFG.actorId || 0), name: String(CFG.actorName || '') },
                onSaveLines: saveLines,
                onSaveNote: saveNote,
                onIssueReplacement: issueReplacement,
                onSearchProduct: searchProduct,
                onLoadUnits: loadUnits,
                /* Sau khi lưu: chạy lại tìm kiếm để bảng khớp với dữ liệu mới */
                onRowUpdated: function () {
                    if ($('.bctk-site:checked').length) { $(document).trigger('bctk:search'); }
                }
            });
        });
    });
})(jQuery);
