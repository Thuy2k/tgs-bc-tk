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
        $stock_rows = TGS_BCTK_Report::site_stock_rows($blog_id, $is_warehouse ? $zones : []);
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
