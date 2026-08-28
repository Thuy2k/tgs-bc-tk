# Báo cáo VAT — Phiếu xuất bán (VAT) & Phiếu điều chỉnh giảm (VAT)

> Đọc file này trước khi đụng vào hai màn `Bán hàng → Quản lý VAT → Phiếu xuất
> bán` / `Phiếu điều chỉnh giảm`, hay lớp `TGS_BCTK_Report::site_vat_*_rows()`.

---

## 1. Đây là màn gì, khác gì màn của quầy

| | Màn quầy (`tgs_pos`) | Màn này (`tgs-bc-tk`) |
|---|---|---|
| File | `templates/front/pos-viettel-tax.php` | `admin-views/vat-sales.php`, `vat-adjust.php` |
| Người dùng | Nhân viên một shop | **Kế toán, nhiều shop** |
| Mục tiêu | "Còn gì chưa gửi?" — 3 trạng thái | Tổng quan xử lý sự cố: sửa phiếu, gửi lại, điều chỉnh, thay thế hoá đơn |
| Cột | Rút gọn 7 cột | **Đầy đủ 32 cột chứng từ** |
| Phạm vi | Đơn của shop hiện tại | Lọc nhiều shop cùng lúc (chạy batch từng site) |

Bộ lọc trái CHỈ hiện shop đã khai áp dụng thuế
(`TGS_BCTK_Vat_Shops::active_blog_ids()` → bảng global `wp_global_vat_shops`).
Khai thêm ở màn *Quản lý VAT → Shop áp dụng thuế*.

---

## 2. Ba luật bất di bất dịch

**Luật 1 — Tiền LUÔN tính lại từ `local_ledger_item` qua `TGS_Money`.**
Không đọc `local_viettel_invoice.total_*`. Làm tròn **từng dòng** rồi cộng, đúng
`tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md`. Cách làm
tròn giống hệt `TGS_BCTK_Ajax::build_sales_rows()` và POS:

```
thue_dòng        = round( local_ledger_item_tax_amount đã lưu )
thành_tiền_dòng  = round( TGS_Money::line(...)['tien_hang_sau_ck'] + thue_dòng )
tt_chưa_thuế_dòng = thành_tiền_dòng − thue_dòng
```

Cột phiếu = tổng các dòng. "Tổng chiết khấu" = Σ `local_ledger_item_discount_amount`
đã lưu (trước thuế, cả dòng).

**Luật 2 — "Thông tin VAT" xét theo bản ghi `local_viettel_invoice`.**

| Bộ lọc | Nghĩa |
|---|---|
| Đã có thông tin VAT | có bản ghi VAT mới nhất cho phiếu (đã từng lập/gửi) |
| Chưa có (bỏ qua gửi thuế) | không có bản ghi nào |
| Có VAT nhưng gửi lỗi | có bản ghi, `invoice_state` ∈ `issue_error/cqt_error/validate_error/error` (điều chỉnh: hoặc `queue.status` ∈ `error/blocked`) |

**Luật 3 — Bill Z (phiếu nội bộ) nhận diện bằng QUAN HỆ CHA–CON.**
Phiếu bán có cha là một phiếu bán, và `mã = mã_cha + 'Z'`
(`TGS_BCTK_Report::promo_suffix()`, đối chiếu
`TGS_Viettel_Invoice::is_promo_split_bill_row()` và
`tgs_pos/docs/bill-z-va-hang-tang.md`). KHÔNG chỉ nhìn chữ Z cuối mã — mã POS đời
cũ sinh ngẫu nhiên có thể tự kết thúc bằng Z.

Bộ lọc **Loại phiếu**: `Phiếu thường` (bỏ bill Z — mặc định) · `Chỉ phiếu nội bộ (Z)` · `Tất cả`.

---

## 3. 32 cột — nguồn dữ liệu

