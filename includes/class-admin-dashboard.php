<?php
/**
 * Admin Dashboard Widget for payment summaries.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Dashboard
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
    }

    /**
     * Register the dashboard widget.
     */
    public function register_widget(): void
    {
        if (!current_user_can('bdg_view_payments')) {
            return;
        }

        wp_add_dashboard_widget(
            'bdg_payment_summary',
            __('Resumen de Pagos Biodevas', 'convoca-gateway'),
            [$this, 'render_widget']
        );
    }

    /**
     * Render the widget content.
     */
    public function render_widget(): void
    {
        $data = $this->get_dashboard_data();

        ?>
        <div class="bdg-dashboard-widget">
            <div class="bdg-stats-grid">
                <div class="bdg-stat">
                    <span class="bdg-stat-label"><?php esc_html_e('Total este mes', 'convoca-gateway'); ?></span>
                    <span class="bdg-stat-value"><?php echo esc_html(CPT_Pago::format_amount($data['total_month'])); ?></span>
                </div>
                <div class="bdg-stat">
                    <span class="bdg-stat-label"><?php esc_html_e('Pagos hoy', 'convoca-gateway'); ?></span>
                    <span class="bdg-stat-value"><?php echo esc_html($data['count_today']); ?></span>
                </div>
            </div>

            <div class="bdg-section">
                <h4><?php esc_html_e('Métodos de pago (Mes)', 'convoca-gateway'); ?></h4>
                <div class="bdg-methods">
                    <div class="bdg-method-bar">
                        <div class="bdg-method-fill card" style="width: <?php echo esc_attr($data['methods']['tarjeta_pct']); ?>%;"></div>
                        <div class="bdg-method-fill bizum" style="width: <?php echo esc_attr($data['methods']['bizum_pct']); ?>%;"></div>
                    </div>
                    <div class="bdg-method-labels">
                        <span>💳 <?php echo esc_html($data['methods']['tarjeta_pct']); ?>% <?php esc_html_e('Tarjeta', 'convoca-gateway'); ?></span>
                        <span>📱 <?php echo esc_html($data['methods']['bizum_pct']); ?>% <?php esc_html_e('Bizum', 'convoca-gateway'); ?></span>
                    </div>
                </div>
            </div>

            <div class="bdg-section">
                <h4><?php esc_html_e('Últimos 7 días', 'convoca-gateway'); ?></h4>
                <table class="bdg-chart-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Día', 'convoca-gateway'); ?></th>
                            <th><?php esc_html_e('Pagos', 'convoca-gateway'); ?></th>
                            <th><?php esc_html_e('Total', 'convoca-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['last_7_days'] as $day): ?>
                            <tr>
                                <td><?php echo esc_html($day['label']); ?></td>
                                <td><?php echo esc_html($day['count']); ?></td>
                                <td><?php echo esc_html(CPT_Pago::format_amount($day['total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="bdg-footer-links">
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=pago')); ?>" class="button"><?php esc_html_e('Ver todos los pagos', 'convoca-gateway'); ?></a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=bdg-settings')); ?>" class="button"><?php esc_html_e('Configuración', 'convoca-gateway'); ?></a>
            </p>
        </div>

        <style>
            .bdg-stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
            .bdg-stat { background: #f6f7f7; padding: 15px; border-radius: 4px; border-left: 4px solid #0073aa; }
            .bdg-stat-label { display: block; font-size: 12px; color: #646970; }
            .bdg-stat-value { display: block; font-size: 20px; font-weight: 600; margin-top: 5px; }
            .bdg-section h4 { margin: 15px 0 10px; border-bottom: 1px solid #dcdcde; padding-bottom: 5px; }
            .bdg-method-bar { height: 12px; background: #dcdcde; border-radius: 6px; overflow: hidden; display: flex; margin-bottom: 5px; }
            .bdg-method-fill.card { background: #0073aa; }
            .bdg-method-fill.bizum { background: #46b450; }
            .bdg-method-labels { display: flex; justify-content: space-between; font-size: 11px; }
            .bdg-chart-table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .bdg-chart-table th { text-align: left; padding: 5px; background: #f6f7f7; }
            .bdg-chart-table td { padding: 5px; border-bottom: 1px solid #f0f0f1; }
            .bdg-footer-links { margin-top: 20px; border-top: 1px solid #dcdcde; padding-top: 15px; display: flex; gap: 10px; }
        </style>
        <?php
    }

    /**
     * Get data for the dashboard widget with caching.
     */
    private function get_dashboard_data(): array
    {
        global $wpdb;

        $data = get_transient('bdg_dashboard_stats');
        if (false !== $data) {
            return $data;
        }

        $now = time();
        $month_start = strtotime('first day of this month 00:00:00', $now);
        $today_start = strtotime('today 00:00:00', $now);
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;

        // Single aggregation query: all paid payments this month
        $paid_posts = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM $posts p
             INNER JOIN $postmeta m ON m.post_id = p.ID AND m.meta_key = '_bdg_status' AND m.meta_value = 'paid'
             WHERE p.post_type = 'pago'
               AND p.post_status = 'publish'
               AND p.post_date >= %s",
            wp_date('Y-m-d H:i:s', $month_start)
        ));

        if (empty($paid_posts)) {
            $data = [
                'total_month'  => 0,
                'count_today'  => 0,
                'count_month'  => 0,
                'methods_count' => ['tarjeta' => 0, 'bizum' => 0],
                'methods_pct'  => ['tarjeta_pct' => 0, 'bizum_pct' => 0],
                'last_7_days'  => [],
            ];
            set_transient('bdg_dashboard_stats', $data, 300);
            return $data;
        }

        // Bulk load meta for all paid posts this month (2 extra queries instead of N×3)
        update_meta_cache('post', $paid_posts);

        $total_month = 0;
        $count_today = 0;
        $methods_count = ['tarjeta' => 0, 'bizum' => 0];

        foreach ($paid_posts as $pid) {
            $amount = (int) get_post_meta($pid, '_bdg_amount_cents', true);
            $total_month += $amount;

            $method = get_post_meta($pid, '_bdg_method', true);
            if (isset($methods_count[$method])) {
                $methods_count[$method]++;
            }

            $paid_at = get_post_meta($pid, '_bdg_paid_at', true);
            if ($paid_at && strtotime($paid_at) >= $today_start) {
                $count_today++;
            }
        }

        $total_methods = array_sum($methods_count);
        $methods_pct = [
            'tarjeta_pct' => $total_methods > 0 ? round(($methods_count['tarjeta'] / $total_methods) * 100) : 0,
            'bizum_pct'   => $total_methods > 0 ? round(($methods_count['bizum'] / $total_methods) * 100) : 0,
        ];

        // Last 7 days via a single GROUP BY query
        $week_ago = $today_start - 6 * DAY_IN_SECONDS;
        $day_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(p.post_date) AS day, COUNT(*) AS cnt, COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)), 0) AS total
             FROM $posts p
             INNER JOIN $postmeta ms ON ms.post_id = p.ID AND ms.meta_key = '_bdg_status' AND ms.meta_value = 'paid'
             INNER JOIN $postmeta ma ON ma.post_id = p.ID AND ma.meta_key = '_bdg_amount_cents'
             WHERE p.post_type = 'pago'
               AND p.post_status = 'publish'
               AND p.post_date >= %s
               AND p.post_date < %s
             GROUP BY DATE(p.post_date)
             ORDER BY day ASC",
            wp_date('Y-m-d H:i:s', $week_ago),
            wp_date('Y-m-d H:i:s', $today_start + DAY_IN_SECONDS)
        ));

        $day_map = [];
        foreach ($day_rows as $row) {
            $day_map[$row->day] = ['count' => (int) $row->cnt, 'total' => (int) $row->total];
        }

        $last_7_days = [];
        for ($i = 6; $i >= 0; $i--) {
            $ts = strtotime("-{$i} days", $today_start);
            $label = wp_date('d M', $ts);
            $key = wp_date('Y-m-d', $ts);
            $last_7_days[] = [
                'label' => $label,
                'count' => $day_map[$key]['count'] ?? 0,
                'total' => $day_map[$key]['total'] ?? 0,
            ];
        }

        $data = [
            'total_month'   => $total_month,
            'count_today'   => $count_today,
            'count_month'   => count($paid_posts),
            'methods_count' => $methods_count,
            'methods_pct'   => $methods_pct,
            'last_7_days'   => $last_7_days,
        ];

        set_transient('bdg_dashboard_stats', $data, 300);

        return $data;
    }
}
