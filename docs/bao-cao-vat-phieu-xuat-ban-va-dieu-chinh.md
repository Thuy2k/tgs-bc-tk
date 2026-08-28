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
| 20–23 | Địa chỉ / Tên / Điện thoại / MST bên bán | **SNAPSHOT cấu hình Viettel của chính hoá đơn** — `wp_tgs_viettel_invoice_config_snapshots.settings_json` (`company_name` / `company_address` / `company_phone` / `supplier_tax_code`), qua `TGS_Viettel_Invoice_Plugin::get_settings_for_invoice($vi_id, $blog_id)`. Chưa có hoá đơn → cấu hình cụm đang hiệu lực (`wp_tgs_viettel_invoice_clusters.legal_*`). Cuối cùng: option blog, rồi `result.supplierTaxCode` trong payload. Xem `TGS_BCTK_Report::seller_info()` |
| 24 | Ngày xuất | `local_ledger.created_at` (phiếu bán / phiếu hoàn) |
| 25 | Số phiếu xuất | `local_ledger_code` (mã phiếu bên mình) |
| 26 | Nhân viên xuất | `display_name` theo `local_ledger.user_id` |
| 27 | Lý do | hằng `XBA` (xuất bán) · `NTH1` (nhập trả hàng — điều chỉnh giảm) |
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

Bấm một dòng → **component modal dùng chung** `assets/js/bctk-phieu-modal.js`
(`window.TGSBctkPhieuModal.open(payload, {title, note, actions})`). Component tự
dựng DOM một lần, chia khối: `renderToolbar` · `renderDoc` · `renderLines` ·
`renderFoot` — sửa khối nào không đụng khối khác. Màn báo cáo chỉ TRUYỀN
`payload` (dòng đã dựng ở server) + mảng `actions` vào.

**Bố cục bám PHẦN MỀM CŨ** (kế toán chạy song song hai phần mềm, tránh ngợp) —
modal **full màn (100vw × 100vh)**, cao cố định — không co giãn theo nội dung để
bố cục không nhảy. Lớp xem PDF (`.pm-pdf`) có `z-index: 20` để không bị tiêu đề
bảng dòng hàng (thead sticky) xuyên qua:

| Vùng | Nội dung |
|---|---|
| Tiêu đề | mã phiếu · badge trạng thái VAT · nhãn BILL Z |
| **Thanh hành động** (trên) | các nút sự kiện theo trạng thái (§6) + Đóng |
| **Chứng từ** (trên) | Lý do · Kho/Mã shop · Số phiếu xuất · Ngày xuất · Số HĐ · Seri · Mẫu HĐ · Ngày HĐ · Hình thức TT · Trạng thái VAT · Tỷ lệ thuế · Số SO · SL bản ghi — kèm dòng **ĐƠN VỊ BÁN HÀNG** (tên · MST · địa chỉ · ĐT) |
| **Dòng hàng** (giữa, cuộn riêng) | STT · Mã hàng · Tên hàng · Kho · ĐVT · SL · SL ĐVT · Đơn giá · CK · TT chưa thuế · Thuế suất · Tiền thuế · Thành tiền · Số lô · EXP · Ghi chú — tfoot: tổng từng cột |
| **Đáy 3 cột** | ① Thông tin khách hàng (Mã KH/SĐT · Tên khách · Tên công ty · MST · Địa chỉ · ĐT · Email) ② Nhân viên xuất + userID + Ghi chú phiếu ③ Tổng cộng: Tiền hàng · Thuế · Chiết khấu · **Tổng thanh toán** + bằng chữ |

Mỗi dòng hàng kèm `item_id` để base sửa/xoá bám vào. Tên hàng trống thì bồi từ
catalog global (`TGS_BCTK_Report::product_info()`). Số lô / EXP lấy thêm từ
`local_ledger_item.lot_code` / `exp_date`.

