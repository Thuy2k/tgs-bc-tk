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
modal **gần full màn (98vw × 97vh)** — chừa khe hẹp quanh viền để thấy rõ là
modal; cao cố định, không co giãn theo nội dung để bố cục không nhảy. Lớp xem
PDF (`.pm-pdf`) có `z-index: 20` để không bị tiêu đề bảng dòng hàng (thead
sticky) xuyên qua:

| Vùng | Nội dung |
|---|---|
| Tiêu đề | mã phiếu · badge trạng thái VAT · nhãn BILL Z |
| **Thanh hành động** (trên) | các nút sự kiện theo trạng thái (§6) + **⭳ Xuất Excel phiếu** + Đóng |
| **Chứng từ** (trên) | Lý do · Kho/Mã shop · Số phiếu xuất · Ngày xuất · Số HĐ · Seri · Mẫu HĐ · Ngày HĐ · Hình thức TT · Trạng thái VAT · Tỷ lệ thuế · SL bản ghi — kèm dòng **ĐƠN VỊ BÁN HÀNG** (tên · MST · địa chỉ · ĐT). *("Số SO" đã bỏ — luôn trống, gây rối.)* |
| **Dòng hàng** (giữa, cuộn riêng) | STT · Mã hàng · Tên hàng · Kho · ĐVT · SL · SL ĐVT · Đơn giá · CK · TT chưa thuế · Thuế suất · Tiền thuế · Thành tiền · Số lô · EXP · Ghi chú — tfoot: tổng từng cột |
| **Đáy 3 cột** | ① Thông tin khách hàng (Mã KH/SĐT · Tên khách · Tên công ty · MST · Địa chỉ · ĐT · Email) ② **Nhân viên xuất** + userID xuất, **Người thao tác (đang can thiệp)** + userID thao tác (người đăng nhập — có thể là kế toán), Ghi chú phiếu ③ Tổng cộng: Tiền hàng · Thuế · Chiết khấu · **Tổng thanh toán** + bằng chữ |

**⭳ Xuất Excel phiếu** — nút luôn có (khi không ở chế độ sửa): xuất `.xls`
(bảng HTML, Excel mở thẳng, không cần thư viện) gồm khối chứng từ + bên bán +
bên mua + nhân viên/người thao tác + tổng cộng, rồi bảng 16 cột dòng hàng — đúng
số đang hiển thị (đã theo `TGS_Money`). Tên file `phieu-<số phiếu xuất>.xls`.
`actorId` / `actorName` do `tgs-bc-tk.php` `wp_localize_script` truyền
(`wp_get_current_user()`), report chuyển vào modal qua `opts.actor`.

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
`pos_tax_url`) **kèm deep-link `?open_code=<local_ledger_code>&open_date=<YYYY-MM-DD>`**.
`pos-viettel-tax.js` (`init()` → `_openByDeepLinkCode()`) đọc 2 tham số → đặt
khoảng ngày về đúng hôm đó, `fetchList()`, rồi tự `openRowQuickView()` chứng từ
khớp `local_ledger_code` → bung sẵn chi tiết bill. Ở đó luồng chạy y hệt, native.
Kế toán thao tác xong bấm "Tìm kiếm" lại để cập nhật báo cáo.

| Điều kiện phiếu | Nút | Hành vi hiện tại |
|---|---|---|
| Chưa phát hành / bill Z | **Sửa phiếu ↗** | mở màn DS Gửi Thuế của shop |
| Chưa phát hành (không phải Z) | **Tách / chuyển bill Z ↗**, **Gửi hoá đơn thuế ↗** | mở màn DS Gửi Thuế của shop |
| Gửi lỗi | **Gửi lại ↗** | mở màn DS Gửi Thuế của shop |
| **Chưa** phát hành | **Hoàn hàng ↗** | mở màn "Lịch sử đơn hàng" của shop, bung sẵn đúng đơn |
| **Đã** phát hành | **Xem PDF**, **Điều chỉnh (hoàn hàng) ↗**, **Thay thế** | PDF chạy thật; "Điều chỉnh" mở màn đơn hàng của shop (hoàn ở đó = sinh phiếu điều chỉnh giảm); "Thay thế" chờ chốt luồng |

