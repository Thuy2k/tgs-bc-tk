<?php

/**
 * Modal xem lại một dòng chứng từ VAT — dùng chung cho hai màn báo cáo.
 *
 * CHỈ ĐỌC ở giai đoạn này: xem thông tin bên bán / bên mua / chứng từ + bảng
 * dòng hàng (bố cục theo hoá đơn), và nút "Xem PDF hoá đơn" khi phiếu đã phát
 * hành. Nút sửa phiếu / gửi lại / điều chỉnh làm sau (chờ hướng dẫn).
 *
 * bctk-vat-report.js đổ dữ liệu vào các phần tử [data-vat-*] và #bctkVatItemsBody.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="bctk-vat-modal bctk-hidden" id="bctkVatModal" aria-hidden="true">
    <div class="bctk-vat-modal__overlay" data-vat-close></div>

    <div class="bctk-vat-modal__panel" role="dialog" aria-modal="true" aria-labelledby="bctkVatModalTitle">
        <header class="bctk-vat-modal__head">
            <div>
                <strong id="bctkVatModalTitle">Chi tiết chứng từ VAT</strong>
                <span class="bctk-vat-modal__sub" data-vat-field="ma_phieu"></span>
            </div>
            <button type="button" class="bctk-vat-modal__x" data-vat-close title="Đóng (Esc)">&times;</button>
        </header>

        <div class="bctk-vat-modal__body">

            <div class="bctk-vat-modal__grid">
                <section class="bctk-vat-card">
                    <h4>Đơn vị bán hàng</h4>
                    <dl>
                        <dt>Tên</dt><dd data-vat-field="ban_ten"></dd>
                        <dt>Mã số thuế</dt><dd data-vat-field="ban_mst"></dd>
                        <dt>Địa chỉ</dt><dd data-vat-field="ban_dchi"></dd>
                        <dt>Điện thoại</dt><dd data-vat-field="ban_dt"></dd>
                    </dl>
                </section>

                <section class="bctk-vat-card">
                    <h4>Người mua</h4>
                    <dl>
                        <dt>Tên khách</dt><dd data-vat-field="mua_ten"></dd>
                        <dt>Tên công ty</dt><dd data-vat-field="mua_cty"></dd>
                        <dt>Mã số thuế</dt><dd data-vat-field="mua_mst"></dd>
                        <dt>Địa chỉ</dt><dd data-vat-field="mua_dchi"></dd>
                        <dt>Điện thoại</dt><dd data-vat-field="mua_dt"></dd>
                        <dt>Email</dt><dd data-vat-field="mua_email"></dd>
                    </dl>
                </section>

                <section class="bctk-vat-card">
                    <h4>Chứng từ</h4>
                    <dl>
                        <dt>Số phiếu xuất</dt><dd data-vat-field="so_phieu_xuat"></dd>
                        <dt>Số hóa đơn</dt><dd data-vat-field="so_hd"></dd>
                        <dt>Seri</dt><dd data-vat-field="seri"></dd>
                        <dt>Mẫu HĐ</dt><dd data-vat-field="mau_hd"></dd>
                        <dt>Ngày xuất</dt><dd data-vat-field="ngay_xuat"></dd>
                        <dt>Ngày hóa đơn</dt><dd data-vat-field="ngay_hd"></dd>
                        <dt>Hình thức TT</dt><dd data-vat-field="httt"></dd>
                        <dt>Lý do</dt><dd data-vat-field="ly_do"></dd>
                        <dt>Trạng thái VAT</dt><dd data-vat-field="trang_thai_vat"></dd>
                    </dl>
                </section>
            </div>

            <div class="bctk-vat-modal__tablewrap">
                <table class="bctk-vat-items">
                    <thead>
                        <tr>
                            <th>STT</th>
                            <th>Tên hàng hóa, dịch vụ</th>
                            <th>ĐVT</th>
                            <th class="c-num">SL</th>
                            <th class="c-num">Đơn giá</th>
                            <th class="c-num">Thành tiền chưa thuế</th>
                            <th class="c-num">Thuế suất</th>
                            <th class="c-num">Tiền thuế</th>
                            <th class="c-num">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody id="bctkVatItemsBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">Tổng cộng</td>
                            <td class="c-num" data-vat-field="sum_chua_thue"></td>
                            <td></td>
                            <td class="c-num" data-vat-field="sum_thue"></td>
                            <td class="c-num" data-vat-field="sum_thanh_tien"></td>
                        </tr>
                        <tr>
                            <td colspan="8">Số tiền bằng chữ</td>
                            <td class="bctk-vat-items__words" data-vat-field="thanh_tien_chu"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="bctk-vat-modal__note" data-vat-field="note"></p>
        </div>

        <footer class="bctk-vat-modal__foot">
            <button type="button" class="bctk-btn bctk-btn--primary bctk-hidden" id="bctkVatPdfBtn">
                Xem PDF hóa đơn
            </button>
            <span class="bctk-vat-modal__pdfmsg" id="bctkVatPdfMsg"></span>
            <button type="button" class="bctk-btn" data-vat-close>Đóng</button>
        </footer>

        <div class="bctk-vat-modal__pdf bctk-hidden" id="bctkVatPdfWrap">
            <div class="bctk-vat-modal__pdfhead">
                <span id="bctkVatPdfName"></span>
                <button type="button" class="bctk-vat-modal__x" id="bctkVatPdfClose" title="Đóng PDF">&times;</button>
            </div>
            <iframe id="bctkVatPdfFrame" title="PDF hóa đơn" src="about:blank"></iframe>
        </div>
    </div>
</div>
