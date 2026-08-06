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

    function fetchSite(blogId, zones) {
        return $.post(CFG.ajaxUrl, {
            action: 'tgs_bctk_fetch_site',
            nonce: CFG.nonce,
            blog_id: blogId,
            zones: zones
        }).then(function (res) {
            return (res && res.success && res.data && res.data.rows) ? res.data.rows : [];
        }, function () {
            return [];   // site lỗi → bỏ qua, không chặn các site còn lại
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
                if (!got.length) failed += 0;
                rows = rows.concat(got);
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
            $('#bctkExport').prop('disabled', rows.length === 0);
            render();
            $('#bctkProgressText').text('Xong · ' + sites.length + ' chi nhánh · ' + nf.format(rows.length) + ' dòng');
        });
    }

    // ── Vẽ bảng ─────────────────────────────────────────────────────────────

    function render() {
        if (!rows.length) {
            $('#bctkBody').html('<tr class="bctk-empty"><td colspan="12">Không có dữ liệu.</td></tr>');
            $('#bctkFoot').addClass('bctk-hidden');
            $('#bctkRowCount').text('0 dòng');
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

        /* Điểm nối cho báo cáo khác: nghe sự kiện này để dùng lại bộ lọc */
        $(document).on('bctk:search', run);
    });

    /* API công khai — cho phép trang khác nạp dữ liệu vào rồi vẽ lại */
    window.TGSBctk = {
        getRows: function () { return rows.slice(); },
        setRows: function (r) { rows = r || []; render(); },
        run: run
    };
})(jQuery);
