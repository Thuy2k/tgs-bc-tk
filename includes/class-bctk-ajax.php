<?php

/**
 * AJAX cho báo cáo tồn kho — MỖI LƯỢT GỌI XỬ LÝ ĐÚNG MỘT SITE.
 *
 * Chia nhỏ theo site thay vì gộp một lượt vì ba lý do:
 *   1. Thanh tiến độ chạy thật ("đã lấy 12/70 site"), không phải quay giả.
 *   2. Một site hỏng không kéo sập cả báo cáo — chỉ site đó báo lỗi.
 *   3. Không đụng trần thời gian chạy PHP khi quét 70 site.
 *
 * Server trả về DÒNG ĐÃ GHÉP ĐỦ (tên, alias, ĐVT, giá, min/max, đi đường) để
 * JS chỉ việc nối lại và vẽ — không phải gọi thêm lượt nào để tra cứu.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_BCTK_Ajax
{
    const NONCE = 'tgs_bctk_nonce';

    public static function init()
    {
        add_action('wp_ajax_tgs_bctk_fetch_site', [__CLASS__, 'fetch_site']);
        add_action('wp_ajax_tgs_bctk_fetch_ledger', [__CLASS__, 'fetch_ledger']);
        add_action('wp_ajax_tgs_bctk_fetch_purchase', [__CLASS__, 'fetch_purchase']);
    }

    /**
     * Phân tích mua hàng — sổ kho + tồn max/min + hàng đi đường + gợi ý NCC.
     *
     * Dùng chung khung xử lý với fetch_ledger, chỉ bồi thêm dữ liệu. Phần gợi ý
     * mua bao nhiêu KHÔNG tính ở đây mà tính sau khi gộp các chi nhánh ở phía
     * giao diện — xem chú thích trong stock-purchase.php.
     */
    public static function fetch_purchase()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        $today = current_time('Y-m-d');
        $from  = self::sanitize_date($_POST['date_from'] ?? '', $today);
        $to    = self::sanitize_date($_POST['date_to'] ?? '', $today);

        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }
        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }

        try {
            $base = self::build_ledger_rows($blog_id, $zones, $from, $to);
            if (empty($base['rows'])) {
                wp_send_json_success($base);
            }

            $skus = array_column($base['rows'], 'sku');

            /*
             * Tồn max/min lấy THEO WEBSITE, không theo phân kho — cấu hình
             * min/max vốn khai ở mức site. Gộp nhiều site thì cộng dồn lại,
             * việc cộng do phía giao diện làm sau khi gộp mã hàng.
             */
            $mm        = TGS_BCTK_Report::min_max($skus, [$blog_id])[$blog_id] ?? [];
            $transit   = TGS_BCTK_Report::site_in_transit_rows($blog_id);
            $suppliers = TGS_BCTK_Report::supplier_hint($blog_id, $skus);

            foreach ($base['rows'] as &$r) {
                $sku = $r['sku'];
                $r['max']        = isset($mm[$sku]['max']) ? (float) $mm[$sku]['max'] : 0;
                $r['min']        = isset($mm[$sku]['min']) ? (float) $mm[$sku]['min'] : 0;
                $r['has_minmax'] = isset($mm[$sku]);
                $r['in_transit'] = (float) ($transit[$sku] ?? 0);
                $r['sup_code']   = (string) ($suppliers[$sku]['code'] ?? '');
                $r['sup_name']   = (string) ($suppliers[$sku]['name'] ?? '');
            }
            unset($r);

            wp_send_json_success($base);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /** Sổ kho theo mặt hàng — mỗi lượt một site, giống fetch_site */
    public static function fetch_ledger()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        // Mặc định là hôm nay nếu trang không gửi ngày
        $today = current_time('Y-m-d');
        $from  = self::sanitize_date($_POST['date_from'] ?? '', $today);
        $to    = self::sanitize_date($_POST['date_to'] ?? '', $today);

        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }
        if ($from > $to) {
            list($from, $to) = [$to, $from]; // chọn ngược thì tự đảo, đỡ báo lỗi vặt
        }

        try {
            wp_send_json_success(self::build_ledger_rows($blog_id, $zones, $from, $to));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /** Chỉ nhận đúng dạng Y-m-d, sai thì dùng giá trị thay thế */
    private static function sanitize_date($raw, $fallback)
    {
        $raw = sanitize_text_field((string) $raw);
        $d = DateTime::createFromFormat('Y-m-d', $raw);
        return ($d && $d->format('Y-m-d') === $raw) ? $raw : $fallback;
    }

    /**
     * Dựng dòng sổ kho cho một site.
     *
     * Báo cáo này KHÔNG tách theo phân kho — bộ lọc mã kho chỉ dùng để khoanh
     * phạm vi, còn số liệu cộng dồn hết. Vì vậy luôn truyền $group_by_zone=false.
     */
    public static function build_ledger_rows($blog_id, array $zones, $from, $to)
    {
        $blog_id = (int) $blog_id;

        $site = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        $is_warehouse = TGS_BCTK_Sites::is_warehouse($blog_id);

        $raw = TGS_BCTK_Report::site_ledger_rows(
            $blog_id,
            $is_warehouse ? $zones : [],
            $is_warehouse,   // lọc theo phân kho vẫn áp cho site kho, nhưng không gộp theo nó
            $from,
            $to
        );

        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $info = TGS_BCTK_Report::product_info(array_column($raw, 'sku'));
        $rows = [];

        foreach ($raw as $r) {
            $p = $info[$r['sku']] ?? [];
            $rows[] = array_merge($r, [
                'blog_id' => $blog_id,
                'name'    => (string) ($p['name'] ?? ''),
                'unit'    => (string) ($p['unit'] ?? ''),
            ]);
        }

        return ['rows' => $rows, 'site' => $site];
    }

    public static function fetch_site()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }

        try {
            wp_send_json_success(self::build_site_rows($blog_id, $zones));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /**
     * Dựng danh sách dòng hoàn chỉnh cho một site.
     *
     * Tách riêng khỏi handler AJAX để gọi lại được từ PHP (xuất Excel phía
     * server, cron, hoặc báo cáo khác) mà không phải giả lập request.
     */
    public static function build_site_rows($blog_id, array $zones = [])
    {
        $blog_id = (int) $blog_id;

        $is_warehouse = TGS_BCTK_Sites::is_warehouse($blog_id);
        $site         = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        $site_label = $site['code'] !== '' ? $site['code'] : $site['name'];

        /*
         * Site SHOP không lọc theo phân kho: bảng item của shop thường để trống
         * cột phân kho, truyền bộ lọc vào sẽ ra rỗng. Phân kho chỉ có nghĩa với
         * site kho.
         */
        /*
         * Chỉ site KHO mới lọc và gộp theo phân kho. Site shop gộp thẳng theo
         * mã hàng — nếu gộp theo phân kho thì cùng một mã ở shop bị tách thành
         * nhiều dòng (do lác đác dòng có mã kho sót lại từ phiếu chuyển), mà
         * nhãn hiển thị đều là tên shop nên nhìn như dòng trùng lặp.
         */
        $stock_rows = TGS_BCTK_Report::site_stock_rows(
            $blog_id,
            $is_warehouse ? $zones : [],
            $is_warehouse
        );
        if (empty($stock_rows)) {
            return ['rows' => [], 'site' => $site];
        }

        $skus       = array_values(array_unique(array_column($stock_rows, 'sku')));
        $info       = TGS_BCTK_Report::product_info($skus);
        $min_max    = TGS_BCTK_Report::min_max($skus, [$blog_id]);
        $in_transit = TGS_BCTK_Report::site_in_transit_rows($blog_id);

        $mm = $min_max[$blog_id] ?? [];
        $rows = [];

        foreach ($stock_rows as $r) {
            $sku  = $r['sku'];
            $zone = $r['zone'];
            $p    = $info[$sku] ?? [];

            /*
             * Cột "Kho": site kho thì hiện mã phân kho. Nếu dữ liệu chưa gán
             * phân kho thì vẫn phải hiện đủ số liệu — nhưng gắn cờ cảnh báo để
             * người xem biết đây là phần chưa phân kho, không phải mất hàng.
             */
            $no_zone = ($is_warehouse && $zone === '');
            if ($is_warehouse) {
                $zone_label = $no_zone ? $site['name'] : $zone;
            } else {
                $zone_label = $site_label;
            }

            $qty = (float) $r['qty'];
            $max = isset($mm[$sku]['max']) ? (float) $mm[$sku]['max'] : null;
            $min = isset($mm[$sku]['min']) ? (float) $mm[$sku]['min'] : null;

            $rows[] = [
                'blog_id'    => $blog_id,
                'zone'       => $zone_label,
                'no_zone'    => $no_zone,
                'sku'        => $sku,
                'name'       => (string) ($p['name'] ?? ''),
                'alias'      => (string) ($p['alias'] ?? ''),
                'qty'        => $qty,
                'price'      => isset($p['price']) ? (float) $p['price'] : 0,
                'in_transit' => (float) ($in_transit[$sku] ?? 0),
                'max'        => $max,
                'min'        => $min,
                // Cần nhập = tồn max − số lượng. Thừa thì ra số âm.
                // (Đối chiếu HTsoft: max 720, SL 1.574 → −854)
                'need'       => $max === null ? null : ($max - $qty),
                'unit'       => (string) ($p['unit'] ?? ''),
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }
}

TGS_BCTK_Ajax::init();
