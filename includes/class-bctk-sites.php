<?php

/**
 * Lớp dữ liệu cho bộ lọc bên trái — dùng chung cho MỌI báo cáo trong BC_TK.
 *
 * Cố ý tách hẳn khỏi giao diện: mọi hàm ở đây trả về mảng thuần, không echo gì.
 * Nhờ vậy vừa dùng được cho trang render sẵn, vừa trả thẳng qua AJAX/REST, vừa
 * gọi được từ báo cáo khác mà không phải chép lại.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_BCTK_Sites
{
    /** Option lưu cấu trúc phân cấp, do plugin tgs-multisite-hierarchy quản lý */
    const HIERARCHY_OPTION = 'tgs_multisite_hierarchy_data';

    /*
     * Mã giả cho nhóm "chưa phân kho" — các dòng trong local_ledger_item có cột
     * local_ledger_item_warehouse_zone rỗng hoặc NULL.
     *
     * Hàng vẫn nằm trong kho và vẫn phải tính vào tồn; chỉ là chưa được gán về
     * phân kho nào. Không cho chọn riêng thì phần hàng này biến mất khỏi báo cáo
     * ngay khi người dùng lọc theo mã kho — số bị hụt mà không biết vì sao.
     *
     * Dùng chuỗi khó trùng để không đụng mã kho thật do người dùng đặt.
     */
    const ZONE_NONE = '__none__';

    /** Cache trong 1 request — cấu hình phân cấp bị hỏi nhiều lần */
    private static $hierarchy_cache = null;

    /**
     * Cấu trúc phân cấp đã chuẩn hoá.
     *
     * Trả về:
     *   [
     *     'parent' => [blog_id => parent_blog_id|null],
     *     'sites'  => [blog_id => ['name'=>…, 'type'=>'warehouse'|'shop', 'status'=>…]],
     *   ]
     */
    public static function hierarchy()
    {
        if (self::$hierarchy_cache !== null) {
            return self::$hierarchy_cache;
        }

        $raw = get_site_option(self::HIERARCHY_OPTION);
        if (!$raw) {
            $raw = get_option(self::HIERARCHY_OPTION);
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        self::$hierarchy_cache = [
            'parent' => isset($raw['hierarchy']) && is_array($raw['hierarchy']) ? $raw['hierarchy'] : [],
            'sites'  => isset($raw['sites']) && is_array($raw['sites']) ? $raw['sites'] : [],
        ];

        return self::$hierarchy_cache;
    }

    /** Site này là kho hay cửa hàng. Không khai báo thì coi như shop. */
    public static function is_warehouse($blog_id)
    {
        $h = self::hierarchy();
        return isset($h['sites'][$blog_id]['type']) && $h['sites'][$blog_id]['type'] === 'warehouse';
    }

    /**
     * Danh sách site để dựng bộ lọc chi nhánh.
     *
     * Nhãn hiển thị ưu tiên tgs_site_code (mã ngắn, tiết kiệm chỗ — bộ lọc càng
     * gọn thì càng giống phần mềm cũ). Không có mã thì lấy tên site.
     * blog_id LUÔN được trả kèm vì mọi truy vấn sau đó đều khoá theo nó, không
     * khoá theo mã.
     */
    public static function list_sites()
    {
        global $wpdb;

        /*
         * NGUỒN DANH SÁCH LÀ CẤU HÌNH PHÂN CẤP, KHÔNG PHẢI wp_blogs.
         *
         * wp_blogs chứa MỌI site từng tạo trên multisite — gồm cả site rác, site
         * thử nghiệm, site dựng dở. Đưa hết vào bộ lọc thì người dùng phải tự
         * đoán cái nào là chi nhánh thật.
         *
         * "Cấu trúc phân cấp Multisite" (Tổng – Chi nhánh – Kho – Cửa hàng) mới
         * là nơi khai báo chính thức, nên chỉ site có mặt ở đó mới được liệt kê.
         */
        $h = self::hierarchy();
        if (empty($h['sites'])) {
            return []; // chưa khai báo gì thì không đoán bừa
        }

        $has_code = (bool) $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->blogs} LIKE 'tgs_site_code'");
        $code_col = $has_code ? 'tgs_site_code' : "'' AS tgs_site_code";

        /*
         * Vẫn đọc wp_blogs, nhưng để lấy MÃ SITE và ĐỐI CHIẾU blog còn sống.
         * Cấu hình có thể còn sót site đã bị xoá hoặc lưu trữ; truy vấn vào bảng
         * của site đó sẽ lỗi, nên loại ra trước.
         */
        $rows = $wpdb->get_results(
            "SELECT blog_id, {$code_col} FROM {$wpdb->blogs}
              WHERE archived = '0' AND deleted = '0' AND spam = '0'"
        );

        $alive = [];
        foreach ($rows as $r) {
            $alive[(int) $r->blog_id] = trim((string) ($r->tgs_site_code ?? ''));
        }

        $out = [];

        foreach ($h['sites'] as $bid => $info) {
            $bid = (int) $bid;

            if (!array_key_exists($bid, $alive)) {
                continue; // có khai báo nhưng blog không còn
            }
            if (($info['status'] ?? 'active') === 'inactive') {
                continue; // đã ngừng hoạt động
            }

            $code = $alive[$bid];
            $name = $info['name'] ?? get_blog_option($bid, 'blogname', 'Site #' . $bid);

            $out[] = [
                'blog_id'   => $bid,
                'code'      => $code,
                'name'      => $name,
                // Nhãn gọn cho bộ lọc: "2001 — Vĩnh Tường", không mã thì chỉ tên
                'label'     => $code !== '' ? $code . ' — ' . $name : $name,
                'type'      => self::is_warehouse($bid) ? 'warehouse' : 'shop',
                'parent_id' => isset($h['parent'][$bid]) ? (int) $h['parent'][$bid] : 0,
                'status'    => $info['status'] ?? 'active',
            ];
        }

        usort($out, static function ($a, $b) {
            return $a['blog_id'] - $b['blog_id'];
        });

        return $out;
    }

    /**
     * Các site con trực thuộc một kho — cho nút "chọn nhanh toàn bộ shop của kho này".
     */
    public static function children_of($parent_blog_id)
    {
        $parent_blog_id = (int) $parent_blog_id;
        $out = [];

        foreach (self::hierarchy()['parent'] as $bid => $pid) {
            if ((int) $pid === $parent_blog_id && (int) $bid !== $parent_blog_id) {
                $out[] = (int) $bid;
            }
        }

        return $out;
    }

    /**
     * Mã phân kho của một site.
     *
     * Chỉ site KHO mới có nhiều phân kho thật. Site shop trả về đúng một "phân
     * kho ảo" mang mã tgs_site_code (hoặc tên site nếu chưa có mã) — nhờ vậy
     * phía báo cáo xử lý kho và shop bằng cùng một kiểu dữ liệu, không phải rẽ
     * nhánh ở mọi chỗ.
     */
    public static function zones_of($blog_id)
    {
        global $wpdb;
        $blog_id = (int) $blog_id;

        if (!self::is_warehouse($blog_id)) {
            $site = null;
            foreach (self::list_sites() as $s) {
                if ($s['blog_id'] === $blog_id) { $site = $s; break; }
            }
            if (!$site) {
                return [];
            }
            $code = $site['code'] !== '' ? $site['code'] : $site['name'];
            return [[
                'zone_code' => $code,
                'zone_name' => $site['name'],
                'label'     => $code,
                'virtual'   => true, // không có trong bảng phân kho, suy ra từ site
            ]];
        }

        switch_to_blog($blog_id);
        $table = $wpdb->prefix . 'local_warehouse_zone';

        $zones = [];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $rows = $wpdb->get_results(
                "SELECT zone_code, zone_name FROM {$table}
                  WHERE is_deleted = 0 OR is_deleted IS NULL
                  ORDER BY sort_order ASC, zone_code ASC"
            );
            foreach ($rows as $r) {
                $zones[] = [
                    'zone_code' => (string) $r->zone_code,
                    'zone_name' => (string) ($r->zone_name ?: $r->zone_code),
                    'label'     => (string) $r->zone_code,
                    'virtual'   => false,
                ];
            }
        }
        restore_current_blog();

        /*
         * Luôn thêm nhóm "Chưa phân kho" cho site kho, kể cả khi hiện chưa có
         * dòng nào rơi vào đó — dữ liệu nhập sau vẫn có thể thiếu phân kho, mà
         * người dùng thì cần biết nhóm này tồn tại để mà chọn.
         */
        $zones[] = [
            'zone_code' => self::ZONE_NONE,
            'zone_name' => 'Chưa phân kho',
            'label'     => 'Chưa phân kho',
            'virtual'   => false,
            'none'      => true,
        ];

        return $zones;
    }

    /**
     * Gói dữ liệu khởi tạo cho bộ lọc: site + phân kho của từng site.
     *
     * Trả một lần duy nhất để giao diện không phải gọi AJAX mỗi lần tích chọn —
     * với ~70 site thì số lượt gọi mới là thứ làm chậm, không phải kích thước
     * gói dữ liệu.
     */
    public static function filter_bootstrap()
    {
        $sites = self::list_sites();
        $zones = [];

        foreach ($sites as $s) {
            $zones[$s['blog_id']] = self::zones_of($s['blog_id']);
        }

        return [
            'sites'    => $sites,
            'zones'    => $zones,
            'children' => array_reduce($sites, function ($carry, $s) {
                if ($s['type'] === 'warehouse') {
                    $carry[$s['blog_id']] = self::children_of($s['blog_id']);
                }
                return $carry;
            }, []),
        ];
    }
}
