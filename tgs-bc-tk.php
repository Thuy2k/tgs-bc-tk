<?php

/**
 * Plugin Name: TGS BC_TK — Báo cáo tồn kho
 * Description: Khối báo cáo BC_TK trong menu Mua hàng. Gộp tồn kho theo mã hàng trên toàn hệ thống multisite.
 * Version: 0.1.0
 * Author: TGS
 *
 * ── Ghi chú thiết kế ────────────────────────────────────────────────────────
 *
 * Toàn bộ khối BC_TK TẠM THỜI KHÔNG PHÂN QUYỀN — đang trong giai đoạn phát
 * triển báo cáo. Plugin tgs_permission gác bằng capability của WordPress
 * (current_user_can), ở đây chỉ yêu cầu đăng nhập và đọc được trang quản trị.
 * Khi nào chốt nghiệp vụ thì siết lại ở một chỗ duy nhất: BCTK_CAPABILITY.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_BCTK_VERSION', '0.1.0');
define('TGS_BCTK_DIR', plugin_dir_path(__FILE__));
define('TGS_BCTK_URL', plugin_dir_url(__FILE__));

/*
 * Quyền tối thiểu để xem báo cáo. Để 'read' nghĩa là ai đăng nhập được vào
 * quản trị đều xem được — đúng yêu cầu "chưa phân quyền vội".
 * Siết lại sau: đổi thành 'manage_options' hoặc capability riêng của
 * tgs_permission là xong, không phải sửa chỗ nào khác.
 */
define('TGS_BCTK_CAPABILITY', 'read');

require_once TGS_BCTK_DIR . 'includes/class-bctk-sites.php';
require_once TGS_BCTK_DIR . 'includes/class-bctk-report.php';
require_once TGS_BCTK_DIR . 'includes/class-bctk-ajax.php';

class TGS_BCTK_Plugin
{
    /** View slug — dùng lại ở cả nav, route lẫn điều kiện nạp asset */
    const VIEW_STOCK    = 'bctk-stock';
    const VIEW_LEDGER   = 'bctk-ledger';
    const VIEW_PURCHASE = 'bctk-purchase';
    const VIEW_CSKH     = 'bctk-cskh';
    const VIEW_SALES    = 'bctk-sales';
    const VIEW_SALESSUM = 'bctk-sales-sum';
    const VIEW_PURREPORT = 'bctk-purchase-report';

    /** Mọi view của BC_TK — thêm màn mới chỉ cần khai ở đây */
    private static function views()
    {
        return [
            self::VIEW_STOCK  => ['Báo cáo tồn kho', 'Tồn kho', 'bx bx-box', 'stock-report.php'],
            self::VIEW_LEDGER => ['Sổ kho theo mặt hàng', 'Sổ kho (theo mặt hàng)', 'bx bx-book-content', 'stock-ledger.php'],
            self::VIEW_PURCHASE => ['Phân tích mua hàng', 'Phân tích mua hàng', 'bx bx-cart-add', 'stock-purchase.php'],
            self::VIEW_PURREPORT => ['Báo cáo mua hàng / Hàng trả nhà cung cấp', 'Báo cáo mua hàng', 'bx bx-receipt', 'purchase-report.php'],
            self::VIEW_CSKH   => ['Sổ chăm sóc khách hàng', 'Sổ CSKH', 'bx bx-user-voice', 'cskh-log.php'],
            self::VIEW_SALES  => ['Báo cáo bán hàng / Hàng bán trả lại', 'Báo cáo bán hàng', 'bx bx-receipt', 'sales-report.php'],
            self::VIEW_SALESSUM => ['Tổng hợp bán hàng', 'Tổng hợp bán hàng', 'bx bx-list-check', 'sales-summary.php'],
        ];
    }

    /**
     * Màn nào thuộc menu BÁN HÀNG thay vì MUA HÀNG.
     *
     * Sổ CSKH tra theo khách mua nên thuộc về bán hàng, dù dùng chung khung
     * BC_TK với mấy báo cáo kho.
     */
    private static function sales_views()
    {
        return [self::VIEW_CSKH, self::VIEW_SALES, self::VIEW_SALESSUM];
    }

    public static function init()
    {
        add_filter('tgs_shop_workflow_nav', [__CLASS__, 'add_nav_block'], 10, 2);
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_routes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Chèn khối BC_TK vào menu "Mua hàng" và "Bán hàng".
     *
     * Bám theo cấu trúc mà header-mega-nav.php dựng sẵn:
     *   $nav['purchase']['sections'][] = ['heading'=>…, 'icon'=>…, 'items'=>[…]]
     * Chèn thêm section thay vì sửa section có sẵn để không đụng vào menu cũ.
     *
     * Chia hai nơi theo nghiệp vụ: báo cáo kho nằm ở Mua hàng, còn sổ tra cứu
     * theo khách mua nằm ở Bán hàng — dù cả hai dùng chung khung BC_TK.
     */
    public static function add_nav_block($workflow_nav, $current_view = '')
    {
        $sales = self::sales_views();

        $stock_items = [];
        $sales_items = [];

        foreach (self::views() as $view => $meta) {
            $item = ['view' => $view, 'label' => $meta[1], 'icon' => $meta[2]];
            if (in_array($view, $sales, true)) {
                $sales_items[] = $item;
            } else {
                $stock_items[] = $item;
            }
        }

        $block = static function ($items) {
            return [
                'key'     => 'bctk',
                'heading' => 'BC_TK',
                'icon'    => 'bx bx-bar-chart-square',
                'items'   => $items,
            ];
        };

        if ($sales_items && isset($workflow_nav['sales']['sections'])) {
            $workflow_nav['sales']['sections'][] = $block($sales_items);
        }

        if (!isset($workflow_nav['purchase']['sections'])) {
            return $workflow_nav;
        }

        $workflow_nav['purchase']['sections'][] = $block($stock_items);

        return $workflow_nav;
    }

    /** Route dùng đường dẫn tuyệt đối — plugin shop hỗ trợ sẵn kiểu này */
    public static function register_routes($routes)
    {
        foreach (self::views() as $view => $meta) {
            $routes[$view] = [$meta[0], TGS_BCTK_DIR . 'admin-views/' . $meta[3]];
        }

        return $routes;
    }

    /** Chỉ nạp asset đúng trang báo cáo, không làm nặng các trang khác */
    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_tgs-shop-management') {
            return;
        }
        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if (!array_key_exists($view, self::views())) {
            return;
        }

        wp_enqueue_style(
            'tgs-bctk',
            TGS_BCTK_URL . 'assets/css/bctk.css',
            [],
            TGS_BCTK_VERSION . '.' . @filemtime(TGS_BCTK_DIR . 'assets/css/bctk.css')
        );

        /*
         * Không nạp thư viện xuất Excel có định dạng ở đây.
         *
         * Nó đã nằm trong main-layout.php của tgs_shop_management, dùng chung
         * cho mọi màn — vì nút "Xuất Excel" do tgs-erp-ds.js tự sinh cho mọi
         * bảng chứ không riêng báo cáo BC_TK.
         */
        wp_enqueue_script(
            'tgs-bctk-filter',
            TGS_BCTK_URL . 'assets/js/bctk-filter.js',
            ['jquery'],
            TGS_BCTK_VERSION . '.' . @filemtime(TGS_BCTK_DIR . 'assets/js/bctk-filter.js'),
            true
        );
    }
}

TGS_BCTK_Plugin::init();