| # | Cột | Nguồn |
|---|---|---|
| 0 | Mã shop | `wp_blogs.tgs_site_code` của site |
| 1 | Seri | `local_viettel_invoice.invoice_series` |
| 2 | Mẫu HĐ | `local_viettel_invoice.template_code` |
| 3 | Hình thức thanh toán | meta phiếu bán `$.payment_method_label` |
| 4 | Mã KH | SĐT khách (`local_ledger_person_phone`) |
| 5 | Số hóa đơn | số HĐ Viettel: `viettel_invoice_no` / parse `issue_response_payload`. **Điều chỉnh**: `queue.adjustment_invoice_no` |
| 6 | Ghi chú hóa đơn | ghi chú phiếu (bóc tiền tố "Đơn POS …"). **Điều chỉnh**: kèm "HĐ gốc: …" |
| 7 | Thành tiền chưa thuế | Σ dòng (Luật 1) |
| 8 | Ngày hóa đơn | `local_viettel_invoice.issue_sent_at` |
| 9 | Tổng thuế | Σ `local_ledger_item_tax_amount` |
| 10 | Thành tiền | Σ dòng — số khách trả |
| 11 | Thành tiền bằng chữ | `TGS_BCTK_Report::doc_tien_bang_chu(#10)` — "Bảy mươi tám nghìn đồng chẵn" |
| 12 | Tổng chiết khấu | Σ `local_ledger_item_discount_amount` |
| 13 | Tỷ lệ thuế | mọi dòng cùng % → %; lẫn → dòng đầu khác 8%; toàn KCT → "KCT" |
| 14 | Tên công ty bên mua | `vi.buyer_name` / meta `tax_invoice_buyer.customer_company_name` / tên KH |
| 15 | Tên KH | nhãn bán lẻ (`TGS_Viettel_Invoice_Flow_Service::is_retail_buyer`) → "Bán cho người tiêu dùng" hoặc tên thật |
| 16–19 | Địa chỉ / Email / Điện thoại / MST bên mua | meta `tax_invoice_buyer.*` → khách của phiếu |
| 20–23 | Địa chỉ / Tên / Điện thoại / MST bên bán | `get_blog_option($blog_id, 'tgs_shop_address' / 'blogname' / 'tgs_shop_phone' / 'tgs_shop_tax_code')`; MST dự phòng `wp_global_vat_shops.tax_code` |
| 24 | Ngày xuất | `local_ledger.created_at` (phiếu bán / phiếu hoàn) |
| 25 | Số phiếu xuất | `local_ledger_code` (mã phiếu bên mình) |
| 26 | Nhân viên xuất | `display_name` theo `local_ledger.user_id` |
| 27 | Lý do | hằng `XBA` (xuất bán) · `DCG` (điều chỉnh giảm) |
| 28 | Trạng thái VAT | `TGS_BCTK_Report::vat_state_label()` — "Hóa đơn có chữ ký số" / "Hóa đơn chưa lập VAT" / "Đang xử lý" / "Gửi lỗi VAT" / "Bỏ qua" |
| 29 | Số SO | để trống |
| 30 | SL bản ghi | số dòng hàng của phiếu |
| 31 | userID xuất | `local_ledger.user_id` |

**Màn Phiếu điều chỉnh giảm**: nguồn là bảng GLOBAL
`wp_tgs_viettel_invoice_return_adjustments` (lọc `blog_id` + khoảng ngày), join
phiếu hoàn (type 11) / phiếu bán / bản ghi VAT điều chỉnh
(`adjustment_invoice_record_id`). Dòng hàng lấy từ `local_ledger_item_id` (JSON)
của phiếu hoàn (type 3), cùng cách `build_payload()` của return-adjustment đọc.
**Các cột tiền mang dấu ÂM.**

---

## 4. Modal xem chi tiết + PDF

Bấm một dòng → modal (`partials/vat-detail-modal.php`):

- 3 khối: **Đơn vị bán hàng** · **Người mua** · **Chứng từ**.
- Bảng dòng hàng bố cục hoá đơn: STT · Tên · ĐVT · SL · Đơn giá (sau CK trước
  thuế) · Thành tiền chưa thuế · Thuế suất · Tiền thuế · Thành tiền · số tiền
  bằng chữ.
- Nút **Xem PDF hóa đơn** — hiện khi `invoice_state = 'done'`. Gọi endpoint sẵn
  có `tgs_viettel_pos_preview_invoice_pdf` của `tgs-viettel-invoice` (nhận
  `blog_id` + nonce POS, tự `switch_to_blog`), nhúng base64 vào `<iframe>`.
- Với **Phiếu điều chỉnh giảm**, nút PDF mở hoá đơn **GỐC** của đơn bán để đối
  chiếu (chưa có endpoint PDF riêng cho hoá đơn điều chỉnh).

Dữ liệu dòng hàng đã nằm sẵn trong mảng `rows` trả về (khoá `items`) — modal
không gọi thêm AJAX.

---

## 5. Chỗ code phải nhớ

| Việc | File |
|---|---|
| Truy vấn phiếu + dòng hàng thô, nhận diện bill Z, đọc chữ | `includes/class-bctk-report.php` — `site_vat_sales_rows()`, `site_vat_adjust_rows()`, `doc_tien_bang_chu()`, `vat_state_label()`, `seller_info()`, `retail_buyer_name()`, `buyer_meta_selects()` |
| Handler AJAX per-site, tính tiền qua `TGS_Money`, dựng 32 cột, lọc `vat_filter` | `includes/class-bctk-ajax.php` — `fetch_vat_sales()`, `fetch_vat_adjust()`, `build_vat_row()`, `vat_line_money()` |
| Giao diện bảng + config `window.TGS_BCTK` | `admin-views/vat-sales.php`, `admin-views/vat-adjust.php` |
| Thanh lọc riêng (loại phiếu / thông tin VAT) | `admin-views/partials/vat-report-filters.php` |
| Modal chi tiết | `admin-views/partials/vat-detail-modal.php` |
| Renderer 32 cột + modal + PDF | `assets/js/bctk-vat-report.js` |
| Nạp asset + nonce PDF | `tgs-bc-tk.php` → `enqueue_assets()` |
| Batch từng site, tiến độ, nonce | `assets/js/bctk-filter.js` (dùng lại, chỉ khai `setRenderer` + `extraParams`) |

---

## 6. Chưa làm (chờ hướng dẫn)

- **Sửa phiếu** khi chưa có thông tin VAT (phiếu nội bộ: sửa thoải mái).
- **Gửi lại** hoá đơn / các trường hợp gửi lại.
- **Điều chỉnh / thay thế** hoá đơn từ màn này.
- Endpoint PDF riêng cho hoá đơn điều chỉnh giảm.
- Luồng trạng thái cuối: "được cơ quan thuế chấp nhận" (hiện gộp vào "Hóa đơn có
  chữ ký số").
