# ĐƠN VỊ TÍNH TRONG BÁO CÁO BC_TK

> Tên ĐVT nhỏ nhất lấy theo **bảng giá của từng site**
> Phiên bản: 1.0 | 2026-08-13
> Mô hình bảng giá đầy đủ: `tgs_pos/docs/GIA_VA_DON_VI_TINH.md`
> API dùng chung: `tgs_shop_management/docs/gia-va-don-vi-tinh.md`

---

## 1. BC_TK DÙNG GÌ TỪ BẢNG QUY ĐỔI

**Chỉ dùng TÊN đơn vị nhỏ nhất.** Không lấy đơn giá, không lấy tỷ lệ quy đổi.

Tiền và số lượng trên báo cáo đều đọc từ dữ liệu **đã lưu trên phiếu**
(`wp_<blog>_local_ledger_item`), do `TGS_Money` tính. Cột `SL` là số lượng theo
đơn vị nhỏ nhất, nên tên đơn vị in kèm cũng phải là đơn vị nhỏ nhất **thật** —
lấy từ bảng quy đổi chứ không tin `global_product_unit`.

Hàm duy nhất chạm bảng quy đổi: `TGS_BCTK_Report::base_unit()`.

---

## 2. VẤN ĐỀ KHI CÓ NHIỀU BẢNG GIÁ

Từ 13/08/2026 `wp_global_htsoft_stock_convert` chứa nhiều bảng giá, phân biệt
bằng cột `price_list_id`; mỗi website áp đúng 1 bảng
(`wp_global_htsoft_price_list_blog`, `UNIQUE(blog_id)`).

`base_unit()` vốn có quy tắc chọn khi một mã có nhiều dòng tỉ lệ 1:

1. Dòng nào trùng `global_product_unit` thì lấy
2. Không trùng thì lấy dòng **cấu hình sớm nhất** (id nhỏ nhất) cho ổn định

Khi chưa lọc bảng giá, **mỗi mã hàng có dòng tỉ lệ 1 ở CẢ hai bảng giá**, nên
quy tắc 2 luôn chọn bảng giá cũ hơn ⇒ site dùng bảng giá mới vẫn hiện tên ĐVT
của bảng giá khác.

Đo thực tế (SKU `100861007`, tạm đổi tên ĐVT ở bảng giá #2 để thấy rõ):

| Site | Bảng giá áp dụng | Trước khi sửa | Sau khi sửa |
|---|---|---|---|
| #1 | Bảng giá công ty TGS | Lon | **Lon** |
| #6 | Bảng giá Nguyễn Tất Thành | Lon (sai) | **Hộp_NTT** |

---

## 3. CÁCH SỬA

Giữ **nguyên** câu SQL, quy tắc chọn và mẹo `CONVERT ... COLLATE` xử lý lệch
collation. Chỉ thêm 2 thứ:

```php
// 1. Nhận blog_id của site đang lập báo cáo
public static function base_unit(array $skus, $blog_id = null)

// 2. Ghép điều kiện bảng giá vào WHERE
$price_list_where = class_exists('TGS_Price_List')
    ? TGS_Price_List::where_clause('c', $blog_id)   // " AND c.price_list_id = 2 "
    : '';
```

**Vì sao phải truyền `$blog_id`:** báo cáo chạy trên hub và lặp qua TỪNG site,
nên không được dùng site hiện tại. Cả 2 nơi gọi đều đã có sẵn biến `$blog_id`:

- `TGS_BCTK_Ajax::…` dòng ~153 (báo cáo CSKH)
- `TGS_BCTK_Ajax::…` dòng ~648 (báo cáo bán hàng)

`where_clause()` trả **chuỗi rỗng** nếu DB chưa có cột `price_list_id`, nên site
chưa chạy migration vẫn chạy y như cũ.

`TGS_Price_List` nằm ở `tgs_shop_management` — plugin BC_TK vốn đã cắm vào
dashboard của plugin đó (`tgs_shop_dashboard_routes`) nên luôn có mặt; code vẫn
bọc `class_exists()` cho an toàn.

---

## 4. QUY TẮC KHI SỬA TIẾP

1. BC_TK **không** đọc giá từ bảng quy đổi. Tiền lấy từ phiếu đã lưu — giữ đúng
   nguyên tắc này, đừng query đơn giá lúc dựng báo cáo.
2. Đụng `wp_global_htsoft_stock_convert` thì phải có
   `TGS_Price_List::where_clause('<alias>', $blog_id)`.
3. Luôn truyền `$blog_id` của site đang xét, **không** dùng `get_current_blog_id()`.
4. Tên cột tỷ lệ là `convert_to_htsoft` — không phải `convert_ratio`.

---

## 5. KIỂM TRA NHANH

```sql
-- ĐVT nhỏ nhất của 1 mã ở từng bảng giá
SELECT c.price_list_id, l.price_list_name, c.convert_unit
FROM wp_global_htsoft_stock_convert c
JOIN wp_global_htsoft_price_list l
  ON l.global_htsoft_price_list_id = c.price_list_id
WHERE c.global_product_sku = '100861007'
  AND c.convert_to_htsoft = 1
  AND (c.is_deleted = 0 OR c.is_deleted IS NULL);

-- Site nào áp bảng giá nào
SELECT b.blog_id, l.price_list_name
FROM wp_global_htsoft_price_list_blog b
JOIN wp_global_htsoft_price_list l
  ON l.global_htsoft_price_list_id = b.global_htsoft_price_list_id
WHERE (b.is_deleted = 0 OR b.is_deleted IS NULL);
```

| Hiện tượng | Nguyên nhân |
|---|---|
| Cột ĐVT trống, lùi về `global_product_unit` | Bảng giá của site đó chưa khai dòng tỉ lệ 1 cho mã hàng |
| Hai site hiện ĐVT khác nhau cho cùng mã | Đúng — 2 site áp 2 bảng giá khác nhau |
| Vẫn ra ĐVT của bảng giá cũ | Nơi gọi quên truyền `$blog_id` |
