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
}