Bảng dòng hàng gắn `data-ds-no-grid / -export / -colcfg / -filter` để Design
System KHÔNG nhét thanh "Chọn cột / Xuất Excel / dòng lọc cột" vào. **VẪN cho
kéo giãn cột**.
- Nút **Xem PDF hóa đơn** — hiện khi `invoice_state = 'done'`. Gọi AJAX RIÊNG của
  bc-tk `tgs_bctk_vat_pdf` (`TGS_BCTK_Ajax::vat_pdf()`): `switch_to_blog` →
  đọc `{prefix}local_viettel_invoice` theo prefix thật → cấu hình Viettel từ
  SNAPSHOT → gọi `getInvoiceRepresentationFile`. **KHÔNG** gọi lại
  `tgs_viettel_pos_preview_invoice_pdf` vì endpoint đó bám hằng số `TGS_TABLE_*`
  của site tổng nên tra nhầm bảng khi chạy chéo site.
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
| Renderer 32 cột + đăng ký hành động | `assets/js/bctk-vat-report.js` |
| Component modal chứng từ (dùng chung) | `assets/js/bctk-phieu-modal.js` — `window.TGSBctkPhieuModal` |
| Nạp asset + nonce PDF | `tgs-bc-tk.php` → `enqueue_assets()` |
| Batch từng site, tiến độ, nonce | `assets/js/bctk-filter.js` (dùng lại, chỉ khai `setRenderer` + `extraParams`) |

---

## 6. Hành động & giới hạn CHÉO SITE

### 6.1 Vì sao "chưa phát hành" phải mở màn của shop

Luồng **gửi lại thuế / tách bill / chuyển bill Z / sửa phiếu** nằm ở tgs_pos →
tgs-viettel-invoice và bám **hằng số bảng theo site** (`TGS_TABLE_LOCAL_LEDGER`
= `$wpdb->prefix . 'local_ledger'`, chốt ở `plugins_loaded`). Màn tổng chạy trên
site tổng nên hằng số trỏ về bảng site tổng — gọi các endpoint đó cho shop khác
là **tra nhầm bảng** (đúng lỗi PDF gặp lần đầu). `TGS_BCTK_Ajax::vat_pdf()` giải
quyết riêng cho PDF bằng cách `switch_to_blog` rồi đọc `$wpdb->prefix` tươi.

Nên các nút "chưa phát hành" trong modal **mở tab màn "DS Gửi Thuế" của chính
shop** (`get_home_url($blog_id, '/pos-viettel-tax/')` — kèm trong payload là
`pos_tax_url`). Ở đó luồng chạy y hệt, native. Kế toán thao tác xong bấm "Tìm
kiếm" lại để cập nhật báo cáo.

| Điều kiện phiếu | Nút | Hành vi hiện tại |
|---|---|---|
| Chưa phát hành / bill Z | **Sửa phiếu ↗** | mở màn DS Gửi Thuế của shop |
| Chưa phát hành (không phải Z) | **Tách / chuyển bill Z ↗**, **Gửi hoá đơn thuế ↗** | mở màn DS Gửi Thuế của shop |
| Gửi lỗi | **Gửi lại ↗** | mở màn DS Gửi Thuế của shop |
| Đã phát hành | **Xem PDF**, **Điều chỉnh**, **Thay thế** | PDF chạy thật; điều chỉnh/thay thế chờ chốt luồng |

### 6.2 Việc còn phải làm

- **Làm luồng "chưa phát hành" chạy TRONG modal** thay vì mở tab — cần một
  trong hai: (a) bc-tk proxy `switch_to_blog` + bản logic đọc bảng theo
  `$wpdb->prefix` cho từng bước, hoặc (b) tgs_pos/tgs-viettel-invoice chuyển
  hằng số bảng sang hàm động. Chờ chốt hướng.
- **Điều chỉnh / thay thế hoá đơn** đã phát hành.
- Endpoint PDF riêng cho hoá đơn điều chỉnh giảm.
- Luồng trạng thái cuối "được cơ quan thuế chấp nhận".

### 6.3 Modal là component dùng chung

`bctk-phieu-modal.js` để dùng lại cho Sổ CSKH, Báo cáo/Tổng hợp bán hàng, menu
Mua hàng... — mỗi màn chỉ truyền `payload` + mảng `actions` riêng. Tiền luôn
theo `tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md`.
