# TGS BC_TK — Báo cáo tồn kho

Menu: **Mua hàng → BC_TK → Tồn kho**

## Cách dùng

1. Tích chi nhánh ở cột trái. Site kho có nhãn `kho`.
2. Tích một site kho → khối **Mã kho** hiện ra để lọc theo phân kho.
3. Nút `+ Toàn bộ shop thuộc kho #N` tích nhanh mọi shop trực thuộc kho đó.
4. Bấm **Tìm kiếm**. Thanh tiến độ chạy theo từng chi nhánh.

## Các con số lấy từ đâu

| Cột | Nguồn |
|---|---|
| Kho | mã phân kho, hoặc `tgs_site_code` / tên site nếu là shop |
| Mã hàng | `local_ledger_item.local_product_sku` (bỏ dòng rỗng) |
| Tên hàng, Alias, ĐVT, Đơn giá | `wp_global_product_name` |
| Số lượng | tồn **đã duyệt** — công thức chép từ `TGS_Global_Product_Source::get_stock_for_skus()` |
| SL đi đường | xem `tgs-transfer-management/docs/hang-dang-di-duong.md` |
| Tồn max / min | `wp_global_sku_stock_config` theo `(product_sku, blog_id)` |
| SL cần nhập | `tồn max − số lượng`; thừa hàng thì ra số âm |

Ô **chưa phân kho** nghĩa là dữ liệu của site kho đó chưa gán phân kho — số liệu
vẫn đúng và vẫn được tính, chỉ là chưa chia được về mã kho nào.

## Hiệu năng

Mỗi chi nhánh một lượt AJAX, chạy 3 site song song. Với 70 site:

- 2 truy vấn / site (tồn + hàng đi đường), gộp sẵn bằng `GROUP BY` ở SQL
- 2 truy vấn / site để ghép tên và min/max
- Không dùng `switch_to_blog` — trỏ thẳng bảng qua `$wpdb->get_blog_prefix()`

Chia nhỏ theo site để tiến độ chạy thật, một site hỏng không kéo sập cả báo cáo,
và không đụng trần thời gian chạy PHP.

## Cắm dữ liệu riêng cho một site

Site nào lấy số liệu qua API riêng thay vì đọc DB:

```php
add_filter('tgs_bctk_site_stock_rows', function ($rows, $blog_id, $zones) {
    if ($blog_id !== 42) {
        return $rows;           // null = dùng truy vấn mặc định
    }
    return [
        ['sku' => 'ABC123', 'zone' => 'K1', 'qty' => 12.0],
    ];
}, 10, 3);
```

## Dùng lại bộ lọc cho báo cáo khác

```php
$boot = TGS_BCTK_Sites::filter_bootstrap();
// $boot['sites']    — danh sách chi nhánh (blog_id, code, name, type, parent_id)
// $boot['zones']    — phân kho theo blog_id
// $boot['children'] — shop trực thuộc từng kho
```

Phía JS: `$(document).trigger('bctk:search')` để chạy, `TGSBctk.setRows(rows)` để
đổ dữ liệu tự lấy vào bảng có sẵn.

## Phân quyền

Đang **mở** trong giai đoạn phát triển: `TGS_BCTK_CAPABILITY = 'read'` ở
`tgs-bc-tk.php`. Siết lại chỉ cần đổi hằng số này.
