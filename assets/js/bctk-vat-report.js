/**
 * BC_TK — Báo cáo VAT: Phiếu xuất bán (VAT) & Phiếu điều chỉnh giảm (VAT).
 *
 * Dùng lại toàn bộ bộ máy của bctk-filter.js (chạy theo batch từng site, thanh
 * tiến độ, xin lại nonce). Trang này chỉ khai:
 *   - cách vẽ 32 cột  (rowHtml + cellText)
 *   - cách cộng dòng tổng (4 cột tiền)
 *   - bấm dòng → mở modal xem chi tiết + PDF hoá đơn
 *   - hai <select> lọc riêng (loại phiếu / thông tin VAT) → đổi là chạy lại
 *
 * Xem docs/bao-cao-vat-phieu-xuat-ban-va-dieu-chinh.md.
 */
(function ($) {
    'use strict';

    var CFG = window.tgsBctkVatReport || {};

    function ngay(s, withTime) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m) { return ''; }
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

        // ── Đổi bộ lọc riêng → chạy lại (dữ liệu cũ không còn đúng) ────────────
        $('#bctkVatBillScope, #bctkVatInfoFilter').on('change', function () {
            if ($('.bctk-site:checked').length) { $(document).trigger('bctk:search'); }
        });

        /*
         * Chữ của từng cột — PHẢI khớp thứ tự <thead> (32 cột). Base dùng hàm
         * này cho cả lọc theo cột lẫn xuất Excel.
         */
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
            if (['issue_error', 'cqt_error', 'validate_error', 'error'].indexOf(s) !== -1) { return 'bctk-vat-st--err'; }
            if (['pending', 'processing', 'blocked'].indexOf(s) !== -1) { return 'bctk-vat-st--wait'; }
            return 'bctk-vat-st--none';
        }

        function td(v, cls) { return '<td class="' + (cls || '') + '">' + esc(v) + '</td>'; }
        function tdNum(v) { return '<td class="c-num' + (v < 0 ? ' neg' : '') + '">' + fmt(v) + '</td>'; }

        function rowHtml(r, i) {
            return '<tr data-i="' + i + '" class="bctk-vat-row' + (r.is_z ? ' bctk-vat-row--z' : '') + '"'
                + ' title="Bấm để xem chi tiết chứng từ">'
                + td(r.ma_shop, 'c-sku')
                + td(r.seri, 'c-sku')
                + td(r.mau_hd, 'c-sku')
                + td(r.httt, 'c-sku')
                + td(r.ma_kh, 'c-sku')
                + td(r.so_hd, 'c-sku')
                + '<td class="c-name" title="' + esc(r.ghi_chu) + '">' + esc(r.ghi_chu) + '</td>'
                + tdNum(r.tt_chua_thue)
                + td(ngay(r.ngay_hd, true), 'c-sku')
                + tdNum(r.tong_thue)
                + tdNum(r.thanh_tien)
                + '<td class="c-name">' + esc(r.thanh_tien_chu) + '</td>'
                + tdNum(r.tong_ck)
                + td(rateText(r.ty_le_thue), 'c-num')
                + '<td class="c-name" title="' + esc(r.ten_cty_mua) + '">' + esc(r.ten_cty_mua) + '</td>'
                + td(r.ten_kh, 'c-name')
                + '<td class="c-name" title="' + esc(r.dchi_mua) + '">' + esc(r.dchi_mua) + '</td>'
                + td(r.email_mua, 'c-sku')
                + td(r.dt_mua, 'c-sku')
                + td(r.mst_mua, 'c-sku')
                + '<td class="c-name" title="' + esc(r.dchi_ban) + '">' + esc(r.dchi_ban) + '</td>'
                + '<td class="c-name" title="' + esc(r.ten_cty_ban) + '">' + esc(r.ten_cty_ban) + '</td>'
                + td(r.dt_ban, 'c-sku')
                + td(r.mst_ban, 'c-sku')
                + td(ngay(r.ngay_xuat, true), 'c-sku')
                + td(r.so_phieu_xuat, 'c-sku')
                + td(r.nv_ten, 'c-name')
                + td(r.ly_do, 'c-sku')
                + '<td class="c-sku"><span class="bctk-vat-st ' + stateClass(r) + '">' + esc(r.trang_thai_vat) + '</span></td>'
                + td(r.so_so, 'c-sku')
                + '<td class="c-num">' + fmt(r.sl_ban_ghi) + '</td>'
                + td(r.user_id ? String(r.user_id) : '', 'c-sku')
                + '</tr>';
        }

        // ── Dòng tổng ────────────────────────────────────────────────────────
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
                    table: table,
                    rows: rows,
                    rowHtml: rowHtml,
                    cellText: cellText,
                    onFilter: function (kept) { viewRows = kept; footer(kept); }
                });
            } else {
                var buf = [];
                for (var i = 0; i < rows.length; i++) { buf.push(rowHtml(rows[i], i)); }
                $('#bctkBody').html(buf.join(''));
            }
            footer(rows);
        }, function () {
            footer(viewRows);
        });

        // ── Modal chi tiết ───────────────────────────────────────────────────
        var $modal = $('#bctkVatModal');

        function set(field, val) {
            $modal.find('[data-vat-field="' + field + '"]').text(val == null ? '' : val);
        }

        function openModal(r) {
            set('ma_phieu', r.so_phieu_xuat || '');
            set('ban_ten', r.ten_cty_ban);
            set('ban_mst', r.mst_ban);
            set('ban_dchi', r.dchi_ban);
            set('ban_dt', r.dt_ban);
            set('mua_ten', r.ten_kh);
            set('mua_cty', r.ten_cty_mua);
            set('mua_mst', r.mst_mua);
            set('mua_dchi', r.dchi_mua);
            set('mua_dt', r.dt_mua);
            set('mua_email', r.email_mua);
            set('so_phieu_xuat', r.so_phieu_xuat);
            set('so_hd', r.so_hd || '—');
            set('seri', r.seri || '—');
            set('mau_hd', r.mau_hd || '—');
            set('ngay_xuat', ngay(r.ngay_xuat, true));
            set('ngay_hd', ngay(r.ngay_hd, true) || '—');
            set('httt', r.httt);
            set('ly_do', r.ly_do);
            set('trang_thai_vat', r.trang_thai_vat);
            set('sum_ck', fmt(r.tong_ck));
            set('sum_chua_thue', fmt(r.tt_chua_thue));
            set('sum_thue', fmt(r.tong_thue));
            set('sum_thanh_tien', fmt(r.thanh_tien));
            set('thanh_tien_chu', r.thanh_tien_chu);
            set('note', isAdjust
                ? 'Phiếu điều chỉnh giảm — các số tiền mang dấu âm (phần khai giảm so với hóa đơn gốc). Nút "Xem PDF hóa đơn" mở hóa đơn GỐC của đơn bán để đối chiếu.'
                : '');

            var body = $('#bctkVatItemsBody').empty();
            (r.items || []).forEach(function (it) {
                body.append(
                    '<tr' + (it.is_gift ? ' class="bctk-vat-items__gift"' : '') + '>'
                    + '<td>' + it.stt + '</td>'
                    + '<td>' + esc(it.ma_hang || '') + '</td>'
                    + '<td>' + esc(it.ten) + (it.is_gift ? ' <em>(KM)</em>' : '') + '</td>'
                    + '<td>' + esc(it.kho || '') + '</td>'
                    + '<td>' + esc(it.dvt) + '</td>'
                    + '<td class="c-num">' + fmt(it.sl) + '</td>'
                    + '<td class="c-num">' + fmt(it.don_gia) + '</td>'
                    + '<td class="c-num">' + fmt(it.ck) + '</td>'
                    + '<td class="c-num">' + fmt(it.tien_chua_thue) + '</td>'
                    + '<td class="c-num">' + esc(rateText(it.thue_suat)) + '</td>'
                    + '<td class="c-num">' + fmt(it.tien_thue) + '</td>'
                    + '<td class="c-num">' + fmt(it.thanh_tien) + '</td>'
                    + '<td>' + esc(it.ghi_chu || '') + '</td>'
                    + '</tr>'
                );
            });

            /*
             * Nút PDF: phiếu bán cần invoice_state = 'done'. Phiếu điều chỉnh
             * luôn cho bấm (mở hóa đơn GỐC của đơn bán) — endpoint tự kiểm hóa
             * đơn gốc đã gửi CQT chưa, chưa thì báo lại ở dòng thông báo.
             */
            var canPdf = Number(r.sale_id) > 0
                && (isAdjust || String(r.vat_state) === 'done');
            $('#bctkVatPdfBtn').toggleClass('bctk-hidden', !canPdf).data('row', r);
            $('#bctkVatPdfMsg').text('');
            closePdf();

            $modal.removeClass('bctk-hidden').attr('aria-hidden', 'false');
        }

        function closeModal() {
            $modal.addClass('bctk-hidden').attr('aria-hidden', 'true');
            closePdf();
        }

        function closePdf() {
            $('#bctkVatPdfWrap').addClass('bctk-hidden');
            document.getElementById('bctkVatPdfFrame').src = 'about:blank';
        }

        $(document).on('click', '#bctkBody tr[data-i]', function () {
            var r = viewRows[parseInt(this.getAttribute('data-i'), 10)];
            if (r) { openModal(r); }
        });

        $modal.on('click', '[data-vat-close]', closeModal);
        $('#bctkVatPdfClose').on('click', closePdf);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$modal.hasClass('bctk-hidden')) { closeModal(); }
        });

        // ── Xem PDF hóa đơn (dùng endpoint sẵn có của tgs-viettel-invoice) ────
        $('#bctkVatPdfBtn').on('click', function () {
            var r = $(this).data('row');
            if (!r) { return; }

            var $msg = $('#bctkVatPdfMsg').text('Đang lấy PDF từ Viettel…');
            $(this).prop('disabled', true);

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
                    var d = out.data;
                    $('#bctkVatPdfName').text(d.file_name || 'invoice.pdf');
                    document.getElementById('bctkVatPdfFrame').src =
                        'data:application/pdf;base64,' + d.file_bytes_base64;
                    $('#bctkVatPdfWrap').removeClass('bctk-hidden');
                    $msg.text('');
                })
                .catch(function (err) {
                    $msg.text(err.message || 'Không lấy được PDF hóa đơn.');
                })
                .then(function () {
                    $('#bctkVatPdfBtn').prop('disabled', false);
                });
        });
    });
})(jQuery);
