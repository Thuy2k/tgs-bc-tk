<?php

/**
 * Engine gộp dữ liệu tồn kho theo mã hàng — chạy từng site một.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_BCTK_Report
{
    /*
     * ─── CÁC HẰNG SỐ PHẢI KHỚP TGS_Global_Product_Source ────────────────────
     *
     * Công thức tính tồn ở dưới CHÉP NGUYÊN từ
     * TGS_Global_Product_Source::get_stock_for_skus(). Bắt buộc phải khớp từng
     * chi tiết, vì đây là con số kế toán đối chiếu — lệch một điều kiện là báo
     * cáo ra số khác với màn tìm sản phẩm và POS, không ai biết bên nào đúng.
     *
     * Không gọi thẳng hàm đó được vì nó gộp theo SKU, còn báo cáo này cần gộp
     * theo SKU **và phân kho**. Nhưng biểu thức CASE thì giữ y nguyên.
     *
     * Sửa công thức ở nguồn thì phải sửa cả đây.
     */
    const ITEM_TYPE_IMPORT         = 1;
    const ITEM_TYPE_EXPORT         = 2;
    const ITEM_TYPE_PURCHASE_ORDER = 9;
    const APPROVER_STATUS_APPROVED = 1;

    /*
     * Phiếu điều chỉnh ghi item_type = 21, KHÔNG phải 1 hay 2.
     *
     * Xem class-tgs-ajax-adjustment.php: cả phiếu lẫn từng dòng đều mang
     * TGS_LEDGER_TYPE_PRODUCT_EDIT, và quantity là CHÊNH LỆCH có dấu
     * (tồn mới − tồn cũ), nên có thể âm.
     */
    const ITEM_TYPE_ADJUSTMENT = 21;

    /**
     * Lấy số liệu tồn của MỘT site, gộp theo (mã hàng, phân kho).
     *
     * Trả về mảng dòng:
     *   [ 'sku', 'zone', 'qty' ]
     *
     * Không dùng switch_to_blog: get_blog_prefix() cho phép trỏ thẳng vào bảng
     * của site khác, rẻ hơn nhiều so với switch (switch phải nạp lại option,
     * cache, user caps của site đó).
     *
     * @param int   $blog_id
     * @param array $zones          Lọc theo phân kho; rỗng = lấy tất cả
     * @param bool  $group_by_zone  Gộp thêm theo phân kho hay không.
     *
     * $group_by_zone chỉ nên bật cho site KHO. Site shop không chia phân kho:
     * dữ liệu của shop thường để trống cột phân kho, nhưng lác đác vài dòng lại
     * có giá trị (nhập nhầm, hoặc phiếu chuyển từ kho về còn giữ mã kho nguồn).
     * Gộp theo phân kho ở shop sẽ tách cùng một mã hàng thành nhiều dòng, mà
     * nhãn hiển thị đều là tên shop — nhìn y hệt dòng trùng lặp.
     */
    public static function site_stock_rows($blog_id, array $zones = [], $group_by_zone = true)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return [];
        }

        /*
         * Điểm nối mở rộng: site nào lấy số liệu qua API riêng thì cắm hook này,
         * trả về mảng cùng định dạng là xong, lõi không phải biết gì thêm.
         * Trả null = dùng truy vấn mặc định bên dưới.
         */
        $custom = apply_filters('tgs_bctk_site_stock_rows', null, $blog_id, $zones);
        if (is_array($custom)) {
            return $custom;
        }

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $item_table   = $prefix . 'local_ledger_item';
        $ledger_table = $prefix . 'local_ledger';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item_table)) !== $item_table) {
            return [];
        }

        $params = [
            self::APPROVER_STATUS_APPROVED,
            self::ITEM_TYPE_IMPORT,
            self::ITEM_TYPE_EXPORT,
        ];

        /*
         * Chỉ lấy dòng CÓ local_product_sku. Dòng thiếu SKU không đối chiếu
         * được với sản phẩm global nên không đưa vào báo cáo.
         */
        $where = [
            "li.local_product_sku IS NOT NULL",
            "li.local_product_sku <> ''",
            "(li.is_deleted = 0 OR li.is_deleted IS NULL)",
            "(l.is_deleted = 0 OR l.is_deleted IS NULL)",
            "(li.local_ledger_item_type IS NULL OR li.local_ledger_item_type <> %d)",
        ];
        $params[] = self::ITEM_TYPE_PURCHASE_ORDER;

        if (!empty($zones)) {
            /*
             * Mã giả ZONE_NONE không phải giá trị có thật trong cột, nó đại diện
             * cho các dòng CHƯA GÁN phân kho. Phải tách ra thành điều kiện
             * "rỗng hoặc NULL" riêng, rồi OR với danh sách mã thật.
             *
             * NULL và chuỗi rỗng đều tính là chưa phân kho: dữ liệu cũ có cả hai
             * kiểu, thiếu vế IS NULL là sót hàng.
             */
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];

            if (!empty($real)) {
                $ph = implode(',', array_fill(0, count($real), '%s'));
                $parts[] = "li.local_ledger_item_warehouse_zone IN ({$ph})";
                foreach ($real as $z) {
                    $params[] = (string) $z;
                }
            }

            if ($want_none) {
                $parts[] = "(li.local_ledger_item_warehouse_zone IS NULL"
                         . " OR li.local_ledger_item_warehouse_zone = '')";
            }

            if (!empty($parts)) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $where_sql = implode(' AND ', $where);

        /*
         * Site kho: gộp theo (mã hàng, phân kho) để tách được từng phân kho.
         * Site shop: gộp theo mã hàng thôi, trả zone rỗng — mọi dòng của shop
         * đều thuộc về chính shop đó, bất kể cột phân kho đang mang giá trị gì.
         */
        $zone_select  = $group_by_zone
            ? "COALESCE(NULLIF(li.local_ledger_item_warehouse_zone, ''), '') AS zone"
            : "'' AS zone";
        $zone_groupby = $group_by_zone ? ', zone' : '';

        $sql = "
            SELECT
                li.local_product_sku AS sku,
                {$zone_select},
                COALESCE(SUM(CASE
                    WHEN l.local_ledger_approver_status = %d THEN
                        CASE
                            WHEN li.local_ledger_item_type = %d THEN  ABS(li.quantity)
                            WHEN li.local_ledger_item_type = %d THEN -ABS(li.quantity)
                            ELSE COALESCE(li.quantity, 0)
                        END
                    ELSE 0
                END), 0) AS qty
            FROM {$item_table} li
            LEFT JOIN {$ledger_table} l ON l.local_ledger_id = li.local_ledger_id
            WHERE {$where_sql}
            GROUP BY li.local_product_sku{$zone_groupby}
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        return array_map(static function ($r) {
            return [
                'sku'  => (string) $r['sku'],
                'zone' => (string) $r['zone'],
                'qty'  => (float) $r['qty'],
            ];
        }, $rows);
    }

    /**
     * SỔ KHO theo mặt hàng — phát sinh trong khoảng ngày, gộp theo mã hàng.
     *
     * KHÔNG chia theo phân kho: báo cáo này cộng dồn toàn bộ site đã lọc.
     * Bộ lọc mã kho bên trái vẫn dùng để chọn phạm vi, nhưng kết quả gộp lại.
     *
     * ─── Cách phân loại (theo đúng nghiệp vụ) ───────────────────────────────
     *
     *   CỘNG KHO
     *     Nhập (mua NCC)   item_type=1, phiếu KHÔNG có cha
     *                      — nhập từ nhà cung cấp có hoá đơn đỏ
     *     Nhập lại         item_type=3  — khách hoàn trả lại cửa hàng
     *     Nhập nội bộ      item_type=1, cha là phiếu mua nội bộ    (type 13)
     *
     *   TRỪ KHO
     *     Xuất nội bộ      item_type=2, cha là phiếu bán nội bộ    (type 12)
     *     Xuất bán         item_type=2, cha là phiếu bán hàng      (type 10)
     *     Xuất trả         item_type=2, cha là phiếu trả NCC       (type 16)
     *                      — KHO trả hàng về nhà cung cấp
     *     Xuất điều chỉnh  item_type=2, phiếu KHÔNG có cha
     *
     *   Khác                phần dư, xem chú thích ở chỗ tính $classified
     *
     * Mọi phiếu đều phải ĐÃ DUYỆT.
     *
     * ĐỪNG NHẦM HAI CÁI NÀY — tên gần giống nhau nhưng ngược chiều kho, và
     * chủ thể cũng khác nhau:
     *   Nhập lại = KHÁCH trả hàng về cửa hàng   → tồn TĂNG  (item_type 3)
     *   Xuất trả = KHO trả hàng về nhà cung cấp → tồn GIẢM  (item_type 2, cha 16)
     *
     * Chỉ KHO mới trả hàng cho NCC. Cửa hàng chỉ nhận hàng từ kho hoặc shop
     * khác, rồi bán cho khách — không làm việc trực tiếp với nhà cung cấp.
     *
     * Trước khi có cột Xuất trả, phiếu trả NCC không rơi vào cột nào: nó CÓ
     * cha nên không phải xuất điều chỉnh, mà cha lại không phải 10 hay 12.
     * Số vẫn nằm trong tồn cuối nhưng không hiện ở cột phân loại nào — sổ nhìn
     * như bị hụt mà không rõ hụt ở đâu.
     *
     * ─── Tồn đầu tính thế nào ───────────────────────────────────────────────
     *
     * tồn đầu = tồn cuối − (phát sinh ròng trong kỳ)
     *
     * "Phát sinh ròng" dùng ĐÚNG biểu thức CASE của công thức tồn, không phải
     * cộng trừ từng cột hiển thị. Cộng tay từng cột thì chỉ cần sót một loại
     * phiếu (hoặc đếm trùng một loại) là tồn đầu lệch, mà lệch kiểu đó rất khó
     * phát hiện vì con số vẫn trông hợp lý.
     *
     * @param string $date_from 'Y-m-d'
     * @param string $date_to   'Y-m-d'
     */
    public static function site_ledger_rows($blog_id, array $zones, $group_by_zone, $date_from, $date_to)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return [];
        }

        $custom = apply_filters('tgs_bctk_site_ledger_rows', null, $blog_id, $zones, $date_from, $date_to);
        if (is_array($custom)) {
            return $custom;
        }

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $item_table   = $prefix . 'local_ledger_item';
        $ledger_table = $prefix . 'local_ledger';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item_table)) !== $item_table) {
            return [];
        }

        // Chặn hai đầu ngày: từ 00:00:00 tới 23:59:59
        $from = $date_from . ' 00:00:00';
        $to   = $date_to . ' 23:59:59';

        $A = self::APPROVER_STATUS_APPROVED;
        $I = self::ITEM_TYPE_IMPORT;
        $E = self::ITEM_TYPE_EXPORT;
        $R = 3;  // khách hoàn trả
        $PO = self::ITEM_TYPE_PURCHASE_ORDER;
        $ADJ = self::ITEM_TYPE_ADJUSTMENT;   // phiếu điều chỉnh, quantity có dấu

        /* Biểu thức tồn — giống hệt site_stock_rows(), giữ khớp tuyệt đối */
        $delta = "CASE
                    WHEN li.local_ledger_item_type = {$I} THEN  ABS(li.quantity)
                    WHEN li.local_ledger_item_type = {$E} THEN -ABS(li.quantity)
                    ELSE COALESCE(li.quantity, 0)
                  END";

        $in_range = "li.created_at BETWEEN %s AND %s";

        $where = [
            "li.local_product_sku IS NOT NULL",
            "li.local_product_sku <> ''",
            "(li.is_deleted = 0 OR li.is_deleted IS NULL)",
            "(l.is_deleted = 0 OR l.is_deleted IS NULL)",
            "(li.local_ledger_item_type IS NULL OR li.local_ledger_item_type <> {$PO})",
            "l.local_ledger_approver_status = {$A}",
        ];

        if ($group_by_zone && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));
            $parts = [];
            if (!empty($real)) {
                $parts[] = "li.local_ledger_item_warehouse_zone IN ("
                         . implode(',', array_fill(0, count($real), '%s')) . ")";
            }
            if ($want_none) {
                $parts[] = "(li.local_ledger_item_warehouse_zone IS NULL"
                         . " OR li.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        } else {
            $real = [];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "
            SELECT
                li.local_product_sku AS sku,

                COALESCE(SUM(CASE WHEN {$in_range} THEN {$delta} ELSE 0 END), 0) AS net_period,

                /*
                 * Nhập = nhập từ NCC có hoá đơn đỏ → phiếu nhập KHÔNG có cha.
                 *
                 * CỐ Ý KHÔNG tính phiếu nhập sinh từ phiếu mua hàng (cha type 9).
                 * Luồng đó đã bỏ: phiếu mua hàng nay chỉ là bản nháp đặt hàng gửi
                 * NCC, việc đẩy hàng do plugin tgs_purchase_management lo riêng,
                 * không sinh nhập kho nữa.
                 *
                 * Dữ liệu cũ từ luồng đã bỏ sẽ rơi vào cột Khac — đúng ý đồ:
                 * nó là phát sinh có thật, vẫn nằm trong tồn, nhưng không thuộc
                 * loại nghiệp vụ nào đang dùng nên phải nhìn thấy được.
                 *
                 * (Chú thích trong khối này KHÔNG được dùng dấu nháy kép: cả câu
                 *  SQL nằm trong một chuỗi nháy kép của PHP, chỉ một dấu nháy
                 *  kép lạc vào là đóng chuỗi sớm và cả file lỗi cú pháp.)
                 */
                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$I}
                    AND l.local_ledger_parent_id IS NULL
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS nhap,

                /* Nhập lại: khách hoàn trả về cửa hàng → tồn TĂNG */
                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$R}
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS nhap_lai,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$I}
                    AND p.local_ledger_type = 13
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS nhap_nb,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND p.local_ledger_type = 10
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_ban,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND p.local_ledger_type = 12
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_nb,

                /* Xuất trả: KHO trả hàng về NCC → tồn GIẢM.
                   KHÔNG phải item_type=3 — cái đó là khách trả về, cộng kho. */
                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND p.local_ledger_type = 16
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_tra,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND l.local_ledger_parent_id IS NULL
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_dc,

                /*
                 * SL điều chỉnh (±) — lấy từ PHIẾU ĐIỀU CHỈNH, có dấu.
                 *
                 * Trước đây cột này lặp y hệt điều kiện của xuat_dc (item_type
                 * xuất, không có phiếu cha) nên KHÔNG BAO GIỜ bắt được phiếu
                 * điều chỉnh: phiếu đó ghi item_type = 21 chứ không phải 1/2.
                 * Hệ quả là lượng điều chỉnh rơi hết vào cột Khác — sổ vẫn cân
                 * nhưng nhìn vào không biết là do điều chỉnh.
                 *
                 * (Nhắc lại cảnh báo ở đầu khối: TUYỆT ĐỐI không viết dấu ngoặc
                 *  kép trong chú thích này — cả khối nằm trong một chuỗi PHP
                 *  mở bằng dấu ngoặc kép, lạc một dấu vào là đóng chuỗi sớm.)
                 *
                 * Lấy nguyên quantity chứ không ABS: quantity ở đây là chênh
                 * lệch tồn mới trừ tồn cũ, âm là giảm, dương là tăng.
                 */
                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$ADJ}
                    THEN COALESCE(li.quantity, 0) ELSE 0 END), 0) AS dc_signed,

                /*
                 * Tồn cuối = tồn tính ĐẾN HẾT NGÀY CUỐI KỲ, không phải tồn hiện
                 * tại. Cộng hết mọi phát sinh thì lọc một khoảng trong quá khứ
                 * sẽ ra tồn của hôm nay — sai kỳ, và kéo theo tồn đầu sai luôn
                 * vì tồn đầu suy ngược từ nó.
                 */
                COALESCE(SUM(CASE WHEN li.created_at <= %s THEN {$delta} ELSE 0 END), 0) AS ton_cuoi

            FROM {$item_table} li
            LEFT JOIN {$ledger_table} l ON l.local_ledger_id = li.local_ledger_id
            LEFT JOIN {$ledger_table} p ON p.local_ledger_id = l.local_ledger_parent_id
            WHERE {$where_sql}
            GROUP BY li.local_product_sku
        ";

        /*
         * Thứ tự tham số phải khớp CHÍNH XÁC thứ tự %s xuất hiện trong câu SQL:
         *   1. 9 cặp (from, to) — 9 cột thống kê phát sinh trong kỳ:
         *      net_period, nhap, nhap_lai, nhap_nb, xuat_ban, xuat_nb,
         *      xuat_tra, xuat_dc, dc_signed
         *   2. 1 giá trị $to     — cột tồn cuối (tính đến hết ngày cuối kỳ)
         *   3. danh sách mã kho  — ở mệnh đề WHERE
         *
         * Thêm/bớt cột có %s mà quên sửa chỗ này là toàn bộ tham số lệch một
         * nhịp: ngày chui vào chỗ mã kho, số liệu sai mà không báo lỗi gì.
         */
        $params = [];
        for ($i = 0; $i < 9; $i++) {
            $params[] = $from;
            $params[] = $to;
        }
        $params[] = $to;

        foreach ($real as $z) {
            $params[] = (string) $z;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        return array_map(static function ($r) {
            $ton_cuoi = (float) $r['ton_cuoi'];
            $net      = (float) $r['net_period'];

            /*
             * ─── CỘT "KHÁC" LÀ PHẦN DƯ, KHÔNG PHẢI MỘT LOẠI PHIẾU ───────────
             *
             * = phát sinh ròng thật − tổng ảnh hưởng của các cột đã phân loại.
             *
             * Nhờ nó, đẳng thức sau LUÔN đúng theo cách dựng, không phụ thuộc
             * việc đã liệt kê đủ loại phiếu hay chưa:
             *
             *   tồn đầu + nhập + nhập lại + nhập NB
             *           − xuất NB − xuất bán − xuất trả − xuất điều chỉnh
             *           + khác  =  tồn cuối
             *
             * Mai kia hệ thống thêm loại phiếu mới mà chưa kịp khai báo cột,
             * lượng đó rơi vào "Khác" — sổ vẫn cân và người xem THẤY được là có
             * thứ chưa phân loại. Không có cột này thì phần đó biến mất khỏi
             * các cột nhưng vẫn nằm trong tồn cuối, sổ lệch mà không rõ vì sao
             * (đúng lỗi vừa gặp: hàng nhập qua phiếu mua hàng làm lệch 29).
             */
            $classified = (float) $r['nhap']
                        + (float) $r['nhap_lai']
                        + (float) $r['nhap_nb']
                        - (float) $r['xuat_nb']
                        - (float) $r['xuat_ban']
                        - (float) $r['xuat_tra']
                        - (float) $r['xuat_dc']
                        /* Cộng THẲNG, không đổi dấu: dc_signed đã mang dấu sẵn
                           (âm là điều chỉnh giảm tồn, dương là tăng) */
                        + (float) $r['dc_signed'];

            return [
                'sku'       => (string) $r['sku'],
                'ton_dau'   => $ton_cuoi - $net,   // suy ngược từ tồn cuối
                'nhap'      => (float) $r['nhap'],
                'nhap_lai'  => (float) $r['nhap_lai'],
                'nhap_nb'   => (float) $r['nhap_nb'],
                'xuat_ban'  => (float) $r['xuat_ban'],
                'xuat_nb'   => (float) $r['xuat_nb'],
                'xuat_tra'  => (float) $r['xuat_tra'],
                'xuat_dc'   => (float) $r['xuat_dc'],
                'dc_signed' => (float) $r['dc_signed'],
                'khac'      => $net - $classified,
                'ton_cuoi'  => $ton_cuoi,
            ];
        }, $rows);
    }

    /**
     * Gợi ý NCC cho từng mã hàng — lấy từ PHIẾU NHẬP GẦN NHẤT có NCC.
     *
     * Chỉ mang tính THAM KHẢO để người mua hàng đỡ phải tra lại: "lần gần nhất
     * mã này nhập từ ai". Có thể trống nếu mã chưa từng nhập kèm NCC.
     *
     * Cố ý chỉ lấy MỘT NCC duy nhất (cái gần nhất) thay vì liệt kê tất cả —
     * danh sách dài không giúp quyết định nhanh hơn.
     *
     * @return array [sku => ['code' => ..., 'name' => ...]]
     */
    public static function supplier_hint($blog_id, array $skus)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        $skus    = array_values(array_unique(array_filter($skus)));
        if ($blog_id <= 0 || empty($skus)) {
            return [];
        }

        $prefix = $wpdb->get_blog_prefix($blog_id);
        $item   = $prefix . 'local_ledger_item';
        $ledger = $prefix . 'local_ledger';
        $sup    = $wpdb->base_prefix . 'global_supplier';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item)) !== $item) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($skus), '%s'));

        /*
         * Sắp giảm dần theo ngày rồi lấy dòng ĐẦU TIÊN của mỗi mã ở PHP.
         * Làm kiểu "lấy bản ghi mới nhất trong nhóm" bằng SQL thuần cần window
         * function hoặc self-join — nặng hơn mà không cần thiết, vì tập SKU ở
         * đây chỉ vài nghìn dòng.
         */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT li.local_product_sku AS sku,
                    s.supplier_code AS code,
                    s.supplier_name AS name
               FROM {$item} li
               JOIN {$ledger} l ON l.local_ledger_id = li.local_ledger_id
               JOIN {$sup} s    ON s.supplier_id = l.supplier_id
              WHERE li.local_product_sku IN ({$ph})
                AND li.local_ledger_item_type = %d
                AND l.local_ledger_approver_status = %d
                AND l.supplier_id > 0
                AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
                AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
              ORDER BY li.created_at DESC",
            ...array_merge($skus, [self::ITEM_TYPE_IMPORT, self::APPROVER_STATUS_APPROVED])
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $sku = (string) $r['sku'];
            if (isset($map[$sku])) {
                continue; // đã có cái gần nhất rồi
            }
            $map[$sku] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
        }

        return $map;
    }

    /**
     * Hàng đang đi đường của một site, gộp theo mã hàng.
     *
     * Quy tắc: phiếu nhập (type 1) CHƯA duyệt, phiếu cha là phiếu mua nội bộ
     * (type 13) cũng CHƯA duyệt. Xem docs/hang-dang-di-duong.md ở plugin
     * tgs-transfer-management.
     *
     * Chưa duyệt = NULL hoặc 0. KHÔNG viết "!= 1" vì trạng thái 2 là TỪ CHỐI —
     * phiếu bị từ chối thì hàng không còn đi đường.
     */
    public static function site_in_transit_rows($blog_id)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        $prefix  = $wpdb->get_blog_prefix($blog_id);
        $item    = $prefix . 'local_ledger_item';
        $ledger  = $prefix . 'local_ledger';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item)) !== $item) {
            return [];
        }

        $sql = "
            SELECT li.local_product_sku AS sku,
                   COALESCE(SUM(ABS(li.quantity)), 0) AS qty
            FROM {$ledger} AS imp
            INNER JOIN {$ledger} AS parent
                    ON parent.local_ledger_id = imp.local_ledger_parent_id
            INNER JOIN {$item} AS li
                    ON li.local_ledger_id = imp.local_ledger_id
            WHERE imp.local_ledger_type = 1
              AND (imp.local_ledger_approver_status IS NULL OR imp.local_ledger_approver_status = 0)
              AND parent.local_ledger_type = 13
              AND (parent.local_ledger_approver_status IS NULL OR parent.local_ledger_approver_status = 0)
              AND (imp.is_deleted = 0 OR imp.is_deleted IS NULL)
              AND (parent.is_deleted = 0 OR parent.is_deleted IS NULL)
              AND (li.is_deleted = 0 OR li.is_deleted IS NULL)
              AND li.local_product_sku IS NOT NULL AND li.local_product_sku <> ''
            GROUP BY li.local_product_sku
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['sku']] = (float) $r['qty'];
        }

        return $map;
    }

    /**
     * Thông tin sản phẩm cho một loạt SKU — MỘT truy vấn cho toàn bộ báo cáo.
     *
     * wp_global_product_name là bảng global nên không phụ thuộc site: 70 site
     * vẫn chỉ tốn một lượt hỏi, thay vì hỏi lại ở từng site.
     */
    public static function product_info(array $skus)
    {
        global $wpdb;

        $skus = array_values(array_unique(array_filter($skus)));
        if (empty($skus)) {
            return [];
        }

        $table = $wpdb->base_prefix . 'global_product_name';
        $ph    = implode(',', array_fill(0, count($skus), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_sku AS sku,
                    global_product_name AS name,
                    global_product_barcode_main AS alias,
                    global_product_unit AS unit,
                    global_product_price_after_tax AS price
               FROM {$table}
              WHERE global_product_sku IN ({$ph})",
            ...$skus
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['sku']] = $r;
        }

        return $map;
    }

    /**
     * Tồn max / tồn min theo (mã hàng, site).
     *
     * wp_global_sku_stock_config cũng là bảng GLOBAL và đã có sẵn cột blog_id,
     * nên lấy min/max cho cả 70 site chỉ tốn một truy vấn — không phải hỏi vòng
     * qua từng site.
     */
    public static function min_max(array $skus, array $blog_ids)
    {
        global $wpdb;

        $skus     = array_values(array_unique(array_filter($skus)));
        $blog_ids = array_values(array_unique(array_map('intval', $blog_ids)));
        if (empty($skus) || empty($blog_ids)) {
            return [];
        }

        $table   = $wpdb->base_prefix . 'global_sku_stock_config';
        $sku_ph  = implode(',', array_fill(0, count($skus), '%s'));
        $blog_ph = implode(',', array_fill(0, count($blog_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_sku, blog_id, min_qty, max_qty
               FROM {$table}
              WHERE product_sku IN ({$sku_ph})
                AND blog_id IN ({$blog_ph})
                AND (is_deleted = 0 OR is_deleted IS NULL)
                AND is_active = 1",
            ...array_merge($skus, $blog_ids)
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['blog_id']][(string) $r['product_sku']] = [
                'min' => (float) $r['min_qty'],
                'max' => (float) $r['max_qty'],
            ];
        }

        return $map;
    }

    /**
     * SỔ CHĂM SÓC KHÁCH HÀNG — ai đã mua gì, ngày nào, ở kho/shop nào.
     *
     * ── ĐƯỜNG ĐI TỚI KHÁCH HÀNG ─────────────────────────────────────────────
     *
     * Hàng bán ra KHÔNG nằm thẳng trên phiếu bán. Nó nằm trên PHIẾU XUẤT, còn
     * phiếu bán là CHA của phiếu xuất đó:
     *
     *     dòng hàng (item_type = 2, xuất)
     *        └── phiếu xuất  (l)
     *              └── phiếu bán hàng  (p, local_ledger_type = 10)
     *                    └── local_ledger_person_id → khách hàng
     *
     * Đúng đường mà cột Σ xuất bán của Sổ kho theo mặt hàng đang dùng, nên hai
     * báo cáo bao giờ cũng khớp nhau về phạm vi.
     *
     * ── VÌ SAO QUÉT CẢ SITE KHO ─────────────────────────────────────────────
     *
     * Kho về nguyên tắc không bán lẻ, nhưng vẫn có người bán tại kho bằng POS.
     * Bỏ site kho ra ngoài là mất đúng những đơn bất thường mà người ta cần soi
     * nhất. Nên quét hết, rồi để cột Kho nói rõ đơn đó phát sinh ở đâu.
     *
     * @param bool $is_warehouse Site kho thì mới lọc theo mã phân kho; site shop
     *                           để trống cột phân kho nên lọc vào là ra rỗng.
     */
    public static function site_cskh_rows($blog_id, array $zones, $is_warehouse, $date_from, $date_to)
    {
        global $wpdb;

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $item_table   = $prefix . 'local_ledger_item';
        $ledger_table = $prefix . 'local_ledger';
        $person_table = $prefix . 'local_ledger_person';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item_table)) !== $item_table) {
            return [];
        }

        $from = $date_from . ' 00:00:00';
        $to   = $date_to   . ' 23:59:59';

        $A    = self::APPROVER_STATUS_APPROVED;
        $E    = self::ITEM_TYPE_EXPORT;
        $SALE = 10;   // phiếu bán hàng

        $where = [
            "li.local_ledger_item_type = {$E}",
            "p.local_ledger_type = {$SALE}",
            "l.local_ledger_approver_status = {$A}",
            "(li.is_deleted = 0 OR li.is_deleted IS NULL)",
            "(l.is_deleted = 0 OR l.is_deleted IS NULL)",
            "(p.is_deleted = 0 OR p.is_deleted IS NULL)",
        ];

        /* Lọc theo NGÀY BÁN (phiếu cha), không theo ngày tạo dòng hàng — người
           dùng tra theo ngày khách mua, và đó là ngày trên phiếu bán */
        $params = [];
        $where[] = 'p.created_at BETWEEN %s AND %s';
        $params[] = $from;
        $params[] = $to;

        if ($is_warehouse && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];
            if (!empty($real)) {
                $parts[] = 'li.local_ledger_item_warehouse_zone IN ('
                         . implode(',', array_fill(0, count($real), '%s')) . ')';
                $params = array_merge($params, $real);
            }
            if ($want_none) {
                $parts[] = "(li.local_ledger_item_warehouse_zone IS NULL"
                         . " OR li.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $where_sql = implode(' AND ', $where);

        /*
         * Mỗi dòng hàng là MỘT dòng trong sổ, không gộp.
         *
         * Sổ này để tra cứu từng lượt mua chứ không phải để cộng số, nên gộp
         * lại là mất đúng thứ người dùng cần: khách đó mua mã gì, hôm nào, mấy
         * cái. Giống hệt cách sổ CSKH của phần mềm cũ liệt kê.
         */
        $sql = "
            SELECT
                p.local_ledger_code                        AS pbh,
                p.local_ledger_id                          AS sale_id,
                p.created_at                               AS ngay_mua,
                li.local_product_sku                       AS sku,
                li.quantity                                AS qty,
                COALESCE(li.local_ledger_item_warehouse_zone, '') AS zone,
                /* Ghi chú của PHIẾU BÁN (phiếu cha), không phải của dòng hàng
                   trên phiếu xuất — đây là chỗ người bán ghi lại chuyện của cả
                   đơn, còn ghi chú dòng hàng gần như luôn để trống */
                COALESCE(p.local_ledger_note, '')          AS ghi_chu,
                COALESCE(pe.local_ledger_person_code, '')  AS kh_ma,
                COALESCE(pe.local_ledger_person_name, '')  AS kh_ten,
                COALESCE(pe.local_ledger_person_phone, '') AS kh_dt,
                COALESCE(pe.local_ledger_person_address, '') AS kh_dchi,
                pe.local_ledger_person_baby_birthdate      AS kh_ns
            FROM {$item_table} li
            JOIN {$ledger_table} l ON l.local_ledger_id = li.local_ledger_id
            JOIN {$ledger_table} p ON p.local_ledger_id = l.local_ledger_parent_id
            LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = p.local_ledger_person_id
            WHERE {$where_sql}
            ORDER BY p.created_at DESC, p.local_ledger_code
        ";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        return $rows ?: [];
    }

    /**
     * BÁO CÁO BÁN HÀNG / HÀNG BÁN TRẢ LẠI — chi tiết từng dòng hàng.
     *
     * ── MỘT TRUY VẤN LO CẢ HAI CHIỀU ────────────────────────────────────────
     *
     * Bán và trả tưởng là hai luồng khác nhau, nhưng dữ liệu cho thấy chúng
     * cùng treo dưới MỘT phiếu bán:
     *
     *     bán   : dòng item_type = 2 → phiếu xuất  → phiếu bán (type 10)
     *     trả   : dòng item_type = 3 → phiếu trả   → phiếu bán (type 10)
     *
     * Nhờ vậy chỉ cần một lượt quét, và cột Số phiếu luôn là MÃ PHIẾU BÁN gốc
     * cho cả hai — đúng thứ người dùng cần để lần ngược về đơn.
     *
     * Phân biệt bằng chính item_type, đổi thành mã lý do quen thuộc của phần
     * mềm cũ: 2 → XBA (xuất bán), 3 → NTH1 (nhập trả hàng).
     *
     * @param string $loai 'sale' | 'return' | 'all'
     */
    public static function site_sales_rows($blog_id, array $zones, $is_warehouse, $date_from, $date_to, $loai = 'sale')
    {
        global $wpdb;

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $item_table   = $prefix . 'local_ledger_item';
        $ledger_table = $prefix . 'local_ledger';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table   = $prefix . 'local_ledger_meta';
        $pname_table  = $prefix . 'local_product_name';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item_table)) !== $item_table) {
            return [];
        }

        $A    = self::APPROVER_STATUS_APPROVED;
        $SALE = 10;

        /* Chọn loại: bán, trả, hay cả hai */
        if ($loai === 'return') {
            $type_sql = 'li.local_ledger_item_type = 3';
        } elseif ($loai === 'all') {
            $type_sql = 'li.local_ledger_item_type IN (2, 3)';
        } else {
            $type_sql = 'li.local_ledger_item_type = 2';
        }

        $where = [
            $type_sql,
            "p.local_ledger_type = {$SALE}",
            "l.local_ledger_approver_status = {$A}",
            "(li.is_deleted = 0 OR li.is_deleted IS NULL)",
            "(l.is_deleted = 0 OR l.is_deleted IS NULL)",
            "(p.is_deleted = 0 OR p.is_deleted IS NULL)",
        ];

        $params  = [];
        $where[] = 'p.created_at BETWEEN %s AND %s';
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to   . ' 23:59:59';

        if ($is_warehouse && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];
            if (!empty($real)) {
                $parts[] = 'li.local_ledger_item_warehouse_zone IN ('
                         . implode(',', array_fill(0, count($real), '%s')) . ')';
                $params = array_merge($params, $real);
            }
            if ($want_none) {
                $parts[] = "(li.local_ledger_item_warehouse_zone IS NULL"
                         . " OR li.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $where_sql = implode(' AND ', $where);

        /*
         * Hình thức thanh toán nằm trong JSON meta của phiếu bán, do POS ghi
         * (xem TGS_POS_Order_Handler::create_sale_ledger). Đơn cũ chưa có khoá
         * này thì ra NULL — để trống chứ không đoán bừa thành Tiền mặt.
         *
         * (Chú thích này nằm trong chuỗi PHP mở bằng dấu ngoặc kép: TUYỆT ĐỐI
         *  không viết dấu ngoặc kép ở đây.)
         */
        $sql = "
            SELECT
                li.local_ledger_item_type                  AS it,
                COALESCE(li.local_ledger_item_warehouse_zone, '') AS zone,
                li.local_product_sku                       AS sku,
                p.local_ledger_code                        AS pbh,
                p.local_ledger_id                          AS sale_id,
                p.created_at                               AS ngay,
                li.quantity                                AS qty,
                /*
                 * price là giá TRƯỚC THUẾ, tính theo ĐƠN VỊ NHỎ NHẤT.
                 * Phần cộng thuế vào để ra giá khách trả do PHP làm — xem
                 * build_sales_rows(), có kèm chứng cứ vì sao không chia tỉ lệ.
                 */
                li.price                                   AS gia,
                COALESCE(li.local_ledger_item_tax_percent, 0)    AS thue_pct,
                COALESCE(NULLIF(li.local_ledger_item_unit_ratio, 0), 1) AS ratio,
                COALESCE(li.local_ledger_item_discount_amount, 0) AS chiet_khau,
                COALESCE(li.local_ledger_item_tax_amount, 0)      AS thue,
                COALESCE(li.local_ledger_item_unit_name, '')      AS dvt_ban,
                COALESCE(li.local_ledger_item_unit_quantity, 0)   AS sl_dvmr,
                COALESCE(li.lot_code, '')                  AS so_lo,
                li.exp_date                                AS exp_date,
                COALESCE(li.local_ledger_item_note, '')    AS ghi_chu,
                li.user_id                                 AS nv_id,
                COALESCE(u.display_name, '')               AS nv_ten,
                COALESCE(u.user_login, '')                 AS nv_ma,
                COALESCE(pe.local_ledger_person_code, '')  AS kh_ma,
                COALESCE(pe.local_ledger_person_name, '')  AS kh_ten,
                COALESCE(pe.local_ledger_person_phone, '') AS kh_dt,
                COALESCE(pn.local_product_unit, '')        AS dvcb_local,
                JSON_UNQUOTE(JSON_EXTRACT(mt.local_ledger_meta_value, '$.payment_method_label')) AS httt
            FROM {$item_table} li
            JOIN {$ledger_table} l ON l.local_ledger_id = li.local_ledger_id
            JOIN {$ledger_table} p ON p.local_ledger_id = l.local_ledger_parent_id
            LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = p.local_ledger_person_id
            LEFT JOIN {$meta_table}   mt ON mt.local_ledger_meta_id   = p.local_ledger_meta_id
            LEFT JOIN {$pname_table}  pn ON pn.local_product_name_id  = li.local_product_name_id
            LEFT JOIN {$wpdb->users}   u ON u.ID = li.user_id
            WHERE {$where_sql}
            ORDER BY p.created_at DESC, p.local_ledger_code
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * TỔNG HỢP BÁN HÀNG — mỗi PHIẾU một dòng, không phải mỗi mặt hàng một dòng.
     *
     * Khác hẳn site_sales_rows(): màn kia soi từng dòng hàng, màn này nhìn ở
     * mức chứng từ để biết phiếu nào đã thu đủ, phiếu nào còn nợ.
     *
     * ── SỐ ĐÃ TRẢ LẤY TỪ ĐÂU ────────────────────────────────────────────────
     *
     * Tiền thu/chi không nằm trên phiếu bán mà là PHIẾU CON treo dưới nó:
     *
     *     phiếu bán (type 10)
     *        ├── phiếu xuất  (type 2)   — hàng
     *        ├── phiếu thu   (type 7)   — tiền khách trả   ← cộng vào Số trả
     *        └── phiếu trả   (type 11)  — hàng khách trả lại
     *
     * Phiếu trả thì ngược chiều: cửa hàng trả tiền lại khách nên là phiếu chi
     * (type 8). Gom cả 7 lẫn 8 để một công thức dùng được cho cả hai chiều.
     *
     * Chỉ cộng phiếu ĐÃ DUYỆT — phiếu thu chờ duyệt mà đã trừ vào công nợ thì
     * sổ báo hết nợ trong khi tiền chưa thật sự vào.
     *
     * @param string $loai 'sale' | 'return' | 'all'
     */
    public static function site_sales_summary_rows($blog_id, array $zones, $is_warehouse, $date_from, $date_to, $loai = 'sale')
    {
        global $wpdb;

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $ledger_table = $prefix . 'local_ledger';
        $item_table   = $prefix . 'local_ledger_item';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table   = $prefix . 'local_ledger_meta';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return [];
        }

        $A = self::APPROVER_STATUS_APPROVED;

        if ($loai === 'return') {
            $type_sql = 'd.local_ledger_type = 11';
        } elseif ($loai === 'all') {
            $type_sql = 'd.local_ledger_type IN (10, 11)';
        } else {
            $type_sql = 'd.local_ledger_type = 10';
        }

        $where = [
            $type_sql,
            "d.local_ledger_approver_status = {$A}",
            "(d.is_deleted = 0 OR d.is_deleted IS NULL)",
            /*
             * Bỏ PHIẾU BÁN VỎ của hàng trả lại nhập từ phần mềm cũ.
             *
             * Phần mềm cũ không truy được đơn bán gốc của một phiếu trả lại —
             * số phiếu hai bên khác nhau, chỉ biết tên và mã khách — nên
             * tgs_htsoft_sales_import dựng một phiếu bán RỖNG làm cha để phiếu
             * hoàn còn hình dạng cây mà báo cáo đọc được
             * (xem TGS_HSI_Voucher_Creator::WRAPPER_PREFIX).
             *
             * Phiếu đó tổng tiền 0 nên không làm sai tiền, nhưng ở màn TỔNG HỢP
             * (mỗi phiếu một dòng) thì mỗi phiếu hoàn lại đẻ thêm một "phiếu
             * bán" — SỐ PHIẾU BÁN đếm dư đúng bằng số phiếu hoàn.
             *
             * Chỉ lọc ở ĐÂY. Báo cáo chi tiết (site_sales_rows) phải giữ nguyên
             * vì nó đi qua phiếu cha để lấy dòng hàng — lọc ở đó là mất sạch
             * dòng hoàn.
             */
            "d.local_ledger_code NOT LIKE 'HTS-HDV-%'",
        ];

        $params   = [];
        $where[]  = 'd.created_at BETWEEN %s AND %s';
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to   . ' 23:59:59';

        /*
         * Lọc phân kho ở mức PHIẾU: giữ phiếu nào CÓ ÍT NHẤT MỘT dòng hàng nằm
         * trong các mã kho đang chọn. Dùng EXISTS chứ không JOIN, vì JOIN sẽ
         * nhân bản phiếu lên theo số dòng hàng khớp.
         */
        if ($is_warehouse && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];
            if (!empty($real)) {
                $parts[] = 'zi.local_ledger_item_warehouse_zone IN ('
                         . implode(',', array_fill(0, count($real), '%s')) . ')';
                $params = array_merge($params, $real);
            }
            if ($want_none) {
                $parts[] = "(zi.local_ledger_item_warehouse_zone IS NULL"
                         . " OR zi.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = "EXISTS (SELECT 1 FROM {$item_table} zi
                                     JOIN {$ledger_table} zl ON zl.local_ledger_id = zi.local_ledger_id
                                    WHERE (zl.local_ledger_id = d.local_ledger_id
                                           OR zl.local_ledger_parent_id = d.local_ledger_id)
                                      AND (" . implode(' OR ', $parts) . '))';
            }
        }

        $where_sql = implode(' AND ', $where);

        $sql = "
            SELECT
                d.local_ledger_code                        AS pbh,
                IF(d.local_ledger_type = 11, d.local_ledger_parent_id, d.local_ledger_id) AS sale_id,
                d.local_ledger_type                        AS lt,
                d.created_at                               AS ngay,
                COALESCE(d.local_ledger_total_amount, 0)   AS tong_tien,
                d.user_id                                  AS nv_id,
                COALESCE(u.display_name, '')               AS nv_ten,
                COALESCE(u.user_login, '')                 AS nv_ma,
                COALESCE(pe.local_ledger_person_code, '')  AS kh_ma,
                COALESCE(pe.local_ledger_person_name, '')  AS kh_ten,
                COALESCE(pe.local_ledger_person_phone, '') AS kh_dt,
                COALESCE(d.local_ledger_note, '')          AS ghi_chu,
                JSON_UNQUOTE(JSON_EXTRACT(mt.local_ledger_meta_value, '$.payment_method_label')) AS httt,

                /* Một mã kho đại diện, lấy từ dòng hàng của chính phiếu hoặc
                   phiếu con — phiếu chỉ thuộc về một điểm tồn */
                (SELECT MIN(NULLIF(zi.local_ledger_item_warehouse_zone, ''))
                   FROM {$item_table} zi
                   JOIN {$ledger_table} zl ON zl.local_ledger_id = zi.local_ledger_id
                  WHERE zl.local_ledger_id = d.local_ledger_id
                     OR zl.local_ledger_parent_id = d.local_ledger_id) AS zone,

                /* Tiền đã trả: cộng mọi phiếu thu/chi ĐÃ DUYỆT treo dưới phiếu */
                (SELECT COALESCE(SUM(ch.local_ledger_total_amount), 0)
                   FROM {$ledger_table} ch
                  WHERE ch.local_ledger_parent_id = d.local_ledger_id
                    AND ch.local_ledger_type IN (7, 8)
                    AND ch.local_ledger_approver_status = {$A}
                    AND (ch.is_deleted = 0 OR ch.is_deleted IS NULL)) AS da_tra
            FROM {$ledger_table} d
            LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = d.local_ledger_person_id
            LEFT JOIN {$meta_table}   mt ON mt.local_ledger_meta_id   = d.local_ledger_meta_id
            LEFT JOIN {$wpdb->users}   u ON u.ID = d.user_id
            WHERE {$where_sql}
            ORDER BY d.created_at DESC, d.local_ledger_code
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * ĐƠN VỊ NHỎ NHẤT thật sự của từng SKU, lấy từ bảng cấu hình quy đổi.
     *
     * ── VÌ SAO KHÔNG DÙNG global_product_unit ───────────────────────────────
     *
     * Cột đó khai sai ở nhiều mã. Ví dụ có thật, SKU 140781007
     * (Sữa chua hoa quả Hoff): `global_product_unit` ghi **Vỉ_4**, trong khi
     * bảng quy đổi khai rõ **Hộp ×1** (nhỏ nhất) và **Vỉ_4 ×4**.
     *
     * Hậu quả: báo cáo hiện "SL 4 — ĐVT Vỉ_4", người đọc hiểu thành 4 vỉ =
     * 16 hộp, trong khi thực bán 1 vỉ = 4 hộp. Số lượng `quantity` LUÔN theo
     * đơn vị nhỏ nhất, nên tên đơn vị đi kèm cũng phải là đơn vị nhỏ nhất.
     *
     * Đơn vị nhỏ nhất = dòng có tỉ lệ quy đổi bằng 1. Mã nào chưa cấu hình thì
     * không có trong kết quả, chỗ gọi tự lùi về `global_product_unit`.
     *
     * ── KHI CÓ NHIỀU DÒNG TỈ LỆ 1 ───────────────────────────────────────────
     *
     * Bảng quy đổi cũng có chỗ khai ẩu: SKU 161369004 có HAI dòng tỉ lệ 1 là
     * `Gói` và `Lốc_3` (lốc 3 mà tỉ lệ 1 thì rõ ràng nhập nhầm). Hiện có 8 mã
     * như vậy. Không chọn bừa dòng nào MySQL trả trước, vì mỗi lần chạy có thể
     * ra một kết quả khác nhau.
     *
     * Quy tắc chọn:
     *   1. Dòng nào TRÙNG với `global_product_unit` thì lấy — hai nguồn cùng
     *      nói một thứ là đáng tin nhất
     *   2. Không trùng thì lấy dòng cấu hình SỚM NHẤT, để kết quả cố định
     *
     * ── MỖI SITE MỘT BẢNG GIÁ (🆕 13/08/2026) ───────────────────────────────
     *
     * Bảng quy đổi nay chứa NHIỀU bảng giá (Bảng giá công ty TGS, Phú Thọ,
     * Nguyễn Tất Thành…) phân biệt bằng cột `price_list_id`; mỗi website áp
     * đúng 1 bảng. Không lọc thì một mã hàng có dòng tỉ lệ 1 ở CẢ hai bảng giá,
     * và quy tắc "lấy dòng sớm nhất" ở trên luôn chọn bảng giá cũ hơn — tức là
     * site dùng bảng giá mới vẫn hiện tên ĐVT của bảng giá khác.
     *
     * Vì báo cáo chạy theo TỪNG SITE, phải truyền $blog_id của site đang xét
     * chứ không dùng site hiện tại.
     *
     * @param array    $skus
     * @param int|null $blog_id Site đang lập báo cáo (null = site hiện tại)
     * @return array sku => tên đơn vị nhỏ nhất
     */
    public static function base_unit(array $skus, $blog_id = null)
    {
        global $wpdb;

        $skus = array_values(array_unique(array_filter($skus)));
        if (empty($skus)) {
            return [];
        }

        $table   = $wpdb->base_prefix . 'global_htsoft_stock_convert';
        $product = $wpdb->base_prefix . 'global_product_name';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($skus), '%s'));

        /* Giới hạn theo bảng giá của site; DB chưa có cột thì trả chuỗi rỗng */
        $price_list_where = class_exists('TGS_Price_List')
            ? TGS_Price_List::where_clause('c', $blog_id)
            : '';

        /*
         * Hai bảng khác collation nên phải CONVERT trước khi so, không thì
         * MySQL báo "Illegal mix of collations" và cả báo cáo tắt tiếng.
         */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.global_product_sku AS sku, c.convert_unit AS dvcb
               FROM {$table} c
               LEFT JOIN {$product} p
                      ON p.global_product_sku = c.global_product_sku
              WHERE c.global_product_sku IN ({$ph})
                AND c.convert_to_htsoft = 1
                AND c.convert_unit <> ''
                AND (c.is_deleted = 0 OR c.is_deleted IS NULL)
                {$price_list_where}
              ORDER BY
                CASE WHEN CONVERT(c.convert_unit USING utf8mb4)
                          COLLATE utf8mb4_unicode_520_ci = p.global_product_unit
                     THEN 0 ELSE 1 END,
                c.global_htsoft_stock_convert_id",
            ...$skus
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            /* Đã sắp xếp theo thứ tự ưu tiên nên dòng ĐẦU của mỗi mã là dòng cần */
            if (!isset($map[(string) $r['sku']])) {
                $map[(string) $r['sku']] = (string) $r['dvcb'];
            }
        }

        return $map;
    }

    /**
     * Nhóm hàng + ĐVT cơ bản lấy từ bảng sản phẩm global, theo danh sách SKU.
     *
     * product_info() không trả về đường dẫn nhóm hàng nên phải hỏi riêng, thay
     * vì sửa product_info() — hàm đó đang được ba báo cáo khác dùng chung.
     */
    /**
     * BÁO CÁO MUA HÀNG — mỗi mặt hàng một dòng, gồm cả hàng trả nhà cung cấp.
     *
     * ── HAI CHIỀU, HAI ĐƯỜNG DẪN KHÁC NHAU ──────────────────────────────────
     *
     * Mua và trả NCC không đối xứng như bán và khách trả:
     *
     *   MUA      dòng nhập (type 1) nằm THẲNG trên phiếu nhập kho
     *            phiếu nhập kho: type 1, KHÔNG có cha
     *
     *   TRẢ NCC  dòng xuất (type 2) nằm trên PHIẾU XUẤT,
     *            phiếu xuất mới có cha là phiếu trả NCC (type 16)
     *            → phải đi thêm một bậc
     *
     * Nên phiếu chứng từ của một dòng được chọn bằng: dòng nhập thì lấy chính
     * phiếu của nó, dòng xuất thì lấy phiếu cha. Gộp lại một phép JOIN thay vì
     * viết hai câu rồi UNION — đỡ nhân đôi chỗ lọc phân kho.
     *
     * ⚠️ CHIỀU TIỀN NGƯỢC VỚI BÁN HÀNG: mua là mình CHI tiền, trả NCC là mình
     * NHẬN lại tiền. Cột chi thuần vì thế lấy mua trừ trả — xem build_purchase_rows().
     *
     * @param string $loai 'buy' | 'return' | 'all'
     */
    public static function site_purchase_rows($blog_id, array $zones, $is_warehouse, $date_from, $date_to, $loai = 'buy')
    {
        global $wpdb;

        $prefix        = $wpdb->get_blog_prefix($blog_id);
        $item_table    = $prefix . 'local_ledger_item';
        $ledger_table  = $prefix . 'local_ledger';
        $pname_table   = $prefix . 'local_product_name';
        $supplier_table = $wpdb->base_prefix . 'global_supplier';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $item_table)) !== $item_table) {
            return [];
        }

        $A   = self::APPROVER_STATUS_APPROVED;
        $BUY = 1;    /* phiếu nhập kho */
        $RET = 16;   /* phiếu trả nhà cung cấp */

        if ($loai === 'return') {
            $type_sql = 'li.local_ledger_item_type = 2 AND t.local_ledger_type = ' . $RET;
        } elseif ($loai === 'all') {
            $type_sql = '((li.local_ledger_item_type = 1 AND t.local_ledger_type = ' . $BUY
                      . ' AND t.local_ledger_parent_id IS NULL)'
                      . ' OR (li.local_ledger_item_type = 2 AND t.local_ledger_type = ' . $RET . '))';
        } else {
            $type_sql = 'li.local_ledger_item_type = 1 AND t.local_ledger_type = ' . $BUY
                      . ' AND t.local_ledger_parent_id IS NULL';
        }

        $where = [
            $type_sql,
            "l.local_ledger_approver_status = {$A}",
            "t.local_ledger_approver_status = {$A}",
            "(li.is_deleted = 0 OR li.is_deleted IS NULL)",
            "(l.is_deleted = 0 OR l.is_deleted IS NULL)",
            "(t.is_deleted = 0 OR t.is_deleted IS NULL)",
        ];

        $params   = [];
        $where[]  = 't.created_at BETWEEN %s AND %s';
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to   . ' 23:59:59';

        if ($is_warehouse && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];
            if (!empty($real)) {
                $parts[] = 'li.local_ledger_item_warehouse_zone IN ('
                         . implode(',', array_fill(0, count($real), '%s')) . ')';
                $params = array_merge($params, $real);
            }
            if ($want_none) {
                $parts[] = "(li.local_ledger_item_warehouse_zone IS NULL"
                         . " OR li.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $where_sql = implode(' AND ', $where);

        /*
         * Lý do nhập nằm ở JSON local_ledger_advance_meta, do màn tạo phiếu ghi
         * (xem class-tgs-ajax-ticket-base.php). Mặc định là NMH1 - Nhập mua
         * hàng từ NCC.
         *
         * (Chú thích này nằm trong chuỗi PHP mở bằng dấu ngoặc kép: TUYỆT ĐỐI
         *  không viết dấu ngoặc kép ở đây.)
         */
        $sql = "
            SELECT
                li.local_ledger_item_type                  AS it,
                COALESCE(li.local_ledger_item_warehouse_zone, '') AS zone,
                li.local_product_sku                       AS sku,
                t.local_ledger_code                        AS pnk,
                t.created_at                               AS ngay,
                COALESCE(t.local_ledger_code_source, '')   AS so_hd,
                li.quantity                                AS qty,
                /* price: giá TRƯỚC thuế, theo ĐƠN VỊ NHỎ NHẤT — phần cộng thuế
                   để PHP làm qua TGS_Money, xem build_purchase_rows() */
                li.price                                   AS gia,
                COALESCE(li.local_ledger_item_tax_percent, 0)    AS thue_pct,
                COALESCE(NULLIF(li.local_ledger_item_unit_ratio, 0), 1) AS ratio,
                COALESCE(li.local_ledger_item_discount_amount, 0) AS chiet_khau,
                COALESCE(li.local_ledger_item_tax_amount, 0)      AS thue,
                COALESCE(li.local_ledger_item_unit_name, '')      AS dvt_ban,
                COALESCE(li.local_ledger_item_unit_quantity, 0)   AS sl_dvmr,
                COALESCE(li.lot_code, '')                  AS so_lo,
                li.exp_date                                AS exp_date,
                COALESCE(li.local_ledger_item_note, '')    AS ghi_chu,
                COALESCE(u.display_name, '')               AS nv_ten,
                COALESCE(u.user_login, '')                 AS nv_ma,
                COALESCE(s.supplier_code, '')              AS ncc_ma,
                COALESCE(s.supplier_name, '')              AS ncc_ten,
                COALESCE(pn.local_product_unit, '')        AS dvcb_local,
                JSON_UNQUOTE(JSON_EXTRACT(t.local_ledger_advance_meta, '$.import_reason.code'))  AS ly_do_ma,
                JSON_UNQUOTE(JSON_EXTRACT(t.local_ledger_advance_meta, '$.import_reason.label')) AS ly_do_ten
            FROM {$item_table} li
            JOIN {$ledger_table} l ON l.local_ledger_id = li.local_ledger_id
            /* Dòng nhập lấy chính phiếu của nó, dòng xuất phải leo lên phiếu cha */
            JOIN {$ledger_table} t
              ON t.local_ledger_id = IF(li.local_ledger_item_type = 1,
                                        l.local_ledger_id,
                                        l.local_ledger_parent_id)
            LEFT JOIN {$supplier_table} s ON s.supplier_id = COALESCE(li.supplier_id, t.supplier_id)
            LEFT JOIN {$pname_table}   pn ON pn.local_product_name_id = li.local_product_name_id
            LEFT JOIN {$wpdb->users}    u ON u.ID = li.user_id
            WHERE {$where_sql}
            ORDER BY t.created_at DESC, t.local_ledger_code
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    /**
     * TỔNG HỢP MUA HÀNG — mỗi PHIẾU một dòng, không phải mỗi mặt hàng một dòng.
     *
     * Đối xứng với site_sales_summary_rows() nhưng ngược chiều tiền:
     *
     *   MUA (type 1)      mình nợ nhà cung cấp → trả bằng PHIẾU CHI (type 8)
     *   TRẢ NCC (type 16) nhà cung cấp trả lại → nhận bằng PHIẾU THU (type 7)
     *
     * Gom cả 7 lẫn 8 để một công thức dùng được cho hai chiều, giống bên bán.
     * Chỉ cộng phiếu ĐÃ DUYỆT — phiếu chi chờ duyệt mà đã trừ công nợ thì sổ
     * báo hết nợ trong khi tiền chưa thật sự ra khỏi quỹ.
     *
     * Hạn thanh toán lấy từ ô HẠN TT trên màn tạo phiếu nhập kho.
     *
     * @param string $loai 'buy' | 'return' | 'all'
     */
    public static function site_purchase_summary_rows($blog_id, array $zones, $is_warehouse, $date_from, $date_to, $loai = 'buy')
    {
        global $wpdb;

        $prefix         = $wpdb->get_blog_prefix($blog_id);
        $ledger_table   = $prefix . 'local_ledger';
        $item_table     = $prefix . 'local_ledger_item';
        $supplier_table = $wpdb->base_prefix . 'global_supplier';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return [];
        }

        $A   = self::APPROVER_STATUS_APPROVED;
        $BUY = 1;
        $RET = 16;

        if ($loai === 'return') {
            $type_sql = "d.local_ledger_type = {$RET}";
        } elseif ($loai === 'all') {
            $type_sql = "((d.local_ledger_type = {$BUY} AND d.local_ledger_parent_id IS NULL)"
                      . " OR d.local_ledger_type = {$RET})";
        } else {
            $type_sql = "d.local_ledger_type = {$BUY} AND d.local_ledger_parent_id IS NULL";
        }

        $where = [
            $type_sql,
            "d.local_ledger_approver_status = {$A}",
            "(d.is_deleted = 0 OR d.is_deleted IS NULL)",
        ];

        $params   = [];
        $where[]  = 'd.created_at BETWEEN %s AND %s';
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to   . ' 23:59:59';

        /*
         * Lọc phân kho ở mức PHIẾU: giữ phiếu nào CÓ ÍT NHẤT MỘT dòng hàng nằm
         * trong các mã kho đang chọn. Dùng EXISTS chứ không JOIN, vì JOIN sẽ
         * nhân bản phiếu lên theo số dòng hàng khớp.
         */
        if ($is_warehouse && !empty($zones)) {
            $want_none = in_array(TGS_BCTK_Sites::ZONE_NONE, $zones, true);
            $real      = array_values(array_filter($zones, static function ($z) {
                return $z !== TGS_BCTK_Sites::ZONE_NONE;
            }));

            $parts = [];
            if (!empty($real)) {
                $parts[] = 'zi.local_ledger_item_warehouse_zone IN ('
                         . implode(',', array_fill(0, count($real), '%s')) . ')';
                $params = array_merge($params, $real);
            }
            if ($want_none) {
                $parts[] = "(zi.local_ledger_item_warehouse_zone IS NULL"
                         . " OR zi.local_ledger_item_warehouse_zone = '')";
            }
            if ($parts) {
                $where[] = "EXISTS (SELECT 1 FROM {$item_table} zi
                                     JOIN {$ledger_table} zl ON zl.local_ledger_id = zi.local_ledger_id
                                    WHERE (zl.local_ledger_id = d.local_ledger_id
                                           OR zl.local_ledger_parent_id = d.local_ledger_id)
                                      AND (" . implode(' OR ', $parts) . '))';
            }
        }

        $where_sql = implode(' AND ', $where);

        /*
         * (Chú thích này nằm trong chuỗi PHP mở bằng dấu ngoặc kép: TUYỆT ĐỐI
         *  không viết dấu ngoặc kép ở đây.)
         */
        $sql = "
            SELECT
                d.local_ledger_code                        AS pnk,
                d.local_ledger_type                        AS lt,
                d.created_at                               AS ngay,
                d.local_ledger_payment_due_date            AS han_tt,
                COALESCE(d.local_ledger_code_source, '')   AS so_hd,
                COALESCE(d.local_ledger_total_amount, 0)   AS tong_tien,
                COALESCE(u.display_name, '')               AS nv_ten,
                COALESCE(u.user_login, '')                 AS nv_ma,
                COALESCE(s.supplier_code, '')              AS ncc_ma,
                COALESCE(s.supplier_name, '')              AS ncc_ten,
                COALESCE(d.local_ledger_note, '')          AS ghi_chu,
                JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.import_reason.code'))  AS ly_do_ma,
                JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.import_reason.label')) AS ly_do_ten,
                /* Ký hiệu + ngày hoá đơn nằm trong JSON chứng từ, không có cột riêng */
                JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.invoice.symbol')) AS hd_ky_hieu,
                JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.invoice.date'))   AS hd_ngay,

                /* Một mã kho đại diện, lấy từ dòng hàng của chính phiếu hoặc
                   phiếu con — phiếu chỉ thuộc về một điểm tồn */
                (SELECT MIN(NULLIF(zi.local_ledger_item_warehouse_zone, ''))
                   FROM {$item_table} zi
                   JOIN {$ledger_table} zl ON zl.local_ledger_id = zi.local_ledger_id
                  WHERE zl.local_ledger_id = d.local_ledger_id
                     OR zl.local_ledger_parent_id = d.local_ledger_id) AS zone,

                /* Tiền đã trả: cộng mọi phiếu thu/chi ĐÃ DUYỆT treo dưới phiếu */
                (SELECT COALESCE(SUM(ch.local_ledger_total_amount), 0)
                   FROM {$ledger_table} ch
                  WHERE ch.local_ledger_parent_id = d.local_ledger_id
                    AND ch.local_ledger_type IN (7, 8)
                    AND ch.local_ledger_approver_status = {$A}
                    AND (ch.is_deleted = 0 OR ch.is_deleted IS NULL)) AS da_tra
            FROM {$ledger_table} d
            LEFT JOIN {$supplier_table} s ON s.supplier_id = d.supplier_id
            LEFT JOIN {$wpdb->users}    u ON u.ID = d.user_id
            WHERE {$where_sql}
            ORDER BY d.created_at DESC, d.local_ledger_code
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        return $rows ?: [];
    }

    public static function product_group(array $skus)
    {
        global $wpdb;

        $skus = array_values(array_unique(array_filter($skus)));
        if (empty($skus)) {
            return [];
        }

        $table = $wpdb->base_prefix . 'global_product_name';
        $ph    = implode(',', array_fill(0, count($skus), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_sku AS sku,
                    global_product_category_path AS nhom,
                    global_product_unit AS dvcb
               FROM {$table}
              WHERE global_product_sku IN ({$ph})",
            ...$skus
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['sku']] = $r;
        }

        return $map;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * KHỐI QUẢN LÝ VAT — Phiếu xuất bán (VAT) & Phiếu điều chỉnh giảm (VAT)
     *
     * Đây là báo cáo TỔNG QUAN CHO KẾ TOÁN nhiều shop, khác hẳn màn "Danh sách
     * gửi hoá đơn thuế" trong tgs_pos (màn của quầy, mỗi shop chỉ nhìn đơn mình,
     * chỉ 3 trạng thái). Xem docs/bao-cao-vat-phieu-xuat-ban-va-dieu-chinh.md.
     *
     * Ba luật giữ khớp với luồng gửi thuế:
     *   1. Tiền LUÔN tính lại từ local_ledger_item qua TGS_Money (phía AJAX làm),
     *      không đọc vi.total_* — theo mo-hinh-tien-va-bang-local-ledger-item.md.
     *   2. "Thông tin VAT" xét theo bản ghi local_viettel_invoice mới nhất của
     *      phiếu: có bản ghi = đã có; không = chưa gửi; state lỗi = gửi lỗi.
     *   3. Bill Z (phiếu nội bộ) nhận diện bằng QUAN HỆ CHA–CON + hậu tố Z,
     *      không chỉ nhìn chữ Z cuối mã — xem is_promo_split_bill_row() ở
     *      tgs-viettel-invoice và bill-z-va-hang-tang.md.
     * ═══════════════════════════════════════════════════════════════════════ */

    /** invoice_state thuộc nhóm "gửi lỗi" */
    const VAT_ERROR_STATES = ['issue_error', 'cqt_error', 'validate_error', 'error'];

    /**
     * Sáu cột SELECT bóc người mua đã chốt ở màn review, từ JSON meta của phiếu
     * (khoá tax_invoice_buyer — xem TGS_Viettel_Invoice_Flow_Service). Trả về
     * chuỗi kết thúc bằng dấu phẩy để nối thẳng vào câu SELECT.
     *
     * @param string $alias bí danh bảng local_ledger_meta trong câu SQL
     */
    private static function buyer_meta_selects($alias)
    {
        $base = "'$.tax_invoice_buyer.";
        $col  = static function ($path, $as) use ($alias, $base) {
            return "JSON_UNQUOTE(JSON_EXTRACT({$alias}.local_ledger_meta_value, {$base}{$path}')) AS {$as}";
        };

        return implode(",\n                ", [
            $col('customer_company_name', 'b_company'),
            $col('customer_name', 'b_name'),
            $col('customer_tax_code', 'b_mst'),
            $col('customer_address', 'b_addr'),
            $col('customer_email', 'b_email'),
            $col('customer_phone', 'b_phone'),
        ]) . ',';
    }

    /** Hậu tố mã bill Z — lấy từ tgs_pos nếu bật, mặc định 'Z' */
    public static function promo_suffix()
    {
        if (class_exists('TGS_POS_Order_Handler')
            && method_exists('TGS_POS_Order_Handler', 'promo_split_code_suffix')) {
            $s = (string) TGS_POS_Order_Handler::promo_split_code_suffix();
            return $s !== '' ? $s : 'Z';
        }
        return 'Z';
    }

    /**
     * PHIẾU XUẤT BÁN (VAT) — mỗi PHIẾU BÁN (type 10) một dòng, kèm danh sách
     * dòng hàng thô để phía AJAX tính tiền và dựng modal.
     *
     * @param string $bill_scope 'normal' (bỏ bill Z) | 'internal' (chỉ bill Z) | 'all'
     * @param int[]  $sale_ids   Nếu truyền, chỉ lấy đúng các local_ledger_id này
     *                           (dùng khi nạp lại MỘT phiếu sau khi sửa dòng).
     * @return array[] mỗi phần tử có khoá 'items' => array dòng hàng thô
     */
    public static function site_vat_sales_rows($blog_id, $date_from, $date_to, $bill_scope = 'normal', array $sale_ids = [])
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return [];
        }

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $ledger_table = $prefix . 'local_ledger';
        $item_table   = $prefix . 'local_ledger_item';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table   = $prefix . 'local_ledger_meta';
        $pname_table  = $prefix . 'local_product_name';
        $vi_table     = $prefix . 'local_viettel_invoice';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return [];
        }

        $has_vi = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vi_table)) === $vi_table;

        $A    = self::APPROVER_STATUS_APPROVED;
        $SALE = 10;
        $from = $date_from . ' 00:00:00';
        $to   = $date_to . ' 23:59:59';

        // Lọc đúng một/nhiều phiếu (nạp lại sau khi sửa dòng)
        $sale_ids    = array_values(array_filter(array_map('intval', $sale_ids)));
        $id_filter   = '';
        $params_head = [];
        if (!empty($sale_ids)) {
            $id_filter = 'AND l.local_ledger_id IN ('
                . implode(',', array_fill(0, count($sale_ids), '%d')) . ')';
            $params_head = $sale_ids;
        }

        $buyer_sql = self::buyer_meta_selects('mt');

        /*
         * Bản ghi VAT mới nhất của mỗi phiếu. Bản ghi điều chỉnh KHÔNG gắn
         * sale_ledger_id (cố ý, để không che hoá đơn gốc) nên MAX theo
         * sale_ledger_id là an toàn.
         */
        $vi_join = $has_vi
            ? "LEFT JOIN (
                   SELECT v1.* FROM {$vi_table} v1
                   INNER JOIN (
                       SELECT sale_ledger_id, MAX(local_viettel_invoice_id) AS mx
                       FROM {$vi_table}
                       WHERE sale_ledger_id IS NOT NULL
                         AND (is_deleted = 0 OR is_deleted IS NULL)
                       GROUP BY sale_ledger_id
                   ) vm ON vm.mx = v1.local_viettel_invoice_id
               ) vi ON vi.sale_ledger_id = l.local_ledger_id"
            : '';

        $vi_cols = $has_vi
            ? "vi.local_viettel_invoice_id AS vi_id,
               vi.invoice_series          AS seri,
               vi.viettel_invoice_no      AS so_hd,
               vi.template_code           AS mau_hd,
               vi.invoice_state           AS vat_state,
               vi.issue_status            AS issue_status,
               vi.send_cqt_status         AS send_cqt_status,
               vi.issue_sent_at           AS ngay_hd,
               vi.buyer_name              AS vi_buyer_name,
               vi.buyer_tax_code          AS vi_buyer_mst,
               vi.issue_response_payload  AS issue_payload,"
            : "NULL AS vi_id, NULL AS seri, NULL AS so_hd, NULL AS mau_hd,
               NULL AS vat_state, NULL AS issue_status, NULL AS send_cqt_status,
               NULL AS ngay_hd, NULL AS vi_buyer_name, NULL AS vi_buyer_mst,
               NULL AS issue_payload,";

        $sql = "
            SELECT
                l.local_ledger_id      AS sale_id,
                l.local_ledger_code    AS code,
                l.created_at           AS ngay_xuat,
                l.local_ledger_note    AS ghi_chu,
                l.user_id              AS user_id,
                l.local_ledger_item_id AS item_id_json,
                par.local_ledger_code  AS parent_code,
                (
                    SELECT COUNT(*) FROM {$ledger_table} rc
                    WHERE rc.local_ledger_parent_id = l.local_ledger_id
                      AND rc.local_ledger_type = 11
                      AND (rc.is_deleted = 0 OR rc.is_deleted IS NULL)
                ) AS has_return,
                COALESCE(u.display_name, u.user_login, '')          AS nv_ten,
                COALESCE(pe.local_ledger_person_name, '')           AS kh_ten,
                COALESCE(pe.local_ledger_person_phone, '')          AS kh_dt,
                COALESCE(pe.local_ledger_person_address, '')        AS kh_dchi,
                COALESCE(pe.local_ledger_person_email, '')          AS kh_email,
                COALESCE(pe.local_ledger_person_tax_code, '')       AS kh_mst,
                JSON_UNQUOTE(JSON_EXTRACT(mt.local_ledger_meta_value, '$.payment_method_label')) AS httt,
                {$buyer_sql}
                {$vi_cols}
                l.local_ledger_id AS _keep
            FROM {$ledger_table} l
            LEFT JOIN {$ledger_table} par
                   ON par.local_ledger_id = l.local_ledger_parent_id
                  AND par.local_ledger_type = l.local_ledger_type
            LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = l.local_ledger_person_id
            LEFT JOIN {$meta_table}   mt ON mt.local_ledger_meta_id   = l.local_ledger_meta_id
            LEFT JOIN {$wpdb->users}   u ON u.ID = l.user_id
            {$vi_join}
            WHERE l.local_ledger_type = {$SALE}
              AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
              AND l.local_ledger_approver_status = {$A}
              {$id_filter}
              AND l.created_at BETWEEN %s AND %s
            ORDER BY l.created_at DESC, l.local_ledger_code
        ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge($params_head, [$from, $to])),
            ARRAY_A
        ) ?: [];
        if (empty($rows)) {
            return [];
        }

        $suffix = strtoupper(self::promo_suffix());

        // Lọc bill Z theo phạm vi
        $kept = [];
        foreach ($rows as $r) {
            $parent = strtoupper(trim((string) ($r['parent_code'] ?? '')));
            $code   = strtoupper(trim((string) ($r['code'] ?? '')));
            $is_z   = ($parent !== '' && $code === $parent . $suffix);
            $r['is_z'] = $is_z ? 1 : 0;

            if ($bill_scope === 'normal' && $is_z) {
                continue;
            }
            if ($bill_scope === 'internal' && !$is_z) {
                continue;
            }
            $kept[] = $r;
        }
        if (empty($kept)) {
            return [];
        }

        // Gom mọi item_id để lấy dòng hàng trong MỘT truy vấn
        $all_ids = [];
        foreach ($kept as &$r) {
            $ids = json_decode((string) ($r['item_id_json'] ?? ''), true);
            $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
            $r['_item_ids'] = $ids;
            foreach ($ids as $id) {
                $all_ids[$id] = true;
            }
        }
        unset($r);

        $item_map = [];
        if (!empty($all_ids)) {
            $ids = array_keys($all_ids);
            $ph  = implode(',', array_fill(0, count($ids), '%d'));
            $item_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT i.local_ledger_item_id AS id,
                        i.quantity  AS qty,
                        i.price     AS gia,
                        COALESCE(i.local_ledger_item_discount_amount, 0) AS chiet_khau,
                        i.local_ledger_item_tax_percent AS thue_pct,
                        COALESCE(i.local_ledger_item_tax_amount, 0)      AS thue,
                        COALESCE(i.local_ledger_item_is_kct, 0)          AS is_kct,
                        COALESCE(i.local_ledger_item_gift_type, 0)       AS gift_type,
                        COALESCE(i.local_product_sku, '')                AS sku,
                        COALESCE(i.local_ledger_item_product_name_cache, pn.local_product_name, '') AS ten,
                        COALESCE(NULLIF(i.local_ledger_item_unit_name, ''), pn.local_product_unit, '') AS dvt,
                        COALESCE(NULLIF(i.local_ledger_item_unit_ratio, 0), 1) AS ratio,
                        COALESCE(i.local_ledger_item_unit_quantity, 0)   AS sl_dvt,
                        COALESCE(i.lot_code, '')                         AS lot_code,
                        i.exp_date                                      AS exp_date,
                        COALESCE(i.local_ledger_item_note, '')           AS li_note
                   FROM {$item_table} i
                   LEFT JOIN {$pname_table} pn ON pn.local_product_name_id = i.local_product_name_id
                  WHERE i.local_ledger_item_id IN ({$ph})
                    AND (i.is_deleted = 0 OR i.is_deleted IS NULL)",
                ...$ids
            ), ARRAY_A) ?: [];
            foreach ($item_rows as $ir) {
                $item_map[(int) $ir['id']] = $ir;
            }
        }

        $out = [];
        foreach ($kept as $r) {
            $items = [];
            foreach ($r['_item_ids'] as $id) {
                if (isset($item_map[$id])) {
                    $items[] = $item_map[$id];
                }
            }
            unset($r['_item_ids'], $r['item_id_json'], $r['_keep']);
            $r['items'] = $items;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * PHIẾU ĐIỀU CHỈNH GIẢM (VAT) — mỗi dòng hàng đợi điều chỉnh một dòng.
     *
     * Nguồn là bảng GLOBAL wp_tgs_viettel_invoice_return_adjustments (có cột
     * blog_id), join sang phiếu hoàn / phiếu bán / bản ghi VAT điều chỉnh của
     * chính site đó. Dòng hàng lấy từ local_ledger_item_id (JSON) của phiếu
     * hoàn (type 3) — cùng cách build_payload() của return-adjustment đọc.
     */
    public static function site_vat_adjust_rows($blog_id, $date_from, $date_to, $bill_scope = 'normal')
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        if ($blog_id <= 0) {
            return [];
        }

        $queue_table = $wpdb->base_prefix . 'tgs_viettel_invoice_return_adjustments';
        if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($queue_table) . "'") !== $queue_table) {
            return [];
        }

        $prefix       = $wpdb->get_blog_prefix($blog_id);
        $ledger_table = $prefix . 'local_ledger';
        $item_table   = $prefix . 'local_ledger_item';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table   = $prefix . 'local_ledger_meta';
        $pname_table  = $prefix . 'local_product_name';
        $vi_table     = $prefix . 'local_viettel_invoice';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return [];
        }
        $has_vi = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vi_table)) === $vi_table;

        $from = $date_from . ' 00:00:00';
        $to   = $date_to . ' 23:59:59';

        $buyer_sql = self::buyer_meta_selects('mt');

        $vi_join = $has_vi
            ? "LEFT JOIN {$vi_table} vi ON vi.local_viettel_invoice_id = q.adjustment_invoice_record_id"
            : '';
        $vi_cols = $has_vi
            ? "vi.invoice_series  AS seri,
               vi.template_code   AS mau_hd,
               vi.invoice_state   AS vat_state,
               vi.issue_status    AS issue_status,
               vi.send_cqt_status AS send_cqt_status,
               vi.issue_sent_at   AS ngay_hd,
               vi.buyer_name      AS vi_buyer_name,
               vi.buyer_tax_code  AS vi_buyer_mst,"
            : "NULL AS seri, NULL AS mau_hd, NULL AS vat_state, NULL AS issue_status,
               NULL AS send_cqt_status, NULL AS ngay_hd, NULL AS vi_buyer_name,
               NULL AS vi_buyer_mst,";

        $sql = "
            SELECT
                q.id                     AS queue_id,
                q.status                 AS queue_status,
                q.sale_ledger_id         AS sale_id,
                COALESCE(NULLIF(q.adjustment_invoice_record_id, 0), NULLIF(q.original_invoice_record_id, 0)) AS vi_id,
                q.original_invoice_no    AS so_hd_goc,
                q.adjustment_invoice_no  AS so_hd,
                q.error_message          AS queue_error,
                q.created_at             AS queue_created,
                r.local_ledger_id        AS return_id,
                r.local_ledger_code      AS return_code,
                r.created_at             AS ngay_xuat,
                r.local_ledger_note      AS ghi_chu,
                r.user_id                AS user_id,
                r.local_ledger_item_id   AS item_id_json,
                s.local_ledger_code      AS sale_code,
                s.local_ledger_parent_id AS sale_parent_id,
                COALESCE(u.display_name, u.user_login, '')     AS nv_ten,
                COALESCE(pe.local_ledger_person_name, '')      AS kh_ten,
                COALESCE(pe.local_ledger_person_phone, '')     AS kh_dt,
                COALESCE(pe.local_ledger_person_address, '')   AS kh_dchi,
                COALESCE(pe.local_ledger_person_email, '')     AS kh_email,
                COALESCE(pe.local_ledger_person_tax_code, '')  AS kh_mst,
                JSON_UNQUOTE(JSON_EXTRACT(mt.local_ledger_meta_value, '$.payment_method_label')) AS httt,
                {$buyer_sql}
                {$vi_cols}
                q.id AS _keep
            FROM {$queue_table} q
            LEFT JOIN {$ledger_table} r  ON r.local_ledger_id = q.return_ledger_id
            LEFT JOIN {$ledger_table} s  ON s.local_ledger_id = q.sale_ledger_id
            LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = COALESCE(r.local_ledger_person_id, s.local_ledger_person_id)
            LEFT JOIN {$meta_table}   mt ON mt.local_ledger_meta_id   = s.local_ledger_meta_id
            LEFT JOIN {$wpdb->users}   u ON u.ID = r.user_id
            {$vi_join}
            WHERE q.blog_id = %d
              AND q.created_at BETWEEN %s AND %s
            ORDER BY q.created_at DESC, q.id DESC
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $blog_id, $from, $to), ARRAY_A) ?: [];
        if (empty($rows)) {
            return [];
        }

        // Bill Z: phiếu bán gốc là bill tách nội bộ (có cha + hậu tố Z)
        $suffix = strtoupper(self::promo_suffix());
        $parent_ids = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['sale_parent_id'] ?? 0);
            if ($pid > 0) {
                $parent_ids[$pid] = true;
            }
        }
        $parent_code_map = [];
        if (!empty($parent_ids)) {
            $pids = array_keys($parent_ids);
            $ph   = implode(',', array_fill(0, count($pids), '%d'));
            $pc = $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_id AS id, local_ledger_code AS code
                   FROM {$ledger_table} WHERE local_ledger_id IN ({$ph})",
                ...$pids
            ), ARRAY_A) ?: [];
            foreach ($pc as $p) {
                $parent_code_map[(int) $p['id']] = strtoupper(trim((string) $p['code']));
            }
        }

        $kept = [];
        foreach ($rows as $r) {
            $pid  = (int) ($r['sale_parent_id'] ?? 0);
            $pcode = $parent_code_map[$pid] ?? '';
            $scode = strtoupper(trim((string) ($r['sale_code'] ?? '')));
            $is_z = ($pcode !== '' && $scode === $pcode . $suffix);
            $r['is_z'] = $is_z ? 1 : 0;

            if ($bill_scope === 'normal' && $is_z) {
                continue;
            }
            if ($bill_scope === 'internal' && !$is_z) {
                continue;
            }
            $kept[] = $r;
        }
        if (empty($kept)) {
            return [];
        }

        $all_ids = [];
        foreach ($kept as &$r) {
            $ids = json_decode((string) ($r['item_id_json'] ?? ''), true);
            $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
            $r['_item_ids'] = $ids;
            foreach ($ids as $id) {
                $all_ids[$id] = true;
            }
        }
        unset($r);

        $item_map = [];
        if (!empty($all_ids)) {
            $ids = array_keys($all_ids);
            $ph  = implode(',', array_fill(0, count($ids), '%d'));
            $item_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT i.local_ledger_item_id AS id,
                        i.quantity  AS qty,
                        i.price     AS gia,
                        COALESCE(i.local_ledger_item_discount_amount, 0) AS chiet_khau,
                        i.local_ledger_item_tax_percent AS thue_pct,
                        COALESCE(i.local_ledger_item_tax_amount, 0)      AS thue,
                        COALESCE(i.local_ledger_item_is_kct, 0)          AS is_kct,
                        COALESCE(i.local_product_sku, '')                AS sku,
                        COALESCE(i.local_ledger_item_product_name_cache, pn.local_product_name, '') AS ten,
                        COALESCE(NULLIF(i.local_ledger_item_unit_name, ''), pn.local_product_unit, '') AS dvt,
                        COALESCE(NULLIF(i.local_ledger_item_unit_ratio, 0), 1) AS ratio,
                        COALESCE(i.local_ledger_item_unit_quantity, 0)   AS sl_dvt,
                        COALESCE(i.lot_code, '')                         AS lot_code,
                        i.exp_date                                      AS exp_date,
                        COALESCE(i.local_ledger_item_note, '')           AS li_note
                   FROM {$item_table} i
                   LEFT JOIN {$pname_table} pn ON pn.local_product_name_id = i.local_product_name_id
                  WHERE i.local_ledger_item_id IN ({$ph})
                    AND (i.is_deleted = 0 OR i.is_deleted IS NULL)",
                ...$ids
            ), ARRAY_A) ?: [];
            foreach ($item_rows as $ir) {
                $item_map[(int) $ir['id']] = $ir;
            }
        }

        $out = [];
        foreach ($kept as $r) {
            $items = [];
            foreach ($r['_item_ids'] as $id) {
                if (isset($item_map[$id])) {
                    $items[] = $item_map[$id];
                }
            }
            unset($r['_item_ids'], $r['item_id_json'], $r['_keep']);
            $r['items'] = $items;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Nhãn cột "Trạng thái VAT".
     *
     * @param string $state    invoice_state của bản ghi VAT ('' nếu không có)
     * @param bool   $has_record Có bản ghi local_viettel_invoice hay không
     */
    public static function vat_state_label($state, $has_record)
    {
        $state = strtolower(trim((string) $state));

        if (!$has_record || $state === '' || $state === 'unsent') {
            return 'Hóa đơn chưa lập VAT';
        }
        if (in_array($state, ['done', 'issued'], true)) {
            return 'Hóa đơn có chữ ký số';
        }
        if (in_array($state, ['pending', 'processing', 'blocked'], true)) {
            return 'Đang xử lý';
        }
        if (in_array($state, self::VAT_ERROR_STATES, true)) {
            return 'Gửi lỗi VAT';
        }
        if ($state === 'skipped') {
            return 'Bỏ qua (không gửi thuế)';
        }
        return $state;
    }

    /**
     * Thông tin ĐƠN VỊ BÁN đúng như lúc phát hành hoá đơn.
     *
     * Ưu tiên SNAPSHOT cấu hình Viettel đã chốt cho hoá đơn đó
     * (wp_tgs_viettel_invoice_config_snapshots — xem
     * TGS_Viettel_Invoice_Plugin::get_settings_for_invoice) để đối chiếu về sau vẫn
     * đúng dù cụm cấu hình shop đã đổi. Không có bản ghi hoá đơn thì lấy cấu
     * hình cụm đang hiệu lực; cuối cùng mới tới option của blog.
     *
     * @param int $blog_id
     * @param int $invoice_record_id local_viettel_invoice_id (0 nếu chưa có)
     */
    private static $seller_cache = [];

    public static function seller_info($blog_id, $invoice_record_id = 0)
    {
        $blog_id = (int) $blog_id;
        $invoice_record_id = (int) $invoice_record_id;
        $key = $blog_id . ':' . $invoice_record_id;
        if (isset(self::$seller_cache[$key])) {
            return self::$seller_cache[$key];
        }

        $name = $addr = $phone = $mst = '';
        $series = $template = $payment = '';

        if (class_exists('TGS_Viettel_Invoice_Plugin')
            && method_exists('TGS_Viettel_Invoice_Plugin', 'get_settings_for_invoice')) {
            $s = (array) TGS_Viettel_Invoice_Plugin::get_settings_for_invoice($invoice_record_id, $blog_id);
            $name     = (string) ($s['company_name'] ?? $s['legal_name'] ?? '');
            $addr     = (string) ($s['company_address'] ?? $s['legal_address'] ?? '');
            $phone    = (string) ($s['company_phone'] ?? $s['legal_phone'] ?? '');
            $mst      = (string) ($s['supplier_tax_code'] ?? '');
            $series   = (string) ($s['default_invoice_series'] ?? '');
            $template = (string) ($s['default_template_code'] ?? '');
            $payment  = (string) ($s['default_payment_method'] ?? '');
        }

        if ($name === '') {
            $name = (string) get_blog_option($blog_id, 'blogname', '');
        }
        if ($addr === '') {
            $addr = (string) get_blog_option($blog_id, 'tgs_shop_address', '');
        }
        if ($phone === '') {
            $phone = (string) get_blog_option($blog_id, 'tgs_shop_phone', '');
        }
        if ($mst === '') {
            $mst = (string) get_blog_option($blog_id, 'tgs_shop_tax_code', '');
        }

        // Dự phòng cuối cho MST: bảng shop áp dụng VAT
        if (trim($mst) === '' && class_exists('TGS_BCTK_Vat_Shops')) {
            foreach (TGS_BCTK_Vat_Shops::all(false) as $shop) {
                if ((int) ($shop['blog_id'] ?? 0) === $blog_id && !empty($shop['tax_code'])) {
                    $mst = (string) $shop['tax_code'];
                    break;
                }
            }
        }

        return self::$seller_cache[$key] = [
            'name'     => $name,
            'addr'     => $addr,
            'phone'    => $phone,
            'mst'      => $mst,
            'series'   => $series,
            'template' => $template,
            'payment'  => $payment,
        ];
    }

    /** Tên người mua theo nhãn bán lẻ — khớp TGS_Viettel_Invoice_Flow_Service */
    public static function retail_buyer_name($name, $tax_code)
    {
        if (class_exists('TGS_Viettel_Invoice_Flow_Service')) {
            $is_retail = TGS_Viettel_Invoice_Flow_Service::is_retail_buyer([
                'customer_name'     => $name,
                'customer_tax_code' => $tax_code,
            ]);
            return $is_retail
                ? TGS_Viettel_Invoice_Flow_Service::retail_buyer_label()
                : (string) $name;
        }

        $folded = function_exists('remove_accents') ? remove_accents((string) $name) : (string) $name;
        $folded = strtolower(trim(preg_replace('/\s+/', ' ', $folded)));
        $placeholders = ['', 'khach le', 'khach hang le', 'khach vang lai'];
        if (trim((string) $tax_code) === '' && in_array($folded, $placeholders, true)) {
            return 'Bán cho người tiêu dùng';
        }
        return (string) $name;
    }

    /**
     * Đọc số tiền VND ra chữ, kiểu "Bảy mươi tám nghìn đồng chẵn",
     * "Không đồng chẵn". Không có helper sẵn trong repo.
     */
    public static function doc_tien_bang_chu($amount)
    {
        $amount = (int) round((float) $amount);
        $neg = $amount < 0;
        $amount = abs($amount);

        if ($amount === 0) {
            return 'Không đồng chẵn';
        }

        $cs = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $dv = ['', ' nghìn', ' triệu', ' tỷ', ' nghìn tỷ', ' triệu tỷ'];

        $doc_khoi = static function ($num, $day_du) use ($cs) {
            $tram = intdiv($num, 100);
            $chuc = intdiv($num % 100, 10);
            $donvi = $num % 10;
            $out = '';

            if ($day_du || $tram > 0) {
                $out .= $cs[$tram] . ' trăm';
            }

            if ($chuc === 0) {
                if ($donvi > 0) {
                    $out .= ($out !== '' ? ' lẻ ' : '') . $cs[$donvi];
                }
            } elseif ($chuc === 1) {
                $out .= ' mười';
                if ($donvi === 5) {
                    $out .= ' lăm';
                } elseif ($donvi > 0) {
                    $out .= ' ' . $cs[$donvi];
                }
            } else {
                $out .= ' ' . $cs[$chuc] . ' mươi';
                if ($donvi === 1) {
                    $out .= ' mốt';
                } elseif ($donvi === 5) {
                    $out .= ' lăm';
                } elseif ($donvi > 0) {
                    $out .= ' ' . $cs[$donvi];
                }
            }

            return trim($out);
        };

        $groups = [];
        while ($amount > 0) {
            $groups[] = $amount % 1000;
            $amount = intdiv($amount, 1000);
        }

        $parts = [];
        $n = count($groups);
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($groups[$i] === 0) {
                continue;
            }
            $parts[] = $doc_khoi($groups[$i], $i < $n - 1) . $dv[$i];
        }

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
        $text = function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1)
            : ucfirst($text);

        return ($neg ? 'Trừ ' : '') . $text . ' đồng chẵn';
    }
}
