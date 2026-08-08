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
                'dvt'     => (string) ($p['unit'] ?? ''),
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

            $gia_dvcb = $qty > 0 ? round($goc / $qty) : 0.0;

            /*
             * Lấy lại tiền gốc THEO ĐƠN GIÁ ĐÃ LÀM TRÒN, để người đọc nhân tay
             * ra đúng con số trên giấy. Kẹp sàn bằng thành tiền để chiết khấu
             * không bao giờ âm khi đơn giá bị làm tròn xuống.
             */
            $goc      = max($gia_dvcb * $qty, $thanh_tien);
            $ck_hien  = max(0.0, $goc - $thanh_tien);

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

                /* ĐVCB = đơn vị nhỏ nhất khai ở sản phẩm global; thiếu thì lấy
                   đơn vị của bản ghi sản phẩm tại site */
                'dvcb'     => (string) ($g['dvcb'] ?: ($p['unit'] ?? $r['dvcb_local'])),
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

                'dvt'      => (string) $r['dvt_ban'],
                'sl_dvmr'  => (float) $r['sl_dvmr'],
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
}

TGS_BCTK_Ajax::init();
