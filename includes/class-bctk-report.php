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
     *   Nhập (mua NCC)   item_type=1, phiếu KHÔNG có cha
     *   Nhập nội bộ      item_type=1, cha là phiếu mua nội bộ  (type 13)
     *   Xuất bán         item_type=2, cha là phiếu bán hàng    (type 10)
     *   Xuất nội bộ      item_type=2, cha là phiếu bán nội bộ  (type 12)
     *   Xuất điều chỉnh  item_type=2, phiếu KHÔNG có cha
     *   Xuất trả         item_type=3  (khách hoàn trả lại cửa hàng)
     *
     * Mọi phiếu đều phải ĐÃ DUYỆT.
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

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$I}
                    AND l.local_ledger_parent_id IS NULL
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS nhap,

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

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$R}
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_tra,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND l.local_ledger_parent_id IS NULL
                    THEN ABS(li.quantity) ELSE 0 END), 0) AS xuat_dc,

                COALESCE(SUM(CASE WHEN {$in_range}
                    AND li.local_ledger_item_type = {$E}
                    AND l.local_ledger_parent_id IS NULL
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
         *   1. 8 cặp (from, to) — 8 cột thống kê phát sinh trong kỳ
         *   2. 1 giá trị $to     — cột tồn cuối (tính đến hết ngày cuối kỳ)
         *   3. danh sách mã kho  — ở mệnh đề WHERE
         *
         * Thêm/bớt cột có %s mà quên sửa chỗ này là toàn bộ tham số lệch một
         * nhịp: ngày chui vào chỗ mã kho, số liệu sai mà không báo lỗi gì.
         */
        $params = [];
        for ($i = 0; $i < 8; $i++) {
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

            return [
                'sku'       => (string) $r['sku'],
                'ton_dau'   => $ton_cuoi - $net,   // suy ngược từ tồn cuối
                'nhap'      => (float) $r['nhap'],
                'nhap_nb'   => (float) $r['nhap_nb'],
                'xuat_ban'  => (float) $r['xuat_ban'],
                'xuat_nb'   => (float) $r['xuat_nb'],
                'xuat_tra'  => (float) $r['xuat_tra'],
                'xuat_dc'   => (float) $r['xuat_dc'],
                'dc_signed' => (float) $r['dc_signed'],
                'ton_cuoi'  => $ton_cuoi,
            ];
        }, $rows);
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
}
