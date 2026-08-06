<?php

/**
 * Bộ lọc trái dùng chung cho MỌI báo cáo trong BC_TK.
 *
 * Trang nào cần chỉ việc include; dữ liệu lấy từ $bctk_boot mà trang đã dựng
 * bằng TGS_BCTK_Sites::filter_bootstrap(). Hành vi (tích chọn, lọc, chạy theo
 * batch) do assets/js/bctk-filter.js lo — dùng chung luôn, không chép lại.
 *
 * @var array $bctk_boot
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
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
