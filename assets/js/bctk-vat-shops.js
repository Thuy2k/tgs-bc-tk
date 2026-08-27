/**
 * Màn "Quản lý shop áp dụng VAT" — thêm / sửa / xoá.
 *
 * Cùng cách làm với màn Shop triển khai của plugin chuyển kho nội bộ để ai đã
 * quen màn kia thì sang đây không phải học lại.
 *
 * @package tgs-bc-tk
 */
(function ($) {
    'use strict';

    var cfg = window.tgsBctkVat || {};
    var $body = $('#bctkVatShopsBody');

    if (!$body.length) {
        return;   // không phải màn này
    }

    function esc(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function post(action, data, done) {
        $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data || {}))
            .done(function (res) {
                if (res && res.success) {
                    done(res.data || {});
                    return;
                }
                window.alert((res && res.data && res.data.message) || 'Thao tác không thành công.');
            })
            .fail(function (xhr) {
                var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                window.alert(msg || 'Không gọi được máy chủ. Thử lại giúp mình.');
            });
    }

    function rowHtml(shop) {
        var active = Number(shop.is_active) === 1;

        return '<tr data-id="' + esc(shop.id) + '">'
            + '<td><code>' + esc(shop.tgs_site_code) + '</code></td>'
            + '<td>' + esc(shop.shop_name || '—') + '</td>'
            + '<td>' + esc(shop.tax_code || '—') + '</td>'
            + '<td>' + esc(shop.applied_from || '—') + '</td>'
            + '<td>' + esc(shop.note || '') + '</td>'
            + '<td>'
            + (active
                ? '<span class="badge bg-success">Đang áp dụng</span>'
                : '<span class="badge bg-secondary">Tạm dừng</span>')
            + '</td>'
            + '<td>'
            + '<button type="button" class="btn btn-sm btn-outline-primary bctk-vat-edit" title="Sửa">'
            + '<i class="bx bx-edit"></i></button> '
            + '<button type="button" class="btn btn-sm btn-outline-danger bctk-vat-del" title="Xoá">'
            + '<i class="bx bx-trash"></i></button>'
            + '</td>'
            + '</tr>';
    }

    var shops = [];

    function load() {
        $body.html('<tr><td colspan="7" class="text-center text-muted py-4">'
            + '<i class="bx bx-loader bx-spin"></i> Đang tải…</td></tr>');

        post('tgs_bctk_vat_shops_list', {}, function (data) {
            shops = data.shops || [];

            if (!shops.length) {
                $body.html('<tr><td colspan="7" class="text-center text-muted py-4">'
                    + 'Chưa khai shop nào. Bấm <strong>Thêm shop</strong> để bắt đầu.</td></tr>');
                return;
            }

            $body.html(shops.map(rowHtml).join(''));
        });
    }

    function openModal(shop) {
        var editing = !!shop;

        $('#bctkVatShopModalTitle').text(editing ? 'Sửa shop áp dụng VAT' : 'Thêm shop áp dụng VAT');
        $('#bctkVatShopId').val(editing ? shop.id : '');
        $('#bctkVatShopCode').val(editing ? shop.tgs_site_code : '').prop('readonly', editing);
        $('#bctkVatShopTaxCode').val(editing ? (shop.tax_code || '') : '');
        $('#bctkVatShopAppliedFrom').val(editing ? (shop.applied_from || '') : '');
        $('#bctkVatShopNote').val(editing ? (shop.note || '') : '');
        $('#bctkVatShopActive').prop('checked', editing ? Number(shop.is_active) === 1 : true);

        new bootstrap.Modal(document.getElementById('bctkVatShopModal')).show();
    }

    $('#bctkVatAddShop').on('click', function () {
        openModal(null);
    });

    $body.on('click', '.bctk-vat-edit', function () {
        var id = String($(this).closest('tr').data('id'));
        var shop = shops.filter(function (s) { return String(s.id) === id; })[0];
        if (shop) {
            openModal(shop);
        }
    });

    $body.on('click', '.bctk-vat-del', function () {
        var $tr = $(this).closest('tr');
        var code = $tr.find('code').text();

        if (!window.confirm('Xoá shop ' + code + ' khỏi danh sách áp dụng VAT?\n\n'
            + 'Báo cáo VAT sẽ không còn tính shop này. Dữ liệu bán hàng của shop giữ nguyên.')) {
            return;
        }

        post('tgs_bctk_vat_shops_delete', { id: $tr.data('id') }, function () {
            load();
        });
    });

    $('#bctkVatShopSave').on('click', function () {
        var code = $.trim($('#bctkVatShopCode').val());
        if (!code) {
            window.alert('Chưa nhập mã shop.');
            $('#bctkVatShopCode').trigger('focus');
            return;
        }

        post('tgs_bctk_vat_shops_save', {
            id: $('#bctkVatShopId').val() || 0,
            tgs_site_code: code,
            tax_code: $.trim($('#bctkVatShopTaxCode').val()),
            applied_from: $('#bctkVatShopAppliedFrom').val(),
            note: $('#bctkVatShopNote').val(),
            is_active: $('#bctkVatShopActive').is(':checked') ? 1 : 0
        }, function (data) {
            var modal = bootstrap.Modal.getInstance(document.getElementById('bctkVatShopModal'));
            if (modal) {
                modal.hide();
            }
            if (data.message) {
                window.console && window.console.log(data.message);
            }
            load();
        });
    });

    load();
}(jQuery));
