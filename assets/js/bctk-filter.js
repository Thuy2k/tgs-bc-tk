/**
 * BC_TK — bộ lọc + chạy báo cáo tồn kho theo từng site.
 *
 * Nguyên tắc: mỗi site một lượt gọi AJAX, chạy tuần tự với độ song song giới
 * hạn. Không gộp 70 site vào một request vì (1) tiến độ phải chạy thật, (2) một
 * site hỏng không được kéo sập cả báo cáo, (3) tránh trần thời gian chạy PHP.
 */
(function ($) {
    'use strict';

    var CFG = window.TGS_BCTK || {};
    var rows = [];              // toàn bộ dòng đã lấy về
    var running = false;

    /* Số site chạy song song. 3 là điểm cân bằng: nhanh hơn hẳn tuần tự mà
       chưa làm nghẽn MySQL khi mỗi site quét bảng ledger lớn. */
    var CONCURRENCY = 3;

    var nf = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 });
    function fmt(n) { return (n === null || n === undefined || n === '') ? '—' : nf.format(n); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /* Bỏ dấu để gõ "vinh tuong" vẫn ra "Vĩnh Tường" */
    function norm(s) {
        return String(s == null ? '' : s)
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/đ/g, 'd')
            .trim();
    }

    // ── Bộ lọc ──────────────────────────────────────────────────────────────

    function selectedSites() {
        return $('.bctk-site:checked').map(function () { return parseInt(this.value, 10); }).get();
    }

    function siteById(id) {
        for (var i = 0; i < (CFG.sites || []).length; i++) {
            if (CFG.sites[i].blog_id === id) return CFG.sites[i];
        }
        return null;
    }

    /*
     * Mã kho đang tích, gom THEO TỪNG SITE.
     *
     * Giá trị ô tích có dạng "blogId::mã" chứ không phải mã trần: hai site khác
     * nhau hoàn toàn có thể trùng mã phân kho, gửi mã trần xuống server thì lọc
     * nhầm site.
     */
    function selectedZonesByBlog() {
        var map = {};
        $('.bctk-zone:checked').each(function () {
            var p = String(this.value).split('::');
            var bid = parseInt(p[0], 10);
            (map[bid] = map[bid] || []).push(p[1]);
        });
        return map;
    }

    /*
     * Nút "chọn nhanh toàn bộ shop của kho" — chỉ hiện cho kho ĐANG được tích.
     *
     * Liệt kê sẵn nút của mọi kho ngay từ đầu thì chưa chọn gì đã thấy một loạt
     * gợi ý, vừa rối vừa chiếm chỗ của danh sách chi nhánh.
     */
    function refreshQuick() {
        var html = '';

        $('.bctk-site:checked[data-type="warehouse"]').each(function () {
            var bid  = parseInt(this.value, 10);
            var kids = (CFG.children || {})[bid] || [];
            if (!kids.length) return;

            var site = siteById(bid);
            html += '<button type="button" class="bctk-quick" data-kids="' + esc(kids.join(',')) + '">'
                 + '+ Toàn bộ shop thuộc ' + esc(site ? site.name : ('kho #' + bid))
                 + ' (' + kids.length + ')</button>';
        });

        $('#bctkQuickBox').html(html);
    }

    /* Tích/bỏ tích chi nhánh kéo theo hai khối bên dưới — luôn đi cùng nhau */
    function onSitesChanged() {
        refreshQuick();
        refreshZones();
    }

    /*
     * Dựng khối "Mã kho" từ TẤT CẢ chi nhánh đang tích ở khối trên.
     *
     * Khối trên chọn nhiều website, khối dưới đi sâu vào từng website đó:
     *   - website kho  → các mã phân kho đã khai báo
     *   - website shop → chính nó (shop không chia phân kho)
     *
     * Giữ nguyên trạng thái đã tích khi dựng lại, nếu không thì mỗi lần thêm
     * một chi nhánh là mất hết lựa chọn cũ.
     */
    function refreshZones() {
        var checked = {};
        $('.bctk-zone:checked').each(function () { checked[this.value] = 1; });

        var siteIds = selectedSites();
        if (!siteIds.length) {
            $('#bctkZoneGroup').addClass('bctk-hidden');
            $('#bctkZoneList').empty();
            return;
        }

        var html = '';
        siteIds.forEach(function (bid) {
            var site  = siteById(bid);
            var zones = CFG.zones[bid] || [];
            if (!site || !zones.length) return;

            // Chỉ chèn tiêu đề nhóm khi site có nhiều hơn một điểm tồn
            if (zones.length > 1) {
                html += '<div class="bctk-zonegrp">' + esc(site.label) + '</div>';
            }

            zones.forEach(function (z) {
                var val = bid + '::' + z.zone_code;
                html += '<label class="bctk-item">'
                     + '<input class="form-check-input bctk-zone" type="checkbox" value="' + esc(val) + '"'
                     + (checked[val] ? ' checked' : '') + '>'
                     + '<span class="bctk-item__label">' + esc(z.label || z.zone_code) + '</span>'
                     + (z.none ? '<span class="bctk-tag bctk-tag--warn">chưa gán</span>'
                               : (z.virtual ? '' : '<span class="bctk-tag">kho</span>'))
                     + '</label>';
            });
        });

        $('#bctkZoneList').html(html || '<div class="bctk-note">Chi nhánh đã chọn chưa khai báo phân kho</div>');
        $('#bctkZoneGroup').removeClass('bctk-hidden');
    }

    // ── Chạy báo cáo ────────────────────────────────────────────────────────

    /*
     * Trang nào dùng lại bộ lọc này thì tự đăng ký cách vẽ của mình.
     * Không đăng ký thì dùng mặc định (báo cáo tồn kho).
     */
    var pageRender = null;   // function(rows)
    var pageFooter = null;   // function(visibleRows, totalCount)

    /*
     * ─── VÌ SAO PHẢI BÁO LỖI RA MẶT ─────────────────────────────────────────
     *
     * Trước đây mọi trục trặc đều được đổi thành mảng rỗng: nonce hết hạn,
     * không đủ quyền, PHP lỗi, rớt mạng — tất cả hiện lên đúng một câu
     * "Xong · N chi nhánh · 0 dòng", không khác gì kho thật sự không có hàng.
     * Hệ quả là cùng một website, máy này ra 499 dòng còn máy kia ra 0 dòng mà
     * không ai biết vì sao, cũng không có gì để lần.
     *
     * Từ đây fetchSite trả về { rows, error, expired } thay vì mảng trần, để
     * chỗ gọi phân biệt được "không có số liệu" với "không lấy được số liệu".
     */
    var lastErrors = [];

    /*
     * Xin nonce mới khi phiên của TRANG đã quá hạn (xem chú thích ở
     * TGS_BCTK_Ajax::refresh_nonce).
     *
     * Gộp chung một lời hứa cho cả loạt: 3 site chạy song song mà cùng hết hạn
     * thì cả 3 chờ đúng một lượt xin, không xin ba lần.
     */
    var nonceRefresh = null;

    function refreshNonce() {
        if (nonceRefresh) return nonceRefresh;

        nonceRefresh = $.post(CFG.ajaxUrl, { action: 'tgs_bctk_refresh_nonce' })
            .then(function (res) {
                if (res && res.success && res.data && res.data.nonce) {
                    CFG.nonce = res.data.nonce;
                    return true;
                }
                /* Xin không được nghĩa là đã đăng xuất hẳn — phải tải lại trang */
                return $.Deferred().reject().promise();
            });

        /* Lượt tìm kiếm sau được phép xin lại từ đầu */
        nonceRefresh.always(function () { nonceRefresh = null; });

        return nonceRefresh;
    }

    function fetchSite(blogId, zones, isRetry) {
        var data = {
            action: CFG.action || 'tgs_bctk_fetch_site',
            nonce: CFG.nonce,
            blog_id: blogId,
            zones: zones
        };

        /* Trang có ô chọn ngày thì gửi kèm — trang không có thì bỏ qua */
        if ($('#bctkDateFrom').length) { data.date_from = $('#bctkDateFrom').val(); }
        if ($('#bctkDateTo').length)   { data.date_to   = $('#bctkDateTo').val(); }

        /* Nonce hỏng → xin cái mới rồi thử lại ĐÚNG một lần, hỏng nữa thì chịu */
        function retryOrExpire() {
            if (isRetry) {
                return { rows: [], expired: true, error: 'Phiên đăng nhập đã hết hạn' };
            }

            return refreshNonce().then(function () {
                return fetchSite(blogId, zones, true);
            }, function () {
                return { rows: [], expired: true, error: 'Phiên đăng nhập đã hết hạn' };
            });
        }

        return $.post(CFG.ajaxUrl, data).then(function (res) {
            if (res && res.success && res.data && res.data.rows) {
                return { rows: res.data.rows };
            }

            /* admin-ajax trả trần "0" (chưa đăng nhập) hoặc "-1" (nonce sai) */
            if (res === 0 || res === '0' || res === -1 || res === '-1') {
                return retryOrExpire();
            }

            /* Máy chủ trả lời được nhưng từ chối — giữ nguyên lý do của nó */
            return {
                rows: [],
                error: (res && res.data && res.data.message) || 'Máy chủ trả về dữ liệu không đọc được'
            };
        }, function (xhr) {
            var status = xhr ? xhr.status : 0;

            if (status === 401 || status === 403) {
                return retryOrExpire();
            }

            return {
                rows: [],
                error: status ? ('Máy chủ báo lỗi ' + status) : 'Mất kết nối tới máy chủ'
            };
        });
    }

    function run() {
        if (running) return;

        var sites = selectedSites();
        if (!sites.length) {
            alert('Chọn ít nhất một chi nhánh.');
            return;
        }

        /*
         * ─── MÃ KHO LÀ BỘ LỌC CHỐT, VÀ LÀ BẮT BUỘC ──────────────────────────
         *
         * Thống kê chạy theo đúng những mã kho được tích, kể cả điều đó loại
         * bớt chi nhánh đang tích ở khối trên.
         *
         * Bắt buộc phải chọn: trước đây bỏ trống thì hiểu là "lấy hết", nhưng
         * người dùng nhìn khối mã kho trống trơn lại tưởng mình chưa lọc gì mà
         * kết quả vẫn ra — không biết con số đang tính trên phạm vi nào. Với
         * báo cáo đối chiếu thì mơ hồ về phạm vi là lỗi nặng hơn cả sai số.
         */
        var zoneMap  = selectedZonesByBlog();
        var zoneBlog = Object.keys(zoneMap).map(Number);

        if (!zoneBlog.length) {
            // Chỉ đòi khi thật sự có mã để chọn — chi nhánh chưa khai báo phân
            // kho thì không thể bắt người dùng chọn thứ không tồn tại
            if ($('.bctk-zone').length) {
                alert('Chọn ít nhất một mã kho ở khối "MÃ KHO" bên dưới.');
                $('#bctkZoneGroup').addClass('bctk-flash');
                setTimeout(function () { $('#bctkZoneGroup').removeClass('bctk-flash'); }, 1200);
                return;
            }
        } else {
            sites = sites.filter(function (id) { return zoneBlog.indexOf(id) !== -1; });
            if (!sites.length) {
                alert('Mã kho đang chọn không thuộc chi nhánh nào đã tích.');
                return;
            }
        }

        running = true;
        rows = [];
        lastErrors = [];
        var done = 0, failed = 0;

        $('#bctkProgress').removeClass('bctk-hidden');
        $('#bctkSearch').prop('disabled', true);
        $('#bctkBody').html('<tr class="bctk-empty"><td colspan="12">Đang lấy dữ liệu…</td></tr>');

        function tick() {
            var pct = Math.round(done / sites.length * 100);
            $('#bctkProgressFill').css('width', pct + '%');
            $('#bctkProgressText').text(
                'Đã lấy ' + done + '/' + sites.length + ' chi nhánh'
                + (failed ? ' — ' + failed + ' lỗi' : '')
                + ' · ' + nf.format(rows.length) + ' dòng'
            );
        }
        tick();

        var queue = sites.slice();

        function next() {
            if (!queue.length) return $.Deferred().resolve().promise();
            var bid = queue.shift();
            return fetchSite(bid, zoneMap[bid] || []).then(function (got) {
                got = got || {};

                if (got.error) {
                    failed++;
                    lastErrors.push({
                        blog: bid,
                        message: got.error,
                        expired: !!got.expired
                    });
                }

                rows = rows.concat(got.rows || []);
                done++;
                tick();
                return next();
            });
        }

        var workers = [];
        for (var i = 0; i < Math.min(CONCURRENCY, sites.length); i++) {
            workers.push(next());
        }

        $.when.apply($, workers).always(function () {
            running = false;
            $('#bctkSearch').prop('disabled', false);
            render();

            $('#bctkProgressText').text(
                'Xong · ' + sites.length + ' chi nhánh · ' + nf.format(rows.length) + ' dòng'
                + (failed ? ' · ' + failed + ' chi nhánh lỗi' : '')
            );
            $('#bctkProgress').toggleClass('bctk-progress--error', failed > 0);
        });
    }

    /*
     * Không ra dòng nào thì phải nói rõ VÌ SAO.
     *
     * "Thật sự không có số liệu" và "gọi hỏng nên không lấy được số liệu" là
     * hai chuyện hoàn toàn khác nhau, mà trước đây hiện chung một câu
     * "Không có dữ liệu." — người dùng tưởng kho trống và đi đối chiếu sai.
     */
    function emptyMessage() {
        if (!lastErrors.length) return 'Không có dữ liệu.';

        var expired = lastErrors.some(function (e) { return e.expired; });
        if (expired) {
            return 'Trang đã mở quá lâu nên phiên làm việc hết hạn, không lấy được số liệu.<br>'
                 + '<button type="button" class="bctk-btn bctk-btn--primary" id="bctkReload" '
                 + 'style="margin-top:8px">Tải lại trang</button>';
        }

        return 'Không lấy được số liệu: ' + esc(lastErrors[0].message)
             + (lastErrors.length > 1
                 ? ' (và ' + (lastErrors.length - 1) + ' chi nhánh khác cũng lỗi)'
                 : '');
    }

    /* Số cột thật của bảng — đếm ở <thead> thay vì ghi cứng, vì mỗi báo cáo
       trong BC_TK có số cột khác nhau (tồn kho 12, sổ kho 14). */
    function bodyColspan() {
        var n = $('#bctkTable thead tr').first().children().length;
        return n || 12;
    }

    // ── Vẽ bảng ─────────────────────────────────────────────────────────────

    function render() {
        if (!rows.length) {
            $('#bctkBody').html(
                '<tr class="bctk-empty' + (lastErrors.length ? ' bctk-empty--error' : '') + '">'
                + '<td colspan="' + bodyColspan() + '">' + emptyMessage() + '</td></tr>'
            );
            $('#bctkFoot').addClass('bctk-hidden');
            $('#bctkRowCount').text('0 dòng');
            return;
        }

        /* Trang tự vẽ (sổ kho chẳng hạn) thì nhường quyền */
        if (pageRender) {
            pageRender(rows);
            $('#bctkFoot').removeClass('bctk-hidden');
            recalcFooter();
            return;
        }

        // Sắp theo số lượng giảm dần — giống phần mềm cũ, hàng nhiều nằm trên
        rows.sort(function (a, b) { return b.qty - a.qty; });

        var html = new Array(rows.length);

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var amount = r.qty * r.price;

            /*
             * Gắn chỉ số dòng vào <tr> để lúc cộng lại còn tra ngược về số gốc
             * trong mảng rows. Đọc ngược từ chữ trong ô thì phải bóc dấu phân
             * cách nghìn và ký hiệu tiền — vừa chậm vừa dễ sai số.
             */
            html[i] = '<tr data-i="' + i + '">'
                + '<td class="c-zone">' + esc(r.zone)
                    + (r.no_zone ? ' <span class="bctk-warn" title="Dữ liệu chưa gán phân kho">chưa phân kho</span>' : '')
                + '</td>'
                + '<td class="c-sku">' + esc(r.sku) + '</td>'
                + '<td class="c-name">' + esc(r.name) + '</td>'
                + '<td class="c-alias">' + esc(r.alias) + '</td>'
                // Số lượng để đỏ đậm giống phần mềm cũ — mọi người đang quen mắt
                + '<td class="c-num c-qty' + (r.qty < 0 ? ' neg' : '') + '">' + fmt(r.qty) + '</td>'
                + '<td class="c-num">' + fmt(r.price) + '</td>'
                + '<td class="c-num">' + fmt(amount) + '</td>'
                + '<td class="c-num">' + (r.in_transit ? fmt(r.in_transit) : '') + '</td>'
                + '<td class="c-num">' + fmt(r.max) + '</td>'
                + '<td class="c-num">' + fmt(r.min) + '</td>'
                + '<td class="c-num' + (r.need < 0 ? ' neg' : '') + '">' + fmt(r.need) + '</td>'
                + '<td class="c-unit">' + esc(r.unit) + '</td>'
                + '</tr>';
        }

        $('#bctkBody').html(html.join(''));
        $('#bctkFoot').removeClass('bctk-hidden');
        recalcFooter();
    }

    /*
     * Cộng dòng tổng theo những dòng ĐANG HIỆN.
     *
     * Gọi sau mỗi lần vẽ, và sau mỗi lần dải lọc theo cột đổi trạng thái. Cộng
     * cứng theo toàn bộ dữ liệu thì lọc còn 4 dòng mà chân bảng vẫn ra tổng của
     * 29 dòng — người dùng đối chiếu sẽ thấy hai con số đá nhau.
     *
     * Lấy số từ mảng rows qua data-i chứ không đọc chữ trong ô: chữ đã qua định
     * dạng nghìn và ký hiệu tiền, bóc ngược vừa chậm vừa dễ lệch.
     */
    function recalcFooter() {
        /* Trang tự cộng dòng tổng theo cột riêng của nó */
        if (pageFooter) {
            var visible = [];
            $('#bctkBody tr[data-i]').each(function () {
                if (this.style.display === 'none') return;
                var r = rows[parseInt(this.getAttribute('data-i'), 10)];
                if (r) visible.push(r);
            });

            pageFooter(visible, rows.length);
            $('#bctkRowCount').text(
                visible.length === rows.length
                    ? nf.format(rows.length) + ' dòng'
                    : nf.format(visible.length) + ' / ' + nf.format(rows.length) + ' dòng (đang lọc)'
            );
            return;
        }

        var t = { qty: 0, amount: 0, transit: 0, max: 0, min: 0, need: 0 };
        var shown = 0;

        $('#bctkBody tr[data-i]').each(function () {
            if (this.style.display === 'none') return;

            var r = rows[parseInt(this.getAttribute('data-i'), 10)];
            if (!r) return;

            shown++;
            t.qty     += r.qty;
            t.amount  += r.qty * r.price;
            t.transit += r.in_transit;
            t.max     += (r.max || 0);
            t.min     += (r.min || 0);
            t.need    += (r.need || 0);
        });

        $('#fQty').text(fmt(t.qty));
        $('#fAmount').text(fmt(t.amount));
        $('#fTransit').text(fmt(t.transit));
        $('#fMax').text(fmt(t.max));
        $('#fMin').text(fmt(t.min));
        $('#fNeed').text(fmt(t.need));

        // Nói rõ đang xem bao nhiêu trên tổng bao nhiêu khi có lọc
        $('#bctkRowCount').text(
            shown === rows.length
                ? nf.format(rows.length) + ' dòng'
                : nf.format(shown) + ' / ' + nf.format(rows.length) + ' dòng (đang lọc)'
        );
    }

    // ── Nối sự kiện ─────────────────────────────────────────────────────────

    /*
     * Đo chiều cao còn lại rồi gán cho khối trang.
     *
     * Không đặt bằng CSS calc(100vh - <số>) được: trang chạy trong iframe của
     * hệ thống tab nên 100vh đã là chiều cao khung, trừ thêm số cố định cho
     * nav/admin bar là trừ nhầm — chính là mảng trắng thừa dưới bảng. Mở trực
     * tiếp ngoài tab thì số trừ lại khác. Đo lúc chạy đúng cho cả hai.
     */
    function fitHeight() {
        var el = document.getElementById('bctkPage');
        if (!el) return;

        var top = el.getBoundingClientRect().top;   // đã tính mọi thứ nằm trên
        var h = window.innerHeight - top - 12;      // chừa 12px thở dưới đáy

        el.style.height = Math.max(360, h) + 'px';
    }

    $(function () {
        if (!document.getElementById('bctkPage')) return;

        fitHeight();
        // Ảnh/webfont tải xong có thể đẩy nội dung xuống → đo lại
        $(window).on('load resize', fitHeight);

        /* Dải lọc theo cột (module dùng chung) vừa đổi → cộng lại dòng tổng */
        document.addEventListener('ds:filter-applied', function (e) {
            if (e.detail && e.detail.table && e.detail.table.id === 'bctkTable') {
                recalcFooter();
            }
        });

        $(document).on('change', '.bctk-site', onSitesChanged);

        $('#bctkCheckAllSites').on('change', function () {
            $('.bctk-site:visible').prop('checked', this.checked);
            onSitesChanged();
        });

        /* Chỉ áp cho mã kho ĐANG HIỆN — đang lọc mà tích "Tất cả" lại chọn cả
           những mã bị ẩn thì người dùng không thấy mình vừa chọn thêm gì */
        $('#bctkCheckAllZones').on('change', function () {
            $('.bctk-zone').filter(':visible').prop('checked', this.checked);
        });

        /*
         * Lọc mã kho. Kho có thể khai báo hàng chục mã, chưa kể gộp nhiều chi
         * nhánh cùng lúc thì danh sách rất dài.
         *
         * Tiêu đề nhóm (tên site) phải ẩn theo khi nhóm đó không còn mã nào
         * khớp, nếu không sẽ trơ lại một loạt tiêu đề rỗng.
         */
        $('#bctkZoneSearch').on('input', function () {
            var k = norm(this.value);

            $('#bctkZoneList .bctk-item').each(function () {
                $(this).toggle(!k || norm($(this).text()).indexOf(k) !== -1);
            });

            $('#bctkZoneList .bctk-zonegrp').each(function () {
                var visible = $(this).nextUntil('.bctk-zonegrp', '.bctk-item').filter(':visible').length;
                $(this).toggle(visible > 0);
            });
        });

        $('#bctkSiteSearch').on('input', function () {
            var k = norm(this.value);
            $('#bctkSiteList .bctk-item').each(function () {
                $(this).toggle(!k || norm($(this).text()).indexOf(k) !== -1);
            });
        });

        $(document).on('click', '.bctk-quick', function () {
            String($(this).data('kids')).split(',').forEach(function (id) {
                $('.bctk-site[value="' + id.trim() + '"]').prop('checked', true);
            });
            onSitesChanged();
        });

        $('#bctkToggleFilter').on('click', function () {
            var collapsed = $('#bctkPage').toggleClass('is-collapsed').hasClass('is-collapsed');
            this.textContent = collapsed ? '»' : '«';
            this.title = collapsed ? 'Mở lại bộ lọc' : 'Thu gọn bộ lọc';
        });

        $('#bctkSearch').on('click', run);

        /* Nút do emptyMessage() dựng ra sau khi chạy → phải bắt theo kiểu ủy quyền */
        $(document).on('click', '#bctkReload', function () {
            window.location.reload();
        });

        /* Điểm nối cho báo cáo khác: nghe sự kiện này để dùng lại bộ lọc */
        $(document).on('bctk:search', run);
    });

    /* API công khai — cho phép trang khác nạp dữ liệu vào rồi vẽ lại */
    window.TGSBctk = {
        getRows: function () { return rows.slice(); },
        setRows: function (r) { rows = r || []; render(); },
        run: run,
        fmt: fmt,
        esc: esc,

        /*
         * Đăng ký cách vẽ riêng cho một trang.
         *   renderFn(rows)                  — đổ dữ liệu vào #bctkBody, mỗi <tr>
         *                                     phải có data-i để cộng lại được
         *   footerFn(visibleRows, total)    — cộng dòng tổng theo cột của trang
         */
        setRenderer: function (renderFn, footerFn) {
            pageRender = renderFn || null;
            pageFooter = footerFn || null;
        },

        /*
         * Thay dữ liệu gốc mà KHÔNG vẽ lại.
         *
         * Trang sổ kho gộp nhiều chi nhánh về cùng một mã hàng trước khi vẽ;
         * sau khi gộp, mảng dòng đã khác mảng server trả về. Phần cộng dòng tổng
         * tra ngược theo data-i nên phải trỏ vào mảng ĐÃ GỘP, không thì cộng
         * nhầm dòng. Gọi setRows() ở đây sẽ vẽ lại lần nữa → lặp vô tận.
         */
        setRowsSilent: function (r) { rows = r || []; }
    };
})(jQuery);