**"Hoàn hàng ↗" / "Điều chỉnh (hoàn hàng) ↗"** — cùng một hành vi: mở
`get_home_url($blog_id, '/pos-orders/')` (payload `pos_orders_url`) kèm
`?open_code=<local_ledger_code>&open_date=<YYYY-MM-DD ngày xuất>`.
`pos-orders.php` (`maybeDeepLinkOpenOrder()` trong `DOMContentLoaded`) đọc 2 tham
số này → đặt khoảng ngày + lọc cột "Mã", `loadOrders()`, rồi `viewOrderDetail()`
đúng đơn để kế toán bấm "Hoàn hàng" ngay trong đó. Đơn **đã phát hành** → thao
tác hoàn tại đó sinh **phiếu điều chỉnh giảm**; đơn **chưa phát hành** → hoàn
hàng thường. Luồng hoàn chạy native trên site shop. (Nút hiện theo trạng thái:
chưa phát hành = "Hoàn hàng"; đã phát hành = "Điều chỉnh (hoàn hàng)".)

### 6.2 SỬA DÒNG HÀNG trong modal (đã có)

Nút **"✎ Sửa dòng hàng"** hiện khi phiếu là **bill Z** hoặc **CHƯA phát hành
hoá đơn** (`vat_state` ∉ `done/issued`), **CHƯA có phiếu hoàn con** và không phải
màn điều chỉnh. Vào chế độ
sửa: mỗi cột sửa được thành ô nhập (Mã hàng · Tên hàng · ĐVT · SL · Đơn giá ·
CK · Thuế suất · Số lô · EXP · Ghi chú), cột tiền cập nhật **tạm tính** ngay;
**+ Thêm dòng**, nút **✕** xoá dòng; phím **↑ ↓ Enter** đi giữa các dòng cùng
cột (Enter ở dòng cuối = thêm dòng). Bấm **💾 Lưu** →
`tgs_bctk_vat_save_lines`:

1. `switch_to_blog` + đọc/ghi bảng theo `$wpdb->prefix` tươi (KHÔNG dùng
   `TGS_TABLE_*` — bám site tổng).
2. Chặn nếu phiếu đã phát hành (trừ bill Z).
   **Chặn (409) nếu phiếu đã có phiếu hoàn con** (`local_ledger_type = 11`,
   `local_ledger_parent_id = phiếu bán`) — hoàn một phần / toàn phần thì số liệu
   dòng đã bị phiếu hoàn tham chiếu, sửa tiếp sẽ lệch. Kiểm cả ở
   `vat_edit_context($block_if_returned = true)` lẫn client. **Ghi chú vẫn sửa
   được** (`vat_save_note` không bật cờ này).
3. Mỗi dòng: quy `(SL, Đơn giá, CK)` — giá trị POS (sau thuế, trước CK) — về 5
   cột gốc bằng `TGS_Money::from_pos()` (thuế suất lấy như trên, không tin
   client); **UPDATE / INSERT / DELETE VĨNH VIỄN** (`$wpdb->delete`, không soft)
   trên `local_ledger_item` của **phiếu xuất con** (type 2).
4. **Đồng bộ `local_ledger_item_id` (JSON) cho CẢ CÂY PHIẾU** — đúng như luồng
   tạo đơn ở `tgs_pos` (`TGS_POS_Order_Handler::update_ledger_items`): danh sách
   id dòng hàng phải giống hệt trên **phiếu bán (10)** và **mọi phiếu con**
   (`local_ledger_parent_id = phiếu bán`) — phiếu xuất (2), phiếu thu (7), phiếu
   chi (8)… Thêm/sửa/xoá là tất cả đi theo.
5. `local_ledger_total_amount` chỉ ghi cho **phiếu bán + phiếu xuất**
   (= `TGS_Money::total()['thanh_tien_dong']`); phiếu thu/chi giữ số tiền của
   chính nó.
6. Đối chiếu tiền đã thu (phiếu thu type 7/8 đã duyệt); lệch ≥ 1đ → trả
   **cảnh báo** để kế toán xử phiếu thu / công nợ.
7. Trả về payload phiếu mới → modal cập nhật tại chỗ, bảng chạy lại tìm kiếm.

**Ghi chú phiếu** có nút **"✎ Sửa ghi chú"** riêng (dưới, khối Nhân viên & ghi
chú) → textarea → `tgs_bctk_vat_save_note` (lưu đúng định dạng POS
`Đơn POS <mã> | Ghi chú: …`).

> ⚠️ Chỉ đụng **dòng hàng + tổng tiền phiếu**. Tồn kho hệ thống này suy từ
> chính `local_ledger_item` nên tự khớp. **Phiếu thu KHÔNG tự chỉnh** — đổi tổng
> mà tiền đã thu khác thì có cảnh báo, kế toán xử tay.

Component `bctk-phieu-modal.js` nhận `editable` + `onSaveLines` + `onSaveNote` →
**các màn báo cáo khác** (bán hàng, mua hàng…) chỉ cần truyền callback tương tự
là có ngay tính năng sửa.

