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
     * Chuyển số thành chữ tiếng Việt.
     *
     * @param float|int $number Số tiền cần chuyển
     * @return string Chuỗi tiếng Việt, ví dụ: "Bốn trăm ba mươi nghìn đồng chẵn"
     */
    private static function number_to_vietnamese($number)
    {
        if ($number == 0) {
            return 'Không đồng chẵn';
        }

        $number = (int) round($number);

        $units = ['', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $levels = ['', 'nghìn', 'triệu', 'tỷ'];

        if ($number < 0) {
            return 'Âm ' . self::number_to_vietnamese(-$number);
        }

        $result = [];
        $level_idx = 0;

        while ($number > 0) {
            $group = $number % 1000;
            if ($group > 0) {
                $group_text = self::convert_group_to_vietnamese($group, $units);
                if ($level_idx > 0) {
                    $group_text .= ' ' . $levels[$level_idx];
                }
                array_unshift($result, $group_text);
            }
            $number = (int)($number / 1000);
            $level_idx++;
        }

        $text = implode(' ', $result);
        $text = ucfirst($text) . ' đồng chẵn';

        return $text;
    }

    /**
     * Chuyển một nhóm 3 chữ số thành chữ.
     *
     * @param int $number Số từ 0-999
     * @param array $units Mảng đơn vị
     * @return string
     */
    private static function convert_group_to_vietnamese($number, $units)
    {
        $hundred = (int)($number / 100);
        $ten = (int)(($number % 100) / 10);
        $unit = $number % 10;

        $result = [];

        if ($hundred > 0) {
            $result[] = $units[$hundred] . ' trăm';
        }

        if ($ten > 1) {
            $result[] = $units[$ten] . ' mươi';
            if ($unit == 1) {
                $result[] = 'mốt';
            } elseif ($unit > 0) {
                $result[] = $units[$unit];
            }
        } elseif ($ten == 1) {
            $result[] = 'mười';
            if ($unit > 0) {
                $result[] = $units[$unit];
            }
        } else {
            if ($hundred > 0 && $unit > 0) {
                $result[] = 'lẻ';
            }
            if ($unit == 5 && $hundred > 0) {
                $result[] = 'lăm';
            } elseif ($unit > 0) {
                $result[] = $units[$unit];
            }
        }

        return implode(' ', $result);
    }

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
        add_action('wp_ajax_tgs_bctk_refresh_nonce', [__CLASS__, 'refresh_nonce']);
        add_action('wp_ajax_tgs_bctk_fetch_vat_sales', [__CLASS__, 'fetch_vat_sales']);
        add_action('wp_ajax_tgs_bctk_fetch_vat_adjustment', [__CLASS__, 'fetch_vat_adjustment']);
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

            $rows[] = [
                'kho'     => $is_warehouse ? ($no_zone ? $site['name'] : $zone) : $site_label,
                'no_zone' => $no_zone,
                'pbh'     => (string) $r['pbh'],
                'ngay'    => (string) $r['ngay'],
                'ly_do'   => $is_return ? 'NTH1' : 'XBA',
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
                'kenh'    => 'Gần shop',
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
                /* Mã lý do theo phần mềm cũ: bán = XBA, trả lại = NTH1 */
                'ly_do'    => $is_return ? 'NTH1' : 'XBA',
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
                'kenh'     => 'Gần shop',
            ];
        }

        return ['rows' => $rows, 'site' => $site];
    }

    /**
     * Bóc lấy chữ người bán thật sự gõ ra khỏi ghi chú phiếu bán.
     *
     * POS ghép sẵn tiêu đề vào rồi mới lưu, xem
     * TGS_POS_Order_Handler::create_sale_ledger:
     *
     *     Đơn POS HD3_A7H8C | Ghi chú: mày là của ai
     *
     * Mã đơn thì cột PBH đã có rồi; bày lại lần nữa chỉ tổ đẩy phần chữ thật ra
     * ngoài tầm nhìn, đúng như đang bị.
     *
     * Quy tắc bóc giữ y hệt TGS_POS_Ajax_Order::extract_order_note(), kể cả
     * nhánh phiếu cũ chưa có tiền tố — hai nơi tách khác nhau thì cùng một đơn
     * lại hiện hai kiểu ghi chú, người dùng không biết tin cái nào.
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

    /**
     * Báo cáo phiếu bán VAT — mỗi lượt một site
     */
    public static function fetch_vat_sales()
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
        $vat_status = sanitize_text_field($_POST['vat_status'] ?? 'all');
        $doc_type = sanitize_text_field($_POST['doc_type'] ?? 'sale');

        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }
        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }

        try {
            wp_send_json_success(self::build_vat_sales_rows($blog_id, $zones, $from, $to, $vat_status, $doc_type));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /**
     * Dựng dòng báo cáo phiếu bán VAT cho một site
     */
    private static function build_vat_sales_rows($blog_id, array $zones, $from, $to, $vat_status, $doc_type)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        $site = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        $prefix = $wpdb->get_blog_prefix($blog_id);
        $ledger_table = $prefix . 'local_ledger';
        $item_table = $prefix . 'local_ledger_item';
        $invoice_table = $prefix . 'local_viettel_invoice';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table = $prefix . 'local_ledger_meta';
        $users_table = $wpdb->users;

        // Kiểm tra bảng tồn tại
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return ['rows' => [], 'site' => $site];
        }

        $where = ["l.local_ledger_type = 10", "l.is_deleted = 0"]; // type 10 = phiếu bán
        $where[] = $wpdb->prepare("DATE(l.created_at) >= %s", $from);
        $where[] = $wpdb->prepare("DATE(l.created_at) <= %s", $to);

        // Lọc theo loại phiếu
        if ($doc_type === 'sale') {
            $where[] = "l.local_ledger_code NOT LIKE '%Z'";
        } elseif ($doc_type === 'internal') {
            $where[] = "l.local_ledger_code LIKE '%Z'";
        }

        // Lọc theo trạng thái VAT
        if ($vat_status === 'has_vat') {
            $where[] = "i.viettel_invoice_no IS NOT NULL";
        } elseif ($vat_status === 'no_vat') {
            $where[] = "(i.viettel_invoice_no IS NULL OR i.viettel_invoice_no = '')";
        } elseif ($vat_status === 'vat_error') {
            $where[] = "i.invoice_state IN ('error', 'issue_error')";
        }

        $where_sql = implode(' AND ', $where);

        // Switch to blog để lấy thông tin shop
        switch_to_blog($blog_id);
        $seller_name = get_bloginfo('name');
        $seller_address = get_option('tgs_shop_address', '');
        $seller_phone = get_option('tgs_shop_phone', '');
        $seller_tax_code = get_option('tgs_shop_tax_code', '');
        restore_current_blog();

        $sql = "SELECT
            l.local_ledger_id,
            l.local_ledger_code,
            l.local_ledger_item_id,
            l.created_at,
            l.local_ledger_total_amount as total_after_tax,
            COALESCE(l.local_ledger_discount, 0) as total_discount,
            l.local_ledger_note as invoice_note,
            l.user_id as created_by,
            COALESCE(pe.local_ledger_person_phone, '') as customer_phone,
            COALESCE(pe.local_ledger_person_name, '') as buyer_name,
            COALESCE(pe.local_ledger_person_address, '') as buyer_address,
            COALESCE(pe.local_ledger_person_email, '') as buyer_email,
            COALESCE(pe.local_ledger_person_tax_code, '') as buyer_tax_code,
            pe.local_ledger_person_meta,
            JSON_UNQUOTE(JSON_EXTRACT(m.local_ledger_meta_value, '$.payment_method')) as payment_method,
            i.viettel_invoice_no,
            i.invoice_state,
            i.invoice_series,
            i.template_code,
            snap.settings_json as seller_config_json,
            COALESCE(u.display_name, '') as cashier_name
        FROM {$ledger_table} l
        LEFT JOIN (
            SELECT sale_ledger_id, viettel_invoice_no, invoice_state, invoice_series, template_code
            FROM {$invoice_table}
            WHERE (sale_ledger_id, local_viettel_invoice_id) IN (
                SELECT sale_ledger_id, MAX(local_viettel_invoice_id)
                FROM {$invoice_table}
                GROUP BY sale_ledger_id
            )
        ) i ON i.sale_ledger_id = l.local_ledger_id
        LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = l.local_ledger_person_id
        LEFT JOIN {$meta_table} m ON m.local_ledger_meta_id = l.local_ledger_meta_id
        LEFT JOIN {$users_table} u ON u.ID = l.user_id
        LEFT JOIN (
            SELECT blog_id, sale_ledger_id, settings_json
            FROM {$wpdb->base_prefix}tgs_viettel_invoice_config_snapshots
            WHERE (blog_id, sale_ledger_id, id) IN (
                SELECT blog_id, sale_ledger_id, MAX(id)
                FROM {$wpdb->base_prefix}tgs_viettel_invoice_config_snapshots
                GROUP BY blog_id, sale_ledger_id
            )
        ) snap ON snap.blog_id = {$blog_id} AND snap.sale_ledger_id = l.local_ledger_id
        WHERE {$where_sql}
        ORDER BY l.created_at DESC
        LIMIT 5000";

        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Debug: Log số dòng tìm được
        error_log("VAT Sales: Found " . count($rows) . " ledgers for blog_id={$blog_id}, date={$from} to {$to}");

        if (empty($rows)) {
            return ['rows' => [], 'site' => $site];
        }

        // Tính toán tiền theo đúng luồng local_ledger_item
        if (!self::money_ready()) {
            error_log("VAT Sales: TGS_Money class not ready!");
            return ['rows' => [], 'site' => $site];
        }

        $result_rows = [];
        foreach ($rows as $row) {
            $ledger_id = (int) $row['local_ledger_id'];

            // Parse company name từ person_meta JSON
            $buyer_company_name = '';
            if (!empty($row['local_ledger_person_meta'])) {
                $meta = json_decode($row['local_ledger_person_meta'], true);
                $buyer_company_name = $meta['company_name'] ?? $meta['company'] ?? '';
            }
            $row['buyer_company_name'] = $buyer_company_name;

            // Lấy các item - dựa vào local_ledger_item_id (JSON array)
            $item_ids_json = $row['local_ledger_item_id'] ?? '[]';
            $item_ids = json_decode($item_ids_json, true);

            $items = [];
            if (!empty($item_ids) && is_array($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items_sql = "SELECT
                        quantity,
                        price,
                        COALESCE(local_ledger_item_discount_amount, 0) as local_ledger_item_discount_amount,
                        COALESCE(local_ledger_item_tax_percent, 0) as local_ledger_item_tax_percent,
                        COALESCE(local_ledger_item_tax_amount, 0) as local_ledger_item_tax_amount
                    FROM {$item_table}
                    WHERE local_ledger_item_id IN ({$item_ids_str}) AND (is_deleted = 0 OR is_deleted IS NULL)";
                $items = $wpdb->get_results($items_sql, ARRAY_A);
            }

            $total_tax = 0;
            $total_before_tax = 0;
            $total_after_tax = 0;
            $tax_percent = 0;

            if (!empty($items)) {
                // Có items: tính theo TGS_Money::from_item() (đúng theo tài liệu)
                foreach ($items as $item) {
                    // Dùng TGS_Money::from_item() theo đúng tài liệu
                    $money = TGS_Money::from_item($item);
                    $thanh_tien = TGS_Money::lam_tron_dong($money['thanh_tien']); // Khách trả
                    $tien_hang_sau_ck = $money['tien_hang_sau_ck']; // Tiền hàng sau CK, trước thuế
                    $thue = TGS_Money::lam_tron_dong($money['thue']); // Tiền thuế

                    $total_after_tax += $thanh_tien;
                    $total_before_tax += TGS_Money::lam_tron_dong($tien_hang_sau_ck);
                    $total_tax += $thue;

                    // Lấy thuế suất đại diện (nếu hóa đơn có item thuế khác nhau thì lấy cái đầu tiên)
                    if ($tax_percent === 0 && $item['local_ledger_item_tax_percent'] > 0) {
                        $tax_percent = $item['local_ledger_item_tax_percent'];
                    }
                }
            } else {
                // Không có items: fallback về local_ledger_total_amount
                // (trường hợp này không nên xảy ra, nhưng nếu có thì vẫn hiển thị)
                $total_after_tax = (float) $row['total_after_tax'];
                $total_discount = (float) $row['total_discount'];

                // Tính ngược: total_before_tax = total_after_tax - total_tax
                // Giả sử thuế 8% (hoặc lấy từ invoice nếu có)
                // total_after_tax = total_before_tax * 1.08
                // => total_before_tax = total_after_tax / 1.08
                $tax_rate = 0.08; // Default 8%
                $total_before_tax = $total_after_tax / (1 + $tax_rate);
                $total_tax = $total_after_tax - $total_before_tax;
                $tax_percent = $tax_rate * 100;
            }

            $row['total_tax_amount'] = $total_tax;
            $row['total_before_tax'] = $total_before_tax;
            $row['total_after_tax'] = $total_after_tax; // Override với số tính từ items hoặc ledger
            $row['tax_percent'] = $tax_percent;
            $row['amount_in_words'] = self::number_to_vietnamese($total_after_tax);
            $row['item_count'] = count($items); // Đếm số items thật sự

            // Parse thông tin seller từ seller_config_json (snapshot lúc gửi invoice)
            $seller_company_name = $seller_name;
            $seller_address_final = $seller_address;
            $seller_phone_final = $seller_phone;
            $seller_tax_code_final = $seller_tax_code;

            if (!empty($row['seller_config_json'])) {
                $config = json_decode($row['seller_config_json'], true);
                if (!empty($config['company_name'])) {
                    $seller_company_name = $config['company_name'];
                }
                if (!empty($config['company_address'])) {
                    $seller_address_final = $config['company_address'];
                }
                if (!empty($config['company_phone'])) {
                    $seller_phone_final = $config['company_phone'];
                }
                if (!empty($config['supplier_tax_code'])) {
                    $seller_tax_code_final = $config['supplier_tax_code'];
                }
            }

            $row['seller_company_name'] = $seller_company_name;
            $row['seller_address'] = $seller_address_final;
            $row['seller_phone'] = $seller_phone_final;
            $row['seller_tax_code'] = $seller_tax_code_final;

            // Normalize payment_method
            if (empty($row['payment_method'])) {
                $row['payment_method'] = 'Tiền mặt';
            }

            // Xóa meta JSON khỏi response
            unset($row['local_ledger_person_meta']);
            unset($row['seller_config_json']);

            $result_rows[] = $row;
        }

        error_log("VAT Sales: Returning " . count($result_rows) . " processed rows");

        return ['rows' => $result_rows, 'site' => $site];
    }

    /**
     * Báo cáo phiếu điều chỉnh giảm VAT — mỗi lượt một site
     */
    public static function fetch_vat_adjustment()
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
        $vat_status = sanitize_text_field($_POST['vat_status'] ?? 'all');
        $doc_type = sanitize_text_field($_POST['doc_type'] ?? 'adjustment');

        if ($blog_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu blog_id']);
        }
        if ($from > $to) {
            list($from, $to) = [$to, $from];
        }

        try {
            wp_send_json_success(self::build_vat_adjustment_rows($blog_id, $zones, $from, $to, $vat_status, $doc_type));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage(), 'blog_id' => $blog_id]);
        }
    }

    /**
     * Dựng dòng báo cáo phiếu điều chỉnh giảm VAT cho một site
     */
    private static function build_vat_adjustment_rows($blog_id, array $zones, $from, $to, $vat_status, $doc_type)
    {
        global $wpdb;

        $blog_id = (int) $blog_id;
        $site = null;
        foreach (TGS_BCTK_Sites::list_sites() as $s) {
            if ($s['blog_id'] === $blog_id) { $site = $s; break; }
        }
        if (!$site) {
            return ['rows' => [], 'site' => null];
        }

        $prefix = $wpdb->get_blog_prefix($blog_id);
        $ledger_table = $prefix . 'local_ledger';
        $item_table = $prefix . 'local_ledger_item';
        $adjustment_table = $wpdb->base_prefix . 'tgs_viettel_invoice_return_adjustments';
        $person_table = $prefix . 'local_ledger_person';
        $meta_table = $prefix . 'local_ledger_meta';
        $users_table = $wpdb->users;

        // Kiểm tra bảng tồn tại
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger_table)) !== $ledger_table) {
            return ['rows' => [], 'site' => $site];
        }

        $where = ["r.local_ledger_type = 11", "r.is_deleted = 0"]; // type 11 = phiếu hoàn hàng
        $where[] = $wpdb->prepare("DATE(r.created_at) >= %s", $from);
        $where[] = $wpdb->prepare("DATE(r.created_at) <= %s", $to);

        // Lọc theo loại phiếu
        if ($doc_type === 'adjustment') {
            $where[] = "r.local_ledger_code NOT LIKE '%Z'";
        } elseif ($doc_type === 'internal') {
            $where[] = "r.local_ledger_code LIKE '%Z'";
        }

        // Lọc theo trạng thái VAT
        if ($vat_status === 'has_vat') {
            $where[] = "a.adjustment_invoice_no IS NOT NULL AND a.adjustment_invoice_no != ''";
        } elseif ($vat_status === 'no_vat') {
            $where[] = "(a.adjustment_invoice_no IS NULL OR a.adjustment_invoice_no = '')";
        } elseif ($vat_status === 'vat_error') {
            $where[] = "a.status IN ('error', 'issue_error')";
        }

        $where_sql = implode(' AND ', $where);

        // Switch to blog để lấy thông tin shop
        switch_to_blog($blog_id);
        $seller_name = get_bloginfo('name');
        $seller_address = get_option('tgs_shop_address', '');
        $seller_phone = get_option('tgs_shop_phone', '');
        $seller_tax_code = get_option('tgs_shop_tax_code', '');
        restore_current_blog();

        $sql = "SELECT
            r.local_ledger_id as return_ledger_id,
            r.local_ledger_code,
            r.local_ledger_item_id,
            r.created_at,
            r.local_ledger_total_amount as total_after_tax,
            COALESCE(r.local_ledger_discount, 0) as total_discount,
            r.local_ledger_note as return_reason,
            r.user_id as created_by,
            COALESCE(pe.local_ledger_person_phone, '') as customer_phone,
            COALESCE(pe.local_ledger_person_name, '') as buyer_name,
            COALESCE(pe.local_ledger_person_address, '') as buyer_address,
            COALESCE(pe.local_ledger_person_email, '') as buyer_email,
            COALESCE(pe.local_ledger_person_tax_code, '') as buyer_tax_code,
            pe.local_ledger_person_meta,
            s.local_ledger_code as original_sale_code,
            a.adjustment_invoice_no,
            a.original_invoice_no,
            a.status as adjustment_status,
            a.sale_ledger_id as original_sale_ledger_id,
            snap.settings_json as seller_config_json,
            COALESCE(u.display_name, '') as cashier_name,
            JSON_UNQUOTE(JSON_EXTRACT(m.local_ledger_meta_value, '$.payment_method')) as payment_method
        FROM {$ledger_table} r
        LEFT JOIN (
            SELECT return_ledger_id, blog_id, adjustment_invoice_no, original_invoice_no, status, sale_ledger_id
            FROM {$adjustment_table}
            WHERE (return_ledger_id, id) IN (
                SELECT return_ledger_id, MAX(id)
                FROM {$adjustment_table}
                GROUP BY return_ledger_id
            )
        ) a ON a.return_ledger_id = r.local_ledger_id AND a.blog_id = {$blog_id}
        LEFT JOIN {$ledger_table} s ON s.local_ledger_id = r.local_ledger_item_id
        LEFT JOIN {$person_table} pe ON pe.local_ledger_person_id = r.local_ledger_person_id
        LEFT JOIN {$meta_table} m ON m.local_ledger_meta_id = r.local_ledger_meta_id
        LEFT JOIN {$users_table} u ON u.ID = r.user_id
        LEFT JOIN (
            SELECT blog_id, sale_ledger_id, settings_json
            FROM {$wpdb->base_prefix}tgs_viettel_invoice_config_snapshots
            WHERE (blog_id, sale_ledger_id, id) IN (
                SELECT blog_id, sale_ledger_id, MAX(id)
                FROM {$wpdb->base_prefix}tgs_viettel_invoice_config_snapshots
                GROUP BY blog_id, sale_ledger_id
            )
        ) snap ON snap.blog_id = {$blog_id} AND snap.sale_ledger_id = a.sale_ledger_id
        WHERE {$where_sql}
        ORDER BY r.created_at DESC
        LIMIT 5000";

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (empty($rows)) {
            return ['rows' => [], 'site' => $site];
        }

        // Tính toán tiền
        if (!self::money_ready()) {
            return ['rows' => [], 'site' => $site];
        }

        $result_rows = [];
        foreach ($rows as $row) {
            $return_id = (int) $row['return_ledger_id'];

            // Parse company name từ person_meta JSON
            $buyer_company_name = '';
            if (!empty($row['local_ledger_person_meta'])) {
                $meta = json_decode($row['local_ledger_person_meta'], true);
                $buyer_company_name = $meta['company_name'] ?? $meta['company'] ?? '';
            }
            $row['buyer_company_name'] = $buyer_company_name;

            // Lấy các item - dựa vào local_ledger_item_id (JSON array)
            $item_ids_json = $row['local_ledger_item_id'] ?? '[]';
            $item_ids = json_decode($item_ids_json, true);

            $items = [];
            if (!empty($item_ids) && is_array($item_ids)) {
                $item_ids_str = implode(',', array_map('intval', $item_ids));
                $items_sql = "SELECT
                        quantity,
                        price,
                        COALESCE(local_ledger_item_discount_amount, 0) as local_ledger_item_discount_amount,
                        COALESCE(local_ledger_item_tax_percent, 0) as local_ledger_item_tax_percent,
                        COALESCE(local_ledger_item_tax_amount, 0) as local_ledger_item_tax_amount
                    FROM {$item_table}
                    WHERE local_ledger_item_id IN ({$item_ids_str}) AND (is_deleted = 0 OR is_deleted IS NULL)";
                $items = $wpdb->get_results($items_sql, ARRAY_A);
            }

            $total_tax = 0;
            $total_before_tax = 0;
            $total_after_tax = 0;
            $tax_percent = 0;

            if (!empty($items)) {
                // Có items: tính theo TGS_Money::from_item() (đúng theo tài liệu)
                foreach ($items as $item) {
                    // Dùng TGS_Money::from_item() theo đúng tài liệu
                    $money = TGS_Money::from_item($item);
                    $thanh_tien = TGS_Money::lam_tron_dong($money['thanh_tien']);
                    $tien_hang_sau_ck = $money['tien_hang_sau_ck'];
                    $thue = TGS_Money::lam_tron_dong($money['thue']);

                    // Phiếu hoàn thì lấy giá trị tuyệt đối (số dương)
                    $total_after_tax += abs($thanh_tien);
                    $total_before_tax += abs(TGS_Money::lam_tron_dong($tien_hang_sau_ck));
                    $total_tax += abs($thue);

                    if ($tax_percent === 0 && $item['local_ledger_item_tax_percent'] > 0) {
                        $tax_percent = abs($item['local_ledger_item_tax_percent']);
                    }
                }
            } else {
                // Không có items: fallback về local_ledger_total_amount
                $total_after_tax = abs((float) $row['total_after_tax']);

                // Tính ngược với thuế 8%
                $tax_rate = 0.08;
                $total_before_tax = $total_after_tax / (1 + $tax_rate);
                $total_tax = $total_after_tax - $total_before_tax;
                $tax_percent = $tax_rate * 100;
            }

            $row['total_tax_amount'] = $total_tax;
            $row['total_before_tax'] = $total_before_tax;
            $row['total_after_tax'] = $total_after_tax;
            $row['tax_percent'] = $tax_percent;
            $row['amount_in_words'] = self::number_to_vietnamese($total_after_tax);
            $row['item_count'] = count($items); // Đếm số items thật sự
            $row['invoice_series'] = '';
            $row['template_code'] = '';

            // Parse thông tin seller từ seller_config_json (snapshot lúc gửi invoice)
            $seller_company_name = $seller_name;
            $seller_address_final = $seller_address;
            $seller_phone_final = $seller_phone;
            $seller_tax_code_final = $seller_tax_code;

            if (!empty($row['seller_config_json'])) {
                $config = json_decode($row['seller_config_json'], true);
                if (!empty($config['company_name'])) {
                    $seller_company_name = $config['company_name'];
                }
                if (!empty($config['company_address'])) {
                    $seller_address_final = $config['company_address'];
                }
                if (!empty($config['company_phone'])) {
                    $seller_phone_final = $config['company_phone'];
                }
                if (!empty($config['supplier_tax_code'])) {
                    $seller_tax_code_final = $config['supplier_tax_code'];
                }
            }

            $row['seller_company_name'] = $seller_company_name;
            $row['seller_address'] = $seller_address_final;
            $row['seller_phone'] = $seller_phone_final;
            $row['seller_tax_code'] = $seller_tax_code_final;
            $row['invoice_note'] = $row['return_reason'];

            // Normalize payment_method
            if (empty($row['payment_method'])) {
                $row['payment_method'] = 'Tiền mặt';
            }

            // Xóa meta JSON khỏi response
            unset($row['local_ledger_person_meta']);
            unset($row['seller_config_json']);

            $result_rows[] = $row;
        }

        return ['rows' => $result_rows, 'site' => $site];
    }
}


TGS_BCTK_Ajax::init();
