<?php

/**
 * BÁO CÁO PHIẾU XUẤT BÁN VAT
 * =============================================================================
 *
 * Lọc dữ liệu theo shop đã khai trong TGS_BCTK_Vat_Shops.
 * Tiền tính ĐÚNG theo mo-hinh-tien-va-bang-local-ledger-item.md:
 *   Làm tròn từng dòng trước, cộng sau.
 *
 * @package tgs-bc-tk
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_BCTK_Vat_Report
{
    const TYPE_SALE       = 10;
    const TYPE_RETURN     = 12;

    // ─── AJAX ──────────────────────────────────────────────────────────────

    public static function ajax_sales()
    {
        check_ajax_referer('tgs_bctk_vat_nonce', 'nonce');

        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Không có quyền.'], 403);
        }

        $blog_ids = TGS_BCTK_Vat_Shops::active_blog_ids();
        if (empty($blog_ids)) {
            wp_send_json_success(['items' => [], 'total' => 0, 'message' => 'Chưa khai shop nào áp dụng thuế.']);
        }

        $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
        $date_to   = isset($_POST['date_to'])   ? sanitize_text_field(wp_unslash($_POST['date_to']))   : '';
        $vat_status = isset($_POST['vat_status']) ? sanitize_text_field(wp_unslash($_POST['vat_status'])) : 'all'; // all|has_vat|no_vat|error
        $limit      = isset($_POST['limit']) ? max(1, min(500, intval($_POST['limit']))) : 200;
        $offset     = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;

        $items = self::fetch_sales($blog_ids, $date_from, $date_to, $vat_status, $limit, $offset);

        wp_send_json_success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    // ─── TRUY VẤN ──────────────────────────────────────────────────────

    /**
     * @param int[]   $blog_ids   Shop đang áp dụng VAT
     * @param string  $date_from YYYY-MM-DD
     * @param string  $date_to   YYYY-MM-DD
     * @param string  $vat_status all|has_vat|no_vat|error
     * @param int     $limit
     * @param int     $offset
     * @return array
     */
    public static function fetch_sales(array $blog_ids, $date_from, $date_to, $vat_status = 'all', $limit = 200, $offset = 0)
    {
        global $wpdb;

        $blog_in = implode(',', array_map('intval', $blog_ids));

        // ── Tiền từng dòng, làm tròn từng dòng rồi cộng ─────────────────
        // JOIN items theo JSON trong local_ledger_item_id
        $item_agg = "(
            SELECT
                li.local_ledger_id,
                SUM(li.quantity * li.price) AS tien_hang_truoc_ck,
                SUM(li.quantity * li.price - li.local_ledger_item_discount_amount) AS tien_sau_ck_raw,
                SUM(li.local_ledger_item_discount_amount) AS tong_ck,
                SUM(ROUND(li.quantity * li.price - li.local_ledger_item_discount_amount)) AS tien_sau_ck,
                SUM(li.local_ledger_item_tax_amount) AS tong_thue_raw,
                SUM(ROUND(li.local_ledger_item_tax_amount)) AS tong_thue,
                SUM(
                    ROUND(li.quantity * li.price - li.local_ledger_item_discount_amount)
                    + ROUND(li.local_ledger_item_tax_amount)
                ) AS thanh_tien_dong,
                COUNT(*) AS sl_ban_ghi,
                -- Thuế suất đại diện: nếu có dòng khác 8% thì ghi rõ, không thì 8%
                GROUP_CONCAT(
                    DISTINCT IF(li.local_ledger_item_tax_percent > 0,
                        ROUND(li.local_ledger_item_tax_percent, 1), NULL)
                    ORDER BY IF(li.local_ledger_item_tax_percent > 0,
                        ROUND(li.local_ledger_item_tax_percent, 1), NULL)
                ) AS cac_ty_le_thue
            FROM {$wpdb->prefix}local_ledger_item li
            WHERE li.is_deleted = 0
            GROUP BY li.local_ledger_id
        ) AS ia";

        $sql = "SELECT
                    l.blog_id,
                    l.local_ledger_id,
                    l.local_ledger_code,
                    l.local_ledger_note,
                    l.local_ledger_note AS ghi_chu_hoa_don,
                    l.created_at AS ngay_hoa_don,
                    ia.tien_hang_truoc_ck,
                    ia.tien_sau_ck,
                    ia.tong_ck AS tong_chiet_khau,
                    ia.tong_thue AS tong_thue,
                    ia.thanh_tien_dong AS thanh_tien,
                    ia.sl_ban_ghi,
                    ia.cac_ty_le_thue,

                    -- VAT invoice
                    vi.invoice_series,
                    vi.template_code,
                    vi.invoice_state,
                    vi.invoice_no,
                    vi.issue_status,
                    vi.send_cqt_status,
                    vi.buyer_name,
                    vi.buyer_tax_code,
                    vi.total_before_tax AS vi_total_before_tax,
                    vi.total_tax_amount AS vi_total_tax,
                    vi.total_after_tax AS vi_total_after_tax,
                    vi.issue_http_code,
                    vi.cqt_http_code,
                    vi.error_message,

                    -- Người mua
                    p.local_ledger_person_name  AS ten_kh,
                    p.local_ledger_person_phone AS sdt_kh,
                    p.local_ledger_person_address AS dia_chi_kh,
                    p.local_ledger_person_email AS email_kh,
                    p.local_ledger_person_tax_code AS mst_kh,

                    -- Người xuất
                    u.display_name AS nhan_vien_xuat,
                    l.user_id AS user_id_xuat,

                    -- Meta JSON
                    lm.local_ledger_meta_value AS meta_json,

                    -- Liên kết bill Z
                    CASE
                        WHEN l.local_ledger_parent_id IS NOT NULL
                             AND parent.local_ledger_code = CONCAT(l.local_ledger_code,
                                COALESCE(
                                    (SELECT CONCAT((SELECT value FROM {$wpdb->prefix}local_ledger_meta WHERE local_ledger_meta_id =
                                        (SELECT local_ledger_meta_id FROM {$wpdb->prefix}local_ledger WHERE local_ledger_id = l.local_ledger_parent_id)),'Z')
                                    FROM DUAL), 'Z')
                            )
                        THEN 1 ELSE 0
                    END AS is_bill_z_child,
                    CASE
                        WHEN parent.local_ledger_code IS NOT NULL
                        THEN parent.local_ledger_code
                        ELSE ''
                    END AS parent_bill_code,

                    -- Phiếu xuất kho đi kèm
                    ex.local_ledger_code AS so_phieu_xuat,
                    ex.created_at AS ngay_xuat

                FROM {$wpdb->prefix}local_ledger l

                INNER JOIN ({$item_agg}) ia ON ia.local_ledger_id = l.local_ledger_id

                LEFT JOIN {$wpdb->prefix}local_ledger parent ON parent.local_ledger_id = l.local_ledger_parent_id

                LEFT JOIN {$wpdb->prefix}local_viettel_invoice vi
                    ON vi.sale_ledger_id = l.local_ledger_id
                    AND vi.is_deleted = 0

                LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id

                LEFT JOIN {$wpdb->prefix}local_ledger_person p
                    ON p.local_ledger_person_id = l.local_ledger_person_id

                LEFT JOIN {$wpdb->prefix}local_ledger_meta lm
                    ON lm.local_ledger_meta_id = l.local_ledger_meta_id

                -- Phiếu xuất kho type=2 cùng blog, cùng ngày, cùng user
                LEFT JOIN {$wpdb->prefix}local_ledger ex
                    ON ex.blog_id = l.blog_id
                    AND ex.local_ledger_type = 2
                    AND ex.user_id = l.user_id
                    AND DATE(ex.created_at) = DATE(l.created_at)
                    AND ex.is_deleted = 0
                    AND ex.local_ledger_code IS NOT NULL

                WHERE l.blog_id IN ({$blog_in})
                  AND l.local_ledger_type = %d
                  AND (l.is_deleted = 0 OR l.is_deleted IS NULL)";

        $args = [self::TYPE_SALE];

        if ($date_from !== '') {
            $sql .= ' AND l.created_at >= %s';
            $args[] = $date_from . ' 00:00:00';
        }
        if ($date_to !== '') {
            $sql .= ' AND l.created_at <= %s';
            $args[] = $date_to . ' 23:59:59';
        }

        // VAT status filter
        if ($vat_status === 'has_vat') {
            $sql .= " AND vi.local_viettel_invoice_id IS NOT NULL AND vi.invoice_state IN ('done','issued')";
        } elseif ($vat_status === 'no_vat') {
            $sql .= " AND (vi.local_viettel_invoice_id IS NULL OR vi.invoice_state = 'unsent')";
        } elseif ($vat_status === 'error') {
            $sql .= " AND vi.invoice_state IN ('error','issue_error','cqt_error','validate_error')";
        }

        $sql .= ' ORDER BY l.created_at DESC LIMIT %d OFFSET %d';
        $args[] = $limit;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return array_map([__CLASS__, 'enrich_row'], $rows);
    }

    // ─── XỬ LÝ TỪNG DÒNG ─────────────────────────────────────────────

    private static function enrich_row(array $row)
    {
        // Meta JSON — thanh toán
        $meta = json_decode((string) ($row['meta_json'] ?? ''), true);
        $row['hinh_thuc_thanh_toan'] = sanitize_text_field($meta['payment_method_label'] ?? $meta['payment_method'] ?? 'Tiền mặt');
        $row['payment_method'] = sanitize_text_field($meta['payment_method'] ?? 'cash');

        // Số lượng sản phẩm
        $row['sl_ban_ghi'] = intval($row['sl_ban_ghi'] ?? 0);

        // Thành tiền bằng chữ
        $row['thanh_tien_bang_chu'] = self::doc_so($row['thanh_tien'] ?? 0);

        // Tỷ lệ thuế đại diện
        $row['ty_le_thue_dai_dien'] = self::ty_le_thue_label($row['cac_ty_le_thue'] ?? '');

        // Trạng thái VAT
        $row['vat_status_label'] = self::vat_status_label($row);
        $row['vat_status_code']  = self::vat_status_code($row);

        // Dãy số hóa đơn (Seri)
        $row['seri'] = sanitize_text_field($row['invoice_series'] ?? '');
        $row['mau_hd'] = sanitize_text_field($row['template_code'] ?? '');

        // Trạng thái gửi CQT
        $row['trang_thai_cqt'] = self::cqt_label($row);

        // Số hóa đơn
        $row['so_hoa_don'] = sanitize_text_field($row['invoice_no'] ?? '');

        // Địa chỉ công ty bên bán (lấy từ settings)
        $settings = TGS_Viettel_Invoice::get_settings($row['blog_id'] ?? 0);
        $row['dia_chi_cong_ty_ban']  = sanitize_text_field($settings['company_address'] ?? '');
        $row['ten_cong_ty_ban']     = sanitize_text_field($settings['company_name'] ?? '');
        $row['dien_thoai_cong_ty_ban'] = sanitize_text_field($settings['company_phone'] ?? '');
        $row['mst_cong_ty_ban']     = sanitize_text_field($settings['supplier_tax_code'] ?? '');

        // Số SO — để trống theo yêu cầu
        $row['so_so'] = '';

        // Lý do cứ để XBA
        $row['ly_do'] = 'XBA';

        return $row;
    }

    // ─── TRẠNG THÁI VAT ───────────────────────────────────────────────

    private static function vat_status_code(array $row)
    {
        $inv_id = intval($row['vi_invoice_id'] ?? 0);
        $state  = strtolower((string) ($row['invoice_state'] ?? ''));
        $has_vi = $inv_id > 0;

        if (!$has_vi) {
            return 'no_vat';
        }
        if (in_array($state, ['done', 'issued'], true)) {
            return 'sent';
        }
        return 'error';
    }

    private static function vat_status_label(array $row)
    {
        $code = self::vat_status_code($row);

        switch ($code) {
            case 'sent':
                return 'Đã có hóa đơn điện tử';
            case 'no_vat':
                return 'Chưa có thông tin VAT';
            case 'error':
                return 'Lỗi: ' . sanitize_text_field(substr((string) ($row['error_message'] ?? ''), 0, 60));
            default:
                return sanitize_text_field($row['invoice_state'] ?? '');
        }
    }

    private static function cqt_label(array $row)
    {
        $status = intval($row['send_cqt_status'] ?? 0);
        $code   = intval($row['cqt_http_code'] ?? 0);
        $state  = strtolower((string) ($row['invoice_state'] ?? ''));

        if (in_array($state, ['done', 'issued'], true) && $status === 1) {
            return 'Hóa đơn có chữ ký số';
        }
        if ($status === 0 && $code === 0) {
            return 'Hóa đơn chưa lập VAT';
        }
        if (in_array($state, ['error', 'issue_error', 'cqt_error', 'validate_error'], true)) {
            return 'Lỗi gửi';
        }
        return 'Chưa lập VAT';
    }

    private static function ty_le_thue_label($cac_ty_le)
    {
        if ($cac_ty_le === '' || $cac_ty_le === null) {
            return '0%';
        }
        $rates = array_unique(array_filter(array_map('floatval', explode(',', $cac_ty_le))));
        if (count($rates) === 0) {
            return '0%';
        }
        if (count($rates) === 1) {
            $r = reset($rates);
            return $r > 0 ? round($r, 1) . '%' : '0%';
        }
        return implode(', ', array_map(function ($r) {
            return $r > 0 ? round($r, 1) . '%' : '0%';
        }, $rates));
    }

    // ─── ĐỌC SỐ THÀNH CHỮ ─────────────────────────────────────────────

    /**
     * Đọc số thành chữ tiền Việt Nam — không dùng thư viện ngoài.
     * Ví dụ: 78000 → "Bảy mươi tám nghìn đồng chẵn"
     */
    public static function doc_so($amount)
    {
        $amount = abs(round(floatval($amount)));

        if ($amount === 0) {
            return 'Không đồng chẵn';
        }

        $don_vi = ['', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $chuc    = ['mười', 'hai mươi', 'ba mươi', 'bốn mươi', 'năm mươi',
                     'sáu mươi', 'bảy mươi', 'tám mươi', 'chín mươi'];
        $tram    = ['một trăm', 'hai trăm', 'ba trăm', 'bốn trăm', 'năm trăm',
                     'sáu trăm', 'bảy trăm', 'tám trăm', 'chín trăm'];
        $nghin   = ['nghìn', 'triệu', 'tỷ'];

        $str = '';
        $n = $amount;

        // Tỷ
        $ty = intval($n / 1000000000);
        $n  = $n % 1000000000;
        if ($ty > 0) {
            $str .= self::doc_so3($ty, $don_vi, $chuc, $tram) . ' tỷ ';
        }

        // Triệu
        $trieu = intval($n / 1000000);
        $n     = $n % 1000000;
        if ($trieu > 0) {
            $str .= self::doc_so3($trieu, $don_vi, $chuc, $tram) . ' triệu ';
        }

        // Nghìn
        $ngan = intval($n / 1000);
        $n    = $n % 1000;
        if ($ngan > 0) {
            $str .= self::doc_so3($ngan, $don_vi, $chuc, $tram) . ' nghìn ';
        }

        // Trăm
        $tr = intval($n);
        if ($tr > 0) {
            $str .= self::doc_so3($tr, $don_vi, $chuc, $tram);
        }

        $str = trim($str);
        $str = preg_replace('/\s+/', ' ', $str);

        return ucfirst($str) . ' đồng chẵn';
    }

    private static function doc_so3($n, $don_vi, $chuc, $tram)
    {
        if ($n < 10) {
            return $don_vi[$n];
        }
        if ($n < 100) {
            $dv = $n % 10;
            $ch = intval($n / 10);
            $s  = $chuc[$ch - 1];
            if ($dv > 0) {
                if ($dv === 5) {
                    $s .= ' lăm';
                } elseif ($dv === 1) {
                    $s .= ' mốt';
                } else {
                    $s .= ' ' . $don_vi[$dv];
                }
            }
            return $s;
        }
        // Trăm
        $tr = intval($n / 100);
        $du = $n % 100;
        $s  = $tram[$tr - 1];
        if ($du > 0) {
            $s .= ' ' . self::doc_so3($du, $don_vi, $chuc, $tram);
        }
        return $s;
    }
}