**Thêm dòng có gợi ý sản phẩm:** gõ vào ô **Mã hàng** hoặc **Tên hàng** (≥ 2 ký
tự) → gợi ý từ catalog GLOBAL (`tgs_bctk_product_search` →
`wp_global_product_name`, tìm theo sku / tên / barcode). Chọn (chuột hoặc ↓ ↑
Enter) → tự điền sku · tên · ĐVT · đơn giá (nếu đang trống). Bảng global nên
không cần `switch_to_blog`.

**ĐVT & đơn giá theo giỏ hàng `tgs_pos`:**
- ĐVT là ô **chọn** trong các đơn vị của mã hàng (cấu hình bảng giá). Chọn sản
  phẩm → mặc định **ĐVT ưu tiên** (`is_default_unit`, đánh dấu ★) + đơn giá của
  ĐVT đó. Đổi ĐVT → tỷ lệ quy đổi + đơn giá tự cập nhật; kế toán sửa tiếp được.
- Cột **SL** = số lượng theo ĐVT bán; **SL ĐVCB** = `SL × tỷ lệ` (chỉ hiện).
  **Đơn giá** = giá 1 ĐVT (đã gồm thuế, trước CK) — như POS hiển thị.
- Nguồn: `TGS_Price_List` (`wp_global_htsoft_stock_convert` +
  `wp_global_htsoft_price_list_blog`) qua `tgs_bctk_product_units` /
  `tgs_bctk_product_search`. **Lấy bảng giá của WEBSITE ĐANG SỬA** (site chạy báo
  cáo) — `vat_save_lines` chốt `blog_id` này TRƯỚC khi `switch_to_blog` sang
  site shop. Server quy `(SL_ĐVT, ĐVT, giá_ĐVT)` → `quantity` (ĐVCB) + `price`
  (1 ĐVCB) + `local_ledger_item_unit_name/_quantity/_ratio` rồi mới
  `TGS_Money::from_pos()`.

**Thuế suất KHÔNG sửa tay** (cột chỉ hiển thị):
- Dòng cũ → giữ đúng `local_ledger_item_tax_percent` / `_is_kct` đã lưu.
- Dòng mới → server tra `wp_global_product_name.global_product_tax` /
  `global_product_is_kct` theo mã hàng (đúng cấu hình ở *Cấu hình → Hàng hoá →
  Quản lý thuế suất*). Không có cấu hình → 8%. `is_kct = 1` ⇒ thuế 0, cột hiện
  "KCT".
- Client gửi `thue_pct` lên chỉ để hiện tạm; `vat_save_lines` **luôn ghi đè**
  bằng số ở trên.

### 6.3 Modal là component dùng chung

`bctk-phieu-modal.js` để dùng lại cho Sổ CSKH, Báo cáo/Tổng hợp bán hàng, menu
Mua hàng... — mỗi màn chỉ truyền `payload` + mảng `actions` riêng. Tiền luôn
theo `tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md`.

### 6.4 Xem lại phiếu ở 3 màn báo cáo bán hàng (CHỈ ĐỌC)

**Sổ CSKH · Báo cáo bán hàng / Hàng bán trả lại · Tổng hợp bán hàng** — bấm một
dòng bảng → mở lại **phiếu bán** trên đúng component modal, luồng dựng payload y
hệt màn VAT. Đây là **xem** — cho sửa **ghi chú** (khi phiếu chưa phát hành /
bill Z), **không** cho sửa dòng hàng (nhạy cảm — phải vào *Bán hàng → Quản lý
VAT*). Không cần shop đã khai thuế; nếu chưa có bản ghi VAT thì các ô Seri / Số
HĐ / Trạng thái để trống hoặc "Hóa đơn chưa lập VAT".

Cách nối:
- `site_sales_rows` / `site_cskh_rows` thêm `p.local_ledger_id AS sale_id`;
  `site_sales_sum_rows` thêm `IF(type=11, parent_id, id) AS sale_id` (dòng hoàn →
  mở phiếu bán cha). `build_sales_rows` / `build_cskh_rows` /
  `build_sales_sum_rows` gắn `blog_id` + `sale_id` vào mỗi dòng.
- 3 view PHP: `rowHtml` gắn `data-blog` / `data-sale` + class `bctk-clickrow`.
- `enqueue_assets` cho `VIEW_CSKH / VIEW_SALES / VIEW_SALESSUM` nạp
  `bctk-phieu-modal.js` + `bctk-phieu-view.js`, localize `tgsBctkPhieuView`
  (`ajaxUrl`, `nonce` = `TGS_BCTK_Ajax::NONCE`, `actorId`, `actorName`).
