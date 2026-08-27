<?php

/**
 * DANH SÁCH SHOP THẬT SỰ ĐANG ÁP DỤNG HOÁ ĐƠN VAT
 * =============================================================================
 *
 * Khối *Quản lý VAT* chỉ được đụng tới dữ liệu của mấy shop khai ở đây. Toàn hệ
 * thống có hàng trăm site, nhưng số shop đã lên thuế thật thì đếm trên đầu ngón
 * tay và tăng dần theo đợt triển khai — không có danh sách này thì mọi báo cáo
 * thuế đều phải quét cả trăm site rồi tự đoán shop nào đang áp dụng.
 *
 * Bảng `wp_global_vat_shops` là bảng GLOBAL (khai ở
 * tgs_shop_management/database/class-tgs-database.php). Kích hoạt lại plugin
 * tgs_shop_management là bảng tự sinh.
 *
 * ── VÌ SAO KHÔNG DÙNG LẠI wp_global_deployment_shops ────────────────────────
 *
 * Bảng đó trả lời câu khác: "shop nào đang triển khai CHUYỂN KHO nội bộ". Hai
 * danh sách trùng nhau lúc này nhưng sẽ tách ra: shop có thể chạy chuyển kho cả
 * năm trước khi lên hoá đơn thuế. Nhét chung một bảng là sớm muộn một bên bật
 * cờ làm hỏng nghiệp vụ của bên kia.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_BCTK_Vat_Shops
{
    const NONCE_ACTION = 'tgs_bctk_vat_nonce';

    public static function init()
    {
        add_action('wp_ajax_tgs_bctk_vat_shops_list', [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_tgs_bctk_vat_shops_save', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_tgs_bctk_vat_shops_delete', [__CLASS__, 'ajax_delete']);
    }

    /** Tên bảng global — luôn base_prefix, không phải prefix của site đang đứng */
    public static function table()
    {
        global $wpdb;

        return $wpdb->base_prefix . 'global_vat_shops';
    }

    public static function table_exists()
    {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($table) . "'") === $table;
    }

    /**
     * Các shop đang áp dụng VAT.
     *
     * @param bool $only_active Chỉ lấy shop đang bật (mặc định), false = lấy cả
     *                          shop tạm dừng để màn quản lý hiện đủ.
     * @return array[]
     */
    public static function all($only_active = true)
    {
        global $wpdb;

        if (!self::table_exists()) {
            return [];
        }

        $table = self::table();
        $sql = "SELECT * FROM {$table} WHERE is_deleted = 0";
        if ($only_active) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY tgs_site_code ASC';

        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * blog_id của các shop đang áp dụng VAT — dùng để lọc dữ liệu báo cáo.
     *
     * @return int[]
     */
    public static function active_blog_ids()
    {
        $ids = [];
        foreach (self::all(true) as $row) {
            $blog_id = intval($row['blog_id'] ?? 0);
            if ($blog_id > 0) {
                $ids[] = $blog_id;
            }
        }

        return array_values(array_unique($ids));
    }

    // ─── AJAX ──────────────────────────────────────────────────────────────

    private static function guard()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền thao tác danh sách shop áp dụng VAT.'], 403);
        }

        if (!self::table_exists()) {
            wp_send_json_error([
                'message' => 'Chưa có bảng ' . self::table() . '. Vào Plugins, tắt rồi bật lại '
                    . 'plugin TGS Shop Management để hệ thống tự tạo bảng.',
            ], 500);
        }
    }

    public static function ajax_list()
    {
        self::guard();

        wp_send_json_success(['shops' => self::all(false)]);
    }

    /**
     * Thêm hoặc sửa một shop.
     *
     * Mã shop là chìa khoá: phải có thật trong wp_blogs.tgs_site_code thì mới
     * nhận. Cho gõ tự do là sớm muộn có dòng mã sai chính tả nằm im trong bảng,
     * và báo cáo thuế thiếu nguyên một shop mà không ai biết vì sao.
     */
    public static function ajax_save()
    {
        global $wpdb;

        self::guard();

        $id = intval($_POST['id'] ?? 0);
        $site_code = strtoupper(sanitize_text_field(wp_unslash($_POST['tgs_site_code'] ?? '')));
        $tax_code = sanitize_text_field(wp_unslash($_POST['tax_code'] ?? ''));
        $applied_from = sanitize_text_field(wp_unslash($_POST['applied_from'] ?? ''));
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        $is_active = !empty($_POST['is_active']) ? 1 : 0;

        if ($site_code === '') {
            wp_send_json_error(['message' => 'Chưa nhập mã shop.']);
        }

        if ($applied_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $applied_from)) {
            wp_send_json_error(['message' => 'Ngày áp dụng không đúng dạng YYYY-MM-DD.']);
        }

        $blog = $wpdb->get_row($wpdb->prepare(
            "SELECT blog_id FROM {$wpdb->blogs} WHERE tgs_site_code = %s LIMIT 1",
            $site_code
        ));

        if (empty($blog)) {
            wp_send_json_error([
                'message' => 'Không tìm thấy website nào mang mã shop "' . $site_code . '". '
                    . 'Kiểm tra lại cột tgs_site_code trong bảng wp_blogs.',
            ]);
        }

        $blog_id = intval($blog->blog_id);

        switch_to_blog($blog_id);
        $shop_name = get_bloginfo('name');
        restore_current_blog();

        $now = current_time('mysql');
        $data = [
            'tgs_site_code' => $site_code,
            'blog_id' => $blog_id,
            'shop_name' => $shop_name,
            'tax_code' => $tax_code !== '' ? $tax_code : null,
            'applied_from' => $applied_from !== '' ? $applied_from : null,
            'note' => $note,
            'is_active' => $is_active,
            'user_id' => get_current_user_id(),
            'updated_at' => $now,
            'is_deleted' => 0,
            'deleted_at' => null,
        ];

        $table = self::table();

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
            wp_send_json_success(['message' => 'Đã cập nhật shop ' . $site_code . '.', 'id' => $id]);
        }

        /*
         * Mã shop là UNIQUE. Khai lại một mã đã từng bị xoá mềm thì GHI ĐÈ dòng
         * cũ (kèm bật lại is_deleted = 0) chứ không chèn thêm — chèn là dính lỗi
         * trùng khoá và người dùng không hiểu vì sao "mã này đã tồn tại" trong
         * khi màn hình chẳng thấy dòng nào.
         */
        $existing_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE tgs_site_code = %s LIMIT 1",
            $site_code
        )));

        if ($existing_id > 0) {
            $wpdb->update($table, $data, ['id' => $existing_id]);
            wp_send_json_success([
                'message' => 'Mã shop ' . $site_code . ' đã có sẵn — đã cập nhật lại dòng đó.',
                'id' => $existing_id,
            ]);
        }

        $data['created_at'] = $now;
        $wpdb->insert($table, $data);

        wp_send_json_success([
            'message' => 'Đã thêm shop ' . $site_code . ' vào danh sách áp dụng VAT.',
            'id' => intval($wpdb->insert_id),
        ]);
    }

    /** Xoá mềm — giữ lại vết ai từng khai shop nào, khai lúc nào */
    public static function ajax_delete()
    {
        global $wpdb;

        self::guard();

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Thiếu id dòng cần xoá.']);
        }

        $wpdb->update(
            self::table(),
            [
                'is_deleted' => 1,
                'deleted_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'user_id' => get_current_user_id(),
            ],
            ['id' => $id]
        );

        wp_send_json_success(['message' => 'Đã xoá shop khỏi danh sách áp dụng VAT.']);
    }
}

TGS_BCTK_Vat_Shops::init();
