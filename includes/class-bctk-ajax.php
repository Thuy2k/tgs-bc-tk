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

    /**
     * Nạp lớp tính tiền dùng chung.
     *
     * Báo cáo TUYỆT ĐỐI không được tự viết công thức tiền: lệch một chút là số
     * trên báo cáo khác số đã gửi cơ quan thuế. Luật nằm ở
     * tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md,
     * còn TGS_Money thực thi luật đó.
     */
    /**
     * Đơn giá làm tròn về đồng, ĐẢM BẢO nhân với số lượng KHÔNG hụt thành tiền.
     *
     * Làm tròn xuống rồi nhân lên là dòng không cộng được nữa: 10.560 × giá bị
     * cắt 0,4đ mỗi đơn vị là hụt 4.000đ cả dòng, mà chiết khấu thì không thể âm
     * để bù. Số lượng càng lớn càng lệch — màn bán hàng chưa lộ chỉ vì bán lẻ
     * số lượng nhỏ.
     *
     * Nên: làm tròn bình thường, nhưng nếu nhân lên mà hụt thì làm tròn LÊN.
     * Phần dư luôn rơi vào chiết khấu, dòng cộng khít trong mọi trường hợp.
     */
    private static function don_gia_lam_tron($tien_goc, $qty, $thanh_tien)
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $gia = round($tien_goc / $qty);

        if ($gia * $qty < $thanh_tien) {
            $gia = ceil($thanh_tien / $qty);
        }

        return (float) $gia;
    }

    private static function money_ready()
    {
        if (class_exists('TGS_Money')) {
            return true;
        }

        $file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-money.php';
        if (file_exists($file)) {
            require_once $file;
        }

        return class_exists('TGS_Money');
    }

    public static function init()
    {
        add_action('wp_ajax_tgs_bctk_fetch_site', [__CLASS__, 'fetch_site']);
        add_action('wp_ajax_tgs_bctk_fetch_ledger', [__CLASS__, 'fetch_ledger']);
        add_action('wp_ajax_tgs_bctk_fetch_purchase', [__CLASS__, 'fetch_purchase']);
        add_action('wp_ajax_tgs_bctk_fetch_cskh', [__CLASS__, 'fetch_cskh']);
        add_action('wp_ajax_tgs_bctk_fetch_sales', [__CLASS__, 'fetch_sales']);
        add_action('wp_ajax_tgs_bctk_fetch_sales_sum', [__CLASS__, 'fetch_sales_sum']);
        add_action('wp_ajax_tgs_bctk_fetch_purchase_report', [__CLASS__, 'fetch_purchase_report']);
        add_action('wp_ajax_tgs_bctk_fetch_purchase_sum', [__CLASS__, 'fetch_purchase_sum']);
        add_action('wp_ajax_tgs_bctk_fetch_vat_sales', [__CLASS__, 'fetch_vat_sales']);
        add_action('wp_ajax_tgs_bctk_fetch_vat_adjust', [__CLASS__, 'fetch_vat_adjust']);
        add_action('wp_ajax_tgs_bctk_vat_pdf', [__CLASS__, 'vat_pdf']);
        add_action('wp_ajax_tgs_bctk_vat_save_lines', [__CLASS__, 'vat_save_lines']);
        add_action('wp_ajax_tgs_bctk_vat_save_note', [__CLASS__, 'vat_save_note']);
        // Xem lại MỘT phiếu bán từ các màn báo cáo bán hàng (chỉ đọc + sửa ghi chú)
        add_action('wp_ajax_tgs_bctk_phieu_view', [__CLASS__, 'phieu_view']);
        // Xem lại MỘT phiếu NHẬP KHO từ 2 màn báo cáo mua hàng (chỉ đọc + sửa ghi chú)
        add_action('wp_ajax_tgs_bctk_phieu_mua_view', [__CLASS__, 'phieu_mua_view']);
        add_action('wp_ajax_tgs_bctk_phieu_mua_save_note', [__CLASS__, 'phieu_mua_save_note']);
        add_action('wp_ajax_tgs_bctk_product_search', [__CLASS__, 'product_search']);
        add_action('wp_ajax_tgs_bctk_product_units', [__CLASS__, 'product_units']);
        add_action('wp_ajax_tgs_bctk_refresh_nonce', [__CLASS__, 'refresh_nonce']);
    }

    /** Sổ chăm sóc khách hàng — mỗi lượt một site, giống các báo cáo khác */
    public static function fetch_cskh()
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
            wp_send_json_success(self::build_cskh_rows($blog_id, $zones, $from, $to));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /**
     * Dựng dòng sổ CSKH cho một site, kèm cột KHO đã ghép nhãn.
     *
     * Cột Kho là điểm hơn hẳn phần mềm cũ: bên đó mỗi lượt chỉ tra được một
     * shop, nên không cần nói rõ đơn ở đâu. Bên mình quét nhiều chi nhánh cùng
     * lúc, thiếu cột này thì nhìn một đống đơn mà không biết của shop nào.
     *
     * Quy tắc ghép nhãn giống hệt Báo cáo tồn kho, để hai màn đọc lên khớp nhau:
     *   site kho  → mã phân kho (chưa gán thì lấy tên site và gắn cờ cảnh báo)
     *   site shop → mã site (không có mã thì lấy tên) — shop không chia phân kho
     */
    public static function build_cskh_rows($blog_id, array $zones, $from, $to)
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
        $site_label   = $site['code'] !== '' ? $site['code'] : $site['name'];

        $raw = TGS_BCTK_Report::site_cskh_rows($blog_id, $zones, $is_warehouse, $from, $to);
        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $info = TGS_BCTK_Report::product_info(array_column($raw, 'sku'));
        /* Cột SL là số lượng theo đơn vị nhỏ nhất, nên ĐVT đi kèm phải là đơn
           vị nhỏ nhất THẬT — lấy từ bảng quy đổi, không tin global_product_unit */
        /* Truyền $blog_id: mỗi site áp một bảng giá riêng — xem base_unit() */
        $base = TGS_BCTK_Report::base_unit(array_column($raw, 'sku'), $blog_id);
        $rows = [];

        foreach ($raw as $r) {
            $zone    = (string) $r['zone'];
            $no_zone = ($is_warehouse && $zone === '');
            $p       = $info[$r['sku']] ?? [];
            $note    = self::extract_order_note($r['ghi_chu']);

            $rows[] = [
                'blog_id' => $blog_id,
                'sale_id' => (int) ($r['sale_id'] ?? 0),
                'kho'     => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone' => $no_zone,
                'pbh'     => (string) $r['pbh'],
                'ngay'    => (string) $r['ngay_mua'],
                'sku'     => (string) $r['sku'],
                'ten'     => (string) ($p['name'] ?? ''),
                'dvt'     => (string) ($base[$r['sku']] ?? ($p['unit'] ?? '')),
                'qty'     => (float) $r['qty'],
                'kh_ma'   => (string) $r['kh_ma'],
                'kh_ten'  => (string) $r['kh_ten'],
                'kh_dt'   => (string) $r['kh_dt'],
                'kh_dchi' => (string) $r['kh_dchi'],
                'kh_ns'   => (string) ($r['kh_ns'] ?? ''),
                'ghi_chu' => $note,
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /** Báo cáo bán hàng / hàng bán trả lại — mỗi lượt một site */
    public static function fetch_sales()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        /* Chỉ nhận đúng ba giá trị; giá trị lạ thì về mặc định là phiếu bán */
        $loai = sanitize_text_field(wp_unslash($_POST['loai'] ?? 'sale'));
        if (!in_array($loai, ['sale', 'return', 'all'], true)) {
            $loai = 'sale';
        }

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
            wp_send_json_success(self::build_sales_rows($blog_id, $zones, $from, $to, $loai));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /** Báo cáo mua hàng — mỗi lượt một site, giống các báo cáo khác */
    public static function fetch_purchase_report()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        $loai = sanitize_text_field(wp_unslash($_POST['loai'] ?? 'buy'));
        if (!in_array($loai, ['buy', 'return', 'all'], true)) {
            $loai = 'buy';
        }

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
            wp_send_json_success(self::build_purchase_rows($blog_id, $zones, $from, $to, $loai));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /**
     * Ghép nhãn kho, tên hàng, nhóm hàng cho báo cáo mua hàng.
     *
     * Dùng chung cách làm tròn và cách tính tiền với báo cáo bán hàng để hai
     * màn không bao giờ lệch nhau — xem chú thích trong build_sales_rows().
     */
    public static function build_purchase_rows($blog_id, array $zones, $from, $to, $loai)
    {
        $blog_id = (int) $blog_id;

        $site = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        if (!self::money_ready()) {
            return ['rows' => [], 'site' => $site,
                    'error' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).'];
        }

        $is_warehouse = TGS_BCTK_Sites::is_warehouse($blog_id);
        $site_label   = $site['code'] !== '' ? $site['code'] : $site['name'];

        $raw = TGS_BCTK_Report::site_purchase_rows($blog_id, $zones, $is_warehouse, $from, $to, $loai);
        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $skus  = array_column($raw, 'sku');
        $info  = TGS_BCTK_Report::product_info($skus);
        $group = TGS_BCTK_Report::product_group($skus);
        $rows  = [];

        foreach ($raw as $r) {
            $zone    = (string) $r['zone'];
            $no_zone = ($is_warehouse && $zone === '');
            $p       = $info[$r['sku']] ?? [];
            $g       = $group[$r['sku']] ?? [];

            /* Dòng xuất (type 2) trong màn này là HÀNG TRẢ LẠI NHÀ CUNG CẤP */
            $is_return = ((string) $r['it'] === '2');

            $qty      = (float) $r['qty'];
            $ck       = (float) $r['chiet_khau'];   // trước thuế, cả dòng
            $thue_pct = (float) $r['thue_pct'];

            $m    = TGS_Money::line($qty, (float) $r['gia'], $ck, $thue_pct);
            $thue = (float) $r['thue'];

            /*
             * ─── KHÔNG LÀM TRÒN, KHÁC HẲN BÁO CÁO BÁN HÀNG ──────────────────
             *
             * Bán hàng làm tròn về đồng vì nhân viên đối chiếu tiền mặt hằng
             * ngày — thấy số lẻ là tưởng lệch quỹ.
             *
             * Mua hàng thì ngược lại: đơn giá nhập suy ra từ tổng tiền hoá đơn
             * nhà cung cấp nên vốn dĩ có phần lẻ (448.446,3đ/lon). Làm tròn về
             * đồng rồi nhân với 10.560 đơn vị là sai vài nghìn — mà đây là giá
             * VỐN, sai giá vốn thì sai lãi gộp của cả kỳ.
             *
             * Phần mềm cũ cũng để lẻ đúng như vậy. Không làm tròn thì phép tính
             * chính xác tuyệt đối, dòng luôn cộng khít, khỏi cần mẹo dồn phần
             * dư vào chiết khấu như bên bán hàng.
             */
            $thanh_tien = $m['tien_hang_sau_ck'] + $thue;
            $tt_chua_ck = $m['tien_hang_truoc_ck'];
            $gia_dvcb   = $m['don_gia_sau_thue'];
            $ck_hien    = $ck * (1 + $thue_pct / 100);

            $ratio   = max(1.0, (float) $r['ratio']);
            $gia_dvt = $gia_dvcb * $ratio;

            $rows[] = [
                'blog_id'  => $blog_id,
                'import_id' => (int) ($r['import_id'] ?? 0),
                'kho'      => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone'  => $no_zone,
                'sku'      => (string) $r['sku'],
                'ten'      => (string) ($p['name'] ?? ''),
                'ngay'     => (string) $r['ngay'],
                'dvcb'     => (string) ($g['dvcb'] ?: ($p['unit'] ?? $r['dvcb_local'])),
                'nhom'     => (string) ($g['nhom'] ?? ''),

                'pnk'      => (string) $r['pnk'],
                'so_hd'    => (string) $r['so_hd'],
                /* Mã lý do lấy từ phiếu; phiếu trả NCC không có nên gán mã riêng */
                'ly_do'    => $is_return ? 'XTNCC' : ((string) ($r['ly_do_ma'] ?: 'NMH1')),
                'ly_do_ten' => $is_return ? 'Xuất trả hàng nhà cung cấp'
                                          : ((string) ($r['ly_do_ten'] ?: '')),
                'tra_lai'  => $is_return,

                'qty'      => $qty,
                /* Đơn giá nhập gốc — trước thuế, trước CK. Đây là con số trên
                   hoá đơn nhà cung cấp, nên để lẻ đúng như đã lưu. */
                'gia_truoc_thue' => (float) $r['gia'],
                'gia'      => $gia_dvcb,
                'gia_dvt'  => $gia_dvt,
                'ck'       => $ck_hien,
                /* Tiền hàng TRƯỚC chiết khấu, trước thuế — cột TT chưa CK */
                'tt_chua_ck' => $tt_chua_ck,
                'tien'     => $thanh_tien,
                /*
                 * ⚠️ NGƯỢC CHIỀU VỚI BÁN HÀNG: mua là CHI tiền nên cộng vào,
                 * trả nhà cung cấp là NHẬN lại tiền nên trừ ra.
                 */
                'chi_thuan' => $is_return ? -$thanh_tien : $thanh_tien,
                'thue'     => $thue,
                'truoc_thue' => $m['tien_hang_sau_ck'],
                /* Giá Net = đơn giá gửi cơ quan thuế (sau CK, trước thuế) */
                'gia_net'  => $m['don_gia_gui_thue'],
                /* CK% để đối chiếu với phần mềm cũ */
                'ck_pct'   => $m['ck_phan_tram'],

                'nv_ten'   => (string) $r['nv_ten'],
                'nv_ma'    => (string) $r['nv_ma'],
                'ncc_ma'   => (string) $r['ncc_ma'],
                'ncc_ten'  => (string) $r['ncc_ten'],
                'ghi_chu'  => (string) $r['ghi_chu'],

                'dvt'      => (string) ($r['dvt_ban'] ?: ($g['dvcb'] ?: ($p['unit'] ?? ''))),
                'sl_dvmr'  => ((float) $r['sl_dvmr']) ?: ($qty / $ratio),
                'so_lo'    => (string) $r['so_lo'],
                'exp'      => (string) ($r['exp_date'] ?? ''),
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /** Tổng hợp mua hàng — gộp theo PHIẾU, mỗi lượt một site */
    public static function fetch_purchase_sum()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        $loai = sanitize_text_field(wp_unslash($_POST['loai'] ?? 'buy'));
        if (!in_array($loai, ['buy', 'return', 'all'], true)) {
            $loai = 'buy';
        }

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
            wp_send_json_success(self::build_purchase_sum_rows($blog_id, $zones, $from, $to, $loai));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /** Ghép nhãn kho, tính còn nợ và trạng thái thanh toán cho từng phiếu */
    public static function build_purchase_sum_rows($blog_id, array $zones, $from, $to, $loai)
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
        $site_label   = $site['code'] !== '' ? $site['code'] : $site['name'];
        $hom_nay      = current_time('Y-m-d');

        $raw = TGS_BCTK_Report::site_purchase_summary_rows(
            $blog_id, $zones, $is_warehouse, $from, $to, $loai
        );
        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $rows = [];
        foreach ($raw as $r) {
            $zone      = (string) ($r['zone'] ?? '');
            $no_zone   = ($is_warehouse && $zone === '');
            $is_return = ((string) $r['lt'] === '16');

            /* Làm tròn về đồng: màn này để đối chiếu công nợ với nhà cung cấp,
               không phải để tra giá vốn — khác báo cáo mua hàng chi tiết. */
            $tong   = round((float) $r['tong_tien']);
            $da_tra = round((float) $r['da_tra']);
            $con_no = $tong - $da_tra;

            /*
             * Trạng thái thanh toán suy từ số còn nợ, không lưu cột riêng —
             * lưu thì phải cập nhật mỗi lần thu chi, quên một chỗ là sai.
             */
            $han_tt = (string) ($r['han_tt'] ?? '');
            if ($con_no <= 0) {
                $tt_tt = 'Đã thanh toán';
            } elseif ($da_tra > 0) {
                $tt_tt = 'Trả một phần';
            } elseif ($han_tt !== '' && $han_tt < $hom_nay) {
                $tt_tt = 'Quá hạn';
            } else {
                $tt_tt = 'Chưa thanh toán';
            }

            $rows[] = [
                'blog_id' => $blog_id,
                'import_id' => (int) ($r['import_id'] ?? 0),
                'kho'     => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone' => $no_zone,
                'pnk'     => (string) $r['pnk'],
                'ngay'    => (string) $r['ngay'],
                'han_tt'  => $han_tt,
                'so_hd'   => (string) $r['so_hd'],
                'hd_ky_hieu' => (string) ($r['hd_ky_hieu'] ?? ''),
                'hd_ngay' => (string) ($r['hd_ngay'] ?? ''),
                'ly_do'   => $is_return ? 'XTNCC' : ((string) ($r['ly_do_ma'] ?: 'NMH1')),
                'ly_do_ten' => $is_return ? 'Xuất trả hàng nhà cung cấp'
                                          : ((string) ($r['ly_do_ten'] ?: '')),
                'tra_lai' => $is_return,
                'ncc_ma'  => (string) $r['ncc_ma'],
                'ncc_ten' => (string) $r['ncc_ten'],
                'nv_ten'  => (string) $r['nv_ten'],
                'nv_ma'   => (string) $r['nv_ma'],
                'tong'    => $tong,
                /*
                 * ⚠️ NGƯỢC CHIỀU VỚI BÁN HÀNG: mua là CHI tiền nên cộng vào,
                 * trả nhà cung cấp là NHẬN lại tiền nên trừ ra.
                 */
                'chi_thuan' => $is_return ? -$tong : $tong,
                'da_tra'  => $da_tra,
                'con_no'  => $con_no,
                'tt_tt'   => $tt_tt,
                /* Quá hạn mà còn nợ thì tô đỏ để nhìn ra ngay */
                'qua_han' => ($con_no > 0 && $han_tt !== '' && $han_tt < $hom_nay),
                'ghi_chu' => self::extract_order_note($r['ghi_chu']),
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /** Tổng hợp bán hàng — gộp theo PHIẾU, mỗi lượt một site */
    public static function fetch_sales_sum()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $zones   = isset($_POST['zones']) && is_array($_POST['zones'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['zones']))
            : [];

        $loai = sanitize_text_field(wp_unslash($_POST['loai'] ?? 'sale'));
        if (!in_array($loai, ['sale', 'return', 'all'], true)) {
            $loai = 'sale';
        }

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
            wp_send_json_success(self::build_sales_sum_rows($blog_id, $zones, $from, $to, $loai));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /** Ghép nhãn kho và tính số còn nợ cho từng phiếu */
    public static function build_sales_sum_rows($blog_id, array $zones, $from, $to, $loai)
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
        $site_label   = $site['code'] !== '' ? $site['code'] : $site['name'];

        $raw = TGS_BCTK_Report::site_sales_summary_rows(
            $blog_id, $zones, $is_warehouse, $from, $to, $loai
        );
        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $rows = [];
        foreach ($raw as $r) {
            $zone      = (string) ($r['zone'] ?? '');
            $no_zone   = ($is_warehouse && $zone === '');
            $is_return = ((string) $r['lt'] === '11');

            /* Làm tròn về đồng cho khớp màn Báo cáo bán hàng và bill POS —
               nhân viên đối chiếu tiền mặt không phải nhìn số lẻ. */
            $tong   = round((float) $r['tong_tien']);
            $da_tra = round((float) $r['da_tra']);

            // Kênh/Lý do xuất: LẤY ĐỘNG nếu phiếu bán có ghi, không thì mới lấy
            // cố định như trước — chỉ áp dụng cho dòng bán, không áp cho hoàn.
            $kenh_dyn  = $is_return ? '' : trim((string) ($r['kenh_dyn'] ?? ''));
            $ly_do_dyn = $is_return ? '' : trim((string) ($r['ly_do_dyn'] ?? ''));

            $rows[] = [
                'blog_id' => $blog_id,
                'sale_id' => (int) ($r['sale_id'] ?? 0),
                'kho'     => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone' => $no_zone,
                'pbh'     => (string) $r['pbh'],
                'ngay'    => (string) $r['ngay'],
                'ly_do'   => $is_return ? 'NTH1' : ($ly_do_dyn !== '' ? $ly_do_dyn : 'XBA'),
                'tra_lai' => $is_return,
                'kh_ma'   => (string) $r['kh_ma'],
                'kh_ten'  => (string) $r['kh_ten'],
                'kh_dt'   => (string) $r['kh_dt'],
                'nv_ten'  => (string) $r['nv_ten'],
                'nv_ma'   => (string) $r['nv_ma'],
                'httt'    => (string) ($r['httt'] ?? ''),
                'tong'    => $tong,
                /*
                 * Doanh thu thuần: phiếu bán cộng vào, phiếu trả TRỪ RA.
                 *
                 * Cột "Tổng tiền" cộng cả hai loại thành một đống nên khi lọc
                 * "Tất cả" thì tổng của nó vô nghĩa — bán 3.062.000, trả
                 * 2.150.000 mà tổng lại ra 5.212.000. Cột này mới là số nhân
                 * viên cần: 912.000.
                 */
                'doanh_thu' => $is_return ? -$tong : $tong,
                'da_tra'  => $da_tra,
                /* Còn nợ = tổng tiền phiếu trừ phần đã thu/chi đã duyệt */
                'con_no'  => $tong - $da_tra,
                'ghi_chu' => self::extract_order_note($r['ghi_chu']),
                'kenh'    => $kenh_dyn !== '' ? $kenh_dyn : 'Gần shop',
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /** Ghép nhãn kho, tên hàng, nhóm hàng và các cột suy ra được */
    public static function build_sales_rows($blog_id, array $zones, $from, $to, $loai)
    {
        $blog_id = (int) $blog_id;

        $site = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        if (!self::money_ready()) {
            /* Thà không ra số còn hơn ra số tự chế lệch với bản kê thuế. */
            return ['rows' => [], 'site' => $site,
                    'error' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).'];
        }

        $is_warehouse = TGS_BCTK_Sites::is_warehouse($blog_id);
        $site_label   = $site['code'] !== '' ? $site['code'] : $site['name'];

        $raw = TGS_BCTK_Report::site_sales_rows($blog_id, $zones, $is_warehouse, $from, $to, $loai);
        if (empty($raw)) {
            return ['rows' => [], 'site' => $site];
        }

        $skus  = array_column($raw, 'sku');
        $info  = TGS_BCTK_Report::product_info($skus);
        $group = TGS_BCTK_Report::product_group($skus);
        /* Truyền $blog_id: mỗi site áp một bảng giá riêng — xem base_unit() */
        $base  = TGS_BCTK_Report::base_unit($skus, $blog_id);
        $rows  = [];

        foreach ($raw as $r) {
            $zone    = (string) $r['zone'];
            $no_zone = ($is_warehouse && $zone === '');
            $p       = $info[$r['sku']] ?? [];
            $g       = $group[$r['sku']] ?? [];

            $is_return = ((string) $r['it'] === '3');
            // Kênh/Lý do xuất động — xem chú thích ở build_sales_sum_rows().
            $kenh_dyn  = $is_return ? '' : trim((string) ($r['kenh_dyn'] ?? ''));
            $ly_do_dyn = $is_return ? '' : trim((string) ($r['ly_do_dyn'] ?? ''));

            /*
             * ─── TIỀN CỦA DÒNG: ĐỂ TGS_Money TÍNH, KHÔNG TỰ NHÂN CHIA ───────
             *
             * price ĐÃ tính theo đơn vị nhỏ nhất, KHÔNG phải theo đơn vị bán —
             * nên tuyệt đối không chia cho tỉ lệ quy đổi.
             *
             * Chứng cứ: số thuế đã lưu trên phiếu phải bằng (cơ sở) × thuế%.
             * Thử ba cơ sở trên 107 dòng có thuế:
             *
             *     SL × giá               khớp 105/107   ← đúng
             *     SL × (giá ÷ tỉ lệ)     khớp  90/107
             *     SL ĐVMR × giá          khớp  89/107
             *
             * ⚠️ CHIẾT KHẤU LƯU TRONG DB LÀ TIỀN TRƯỚC THUẾ. Bản trước của hàm
             * này lấy nó trừ thẳng vào tiền ĐÃ CÓ THUẾ:
             *
             *     sai  : SL × giá_sau_thuế − CK_trước_thuế
             *     đúng : (SL × giá − CK) × (1 + thuế%)
             *
             * Chênh nhau đúng bằng CK × thuế% — mọi dòng có chiết khấu đều bị
             * cộng dư, và cộng dư ÂM THẦM vì dòng không có CK vẫn ra đúng.
             */
            $qty      = (float) $r['qty'];
            $ck       = (float) $r['chiet_khau'];   // trước thuế, cả dòng
            $thue_pct = (float) $r['thue_pct'];

            $m = TGS_Money::line($qty, (float) $r['gia'], $ck, $thue_pct);

            /*
             * Tiền thuế lấy SỐ ĐÃ LƯU chứ không lấy số vừa tính: đó mới là con
             * số đã gửi cơ quan thuế, phải khớp bản kê đã nộp. Hai số này hiện
             * trùng nhau (đã đối chiếu toàn bộ dữ liệu), nếu sau này lệch thì
             * báo cáo phải theo bản đã nộp.
             */
            $thue = round((float) $r['thue']);

            /*
             * ─── LÀM TRÒN VỀ ĐỒNG, THEO ĐÚNG CÁCH POS ĐANG LÀM ──────────────
             *
             * DB lưu 3 số lẻ để không mất chính xác, nhưng BÁO CÁO thì phải ra
             * số chẵn: nhân viên đối chiếu tiền mặt hằng ngày, thấy 429.999,67
             * là tưởng lệch quỹ, trong khi thực thu đúng 430.000.
             *
             * Thứ tự làm tròn quan trọng, làm sai là dòng không cộng được:
             *
             *   1. Thành tiền  — chốt trước, vì đây là tiền THẬT khách trả
             *   2. Đơn giá     — làm tròn từ tiền hàng gốc
             *   3. Chiết khấu  — SUY RA sau cùng = đơn giá × SL − thành tiền
             *
             * Nếu làm tròn cả ba độc lập thì lẻ mỗi thứ một ít rồi lệch nhau:
             * 450.000 × 1 − 20.001 = 429.999, trong khi thành tiền là 430.000.
             * Dồn phần lẻ vào chiết khấu thì dòng luôn cộng khít — đây đúng là
             * cách TGS_POS_Ajax_Order dựng số để in bill.
             */
            $thanh_tien = round($m['tien_hang_sau_ck'] + $thue);

            /* Tiền hàng GỐC (trước CK, sau thuế) — mốc để suy ra đơn giá */
            $goc = round($m['tien_hang_truoc_ck'] * (1 + $thue_pct / 100));

            $gia_dvcb = self::don_gia_lam_tron($goc, $qty, $thanh_tien);

            /*
             * Chiết khấu là phần dư giữa tiền gốc (theo đơn giá ĐÃ làm tròn) và
             * thành tiền — nhờ vậy người đọc nhân tay ra đúng con số trên giấy.
             */
            $ck_hien  = max(0.0, $gia_dvcb * $qty - $thanh_tien);

            /*
             * Đơn giá theo ĐƠN VỊ BÁN (lốc, thùng, vỉ...) — chỉ để người đọc
             * đối chiếu với giá niêm yết trên phiếu, KHÔNG dùng để tính tiền.
             *
             * Nhân lên chứ không chia: price trong DB đã theo đơn vị nhỏ nhất
             * (bẫy 7.2 trong tài liệu). Bán 1 vỉ 4 hộp giá 152.000 thì cột
             * "Đơn giá" là 38.000/hộp, còn cột này là 152.000/vỉ.
             */
            $ratio    = max(1.0, (float) $r['ratio']);
            $gia_dvt  = round($gia_dvcb * $ratio);

            $rows[] = [
                'blog_id'  => $blog_id,
                'sale_id'  => (int) ($r['sale_id'] ?? 0),
                'kho'      => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone'  => $no_zone,
                'sku'      => (string) $r['sku'],
                'ten'      => (string) ($p['name'] ?? ''),
                'ngay'     => (string) $r['ngay'],

                /*
                 * ĐVCB ưu tiên BẢNG QUY ĐỔI (dòng tỉ lệ = 1) vì global_product_unit
                 * khai sai ở nhiều mã — có mã ghi luôn Vi_4 làm đơn vị nhỏ nhất
                 * trong khi nhỏ nhất là Hộp. Xem TGS_BCTK_Report::base_unit().
                 */
                'dvcb'     => (string) ($base[$r['sku']]
                              ?: ($g['dvcb'] ?: ($p['unit'] ?? $r['dvcb_local']))),
                'nhom'     => (string) ($g['nhom'] ?? ''),

                'pbh'      => (string) $r['pbh'],
                /* Mã lý do theo phần mềm cũ: bán = XBA, trả lại = NTH1 — lấy động
                   từ phiếu bán nếu có, không thì mới về mã cố định */
                'ly_do'    => $is_return ? 'NTH1' : ($ly_do_dyn !== '' ? $ly_do_dyn : 'XBA'),
                'tra_lai'  => $is_return,

                'qty'      => $qty,
                /* Đơn giá hiện theo ĐVCB để nhân với số lượng ra đúng thành tiền */
                'gia'      => $gia_dvcb,
                /* Đơn giá theo ĐVT bán — chỉ để đối chiếu, không nhân ra tiền */
                'gia_dvt'  => $gia_dvt,
                'ck'       => $ck_hien,
                'tien'     => $thanh_tien,
                /* Dòng bán cộng vào, dòng trả lại TRỪ RA — xem chú thích ở
                   build_sales_sum_rows() */
                'doanh_thu' => $is_return ? -$thanh_tien : $thanh_tien,
                'thue'     => $thue,
                /*
                 * Tiền hàng sau CK, trước thuế — công thức (2) trong tài liệu.
                 * Lấy hiệu của hai số ĐÃ LÀM TRÒN chứ không làm tròn riêng, để
                 * cột này cộng với cột Thuế ra đúng cột Thành tiền.
                 */
                'truoc_thue' => $thanh_tien - $thue,
                'gia_von'  => '',   // chưa có nguồn, để trống theo yêu cầu

                'nv_ten'   => (string) $r['nv_ten'],
                'nv_ma'    => (string) $r['nv_ma'],
                'kh_ten'   => (string) $r['kh_ten'],
                'kh_ma'    => (string) $r['kh_ma'],
                'kh_dt'    => (string) $r['kh_dt'],
                'ghi_chu'  => (string) $r['ghi_chu'],

                /*
                 * Dòng cũ (hàng tặng, phiếu hoàn trước bản vá) không ghi đơn vị
                 * bán. Tỉ lệ quy đổi của chúng bằng 1 nên lùi về ĐVCB là đúng,
                 * hơn hẳn để trống làm người đọc tưởng thiếu dữ liệu.
                 */
                'dvt'      => (string) ($r['dvt_ban'] ?: ($base[$r['sku']]
                              ?: ($g['dvcb'] ?: ($p['unit'] ?? '')))),
                /*
                 * Dòng cũ bỏ trống SL theo ĐVMR thì suy từ số lượng và tỉ lệ,
                 * thay vì hiện số 0 làm người đọc tưởng không bán được gì.
                 *
                 * ⚠️ Phải ép (float) TRƯỚC khi so: MySQL trả về chuỗi "0.000",
                 * mà chuỗi đó PHP coi là CÓ giá trị nên `?:` không hề nhảy sang
                 * nhánh dự phòng. Chỉ "0" và "" mới là rỗng.
                 */
                'sl_dvmr'  => ((float) $r['sl_dvmr']) ?: ($qty / $ratio),
                'httt'     => (string) ($r['httt'] ?? ''),
                'so_lo'    => (string) $r['so_lo'],
                'exp'      => (string) ($r['exp_date'] ?? ''),
                'kenh'     => $kenh_dyn !== '' ? $kenh_dyn : 'Gần shop',
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /**
     * Lấy chữ người bán/kế toán thật sự gõ ra khỏi local_ledger_note.
     *
     * Phiếu MỚI (từ 2026-08): local_ledger_note CHÍNH LÀ ghi chú, không bọc gì.
     * Phiếu CŨ: "Đơn POS <mã> | Ghi chú: <note>" — bóc lấy phần sau; nếu chỉ có
     * "Đơn POS <mã>" thì trả rỗng.
     *
     * Quy tắc bóc giữ y hệt TGS_POS_Ajax_Order::extract_order_note().
     */
    private static function extract_order_note($raw_note)
    {
        $s = trim((string) $raw_note);
        if ($s === '') {
            return '';
        }

        if (preg_match('/\|\s*Ghi chú:\s*(.+)$/us', $s, $mt)) {
            return trim((string) ($mt[1] ?? ''));
        }

        /* Phiếu cũ không có tiền tố thì cả chuỗi chính là ghi chú */
        if (strpos($s, 'Đơn POS ') !== 0) {
            return $s;
        }

        /* Chỉ có tiêu đề — người bán không ghi gì */
        return '';
    }

    /**
     * Cấp lại nonce cho trang đang mở.
     *
     * Nonce được nhúng vào HTML lúc render và chỉ sống 24 giờ, trong khi màn
     * báo cáo nằm trong hệ thống tab (iframe) nên hoàn toàn có thể mở liên tục
     * nhiều ngày. Quá hạn thì admin-ajax trả 403 cho MỌI lượt gọi — và vì phía
     * JS trước đây nuốt lỗi, người dùng chỉ thấy "0 dòng" y như không có số
     * liệu, bấm Tìm kiếm lại vẫn thế (nonce cũ vẫn nằm trong biến), chỉ tải
     * lại trang mới hết. Đúng hiện tượng đang gặp.
     *
     * Endpoint này CỐ Ý không gọi check_ajax_referer: nonce cần kiểm thì đã hết
     * hạn rồi, kiểm nữa là bế tắc. Chốt chặn ở đây là phiên đăng nhập —
     * wp_ajax_ chỉ chạy cho người đã đăng nhập — cộng capability. Trang khác
     * miền có thể gọi nhưng KHÔNG đọc được phản hồi (không có CORS) nên không
     * moi được nonce ra.
     */
    public static function refresh_nonce()
    {
        if (!is_user_logged_in() || !current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Phiên đăng nhập đã kết thúc'], 401);
        }

        wp_send_json_success(['nonce' => wp_create_nonce(self::NONCE)]);
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

    /* ═══════════════════════════════════════════════════════════════════════
     * QUẢN LÝ VAT — Phiếu xuất bán (VAT) & Phiếu điều chỉnh giảm (VAT)
     *
     * Mỗi lượt AJAX xử lý ĐÚNG MỘT SITE (bctk-filter.js chạy theo batch, giống
     * mọi báo cáo BC_TK). Tiền LUÔN tính lại từ item qua TGS_Money — không đọc
     * vi.total_*. Xem docs/bao-cao-vat-phieu-xuat-ban-va-dieu-chinh.md.
     * ═══════════════════════════════════════════════════════════════════════ */

    /** Đọc + kiểm tham số chung cho hai màn VAT */
    private static function vat_common_params()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem báo cáo']);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }

        $today = current_time('Y-m-d');
        $from  = self::sanitize_date($_POST['date_from'] ?? '', $today);
        $to    = self::sanitize_date($_POST['date_to'] ?? '', $today);
        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }

        $scope = sanitize_text_field(wp_unslash($_POST['bill_scope'] ?? 'normal'));
        if (!in_array($scope, ['normal', 'internal', 'all'], true)) {
            $scope = 'normal';
        }

        $vat_filter = sanitize_text_field(wp_unslash($_POST['vat_filter'] ?? 'all'));
        if (!in_array($vat_filter, ['all', 'has_vat', 'no_vat', 'vat_error'], true)) {
            $vat_filter = 'all';
        }

        return compact('blog_id', 'from', 'to', 'scope', 'vat_filter');
    }

    /** Mã shop (tgs_site_code) của một blog — cho cột đầu bảng */
    private static function site_code_of($blog_id)
    {
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ((int) $s['blog_id'] === (int) $blog_id) {
                return (string) ($s['code'] !== '' ? $s['code'] : $s['name']);
            }
        }
        return '';
    }

    /**
     * Tiền của MỘT dòng hàng — làm tròn y hệt build_sales_rows() và POS.
     *
     * Trả về: tt_chua_thue, thue, thanh_tien (đều đã làm tròn đồng), ck (thô,
     * trước thuế), don_gia_gui_thue, tax_pct, is_kct.
     */
    private static function vat_line_money(array $it)
    {
        $qty      = (float) ($it['qty'] ?? 0);
        $gia      = (float) ($it['gia'] ?? 0);
        $ck       = (float) ($it['chiet_khau'] ?? 0);
        $raw_pct  = $it['thue_pct'];
        $is_kct   = (int) ($it['is_kct'] ?? 0) === 1;
        $tax_pct  = ($raw_pct === null || $raw_pct === '') ? 0.0 : (float) $raw_pct;

        $m    = TGS_Money::line($qty, $gia, $ck, $tax_pct);
        $thue = round((float) ($it['thue'] ?? 0));
        $thanh_tien = round($m['tien_hang_sau_ck'] + $thue);
        $tt_chua_thue = $thanh_tien - $thue;

        return [
            'tt_chua_thue'      => $tt_chua_thue,
            'thue'              => $thue,
            'thanh_tien'        => $thanh_tien,
            // CK thô (trước thuế, cả dòng) — để cộng "Tổng chiết khấu"
            'ck'                => $ck,
            // CK hiển thị theo bill (sau thuế) — cho cột CK trong modal
            'ck_hien'           => round($ck * (1 + $tax_pct / 100)),
            'don_gia_gui_thue'  => round((float) $m['don_gia_gui_thue']),
            // Đơn giá POS hiển thị (trước CK, sau thuế) — quen mắt kế toán
            'don_gia_sau_thue'  => round((float) $m['don_gia_sau_thue']),
            'tax_pct'           => $is_kct ? null : $tax_pct,
            'tax_pct_raw'       => ($raw_pct === null || $raw_pct === '') ? null : (float) $raw_pct,
            'is_kct'            => $is_kct,
        ];
    }

    /** Thuế suất đại diện cho cả phiếu (ưu tiên mức khác 8%) */
    private static function vat_representative_rate(array $pcts, $has_kct, $has_taxed)
    {
        $pcts = array_values(array_unique(array_filter($pcts, static function ($p) {
            return $p !== null;
        })));

        if (empty($pcts)) {
            return $has_kct ? 'KCT' : ($has_taxed ? 8 : 0);
        }
        if (count($pcts) === 1) {
            return $pcts[0] + 0;
        }
        foreach ($pcts as $p) {
            if ((float) $p !== 8.0) {
                return $p + 0;
            }
        }
        return 8;
    }

    /** Lấy giá trị đầu tiên có thật trong JSON payload theo danh sách đường dẫn */
    private static function parse_payload_path($payload, array $paths)
    {
        $data = json_decode((string) $payload, true);
        if (!is_array($data)) {
            return '';
        }
        foreach ($paths as $path) {
            $cur = $data;
            foreach ($path as $key) {
                if (is_array($cur) && array_key_exists($key, $cur)) {
                    $cur = $cur[$key];
                } else {
                    $cur = null;
                    break;
                }
            }
            if (is_scalar($cur) && trim((string) $cur) !== '') {
                return (string) $cur;
            }
        }
        return '';
    }

    /** Bóc số hoá đơn từ issue_response_payload nếu cột viettel_invoice_no trống */
    private static function parse_invoice_no($payload, $fallback)
    {
        $fallback = trim((string) $fallback);
        if ($fallback !== '') {
            return $fallback;
        }
        return self::parse_payload_path($payload, [
            ['result', 'invoiceNo'], ['data', 'invoiceNo'],
            ['result', 'invoiceNumber'], ['data', 'invoiceNumber'],
            ['invoiceNo'], ['invoiceNumber'],
        ]);
    }

    /**
     * Bồi tên hàng cho dòng item khi cache trên phiếu trống — lấy từ catalog
     * global, một truy vấn cho cả loạt phiếu (giống các báo cáo BC_TK khác).
     */
    private static function enrich_item_names(array &$raw)
    {
        $skus = [];
        foreach ($raw as $r) {
            foreach ((array) ($r['items'] ?? []) as $it) {
                $sku = trim((string) ($it['sku'] ?? ''));
                if ($sku !== '') {
                    $skus[$sku] = true;
                }
            }
        }
        if (empty($skus)) {
            return;
        }

        $info = TGS_BCTK_Report::product_info(array_keys($skus));
        foreach ($raw as &$r) {
            if (empty($r['items'])) {
                continue;
            }
            foreach ($r['items'] as &$it) {
                if (trim((string) ($it['ten'] ?? '')) === '') {
                    $it['ten'] = (string) ($info[(string) ($it['sku'] ?? '')]['name'] ?? '');
                }
                if (trim((string) ($it['dvt'] ?? '')) === '') {
                    $it['dvt'] = (string) ($info[(string) ($it['sku'] ?? '')]['unit'] ?? '');
                }
            }
            unset($it);
        }
        unset($r);
    }

    /**
     * Dựng một dòng bảng (đủ 32 cột + meta cho modal) từ một phiếu thô.
     *
     * @param array  $r         dòng thô từ site_vat_*_rows()
     * @param string $ma_shop   mã shop
     * @param string $ly_do     'XBA' (xuất bán) | 'NTH1' (nhập trả hàng — điều chỉnh giảm)
     * @param float  $sign      1 (bán) | -1 (điều chỉnh giảm)
     */
    private static function build_vat_row(array $r, $ma_shop, $ly_do, $sign)
    {
        $is_adjust = ($sign < 0);

        // Dấu cho báo cáo điều chỉnh giảm; tránh -0 lọt ra JSON
        $s = static function ($v) use ($sign) {
            $v = $v * $sign;
            return ($v == 0.0) ? 0 : $v;
        };

        $tt_chua_thue = 0.0;
        $tong_thue    = 0.0;
        $thanh_tien   = 0.0;
        $tong_ck      = 0.0;
        $pcts = [];
        $has_kct = false;
        $has_taxed = false;
        $items = [];
        $stt = 1;

        foreach ((array) ($r['items'] ?? []) as $it) {
            $mo = self::vat_line_money($it);
            $tt_chua_thue += $mo['tt_chua_thue'];
            $tong_thue    += $mo['thue'];
            $thanh_tien   += $mo['thanh_tien'];
            $tong_ck      += $mo['ck'];
            $pcts[] = $mo['tax_pct'];
            if ($mo['is_kct']) {
                $has_kct = true;
            }
            if (!$mo['is_kct'] && $mo['tax_pct'] > 0) {
                $has_taxed = true;
            }

            $_ratio = max(1.0, (float) ($it['ratio'] ?? 1));
            $_sl_unit = (float) ($it['sl_dvt'] ?? 0);
            if ($_sl_unit <= 0) { $_sl_unit = (float) ($it['qty'] ?? 0) / $_ratio; }

            $items[] = [
                'stt'            => $stt++,
                // Cột kế toán quen nhìn (bố cục phần mềm cũ), giống giỏ hàng tgs_pos
                'ma_hang'        => (string) ($it['sku'] ?? ''),
                'ten'            => (string) ($it['ten'] ?? ''),
                'kho'            => $ma_shop,
                'dvt'            => (string) ($it['dvt'] ?? ''),
                'ratio'          => $_ratio,
                // SL = theo ĐVT bán (lốc/vỉ/thùng); SL ĐVCB = quy về đơn vị nhỏ nhất
                'sl'             => $s($_sl_unit),
                'sl_dvcb'        => $s((float) ($it['qty'] ?? 0)),
                // Đơn giá = giá 1 ĐVT bán (trước CK, sau thuế) — như POS hiển thị
                'don_gia'        => $s(round($mo['don_gia_sau_thue'] * $_ratio)),
                'don_gia_gui_thue' => $mo['don_gia_gui_thue'],
                'ck'             => $s($mo['ck_hien']),
                'tien_chua_thue' => $s($mo['tt_chua_thue']),
                'thue_suat'      => $mo['is_kct'] ? 'KCT'
                    : ($mo['tax_pct_raw'] === null ? 'Chưa khai' : ($mo['tax_pct'] + 0)),
                'tien_thue'      => $s($mo['thue']),
                'thanh_tien'     => $s($mo['thanh_tien']),
                'so_lo'          => (string) ($it['lot_code'] ?? ''),
                'exp'            => self::clean_datetime($it['exp_date'] ?? ''),
                'ghi_chu'        => (string) ($it['li_note'] ?? ''),
                'is_gift'        => (int) ($it['gift_type'] ?? 0) === 1,
                // id dòng — để base sửa/xoá dòng sau này bám vào
                'item_id'        => (int) ($it['id'] ?? 0),
            ];
        }

        $rate = self::vat_representative_rate($pcts, $has_kct, $has_taxed);

        $vat_state    = strtolower(trim((string) ($r['vat_state'] ?? '')));
        $queue_status = strtolower(trim((string) ($r['queue_status'] ?? '')));
        $has_vat      = !empty($r['vi_id']) || $vat_state !== ''
            || ($is_adjust && $queue_status !== '' && $queue_status !== 'pending');

        /*
         * Phiếu điều chỉnh chưa phát hành thì chưa có invoice_state — suy nhãn
         * trạng thái từ trạng thái hàng đợi để kế toán biết việc đang ở đâu.
         */
        $state_label = TGS_BCTK_Report::vat_state_label($vat_state, $has_vat);
        if ($is_adjust && $vat_state === '') {
            $map = [
                'pending'  => 'Chờ xử lý điều chỉnh',
                'blocked'  => 'Đang xử lý',
                'error'    => 'Gửi lỗi VAT',
                'skipped'  => 'Bỏ qua (không gửi thuế)',
                'done'     => 'Hóa đơn có chữ ký số',
            ];
            $state_label = $map[$queue_status] ?? 'Chờ xử lý điều chỉnh';
        }

        // Người mua: ưu tiên bản ghi VAT / buyer meta, rồi tới khách của phiếu
        $mua_mst  = self::first_nonempty([$r['vi_buyer_mst'] ?? '', $r['b_mst'] ?? '', $r['kh_mst'] ?? '']);
        $mua_ten  = self::first_nonempty([$r['b_name'] ?? '', $r['kh_ten'] ?? '']);
        $mua_dchi = self::first_nonempty([$r['b_addr'] ?? '', $r['kh_dchi'] ?? '']);
        $mua_mail = self::first_nonempty([$r['b_email'] ?? '', $r['kh_email'] ?? '']);
        $mua_dt   = self::first_nonempty([$r['b_phone'] ?? '', $r['kh_dt'] ?? '']);

        /*
         * Cột "Tên công ty bên mua" chỉ có nghĩa khi khách LÀ đơn vị. Nhãn nội
         * bộ / nhãn bán lẻ ("Khách lẻ", "Bán cho người tiêu dùng") KHÔNG được
         * đổ vào đây — để trống, giống hoá đơn.
         */
        $mua_cty_raw = self::first_nonempty([$r['b_company'] ?? '', $r['vi_buyer_name'] ?? '', $r['kh_ten'] ?? '']);
        $mua_cty = self::is_placeholder_name($mua_cty_raw) ? '' : $mua_cty_raw;

        /*
         * Thông tin bên bán lấy từ SNAPSHOT cấu hình Viettel của chính hoá đơn
         * (wp_tgs_viettel_invoice_config_snapshots) để đối chiếu về sau vẫn
         * đúng; chưa có hoá đơn thì lấy cấu hình cụm đang hiệu lực. Xem
         * TGS_BCTK_Report::seller_info().
         */
        $seller = TGS_BCTK_Report::seller_info((int) $r['_blog_id'], (int) ($r['vi_id'] ?? 0));

        // MST bên bán: snapshot trống thì lấy đúng cái đã gửi CQT trong payload
        $seller_mst = $seller['mst'];
        if (trim((string) $seller_mst) === '') {
            $seller_mst = self::parse_payload_path($r['issue_payload'] ?? '', [
                ['result', 'supplierTaxCode'], ['data', 'supplierTaxCode'], ['supplierTaxCode'],
            ]);
        }

        $so_hd = $is_adjust
            ? (string) ($r['so_hd'] ?? '')
            : self::parse_invoice_no($r['issue_payload'] ?? '', $r['so_hd'] ?? '');

        $ghi_chu = self::extract_order_note($r['ghi_chu'] ?? '');
        if ($is_adjust && trim((string) ($r['so_hd_goc'] ?? '')) !== '') {
            $ghi_chu = trim('HĐ gốc: ' . $r['so_hd_goc'] . ($ghi_chu !== '' ? ' — ' . $ghi_chu : ''));
        }

        return [
            'ma_shop'        => $ma_shop,
            // Seri/Mẫu/HTTT: bản ghi hoá đơn trống thì lấy mặc định của cụm cấu hình
            'seri'           => self::first_nonempty([$r['seri'] ?? '', $seller['series'] ?? '']),
            'mau_hd'         => self::first_nonempty([$r['mau_hd'] ?? '', $seller['template'] ?? '']),
            'httt'           => self::first_nonempty([$r['httt'] ?? '', $seller['payment'] ?? '']),
            'ma_kh'          => (string) ($r['kh_dt'] ?? ''),
            'so_hd'          => $so_hd,
            'ghi_chu'        => $ghi_chu,
            'tt_chua_thue'   => $s($tt_chua_thue),
            'ngay_hd'        => self::clean_datetime($r['ngay_hd'] ?? ''),
            'tong_thue'      => $s($tong_thue),
            'thanh_tien'     => $s($thanh_tien),
            'thanh_tien_chu' => TGS_BCTK_Report::doc_tien_bang_chu($s($thanh_tien)),
            'tong_ck'        => $s($tong_ck),
            'ty_le_thue'     => $rate,
            'ten_cty_mua'    => $mua_cty,
            'ten_kh'         => TGS_BCTK_Report::retail_buyer_name($mua_ten, $mua_mst),
            'dchi_mua'       => $mua_dchi,
            'email_mua'      => $mua_mail,
            'dt_mua'         => $mua_dt,
            'mst_mua'        => $mua_mst,
            'dchi_ban'       => $seller['addr'],
            'ten_cty_ban'    => $seller['name'],
            'dt_ban'         => $seller['phone'],
            'mst_ban'        => $seller_mst,
            'ngay_xuat'      => self::clean_datetime($r['ngay_xuat'] ?? ''),
            'so_phieu_xuat'  => (string) ($r['code'] ?? $r['return_code'] ?? ''),
            'nv_ten'         => (string) ($r['nv_ten'] ?? ''),
            'ly_do'          => $ly_do,
            'trang_thai_vat' => $state_label,
            'so_so'          => '',
            'sl_ban_ghi'     => count($items),
            'user_id'        => (int) ($r['user_id'] ?? 0),

            // ── meta (không phải cột hiển thị) ──
            'blog_id'    => (int) $r['_blog_id'],
            'sale_id'    => (int) ($r['sale_id'] ?? 0),
            'is_z'       => (int) ($r['is_z'] ?? 0),
            'has_vat'    => $has_vat ? 1 : 0,
            /*
             * Phiếu bán đã có phiếu hoàn con (hoàn một phần / toàn phần,
             * local_ledger_type = 11 trỏ về phiếu này). Khi đó KHÓA sửa dòng
             * hàng (số liệu đã bị phiếu hoàn tham chiếu) — ghi chú vẫn sửa được.
             */
            'has_return' => (int) ($r['has_return'] ?? 0) > 0 ? 1 : 0,
            'vat_state'  => $vat_state,
            'invoice_no' => $so_hd,
            'queue_status' => (string) ($r['queue_status'] ?? ''),
            /*
             * Màn "DS Gửi Thuế" của chính shop — nơi luồng gửi lại / tách bill /
             * chuyển bill / sửa phiếu chạy ĐÚNG NATIVE (hằng số bảng của tgs_pos
             * bám theo site, không gọi chéo site được — xem vat_pdf()). Kế toán
             * bấm hành động "chưa phát hành" là mở tab này.
             */
            'pos_tax_url' => get_home_url((int) $r['_blog_id'], '/pos-viettel-tax/'),
            /*
             * Màn "Lịch sử đơn hàng" của chính shop — nút "Hoàn hàng" mở tab này
             * và bung sẵn đúng đơn (deep-link ?open_code=&open_date=).
             */
            'pos_orders_url' => get_home_url((int) $r['_blog_id'], '/pos-orders/'),
            'items'      => $items,
        ];
    }

    private static function first_nonempty(array $vals)
    {
        foreach ($vals as $v) {
            if (trim((string) $v) !== '') {
                return (string) $v;
            }
        }
        return '';
    }

    /** DATETIME rỗng / 0000-00-00 → chuỗi rỗng */
    private static function clean_datetime($v)
    {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, '0000-00-00') === 0) {
            return '';
        }
        return $v;
    }

    /** "Khách lẻ" / "Bán cho người tiêu dùng" / rỗng — nhãn nội bộ, không phải tên đơn vị */
    private static function is_placeholder_name($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }
        if (class_exists('TGS_Viettel_Invoice_Flow_Service')) {
            if (TGS_Viettel_Invoice_Flow_Service::is_placeholder_buyer_name($name)) {
                return true;
            }
            if ($name === TGS_Viettel_Invoice_Flow_Service::retail_buyer_label()) {
                return true;
            }
        }
        $folded = function_exists('remove_accents') ? remove_accents($name) : $name;
        $folded = strtolower(trim(preg_replace('/\s+/', ' ', $folded)));
        return in_array($folded, [
            'khach le', 'khach hang le', 'khach vang lai', 'ban cho nguoi tieu dung',
        ], true);
    }

    /** Áp bộ lọc "thông tin VAT" lên một dòng đã dựng */
    private static function vat_row_passes_filter(array $row, $vat_filter)
    {
        if ($vat_filter === 'all') {
            return true;
        }
        if ($vat_filter === 'has_vat') {
            return !empty($row['has_vat']);
        }
        if ($vat_filter === 'no_vat') {
            return empty($row['has_vat']);
        }
        // vat_error
        $err_states = TGS_BCTK_Report::VAT_ERROR_STATES;
        return !empty($row['has_vat'])
            && (in_array($row['vat_state'], $err_states, true)
                || in_array($row['queue_status'], ['error', 'blocked'], true));
    }

    public static function fetch_vat_sales()
    {
        $p = self::vat_common_params();

        if (!self::money_ready()) {
            wp_send_json_error(['message' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).']);
        }

        try {
            $raw = TGS_BCTK_Report::site_vat_sales_rows($p['blog_id'], $p['from'], $p['to'], $p['scope']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $p['blog_id']]);
        }

        self::enrich_item_names($raw);
        $ma_shop = self::site_code_of($p['blog_id']);
        $rows = [];
        foreach ($raw as $r) {
            $r['_blog_id'] = $p['blog_id'];
            $row = self::build_vat_row($r, $ma_shop, 'XBA', 1.0);
            if (self::vat_row_passes_filter($row, $p['vat_filter'])) {
                $rows[] = $row;
            }
        }

        wp_send_json_success(['rows' => $rows]);
    }

    public static function fetch_vat_adjust()
    {
        $p = self::vat_common_params();

        if (!self::money_ready()) {
            wp_send_json_error(['message' => 'Thiếu lớp tính tiền TGS_Money (plugin tgs_shop_management).']);
        }

        try {
            $raw = TGS_BCTK_Report::site_vat_adjust_rows($p['blog_id'], $p['from'], $p['to'], $p['scope']);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $p['blog_id']]);
        }

        self::enrich_item_names($raw);
        $ma_shop = self::site_code_of($p['blog_id']);
        $rows = [];
        foreach ($raw as $r) {
            $r['_blog_id'] = $p['blog_id'];
            $row = self::build_vat_row($r, $ma_shop, 'NTH1', -1.0);
            if (self::vat_row_passes_filter($row, $p['vat_filter'])) {
                $rows[] = $row;
            }
        }

        wp_send_json_success(['rows' => $rows]);
    }

    /**
     * PDF hoá đơn Viettel cho một phiếu bán — chạy ĐÚNG SITE của phiếu.
     *
     * KHÔNG gọi lại endpoint POS tgs_viettel_pos_preview_invoice_pdf: endpoint
     * đó dùng hằng số TGS_TABLE_* đã định nghĩa theo prefix của site đang đứng
     * (site tổng), nên gọi chéo site khác là tra nhầm bảng → "không tìm thấy
     * hoá đơn". Ở đây switch_to_blog rồi đọc bảng theo prefix thật, cấu hình
     * Viettel lấy từ SNAPSHOT của chính hoá đơn.
     */
    public static function vat_pdf()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem PDF hoá đơn.'], 403);
        }

        $blog_id = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : 0;
        $sale_id = isset($_POST['sale_ledger_id']) ? (int) $_POST['sale_ledger_id'] : 0;
        if ($blog_id <= 0 || $sale_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id hoặc sale_ledger_id.'], 400);
        }
        if (!class_exists('TGS_Viettel_Invoice_Plugin')) {
            wp_send_json_error(['message' => 'Chưa bật plugin tgs-viettel-invoice.'], 500);
        }

        $switched = false;
        if (function_exists('switch_to_blog') && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        try {
            global $wpdb;
            $vi_table = $wpdb->prefix . 'local_viettel_invoice';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT local_viettel_invoice_id, invoice_state, template_code,
                        viettel_invoice_no, issue_response_payload
                   FROM {$vi_table}
                  WHERE sale_ledger_id = %d
                    AND (is_deleted = 0 OR is_deleted IS NULL)
                  ORDER BY local_viettel_invoice_id DESC LIMIT 1",
                $sale_id
            ), ARRAY_A);

            if (empty($row)) {
                wp_send_json_error(['message' => 'Đơn này chưa có hoá đơn Viettel.'], 404);
            }
            if (strtolower((string) ($row['invoice_state'] ?? '')) !== 'done') {
                wp_send_json_error(['message' => 'Hoá đơn chưa gửi CQT thành công nên chưa có bản thể hiện PDF.'], 400);
            }

            $invoice_no = trim((string) ($row['viettel_invoice_no'] ?? ''));
            if ($invoice_no === '') {
                $invoice_no = self::parse_payload_path($row['issue_response_payload'] ?? '', [
                    ['result', 'invoiceNo'], ['data', 'invoiceNo'], ['invoiceNo'],
                ]);
            }
            if ($invoice_no === '') {
                wp_send_json_error(['message' => 'Không lấy được số hoá đơn để tải PDF.'], 400);
            }

            $vi_id    = (int) $row['local_viettel_invoice_id'];
            $settings = (array) TGS_Viettel_Invoice_Plugin::get_settings_for_invoice($vi_id, $blog_id);
            $mst      = (string) ($settings['supplier_tax_code'] ?? '');
            $template = trim((string) ($row['template_code'] ?? ''));
            if ($template === '') {
                $template = (string) ($settings['default_template_code'] ?? '1/770');
            }
            if ($mst === '') {
                wp_send_json_error(['message' => 'Thiếu MST người bán trong cấu hình Viettel của shop.'], 400);
            }

            $res = self::viettel_representation_file($settings, $mst, $invoice_no, $template);
            if (empty($res['success'])) {
                wp_send_json_error([
                    'message'   => $res['message'] ?? 'Không lấy được PDF hoá đơn.',
                    'http_code' => (int) ($res['http_code'] ?? 0),
                ], 400);
            }

            $safe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice_no);
            wp_send_json_success([
                'invoice_no'        => $invoice_no,
                'file_name'         => $mst . '-' . $safe . '.pdf',
                'file_bytes_base64' => $res['file_bytes_base64'],
            ]);
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /**
     * Gọi API getInvoiceRepresentationFile của Viettel.
     *
     * Chép tối thiểu từ TGS_Viettel_Invoice::fetch_invoice_representation_file()
     * (hàm đó private nên không gọi trực tiếp được). Sửa một bên nhớ dòm bên kia.
     */
    private static function viettel_representation_file(array $settings, $mst, $invoice_no, $template, $file_type = 'PDF')
    {
        $base = untrailingslashit((string) ($settings['api_base_url'] ?? ''));
        if ($base === '') {
            return ['success' => false, 'message' => 'Thiếu api_base_url trong cấu hình Viettel.'];
        }

        $headers = ['Content-Type' => 'application/json', 'Connection' => 'keep-alive'];
        if (($settings['auth_mode'] ?? 'basic') === 'token') {
            $headers['Authorization'] = 'Bearer ' . ($settings['access_token'] ?? '');
        } else {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                ($settings['username'] ?? '') . ':' . ($settings['password'] ?? '')
            );
        }

        $response = wp_remote_post(
            $base . '/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile',
            [
                'headers'     => $headers,
                'body'        => wp_json_encode([
                    'supplierTaxCode' => (string) $mst,
                    'invoiceNo'       => (string) $invoice_no,
                    'templateCode'    => (string) $template,
                    'fileType'        => (string) $file_type,
                ], JSON_UNESCAPED_UNICODE),
                'timeout'     => 60,
                'httpversion' => '1.1',
                'sslverify'   => !empty($settings['verify_ssl']),
            ]
        );

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return ['success' => false, 'http_code' => $code,
                    'message' => 'Lấy file hoá đơn thất bại (HTTP ' . $code . ').'];
        }

        $bytes = (string) ($data['fileToBytes'] ?? '');
        if ($bytes === '') {
            return ['success' => false, 'http_code' => $code,
                    'message' => sanitize_text_field($data['description'] ?? $data['message'] ?? 'API không trả về fileToBytes.')];
        }

        return ['success' => true, 'http_code' => $code, 'file_bytes_base64' => $bytes];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * SỬA PHIẾU (kế toán cấp cao) — chỉ khi CHƯA phát hành hoá đơn
     *
     * Chạy ĐÚNG SITE của phiếu bằng switch_to_blog + đọc/ghi bảng theo
     * $wpdb->prefix tươi (KHÔNG dùng hằng số TGS_TABLE_* — chúng bám site tổng,
     * xem vat_pdf()). Tiền quy đổi & cộng tổng qua TGS_Money — luật ở
     * tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md.
     *
     * ⚠️ Chỉ đụng dòng hàng + tổng tiền phiếu. Tồn kho HỆ THỐNG NÀY suy từ
     * chính local_ledger_item nên tự khớp; còn PHIẾU THU thì không tự chỉnh —
     * nếu tổng đổi mà tiền đã thu khác thì trả cảnh báo để kế toán xử tay.
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * Vào ngữ cảnh sửa: switch_to_blog, kiểm quyền sửa, trả phiếu + phiếu xuất con.
     * Gọi wp_send_json_error nếu không được phép.
     *
     * @return array{sale: array, export_id: int}
     */
    private static function vat_edit_context($blog_id, $sale_id, &$switched, $block_if_returned = false)
    {
        $blog_id = (int) $blog_id;
        $sale_id = (int) $sale_id;
        if ($blog_id <= 0 || $sale_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id hoặc sale_id.'], 400);
        }

        $switched = false;
        if (function_exists('switch_to_blog') && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        global $wpdb;
        $L = $wpdb->prefix . 'local_ledger';
        $VI = $wpdb->prefix . 'local_viettel_invoice';

        $sale = $wpdb->get_row($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_code, local_ledger_note, local_ledger_type,
                    local_ledger_parent_id, local_ledger_meta_id, user_id
               FROM {$L}
              WHERE local_ledger_id = %d AND local_ledger_type = 10
                AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1",
            $sale_id
        ), ARRAY_A);
        if (empty($sale)) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu bán.'], 404);
        }

        // Bill Z (nội bộ) → sửa thoải mái. Ngược lại: cấm sửa nếu ĐÃ phát hành.
        $is_z = false;
        $pid = (int) ($sale['local_ledger_parent_id'] ?? 0);
        if ($pid > 0) {
            $pcode = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT local_ledger_code FROM {$L} WHERE local_ledger_id = %d AND local_ledger_type = 10 LIMIT 1",
                $pid
            ));
            $suffix = class_exists('TGS_BCTK_Report') ? strtoupper(TGS_BCTK_Report::promo_suffix()) : 'Z';
            $is_z = $pcode !== '' && strtoupper(trim($sale['local_ledger_code'])) === strtoupper(trim($pcode)) . $suffix;
        }

        if (!$is_z
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $VI)) === $VI) {
            $state = strtolower((string) $wpdb->get_var($wpdb->prepare(
                "SELECT invoice_state FROM {$VI}
                  WHERE sale_ledger_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)
                  ORDER BY local_viettel_invoice_id DESC LIMIT 1",
                $sale_id
            )));
            if (in_array($state, ['done', 'issued'], true)) {
                wp_send_json_error([
                    'message' => 'Phiếu đã phát hành hoá đơn (' . $state . ') — không sửa trực tiếp được. '
                        . 'Dùng "Điều chỉnh" hoặc "Thay thế".',
                ], 409);
            }
        }

        /*
         * Đã có phiếu hoàn con (hoàn một phần / toàn phần, type 11 trỏ về phiếu
         * bán này) → KHÓA sửa dòng hàng: số liệu dòng đã bị phiếu hoàn tham
         * chiếu, sửa tiếp sẽ lệch. Ghi chú thì vẫn cho ($block_if_returned = false).
         */
        if ($block_if_returned) {
            $ret = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$L}
                  WHERE local_ledger_parent_id = %d AND local_ledger_type = 11
                    AND (is_deleted = 0 OR is_deleted IS NULL)",
                $sale_id
            ));
            if ($ret > 0) {
                wp_send_json_error([
                    'message' => 'Phiếu đã có phiếu hoàn con — không sửa dòng hàng được nữa. '
                        . 'Chỉ sửa được ghi chú.',
                ], 409);
            }
        }

        // Phiếu xuất con (type 2, cha = phiếu bán) — nơi dòng hàng thật sự nằm
        $export_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT local_ledger_id FROM {$L}
              WHERE local_ledger_parent_id = %d AND local_ledger_type = 2
                AND (is_deleted = 0 OR is_deleted IS NULL)
              ORDER BY local_ledger_id ASC LIMIT 1",
            $sale_id
        ));

        return ['sale' => $sale, 'export_id' => $export_id];
    }

    /**
     * Xem lại MỘT phiếu bán từ các màn báo cáo bán hàng (Sổ CSKH, Báo cáo bán
     * hàng, Tổng hợp bán hàng). CHỈ ĐỌC — modal chỉ cho sửa ghi chú, không cho
     * sửa dòng hàng (việc đó nhạy cảm, phải vào Bán hàng → Quản lý VAT).
     *
     * Dùng lại đúng luồng dựng payload của màn VAT (vat_row_for_sale) nên modal
     * hiện y hệt: chứng từ, bên bán/bên mua, dòng hàng, tổng tiền theo TGS_Money.
     * Chạy chéo site an toàn (site_vat_sales_rows tự bám prefix theo blog_id).
     */
    public static function phieu_view()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem phiếu.'], 403);
        }
        if (!self::money_ready()) {
            wp_send_json_error(['message' => 'Thiếu lớp tính tiền TGS_Money.'], 500);
        }

        $blog_id = (int) ($_POST['blog_id'] ?? 0);
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        if ($blog_id <= 0 || $sale_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id hoặc sale_id.'], 400);
        }

        $row = self::vat_row_for_sale($blog_id, $sale_id);
        if (!$row) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu bán.'], 404);
        }
        wp_send_json_success(['row' => $row]);
    }

    /** Dựng lại payload của MỘT phiếu sau khi sửa (để modal cập nhật tại chỗ) */
    private static function vat_row_for_sale($blog_id, $sale_id)
    {
        $raw = TGS_BCTK_Report::site_vat_sales_rows(
            (int) $blog_id, '2000-01-01', '2100-01-01', 'all', [(int) $sale_id]
        );
        if (empty($raw)) {
            return null;
        }
        self::enrich_item_names($raw);
        $r = $raw[0];
        $r['_blog_id'] = (int) $blog_id;
        return self::build_vat_row($r, self::site_code_of($blog_id), 'XBA', 1.0);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * XEM LẠI PHIẾU NHẬP KHO từ 2 màn báo cáo MUA HÀNG (Báo cáo mua hàng,
     * Tổng hợp mua hàng). CHỈ ĐỌC — cho sửa ghi chú, không sửa dòng hàng.
     *
     * Base RIÊNG với bên bán vì cột mua khác hẳn: đơn giá là TRƯỚC thuế, TRƯỚC
     * chiết khấu (đúng như lúc tạo phiếu nhập), khối thông tin có NHÀ CUNG CẤP.
     * Tiền vẫn đi sát mo-hinh-tien-va-bang-local-ledger-item.md: mỗi dòng qua
     * TGS_Money::line(qty, price_trước_thuế, ck_trước_thuế, thuế%); KHÔNG làm
     * tròn (giống báo cáo mua hàng chi tiết — đây là giá vốn).
     * ═══════════════════════════════════════════════════════════════════════ */

    public static function phieu_mua_view()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền xem phiếu.'], 403);
        }
        if (!self::money_ready()) {
            wp_send_json_error(['message' => 'Thiếu lớp tính tiền TGS_Money.'], 500);
        }

        $blog_id   = (int) ($_POST['blog_id'] ?? 0);
        $import_id = (int) ($_POST['import_id'] ?? 0);
        if ($blog_id <= 0 || $import_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id hoặc import_id.'], 400);
        }

        $row = self::import_ledger_row($blog_id, $import_id);
        if (!$row) {
            wp_send_json_error(['message' => 'Không tìm thấy phiếu nhập kho.'], 404);
        }
        wp_send_json_success(['row' => $row]);
    }

    /** Dựng payload MỘT phiếu nhập kho (type 1). Chạy chéo site qua prefix. */
    private static function import_ledger_row($blog_id, $import_id)
    {
        global $wpdb;

        $blog_id   = (int) $blog_id;
        $import_id = (int) $import_id;

        $prefix = $wpdb->get_blog_prefix($blog_id);
        $L   = $prefix . 'local_ledger';
        $LI  = $prefix . 'local_ledger_item';
        $PN  = $prefix . 'local_product_name';
        $SUP = $wpdb->base_prefix . 'global_supplier';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $L)) !== $L) {
            return null;
        }

        $head = $wpdb->get_row($wpdb->prepare(
            "SELECT d.local_ledger_id, d.local_ledger_code, d.created_at,
                    d.local_ledger_payment_due_date            AS han_tt,
                    COALESCE(d.local_ledger_code_source, '')   AS so_hd,
                    COALESCE(d.local_ledger_note, '')          AS note_raw,
                    d.user_id,
                    JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.import_reason.code'))  AS ly_do_ma,
                    JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.import_reason.label')) AS ly_do_ten,
                    JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.invoice.symbol'))      AS hd_ky_hieu,
                    JSON_UNQUOTE(JSON_EXTRACT(d.local_ledger_advance_meta, '$.invoice.date'))        AS hd_ngay,
                    COALESCE(u.display_name, u.user_login, '') AS nv_ten,
                    COALESCE(s.supplier_code, '')     AS ncc_ma,
                    COALESCE(s.supplier_name, '')     AS ncc_ten,
                    COALESCE(s.supplier_tax_code, '') AS ncc_mst,
                    COALESCE(s.supplier_address, '')  AS ncc_dchi,
                    COALESCE(s.supplier_phone, '')    AS ncc_dt,
                    COALESCE(s.supplier_email, '')    AS ncc_email
               FROM {$L} d
               LEFT JOIN {$SUP} s ON s.supplier_id = d.supplier_id
               LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
              WHERE d.local_ledger_id = %d AND d.local_ledger_type = 1
                AND (d.is_deleted = 0 OR d.is_deleted IS NULL)
              LIMIT 1",
            $import_id
        ), ARRAY_A);
        if (empty($head)) {
            return null;
        }

        $raw = $wpdb->get_results($wpdb->prepare(
            "SELECT i.local_ledger_item_id AS id,
                    i.local_product_sku    AS sku,
                    COALESCE(NULLIF(i.local_ledger_item_product_name_cache, ''), pn.local_product_name, '') AS ten,
                    COALESCE(i.local_ledger_item_warehouse_zone, '') AS kho,
                    i.quantity             AS qty,
                    i.price                AS gia,
                    COALESCE(i.local_ledger_item_discount_amount, 0) AS ck,
                    COALESCE(i.local_ledger_item_tax_percent, 0)     AS thue_pct,
                    COALESCE(i.local_ledger_item_tax_amount, 0)      AS thue_amt,
                    COALESCE(i.local_ledger_item_unit_name, '')      AS dvt,
                    COALESCE(i.local_ledger_item_unit_quantity, 0)   AS sl_dvmr,
                    COALESCE(NULLIF(i.local_ledger_item_unit_ratio, 0), 1) AS ratio,
                    COALESCE(i.lot_code, '') AS so_lo,
                    i.exp_date              AS exp,
                    COALESCE(i.local_ledger_item_note, '')     AS ghi_chu,
                    COALESCE(i.local_ledger_item_is_kct, 0)    AS is_kct
               FROM {$LI} i
               LEFT JOIN {$PN} pn ON pn.local_product_name_id = i.local_product_name_id
              WHERE i.local_ledger_id = %d AND i.local_ledger_item_type = 1
                AND (i.is_deleted = 0 OR i.is_deleted IS NULL)
              ORDER BY i.local_ledger_item_id ASC",
            $import_id
        ), ARRAY_A) ?: [];

        /*
         * Tên hàng: dòng phiếu nhập thường KHÔNG lưu cache tên và không gắn
         * local_product_name_id → bồi từ catalog GLOBAL theo mã hàng, giống
         * build_purchase_rows() dùng product_info().
         */
        $cat = TGS_BCTK_Report::product_info(array_column($raw, 'sku'));

        $items = [];
        $sum_before_ck = 0.0; $sum_before = 0.0; $sum_tax = 0.0; $sum_ck = 0.0; $grand = 0.0;
        $stt = 0;
        foreach ($raw as $it) {
            $stt++;
            $sku = (string) $it['sku'];
            $ten = trim((string) $it['ten']);
            if ($ten === '') {
                $ten = (string) ($cat[$sku]['name'] ?? '');
            }
            $qty = (float) $it['qty'];
            $gia = (float) $it['gia'];   // ĐVCB, trước thuế, trước CK
            $ck  = (float) $it['ck'];    // cả dòng, trước thuế
            $pct = (float) $it['thue_pct'];
            $m   = TGS_Money::line($qty, $gia, $ck, $pct);
            /* Dùng số thuế ĐÃ LƯU, KHÔNG làm tròn — giống build_purchase_rows() */
            $tax   = (float) $it['thue_amt'];
            $total = $m['tien_hang_sau_ck'] + $tax;

            $ratio   = max(1.0, (float) $it['ratio']);
            $sl_unit = ((float) $it['sl_dvmr']) ?: ($ratio > 0 ? $qty / $ratio : $qty);

            $items[] = [
                'stt'          => $stt,
                'ma_hang'      => $sku,
                'ten'          => $ten,
                'kho'          => (string) $it['kho'],
                'dvt'          => (string) $it['dvt'],
                'sl'           => $sl_unit,
                'ratio'        => $ratio,
                'sl_dvcb'      => $qty,
                'don_gia'      => $gia,               // TRƯỚC thuế, TRƯỚC CK (ĐVCB)
                'don_gia_dvt'  => $gia * $ratio,
                'tt_chua_ck'   => $m['tien_hang_truoc_ck'],
                'ck'           => $ck,
                'ck_pct'       => $m['ck_phan_tram'],
                'tt_chua_thue' => $m['tien_hang_sau_ck'],
                'thue_pct'     => ((int) $it['is_kct'] === 1) ? 'KCT' : $pct,
                'tien_thue'    => $tax,
                'thanh_tien'   => $total,
                'so_lo'        => (string) $it['so_lo'],
                'exp'          => self::clean_datetime($it['exp']),
                'ghi_chu'      => (string) $it['ghi_chu'],
            ];

            $sum_before_ck += $m['tien_hang_truoc_ck'];
            $sum_before    += $m['tien_hang_sau_ck'];
            $sum_tax       += $tax;
            $sum_ck        += $ck;
            $grand         += $total;
        }

        /*
         * Trang chi tiết phiếu nhập CŨ (đầy đủ: Sửa phiếu, Trình tự & Duyệt…).
         * Nằm ở site của shop — get_admin_url theo blog_id, nhận ?id = ledger id.
         */
        $detail_url = add_query_arg(
            ['page' => 'tgs-shop-management', 'view' => 'ticket-import-v2-detail', 'id' => $import_id],
            get_admin_url($blog_id, 'admin.php')
        );

        return [
            'blog_id'        => $blog_id,
            'import_id'      => $import_id,
            'detail_url'     => $detail_url,
            'so_phieu'       => (string) $head['local_ledger_code'],
            'ngay_ct'        => (string) $head['created_at'],
            'han_tt'         => self::clean_datetime($head['han_tt']),
            'so_hd'          => (string) $head['so_hd'],
            'hd_ky_hieu'     => (string) ($head['hd_ky_hieu'] ?? ''),
            'hd_ngay'        => (string) ($head['hd_ngay'] ?? ''),
            'ly_do'          => (string) ($head['ly_do_ma'] ?: 'NMH1'),
            'ly_do_ten'      => (string) ($head['ly_do_ten'] ?: ''),
            'ma_shop'        => self::site_code_of($blog_id),
            'kho'            => $items ? ($items[0]['kho'] ?: '') : '',
            'nv_ten'         => (string) $head['nv_ten'],
            'user_id'        => (int) $head['user_id'],
            'ncc_ma'         => (string) $head['ncc_ma'],
            'ncc_ten'        => (string) $head['ncc_ten'],
            'ncc_mst'        => (string) $head['ncc_mst'],
            'ncc_dchi'       => (string) $head['ncc_dchi'],
            'ncc_dt'         => (string) $head['ncc_dt'],
            'ncc_email'      => (string) $head['ncc_email'],
            'ghi_chu'        => trim((string) $head['note_raw']),
            'tt_chua_ck'     => $sum_before_ck,
            'tt_chua_thue'   => $sum_before,
            'tong_thue'      => $sum_tax,
            'tong_ck'        => $sum_ck,
            'thanh_tien'     => $grand,
            'thanh_tien_chu' => TGS_BCTK_Report::doc_tien_bang_chu(round($grand)),
            'sl_ban_ghi'     => count($items),
            'items'          => $items,
        ];
    }

    public static function phieu_mua_save_note()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền sửa ghi chú.'], 403);
        }

        $blog_id   = (int) ($_POST['blog_id'] ?? 0);
        $import_id = (int) ($_POST['import_id'] ?? 0);
        $note = trim(sanitize_textarea_field((string) wp_unslash($_POST['note'] ?? '')));
        if ($blog_id <= 0 || $import_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id hoặc import_id.'], 400);
        }

        $switched = false;
        if (function_exists('switch_to_blog') && get_current_blog_id() !== $blog_id) {
            switch_to_blog($blog_id);
            $switched = true;
        }

        try {
            global $wpdb;
            $L  = $wpdb->prefix . 'local_ledger';
            $ok = $wpdb->query($wpdb->prepare(
                "UPDATE {$L} SET local_ledger_note = %s, updated_at = %s
                  WHERE local_ledger_id = %d AND local_ledger_type = 1 LIMIT 1",
                $note, current_time('mysql'), $import_id
            ));

            if ($switched) { restore_current_blog(); $switched = false; }
            if ($ok === false) {
                wp_send_json_error(['message' => 'Lưu ghi chú thất bại.'], 500);
            }
            wp_send_json_success(['ghi_chu' => $note, 'message' => 'Đã lưu ghi chú.']);
        } finally {
            if ($switched) { restore_current_blog(); }
        }
    }

    public static function vat_save_lines()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền sửa phiếu.'], 403);
        }
        if (!self::money_ready()) {
            wp_send_json_error(['message' => 'Thiếu lớp tính tiền TGS_Money.'], 500);
        }

        $blog_id = (int) ($_POST['blog_id'] ?? 0);
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $lines   = json_decode((string) wp_unslash($_POST['lines'] ?? '[]'), true);
        if (!is_array($lines)) {
            wp_send_json_error(['message' => 'Danh sách dòng hàng không hợp lệ.'], 400);
        }

        // Bảng giá / ĐVT lấy theo WEBSITE ĐANG SỬA (site hiện tại), chốt TRƯỚC
        // khi switch_to_blog sang site shop.
        $report_blog = get_current_blog_id();
        $unit_cfg = self::unit_configs_for(array_map(static function ($ln) {
            return (string) ($ln['ma_hang'] ?? '');
        }, $lines), $report_blog);

        $switched = false;
        $ctx = self::vat_edit_context($blog_id, $sale_id, $switched, true);

        try {
            global $wpdb;
            $L  = $wpdb->prefix . 'local_ledger';
            $LI = $wpdb->prefix . 'local_ledger_item';
            $export_id = (int) $ctx['export_id'];
            if ($export_id <= 0) {
                wp_send_json_error(['message' => 'Phiếu chưa có phiếu xuất con — không sửa dòng được.'], 409);
            }

            $now = current_time('mysql');
            $uid = get_current_user_id();

            // Dòng đang thuộc phiếu xuất + thuế suất ĐÃ LƯU của từng dòng.
            // Thuế suất KHÔNG cho client sửa: dòng cũ giữ nguyên số đã lưu,
            // dòng mới lấy theo cấu hình mã hàng (wp_global_product_name).
            $own = [];
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_item_id AS id,
                        COALESCE(local_ledger_item_tax_percent, 0) AS pct,
                        COALESCE(local_ledger_item_is_kct, 0)      AS kct
                   FROM {$LI}
                  WHERE local_ledger_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)",
                $export_id
            ), ARRAY_A) as $o) {
                $own[(int) $o['id']] = ['pct' => (float) $o['pct'], 'kct' => (int) $o['kct']];
            }

            /*
             * ─── NHẬT KÝ (plugin tgs_audit_log) ───────────────────────────
             * Chụp trạng thái dòng hàng + tổng phiếu TRƯỚC khi sửa. Chỉ chạy
             * khi có plugin nghe hook — không thì bỏ, khỏi tốn query.
             */
            $audit_on = has_action('tgs_bctk_vat_lines_saved');
            $audit_snap_sql = "SELECT local_ledger_item_id AS id, local_product_sku AS sku,
                        local_ledger_item_product_name_cache AS ten,
                        local_ledger_item_unit_name AS dvt,
                        local_ledger_item_unit_quantity AS sl,
                        quantity AS qty, price,
                        local_ledger_item_discount_amount AS ck,
                        local_ledger_item_tax_percent AS thue_pct,
                        local_ledger_item_tax_amount AS thue,
                        lot_code AS so_lo, exp_date AS exp
                   FROM {$LI}
                  WHERE local_ledger_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)
                  ORDER BY local_ledger_item_id ASC";
            $audit_before = $audit_on
                ? ($wpdb->get_results($wpdb->prepare($audit_snap_sql, $export_id), ARRAY_A) ?: [])
                : [];
            $audit_grand_before = $audit_on
                ? (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT local_ledger_total_amount FROM {$L} WHERE local_ledger_id = %d",
                    $export_id
                ))
                : 0.0;

            /*
             * Catalog GLOBAL cho MỌI mã hàng trong payload:
             *   - Thuế suất dòng mới lấy từ đây (dòng cũ giữ số đã lưu — xem dưới).
             *   - TÊN HÀNG: cột local_ledger_item_product_name_cache LUÔN lấy theo
             *     tên trong catalog (không tin ô "Tên hàng" người dùng gõ) — đúng
             *     ý "tên tự lấy theo mã hàng", tránh lưu chữ rác.
             */
            $gp_table = $wpdb->base_prefix . 'global_product_name';
            $all_skus = [];
            foreach ($lines as $ln) {
                if (empty($ln['del'])) {
                    $sk = trim((string) ($ln['ma_hang'] ?? ''));
                    if ($sk !== '') { $all_skus[$sk] = true; }
                }
            }
            $gp_tax = [];   // sku => ['pct','kct','name','gid']
            if (!empty($all_skus)
                && $wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($gp_table) . "'") === $gp_table) {
                $sk_list = array_keys($all_skus);
                $ph = implode(',', array_fill(0, count($sk_list), '%s'));
                foreach ((array) $wpdb->get_results($wpdb->prepare(
                    "SELECT global_product_sku AS sku,
                            global_product_name_id             AS gid,
                            COALESCE(global_product_tax, 8)    AS pct,
                            COALESCE(global_product_is_kct, 0) AS kct,
                            COALESCE(global_product_name, '')  AS name
                       FROM {$gp_table} WHERE global_product_sku IN ({$ph})",
                    ...$sk_list
                ), ARRAY_A) as $g) {
                    $gp_tax[(string) $g['sku']] = [
                        'gid'  => (int) $g['gid'],
                        'pct'  => (float) $g['pct'],
                        'kct'  => (int) $g['kct'],
                        'name' => (string) $g['name'],
                    ];
                }
            }

            // Bản ghi SẢN PHẨM LOCAL của site shop — để dòng hàng trỏ đúng
            // local_product_name_id / global_product_name_id như luồng POS.
            $lp_table = $wpdb->prefix . 'local_product_name';
            $lp = [];   // sku => ['lid','gid','name','unit']
            if (!empty($all_skus)) {
                $sk_list = array_keys($all_skus);
                $ph = implode(',', array_fill(0, count($sk_list), '%s'));
                foreach ((array) $wpdb->get_results($wpdb->prepare(
                    "SELECT local_product_sku AS sku,
                            local_product_name_id  AS lid,
                            global_product_name_id AS gid,
                            local_product_name     AS name,
                            local_product_unit     AS unit
                       FROM {$lp_table}
                      WHERE local_product_sku IN ({$ph})
                        AND (is_deleted = 0 OR is_deleted IS NULL)",
                    ...$sk_list
                ), ARRAY_A) as $p) {
                    $lp[(string) $p['sku']] = [
                        'lid'  => (int) $p['lid'],
                        'gid'  => $p['gid'] !== null ? (int) $p['gid'] : 0,
                        'name' => (string) $p['name'],
                        'unit' => (string) $p['unit'],
                    ];
                }
            }

            foreach ($lines as $ln) {
                $id  = (int) ($ln['item_id'] ?? 0);
                $del = !empty($ln['del']);
                $sl_unit  = max(0, (float) ($ln['sl'] ?? 0));         // SL theo ĐVT bán
                $gia_unit = max(0, (float) ($ln['don_gia'] ?? 0));   // giá 1 ĐVT, đã gồm thuế, trước CK
                $ck  = max(0, (float) ($ln['ck'] ?? 0));             // sau thuế, cả dòng
                $sku = sanitize_text_field((string) ($ln['ma_hang'] ?? ''));
                $ten = sanitize_text_field((string) ($ln['ten'] ?? ''));
                $dvt = sanitize_text_field((string) ($ln['dvt'] ?? ''));

                // ── ĐVT ưu tiên & TỶ LỆ QUY ĐỔI (giống giỏ hàng tgs_pos) ──
                // Tỷ lệ lấy theo cấu hình bảng giá của WEBSITE HIỆN TẠI, khớp
                // theo tên ĐVT; không khớp thì dùng tỷ lệ client gửi, cuối cùng 1.
                $ratio = 0.0;
                foreach ((array) ($unit_cfg[$sku] ?? []) as $uc) {
                    if (strcasecmp((string) $uc['unit'], $dvt) === 0) {
                        $ratio = (float) $uc['ratio'];
                        break;
                    }
                }
                if ($ratio <= 0) { $ratio = (float) ($ln['ratio'] ?? 0); }
                if ($ratio <= 0) { $ratio = 1.0; }

                // Quy về ĐVCB cho đúng mô hình tiền
                $qty = $sl_unit * $ratio;                          // SL theo ĐVCB
                $gia = $ratio > 0 ? $gia_unit / $ratio : $gia_unit; // giá 1 ĐVCB (đã gồm thuế)

                // ── THUẾ SUẤT KHÔNG LẤY TỪ CLIENT ──
                // dòng cũ: giữ số đã lưu; dòng mới: theo cấu hình mã hàng
                if ($id > 0 && isset($own[$id])) {
                    $pct = (float) $own[$id]['pct'];
                    $is_kct = (int) $own[$id]['kct'];
                } elseif (isset($gp_tax[$sku])) {
                    $pct = (float) $gp_tax[$sku]['pct'];
                    $is_kct = (int) $gp_tax[$sku]['kct'];
                } else {
                    $pct = 8.0;   // không có cấu hình → mức phổ biến
                    $is_kct = 0;
                }
                if ($is_kct === 1) { $pct = 0.0; }
                $lo  = sanitize_text_field((string) ($ln['so_lo'] ?? ''));
                $exp = sanitize_text_field((string) ($ln['exp'] ?? ''));
                $exp = preg_match('/^\d{4}-\d{2}-\d{2}/', $exp) ? substr($exp, 0, 10) : null;
                $note = sanitize_textarea_field((string) ($ln['ghi_chu'] ?? ''));

                if ($id > 0 && !isset($own[$id])) {
                    continue; // không phải dòng của phiếu này
                }

                // Dòng TRỐNG (không có mã hàng): dòng cũ → xoá; dòng mới → bỏ qua.
                $catalog_name0 = trim((string) ($lp[$sku]['name'] ?? $gp_tax[$sku]['name'] ?? ''));
                $blank = ($sku === '' && $ten === '' && $catalog_name0 === '');

                if (($del || $blank) && $id > 0) {
                    $wpdb->delete($LI, ['local_ledger_item_id' => $id], ['%d']);
                    continue;
                }
                if ($id === 0 && ($del || $blank || $sku === '' || $qty <= 0)) {
                    continue; // dòng THÊM MỚI chưa đủ dữ liệu ⇒ bỏ, không tạo dòng thừa
                }

                $m = TGS_Money::from_pos($qty, $gia, $ck, $pct);
                $data = [
                    'quantity' => $qty,
                    'price'    => $m['price'],
                    'local_ledger_item_discount_amount' => $m['discount'],
                    'local_ledger_item_tax_percent'     => $pct,
                    'local_ledger_item_tax_amount'      => $m['tax'],
                    'local_ledger_item_is_kct'          => $is_kct,
                    'local_ledger_item_note'            => $note,
                    /*
                     * Đơn giá SAU chiết khấu, TRƯỚC thuế, cho 1 ĐVCB — cột màn
                     * lịch sử đơn / hoá đơn điện tử của tgs_pos đọc để dựng
                     * ĐƠN GIÁ · CK · THÀNH TIỀN trên bill
                     * (get_order_receipt_data). KHÔNG ghi cột này thì bill hiện
                     * số CŨ (đơn giá + thành tiền lệch, CK về 0).
                     */
                    'local_ledger_item_price_after_discount' => $m['line']['don_gia_gui_thue'],
                    // ĐVT bán — cặp đi cùng: quantity(ĐVCB) = unit_quantity × unit_ratio
                    'local_ledger_item_unit_name'       => $dvt,
                    'local_ledger_item_unit_quantity'   => $sl_unit,
                    'local_ledger_item_unit_ratio'      => $ratio,
                    'lot_code'  => $lo !== '' ? $lo : null,
                    'exp_date'  => $exp,
                    'updated_at' => $now,
                ];
                if ($sku !== '') { $data['local_product_sku'] = $sku; }

                /*
                 * TRỎ SẢN PHẨM theo ĐÚNG luồng POS (create_export_ledger):
                 * cả `global_product_name_id` LẪN `local_product_name_id` đều mang
                 * `wp_global_product_name.global_product_name_id` (tra theo SKU).
                 * Nếu site shop có bản ghi local riêng thì `local_product_name_id`
                 * ưu tiên id local đó; còn `global_product_name_id` luôn là id
                 * global. Không có gì thì để NULL (mã lạ).
                 */
                $lpr = $lp[$sku] ?? null;
                $gid = (int) ($gp_tax[$sku]['gid'] ?? ($lpr['gid'] ?? 0));
                if ($gid > 0) {
                    $data['global_product_name_id'] = $gid;
                    $data['local_product_name_id']  = ($lpr && $lpr['lid'] > 0) ? (int) $lpr['lid'] : $gid;
                } elseif ($lpr && $lpr['lid'] > 0) {
                    $data['local_product_name_id'] = (int) $lpr['lid'];
                }

                $cache_name = trim((string) ($gp_tax[$sku]['name'] ?? ''));
                if ($cache_name === '') { $cache_name = trim((string) ($lpr['name'] ?? '')); }
                if ($cache_name === '') { $cache_name = $ten; }
                if ($cache_name !== '') {
                    $data['local_ledger_item_product_name_cache'] = $cache_name;
                }

                /*
                 * local_ledger_item_meta — JSON bổ sung, ĐÚNG khuôn POS
                 * (TGS_POS_Order_Handler::create_export_ledger $item_meta) để màn
                 * lịch sử đơn / hoá đơn điện tử đọc ra được. bc-tk nhập CK bằng
                 * TIỀN CẢ DÒNG nên discount_type = 'vnd_line'.
                 */
                $data['local_ledger_item_meta'] = wp_json_encode([
                    'sku'                  => $sku,
                    'is_gift'              => false,
                    'unit'                 => $dvt,
                    'unit_price_effective' => (float) $m['price'],
                    'subtotal_no_vat'      => (float) $m['price'] * $qty,
                    'discount_type'        => 'vnd_line',
                    'discount_value'       => (float) $m['discount'],
                    'discount_amount'      => (float) $m['discount'],
                    'tax_percent'          => (float) $pct,
                    'is_kct'               => (int) $is_kct,
                    'tax_amount'           => (float) $m['tax'],
                    'unit_quantity'        => (float) $sl_unit,
                    'unit_ratio'           => (float) $ratio,
                    'unit_name'            => $dvt,
                    'total_weight_kg'      => 0,
                    'edited_by_bctk'       => [
                        'user_id' => $uid,
                        'at'      => $now,
                        'source'  => 'bctk_vat_save_lines',
                    ],
                ], JSON_UNESCAPED_UNICODE);

                if ($id > 0) {
                    $wpdb->update($LI, $data, ['local_ledger_item_id' => $id]);
                } else {
                    // Dòng mới đã qua các chốt ở trên (có mã hàng + SL > 0)
                    $wpdb->insert($LI, array_merge($data, [
                        'local_ledger_id' => $export_id,
                        'local_ledger_item_type' => 2,
                        'local_ledger_item_gift_type'     => 0,
                        'user_id'    => $uid,
                        'is_deleted' => 0,
                        'created_at' => $now,
                    ]));
                }
            }

            // Dòng còn sống → id JSON + tổng tiền
            $live = $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_item_id, quantity, price,
                        local_ledger_item_discount_amount, local_ledger_item_tax_percent,
                        local_ledger_item_tax_amount
                   FROM {$LI}
                  WHERE local_ledger_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)
                  ORDER BY local_ledger_item_id ASC",
                $export_id
            ), ARRAY_A) ?: [];

            $ids_json = wp_json_encode(array_map(static function ($x) {
                return (int) $x['local_ledger_item_id'];
            }, $live), JSON_UNESCAPED_UNICODE);

            $tot = TGS_Money::total($live);
            $grand = (float) $tot['thanh_tien_dong'];

            /*
             * ─── ĐỒNG BỘ local_ledger_item_id CHO CẢ CÂY PHIẾU ─────────────
             *
             * Đúng như luồng tạo đơn ở tgs_pos (update_ledger_items): danh sách
             * id dòng hàng phải giống hệt nhau trên PHIẾU BÁN và MỌI phiếu con
             * (local_ledger_parent_id = phiếu bán) — phiếu xuất, phiếu thu,
             * phiếu chi… Thêm/sửa/xoá dòng thì tất cả đi theo.
             *
             * Tổng tiền (local_ledger_total_amount) chỉ ghi cho PHIẾU BÁN và
             * PHIẾU XUẤT; phiếu thu/chi giữ số tiền của chính nó (tiền đã thu),
             * không phải tổng đơn.
             */
            $children = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT local_ledger_id FROM {$L}
                  WHERE local_ledger_parent_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)",
                $sale_id
            )));
            $all_ledgers = array_values(array_unique(array_merge([$sale_id], $children)));

            foreach ($all_ledgers as $lid) {
                $fields = ['local_ledger_item_id' => $ids_json, 'updated_at' => $now];
                if ($lid === $sale_id || $lid === $export_id) {
                    $fields['local_ledger_total_amount'] = $grand;
                }
                $wpdb->update($L, $fields, ['local_ledger_id' => $lid]);
            }

            // Đối chiếu tiền đã thu
            $paid = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(local_ledger_total_amount), 0) FROM {$L}
                  WHERE local_ledger_parent_id = %d AND local_ledger_type IN (7, 8)
                    AND (local_ledger_approver_status = 1)
                    AND (is_deleted = 0 OR is_deleted IS NULL)",
                $sale_id
            ));
            $warning = '';
            if (abs($paid - $grand) >= 1) {
                $warning = 'Tổng phiếu mới ' . number_format_i18n($grand) . 'đ nhưng tiền đã thu là '
                    . number_format_i18n($paid) . 'đ — chênh ' . number_format_i18n($paid - $grand)
                    . 'đ. Kiểm tra lại phiếu thu / công nợ.';
            }

            /*
             * ─── NHẬT KÝ (plugin tgs_audit_log) ───────────────────────────
             * Chụp trạng thái SAU rồi phát hook — vẫn đang ở ngữ cảnh site
             * shop (chưa restore_current_blog). Plugin nghe hook tự lo so sánh
             * trước↔sau, ghi file JSONL và bắn Zalo ở shutdown.
             */
            if (!empty($audit_on)) {
                $audit_after = $wpdb->get_results($wpdb->prepare($audit_snap_sql, $export_id), ARRAY_A) ?: [];
                do_action('tgs_bctk_vat_lines_saved', [
                    'blog_id'      => $blog_id,
                    'sale_id'      => $sale_id,
                    'report_blog'  => (int) $report_blog,
                    'sale_code'    => (string) ($ctx['sale']['local_ledger_code'] ?? ''),
                    'export_id'    => $export_id,
                    'before'       => $audit_before,
                    'after'        => $audit_after,
                    'grand_before' => (float) $audit_grand_before,
                    'grand_total'  => (float) $grand,
                    'warning'      => $warning,
                    'live_count'   => count($live),
                ]);
            }

            $row = self::vat_row_for_sale($blog_id, $sale_id);

            if ($switched) { restore_current_blog(); $switched = false; }

            wp_send_json_success([
                'row'     => $row,
                'warning' => $warning,
                'message' => 'Đã lưu ' . count($live) . ' dòng · tổng phiếu ' . number_format_i18n($grand) . 'đ.',
            ]);
        } finally {
            if ($switched) { restore_current_blog(); }
        }
    }

    /**
     * Tìm sản phẩm trong catalog GLOBAL để thêm dòng khi sửa phiếu.
     *
     * Bảng wp_global_product_name là bảng global (base_prefix) — không phụ thuộc
     * site, không cần switch_to_blog.
     */
    public static function product_search()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }

        global $wpdb;
        $q = trim(sanitize_text_field((string) wp_unslash($_POST['q'] ?? '')));
        if (mb_strlen($q) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $table = $wpdb->base_prefix . 'global_product_name';
        if ($wpdb->get_var("SHOW TABLES LIKE '" . esc_sql($table) . "'") !== $table) {
            wp_send_json_error(['message' => 'Chưa có bảng sản phẩm global.'], 500);
        }

        $like = '%' . $wpdb->esc_like($q) . '%';
        $exact = $q;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_sku            AS sku,
                    global_product_name           AS name,
                    global_product_unit           AS unit,
                    global_product_price_after_tax AS price,
                    global_product_tax            AS tax,
                    global_product_is_kct         AS is_kct
               FROM {$table}
              WHERE global_product_sku = %s
                 OR global_product_sku LIKE %s
                 OR global_product_name LIKE %s
                 OR global_product_barcode_main LIKE %s
                 OR global_product_special_barcode LIKE %s
              ORDER BY (global_product_sku = %s) DESC,
                       (global_product_barcode_main = %s) DESC,
                       CHAR_LENGTH(global_product_name) ASC
              LIMIT 25",
            $exact, $like, $like, $like, $like, $exact, $exact
        ), ARRAY_A) ?: [];

        $skus = array_map(static function ($r) { return (string) $r['sku']; }, $rows);
        $units = self::unit_configs_for($skus);

        $items = array_map(static function ($r) use ($units) {
            $sku = (string) $r['sku'];
            return [
                'sku'   => $sku,
                'name'  => (string) $r['name'],
                'unit'  => (string) $r['unit'],
                'price' => (float) $r['price'],
                'tax'   => ($r['tax'] === null || $r['tax'] === '') ? null : (float) $r['tax'],
                'is_kct' => (int) $r['is_kct'] === 1,
                'units' => $units[$sku] ?? [],
            ];
        }, $rows);

        wp_send_json_success(['items' => $items]);
    }

    /**
     * Cấu hình ĐVT bán + giá theo ĐVT của các mã hàng — LẤY THEO BẢNG GIÁ CỦA
     * WEBSITE HIỆN TẠI (site đang chạy báo cáo), KHÔNG cần vào site shop.
     *
     * Nguồn: TGS_Price_List (wp_global_htsoft_stock_convert +
     * wp_global_htsoft_price_list_blog) — "nơi duy nhất" lấy ĐVT bán + giá,
     * xem tgs_shop_management/docs/gia-va-don-vi-tinh.md.
     *
     * @return array [sku => [ ['unit','ratio','price','is_default'], … ]]
     */
    private static function unit_configs_for(array $skus, $blog_id = null)
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
        if (empty($skus)) {
            return [];
        }

        if (!class_exists('TGS_Price_List')) {
            $file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-price-list.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('TGS_Price_List')) {
            return [];
        }

        // $blog_id: truyền rõ khi đã switch_to_blog (site đang sửa phiếu ≠ site shop)
        $raw = TGS_Price_List::unit_configs_by_skus($skus, $blog_id);
        $out = [];
        foreach ($raw as $sku => $cfgs) {
            $list = [];
            foreach ((array) $cfgs as $c) {
                $unit = trim((string) ($c['unit'] ?? ''));
                if ($unit === '') {
                    continue;
                }
                $ratio = (float) ($c['ratio'] ?? 1);
                if ($ratio <= 0) {
                    $ratio = 1.0;
                }
                $list[] = [
                    'unit'       => $unit,
                    'ratio'      => $ratio,
                    // giá 1 ĐVT (đã gồm thuế như lúc cấu hình); null thì suy từ giá ĐVCB
                    'price'      => ($c['unit_price'] === null)
                        ? null : (float) $c['unit_price'],
                    'is_default' => ((int) ($c['is_default_unit'] ?? 0) === 1) ? 1 : 0,
                ];
            }
            $out[(string) $sku] = $list;
        }
        return $out;
    }

    /** Cấu hình ĐVT cho một loạt mã hàng (khi vào chế độ sửa) */
    public static function product_units()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }
        $skus = json_decode((string) wp_unslash($_POST['skus'] ?? '[]'), true);
        $skus = is_array($skus) ? $skus : [];
        wp_send_json_success(['units' => self::unit_configs_for($skus)]);
    }

    public static function vat_save_note()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can(TGS_BCTK_CAPABILITY)) {
            wp_send_json_error(['message' => 'Không có quyền sửa ghi chú.'], 403);
        }

        $blog_id = (int) ($_POST['blog_id'] ?? 0);
        $sale_id = (int) ($_POST['sale_id'] ?? 0);
        $note    = trim(sanitize_textarea_field((string) wp_unslash($_POST['note'] ?? '')));

        // Site đang chạy hệ quản trị — chốt TRƯỚC khi vat_edit_context switch_to_blog
        $report_blog = get_current_blog_id();

        $switched = false;
        $ctx = self::vat_edit_context($blog_id, $sale_id, $switched);

        try {
            global $wpdb;
            $L = $wpdb->prefix . 'local_ledger';

            $note_before = (string) ($ctx['sale']['local_ledger_note'] ?? '');

            /*
             * Lưu THẲNG chữ kế toán nhập — giống luồng POS mới, không bọc
             * "Đơn POS <mã> | Ghi chú:" nữa (mã phiếu đã có ở local_ledger_code).
             * Rỗng ⇒ để trống. Xem tgs_pos: create_sale_ledger / build_ledger_note.
             */
            $wpdb->update($L, ['local_ledger_note' => $note, 'updated_at' => current_time('mysql')],
                ['local_ledger_id' => $sale_id]);

            // ─── NHẬT KÝ (plugin tgs_audit_log) ───
            if (has_action('tgs_bctk_vat_note_saved')) {
                do_action('tgs_bctk_vat_note_saved', [
                    'blog_id'     => $blog_id,
                    'sale_id'     => $sale_id,
                    'report_blog' => (int) $report_blog,
                    'sale_code'   => (string) ($ctx['sale']['local_ledger_code'] ?? ''),
                    'before'      => $note_before,
                    'after'       => $note,
                ]);
            }

            if ($switched) { restore_current_blog(); $switched = false; }
            wp_send_json_success(['ghi_chu' => $note, 'message' => 'Đã lưu ghi chú.']);
        } finally {
            if ($switched) { restore_current_blog(); }
        }
    }
}

TGS_BCTK_Ajax::init();