- `bctk-phieu-view.js` — click `#bctkBody tr[data-sale]` → POST
  `tgs_bctk_phieu_view` (`TGS_BCTK_Ajax::phieu_view()` → `vat_row_for_sale()`) →
  `TGSBctkPhieuModal.open(row, { editable: canNote, editableLines: false,
  onSaveNote, actions:[pdf] })`. Ghi chú lưu qua `tgs_bctk_vat_save_note` (đã có).

### 6.5 Xem lại phiếu ở 2 màn báo cáo MUA HÀNG (CHỈ ĐỌC) — base RIÊNG

**Báo cáo mua hàng / Hàng trả nhà cung cấp · Tổng hợp mua hàng** — bấm một dòng
→ mở lại **phiếu nhập kho** (`local_ledger_type = 1`, KHÔNG có phiếu cha). Vì cột
mua khác hẳn bên bán nên có **component modal riêng** `bctk-phieu-mua-modal.js`
(`window.TGSBctkPhieuMuaModal`):

- **Đơn giá = TRƯỚC thuế, TRƯỚC chiết khấu** (đúng như lúc tạo phiếu nhập / như
  HTsoft), không phải giá bán sau thuế như bên bán.
- Cột dòng hàng: STT · Mã hàng · Tên hàng · Kho · SL · ĐVT · SL ĐVCB · **Đơn giá**
  · **TT không VAT** (= SL ĐVCB × Đơn giá) · CK (VNĐ) · CK % · Thuế % · Thuế VNĐ ·
  Thành tiền · Số lô · EXP · Ghi chú.
- Khối thông tin: **NHÀ CUNG CẤP** (mã · tên · MST · địa chỉ · ĐT · email) thay
  cho khách hàng; chứng từ có Lý do nhập, Hạn TT, Số HĐ, Ký hiệu HĐ, Ngày HĐ.
- Tiền vẫn đi sát `mo-hinh-tien-va-bang-local-ledger-item.md`: mỗi dòng qua
  `TGS_Money::line(qty, giá_trước_thuế, ck_trước_thuế, thuế%)`; **KHÔNG làm tròn**
  (giống `build_purchase_rows()` — đây là giá vốn). Thuế lấy số đã lưu.
- CHỈ ĐỌC + **sửa ghi chú** phiếu (ghi thẳng `local_ledger_note`, không tiền tố
  POS) + **Xuất Excel phiếu**. Không sửa dòng hàng.
- Nút **"↗ Xem & sửa phiếu nhập kho"** mở trang chi tiết CŨ (đầy đủ Sửa phiếu /
  Trình tự & Duyệt): `get_admin_url($blog_id, 'admin.php')` +
  `?page=tgs-shop-management&view=ticket-import-v2-detail&id=<ledger id>` — payload
  `detail_url`.

Cách nối:
- `site_purchase_rows` / `site_purchase_summary_rows` thêm
  `IF(type = 1, local_ledger_id, 0) AS import_id` (dòng trả NCC type 16 → 0, không
  mở được). `build_purchase_rows` / `build_purchase_sum_rows` gắn `blog_id` +
  `import_id`.
- `purchase-report.php` / `purchase-summary.php`: `rowHtml` gắn `data-blog` /
  `data-import` + class `bctk-clickrow` (chỉ khi `import_id > 0`).
- `enqueue_assets` cho `VIEW_PURREPORT / VIEW_PURSUM` nạp
  `bctk-phieu-mua-modal.js` + `bctk-phieu-mua-view.js`, localize
  `tgsBctkPhieuMuaView`.
- `bctk-phieu-mua-view.js` — click `#bctkBody tr[data-import]` → POST
  `tgs_bctk_phieu_mua_view` (`TGS_BCTK_Ajax::phieu_mua_view()` →
  `import_ledger_row()`) → `TGSBctkPhieuMuaModal.open(row, { editable: true,
  onSaveNote })`. Ghi chú lưu qua `tgs_bctk_phieu_mua_save_note`.

### 6.6 Việc còn phải làm

- Tự chỉnh **phiếu thu** khi tổng phiếu đổi (hiện chỉ cảnh báo).
- **Gửi thuế / tách bill / gửi lại** chạy trong modal (hiện mở tab màn shop) —
  cần proxy `switch_to_blog` gọi lại luồng tgs-viettel-invoice.
- **Điều chỉnh / thay thế** hoá đơn đã phát hành.
- Endpoint PDF riêng cho hoá đơn điều chỉnh giảm.
- Luồng trạng thái cuối "được cơ quan thuế chấp nhận".
