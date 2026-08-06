<?php

/**
 * Trang Báo cáo tồn kho (BC_TK → Tồn kho)
 *
 * Bố cục bám theo phần mềm cũ: bộ lọc cột trái hẹp, bảng số liệu chiếm phần
 * còn lại. Bộ lọc được dựng bằng dữ liệu từ TGS_BCTK_Sites::filter_bootstrap()
 * nên tách hẳn khỏi báo cáo — báo cáo khác dùng lại chỉ cần include partial
 * này rồi nghe sự kiện 'bctk:search'.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

$bctk_boot = TGS_BCTK_Sites::filter_bootstrap();
?>

<div class="bctk-page" id="bctkPage">

    <!-- ══ BỘ LỌC TRÁI ══ -->
    <aside class="bctk-filter" id="bctkFilter">
        <div class="bctk-filter__head">
            <span>Tiêu chí tìm kiếm</span>
            <button type="button" class="bctk-collapse" id="bctkToggleFilter" title="Thu gọn bộ lọc">«</button>
        </div>

        <div class="bctk-filter__body">

            <div class="bctk-group bctk-group--sites">
                <?php
                /*
                 * Mọi ô tích PHẢI mang class form-check-input.
                 * Theme đặt appearance:none cho input[type=checkbox] rồi vẽ lại
                 * dấu tích bằng background-image, nhưng CHỈ cho .form-check-input.
                 * Thiếu class thì ô vẫn tích được nhưng không hiện dấu gì —
                 * nhìn như bấm không ăn.
                 */
                ?>
                <div class="bctk-group__title">
                    Chi nhánh
                    <label class="bctk-all"><input class="form-check-input" type="checkbox" id="bctkCheckAllSites"> Tất cả</label>
                </div>
                <input type="search" class="bctk-search" id="bctkSiteSearch" placeholder="Lọc chi nhánh…">
                <div class="bctk-list" id="bctkSiteList">
                    <?php foreach ($bctk_boot['sites'] as $s) : ?>
                        <label class="bctk-item" data-blog="<?php echo (int) $s['blog_id']; ?>">
                            <input class="form-check-input bctk-site" type="checkbox" value="<?php echo (int) $s['blog_id']; ?>"
                                   data-type="<?php echo esc_attr($s['type']); ?>">
                            <span class="bctk-item__label"><?php echo esc_html($s['label']); ?></span>
                            <?php if ($s['type'] === 'warehouse') : ?>
                                <span class="bctk-tag" title="Site kho — có nhiều phân kho">kho</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php
                /*
                 * Nút "chọn nhanh toàn bộ shop của kho" do JS dựng, và CHỈ dựng
                 * khi người dùng đã tích đúng cái kho đó.
                 *
                 * Trước đây liệt kê sẵn nút của mọi kho ngay từ đầu — chưa chọn
                 * gì đã thấy một loạt gợi ý, rối và chiếm chỗ.
                 */
                ?>
                <div id="bctkQuickBox"></div>
            </div>

            <?php
            /*
             * Khối này là "điểm tồn" của các chi nhánh vừa tích ở trên.
             *
             * Khối TRÊN chọn nhiều website; khối DƯỚI đi sâu vào từng website đó:
             *   - website KHO  → đổ ra các mã phân kho đã khai báo
             *   - website SHOP → đổ ra chính nó (mã tgs_site_code, không có mã
             *     thì lấy tên) vì shop không chia phân kho
             *
             * Nhờ vậy tích chi nhánh nào ở trên là thấy nó "rơi xuống" dưới,
             * rồi lọc tiếp cho gọn.
             */
            ?>
            <div class="bctk-group bctk-group--zones bctk-hidden" id="bctkZoneGroup">
                <div class="bctk-group__title">
                    Mã kho
                    <label class="bctk-all"><input class="form-check-input" type="checkbox" id="bctkCheckAllZones"> Tất cả</label>
                </div>
                <input type="search" class="bctk-search" id="bctkZoneSearch" placeholder="Lọc mã kho…">
                <div class="bctk-list" id="bctkZoneList"></div>
            </div>

        </div>

        <?php
        /*
         * Nút bấm nằm NGOÀI vùng cuộn.
         *
         * Trước đây đặt trong .bctk-filter__body — mà body cuộn được, nên kho
         * khai báo chục mã phân kho là nút "Tìm kiếm" bị đẩy khuất xuống dưới.
         * Người dùng không biết phải lăn tiếp nên tưởng màn hình hỏng.
         */
        ?>
        <div class="bctk-filter__foot">
            <?php
            /*
             * Không có nút xuất Excel ở đây.
             *
             * Bảng kết quả đã có sẵn nút "Xuất Excel" do Design System tự chèn
             * (tgs-erp-ds.js), và nút đó xuất đúng những dòng đang hiển thị —
             * kể cả sau khi lọc theo cột. Đặt thêm một nút nữa ở bộ lọc chỉ làm
             * người dùng phân vân không biết hai nút khác nhau chỗ nào.
             */
            ?>
            <div class="bctk-actions">
                <button type="button" class="bctk-btn bctk-btn--primary" id="bctkSearch">Tìm kiếm</button>
            </div>

            <div class="bctk-progress bctk-hidden" id="bctkProgress">
                <div class="bctk-progress__bar"><span id="bctkProgressFill"></span></div>
                <div class="bctk-progress__text" id="bctkProgressText"></div>
            </div>
        </div>
    </aside>

    <!-- ══ BẢNG SỐ LIỆU ══ -->
    <section class="bctk-result">
        <div class="bctk-result__head">
            <strong>Tồn kho theo mặt hàng</strong>
            <span class="bctk-count" id="bctkRowCount">chưa tìm kiếm</span>
        </div>

        <div class="bctk-tablewrap">
            <table class="bctk-table" id="bctkTable">
                <thead>
                    <tr>
                        <th class="c-zone">Kho</th>
                        <th class="c-sku">Mã hàng</th>
                        <th class="c-name">Tên hàng</th>
                        <th class="c-alias">Alias</th>
                        <th class="c-num">Số lượng</th>
                        <th class="c-num">Đơn giá</th>
                        <th class="c-num">Thành tiền</th>
                        <th class="c-num">SL đi đường</th>
                        <th class="c-num">Tồn max</th>
                        <th class="c-num">Tồn min</th>
                        <th class="c-num">SL cần nhập</th>
                        <th class="c-unit">ĐVT</th>
                    </tr>
                </thead>
                <tbody id="bctkBody">
                    <tr class="bctk-empty">
                        <td colspan="12">Chọn chi nhánh bên trái rồi bấm <strong>Tìm kiếm</strong>.</td>
                    </tr>
                </tbody>
                <tfoot id="bctkFoot" class="bctk-hidden">
                    <tr>
                        <td colspan="4">Tổng cộng</td>
                        <td class="c-num" id="fQty">0</td>
                        <td class="c-num"></td>
                        <td class="c-num" id="fAmount">0</td>
                        <td class="c-num" id="fTransit">0</td>
                        <td class="c-num" id="fMax">0</td>
                        <td class="c-num" id="fMin">0</td>
                        <td class="c-num" id="fNeed">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>

<script>
    window.TGS_BCTK = {
        ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce(TGS_BCTK_Ajax::NONCE)); ?>',
        zones: <?php echo wp_json_encode($bctk_boot['zones']); ?>,
        sites: <?php echo wp_json_encode($bctk_boot['sites']); ?>,
        children: <?php echo wp_json_encode($bctk_boot['children']); ?>
    };
</script>
