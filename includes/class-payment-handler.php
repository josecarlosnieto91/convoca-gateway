<?php
/**
 * Payment handler: creates payments, renders payment page, processes Redsys notifications.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Payment_Handler
{
    /**
     * Get a persistent salt for hashing payment links.
     * This avoids links breaking when AUTH_SALT is changed in wp-config.php.
     */
    private static function get_persistent_salt(): string
    {
        $salt = get_option('bdg_persistent_salt');
        if (!$salt) {
            $salt = wp_generate_password(64, true, true);
            update_option('bdg_persistent_salt', $salt, 'no');
        }
        return $salt;
    }


    private string $upload_error = '';
    private bool $upload_success = false;

    public function __construct()
    {
        // Assets.
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        // Payment page shortcode.
        add_shortcode('biodevas_pago', [$this, 'render_payment_page']);

        // Return pages.
        add_shortcode('biodevas_pago_ok', [$this, 'render_ok_page']);
        add_shortcode('biodevas_pago_ko', [$this, 'render_ko_page']);
    }

    public function register_assets(): void
    {
        wp_register_script(
            'bdg-redsys',
            BDG_URL . 'assets/js/redsys.js',
            [],
            BDG_VERSION,
            true
        );
    }

    /* ── Public API ────────────────────────────── */

    /**
     * Get the stored recurring token for a member.
     */
    public static function get_member_token(int $member_id): string
    {
        global $wpdb;
        $token = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_tok.meta_value 
             FROM {$wpdb->postmeta} pm_orig
             JOIN {$wpdb->postmeta} pm_tok ON pm_orig.post_id = pm_tok.post_id AND pm_tok.meta_key = '_bdg_redsys_merchant_id'
             JOIN {$wpdb->postmeta} pm_stat ON pm_orig.post_id = pm_stat.post_id AND pm_stat.meta_key = '_bdg_status' AND pm_stat.meta_value = 'paid'
             WHERE pm_orig.meta_key = '_bdg_origin_id' AND pm_orig.meta_value = %d
               AND pm_tok.meta_value != ''
             ORDER BY pm_orig.post_id DESC LIMIT 1",
            $member_id
        ));
        return (string) ($token ?: '');
    }

    /**
     * Create a payment and return the URL to the payment page.
     *
     * @param array $data {
     *     @type int    $amount_cents   Amount in cents. (> 0)
     *     @type string $method         tarjeta | bizum (optional, user chooses).
     *     @type string $origin         enroll | members.
     *     @type int    $origin_id      Post ID of inscription/miembro.
     *     @type string $product_desc   Bank statement description (max 125).
     *     @type bool   $tokenize       Whether to request card tokenization (for recurring).
     * }
     * @return array{pago_id: int, payment_url: string}|\WP_Error
     */
    public static function create_payment(array $data): array|\WP_Error
    {
        // Validation.
        $amount = (int) ($data['amount_cents'] ?? 0);
        if ($amount <= 0) {
            return new \WP_Error('invalid_amount', 'El importe debe ser mayor que cero.');
        }

        $origin = $data['origin'] ?? '';
        if (!in_array($origin, ['enroll', 'members'], true)) {
            return new \WP_Error('invalid_origin', 'Origen de pago no válido.');
        }

        $origin_id = (int) ($data['origin_id'] ?? 0);
        if (!$origin_id || !get_post($origin_id)) {
            return new \WP_Error('invalid_origin_id', 'ID de origen no válido.');
        }

        if (isset($data['product_desc']) && strlen($data['product_desc']) > 125) {
            $data['product_desc'] = substr($data['product_desc'], 0, 122) . '...';
        }

        $pago_id = CPT_Pago::create($data);
        if (is_wp_error($pago_id)) {
            return $pago_id;
        }

        if (!empty($data['tokenize'])) {
            update_post_meta($pago_id, '_bdg_tokenize', '1');
        }

        $payment_url = self::get_payment_link($pago_id);

        return [
            'pago_id' => $pago_id,
            'payment_url' => $payment_url,
        ];
    }

    /**
     * Get the URL of the payment page.
     * Looks for a page with [biodevas_pago] shortcode, or falls back to a default.
     */
    public static function get_payment_page_url(): string
    {
        $settings = get_option('bdg_settings', []);
        $page_id = (int) ($settings['payment_page_id'] ?? 0);

        if ($page_id) {
            $url = get_permalink($page_id);
        } else {
            $url = home_url('/pago/');
        }

        // Force HTTPS if available
        if (is_ssl()) {
            $url = set_url_scheme($url, 'https');
        }

        return $url;
    }

    /**
     * Generate a signed payment link for an existing payment.
     *
     * @param int   $pago_id    The payment post ID.
     * @param string $token      Optional token (for link payment).
     * @param int   $expires_ts Optional expiration timestamp.
     * @return string The signed URL.
     */
    public static function get_payment_link(int $pago_id, string $token = '', ?int $expires_ts = null): string
    {
        $base_url = self::get_payment_page_url();

        $args = ['bdg_pago' => $pago_id];

        if ($token) {
            $args['bdg_key'] = $token;
            // No longer exposing expiration in URL for security/clarity.
        } else {
            $ts = time();
            $args['bdg_t'] = $ts;
            $args['bdg_key'] = hash_hmac('sha256', $pago_id . '|' . $ts . '_bdg_payment', self::get_persistent_salt());
        }

        return add_query_arg($args, $base_url);
    }

    /* ── Payment page rendering ────────────────── */

    /**
     * Render the payment method selection + Redsys redirect.
     */
    public function render_payment_page($atts): string
    {
        // Handle manual form submission first.
        if (isset($_POST['bdg_manual_payment']) && check_admin_referer('bdg_manual_payment_action', 'bdg_manual_nonce')) {
            return $this->handle_manual_payment_submission();
        }

        $pago_id = (int) ($_GET['bdg_pago'] ?? 0);
        $key = sanitize_text_field($_GET['bdg_key'] ?? '');

        // Handle proof of payment upload.
        if (isset($_POST['bdg_upload_proof']) && check_admin_referer('bdg_proof_upload_action', 'bdg_proof_nonce')) {
            $upload_result = $this->handle_proof_upload($pago_id);
            if (is_wp_error($upload_result)) {
                $this->upload_error = $upload_result->get_error_message();
            } else {
                $this->upload_success = true;
            }
        }

        if (!$pago_id || !$key) {
            return $this->render_manual_form();
        }

        $expires_param = $_GET['bdg_expires'] ?? null;
        $legacy_ts = (int) ($_GET['bdg_t'] ?? 0);

        // 1. Check if it is a legacy link (uses bdg_t).
        if ($legacy_ts > 0) {
            return $this->render_legacy_payment_page($pago_id, $legacy_ts, $key);
        }

        // 2. Otherwise, treat as a new link payment (link generator).
        // The expiration is optional in the URL (it's 0 for 'never'), 
        // we'll use the param if present or 0 otherwise.
        return $this->render_link_payment_page($pago_id, $key, (int) ($expires_param ?? 0));
    }

    /**
     * Render payment page for link-generated payments (new system).
     */
    private function render_link_payment_page(int $pago_id, string $key, int $expires_ts): string
    {
        $post = get_post($pago_id);
        if (!$post || $post->post_type !== 'pago') {
            return '<div class="biodevas-alert biodevas-alert--danger">Pago no encontrado.</div>';
        }

        $stored_key = get_post_meta($pago_id, '_bdg_link_key', true);
        if (!$stored_key || !hash_equals($stored_key, $key)) {
            \Convoca\Core\Logger::warning("Intento de acceso con token inválido. Pago ID: $pago_id", 'Gateway/LinkPayment', $pago_id);
            return '<div class="biodevas-alert biodevas-alert--danger">Enlace de pago inválido.</div>';
        }

        $stored_expires = get_post_meta($pago_id, '_bdg_expires_at', true);
        if ($stored_expires && $stored_expires < time()) {
            return '<div class="biodevas-alert biodevas-alert--warning">El enlace de pago ha caducado. Por favor, contacta con el administrador para solicitar uno nuevo.</div>';
        }

        $meta = CPT_Pago::get_meta($pago_id);
        if ($meta['status'] === 'paid') {
            $paid_at = !empty($meta['paid_at']) ? wp_date('d/m/Y H:i', strtotime($meta['paid_at'])) : '';
            return '<div class="biodevas-alert biodevas-alert--success">✅ Este pago ya ha sido completado' . ($paid_at ? ' el ' . $paid_at : '') . '.</div>';
        }

        $product_desc = get_post_meta($pago_id, '_bdg_product_desc', true);
        $amount_cents = (int) get_post_meta($pago_id, '_bdg_amount_cents', true);
        $suggested_method = get_post_meta($pago_id, '_bdg_method', true);
        $recipient_email = get_post_meta($pago_id, '_bdg_recipient_email', true);
        $params = get_post_meta($pago_id, '_bdg_params', true);
        $params = is_array($params) ? $params : [];

        $selected_method = sanitize_text_field($_GET['bdg_method'] ?? '');
        if ($selected_method && in_array($selected_method, ['tarjeta', 'bizum'], true)) {
            update_post_meta($pago_id, '_bdg_method', $selected_method);
            return $this->render_redsys_redirect($pago_id, $meta, $selected_method);
        }

        if ($selected_method === 'transferencia') {
            update_post_meta($pago_id, '_bdg_method', 'transferencia');
            return $this->render_transfer_instructions($pago_id, $meta);
        }

        return $this->render_link_form($pago_id, $meta, $product_desc, $amount_cents, $suggested_method, $recipient_email, $params);
    }

    /**
     * Render payment form for link-generated payments.
     */
    private function render_link_form(int $pago_id, array $meta, string $product_desc, int $amount_cents, string $suggested_method, string $recipient_email, array $params): string
    {
        $amount_display = CPT_Pago::format_amount($amount_cents);
        $base_url = Payment_Handler::get_payment_link($pago_id, get_post_meta($pago_id, '_bdg_link_key', true), get_post_meta($pago_id, '_bdg_expires_at', true));

        $card_url = add_query_arg('bdg_method', 'tarjeta', $base_url);
        $bizum_url = add_query_arg('bdg_method', 'bizum', $base_url);
        $transfer_url = add_query_arg('bdg_method', 'transferencia', $base_url);

        $settings = get_option('bdg_settings', []);
        $transfer_enabled = !empty($settings['iban']);
        $bizum_enabled = !empty(Redsys_Client::bizum_merchant_code()) || !empty(Redsys_Client::merchant_code());

        $suggested = ($suggested_method === 'any') ? '' : $suggested_method;

        ob_start();
        ?>
        <div class="bdg-payment-wrapper biodevas-form" role="region" aria-label="Formulario de pago">
            <div class="bdg-payment-summary">
                <h3>Resumen del pago</h3>
                <div class="bdg-amount"><?php echo esc_html($amount_display); ?></div>
                <?php if ($product_desc): ?>
                    <p class="bdg-desc"><?php echo esc_html($product_desc); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($params)): ?>
            <div class="bdg-params">
                <h4>Datos adicionales</h4>
                <ul class="bdg-params-list">
                    <?php foreach ($params as $k => $v): ?>
                    <li><span class="bdg-param-key"><?php echo esc_html($k); ?>:</span> <span class="bdg-param-value"><?php echo esc_html($v); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="" class="bdg-link-form">
                <div class="bdg-email-field">
                    <label for="bdg_email"><?php esc_html_e('Email de notificación', 'convoca-gateway'); ?></label>
                    <input type="email" name="bdg_email" id="bdg_email" value="<?php echo esc_attr($recipient_email); ?>" class="regular-text">
                </div>

                <h4>Selecciona un método de pago</h4>
                <div class="bdg-methods">
                    <a href="<?php echo esc_url($card_url); ?>" class="bdg-method bdg-method-card <?php echo ($suggested === 'tarjeta') ? 'bdg-method--suggested' : ''; ?>">
                        <span class="bdg-method-icon">💳</span>
                        <span class="bdg-method-label">Tarjeta</span>
                        <span class="bdg-method-desc">Visa, Mastercard, etc.</span>
                        <?php if ($suggested === 'tarjeta'): ?><span class="bdg-method-badge">Recomendado</span><?php endif; ?>
                    </a>

                    <?php if ($bizum_enabled): ?>
                    <a href="<?php echo esc_url($bizum_url); ?>" class="bdg-method bdg-method-bizum <?php echo ($suggested === 'bizum') ? 'bdg-method--suggested' : ''; ?>">
                        <span class="bdg-method-icon">📱</span>
                        <span class="bdg-method-label">Bizum</span>
                        <span class="bdg-method-desc">Pago instantáneo con tu móvil</span>
                        <?php if ($suggested === 'bizum'): ?><span class="bdg-method-badge">Recomendado</span><?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($transfer_enabled): ?>
                    <a href="<?php echo esc_url($transfer_url); ?>" class="bdg-method bdg-method-transfer <?php echo ($suggested === 'transferencia') ? 'bdg-method--suggested' : ''; ?>">
                        <span class="bdg-method-icon">🍀</span>
                        <span class="bdg-method-label">Transferencia</span>
                        <span class="bdg-method-desc">Ingresa desde tu banco</span>
                        <?php if ($suggested === 'transferencia'): ?><span class="bdg-method-badge">Sugerido</span><?php endif; ?>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <style>
            .bdg-payment-wrapper {
                max-width: 600px;
                margin: 2rem auto;
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.05);
                padding: 2.5rem 2rem;
                border: 1px solid #f0f0f0;
            }
            .bdg-payment-summary {
                text-align: center;
                margin-bottom: 2rem;
                padding-bottom: 1.5rem;
                border-bottom: 1px solid #f0f0f0;
            }
            .bdg-amount {
                font-size: 2.5rem;
                font-weight: 800;
                color: var(--wp--preset--color--naranja, #ff8700);
                margin: 0.5rem 0;
            }
            .bdg-desc {
                color: #666;
                font-size: 1.1rem;
            }
            .bdg-params { 
                margin: 1.5rem 0; 
                padding: 1.25rem; 
                background: #f8f9fa; 
                border-radius: 12px; 
                border: 1px solid #eee;
            }
            .bdg-params h4 { margin-top: 0; font-size: 1rem; color: #333; }
            .bdg-params-list { margin: 0; padding-left: 1.25rem; list-style-type: square; color: #555; }
            .bdg-param-key { font-weight: 700; color: #333; }
            
            .bdg-email-field {
                margin-bottom: 2rem;
            }
            .bdg-email-field label {
                display: block;
                font-weight: 700;
                margin-bottom: 0.5rem;
                color: #333;
                text-align: left;
            }
            .bdg-email-field input {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 1rem;
            }
            .bdg-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 1.25rem;
                margin-top: 1rem;
            }
            .bdg-method {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 1.5rem;
                border: 2px solid #eee;
                border-radius: 12px;
                text-decoration: none;
                color: #333 !important;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                position: relative;
                background: #fafafa;
            }
            .bdg-method:hover {
                border-color: var(--wp--preset--color--naranja, #ff8700);
                background: #fff;
                transform: translateY(-4px);
                box-shadow: 0 8px 20px rgba(255, 135, 0, 0.12);
            }
            .bdg-method--suggested {
                border-color: var(--wp--preset--color--naranja, #ff8700);
                background: rgba(255, 135, 0, 0.03);
            }
            .bdg-method-icon {
                font-size: 2.5rem;
                margin-bottom: 0.75rem;
                filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
            }
            .bdg-method-label {
                font-weight: 700;
                font-size: 1.1rem;
                margin-bottom: 0.25rem;
            }
            .bdg-method-desc {
                font-size: 0.85rem;
                color: #777;
                text-align: center;
                line-height: 1.4;
            }
            .bdg-method-badge {
                position: absolute;
                top: -12px;
                background: var(--wp--preset--color--naranja, #ff8700);
                color: #fff;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 0.7rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                box-shadow: 0 2px 8px rgba(255, 135, 0, 0.3);
            }
            @media (max-width: 480px) {
                .bdg-methods {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render bank transfer instructions.
     */
    private function render_transfer_instructions(int $pago_id, array $meta): string
    {
        $settings = get_option('bdg_settings', []);
        $iban = $settings['iban'] ?? '';

        if (empty($iban)) {
            return '<div class="biodevas-form biodevas-card" style="max-width:500px; margin: 2rem auto; text-align:center;">
                <div class="biodevas-alert biodevas-alert--danger">
                    <h4 style="margin-top:0">⚠️ Método no disponible</h4>
                    <p>Lo sentimos, el pago por transferencia no está configurado correctamente en este momento (falta el IBAN de destino).</p>
                    <div style="margin-top:1.5rem">
                        <a href="' . esc_url(remove_query_arg('bdg_method')) . '" class="wp-block-button__link">Volver a elegir método</a>
                    </div>
                </div>
            </div>';
        }

        $beneficiary = $settings['beneficiary'] ?? 'Asociación Biodevas';
        $instructions = $settings['instructions'] ?? '';
        $amount_display = CPT_Pago::format_amount((int) $meta['amount_cents']);
        $order_id = $meta['order_id'];

        ob_start();
        ?>
        <div class="bdg-payment-wrapper biodevas-form bdg-transfer-view" role="region" aria-label="Instrucciones de transferencia">
            <div class="bdg-payment-summary">
                <div class="bdg-success-icon">🍀</div>
                <h3>Pago por Transferencia</h3>
                <p>Por favor, realiza el ingreso con los siguientes datos:</p>
            </div>

            <div class="bdg-transfer-details">
                <div class="bdg-detail-row">
                    <span class="bdg-detail-label">Importe:</span>
                    <span class="bdg-detail-value bdg-highlight"><?php echo esc_html($amount_display); ?></span>
                </div>
                <div class="bdg-detail-row">
                    <span class="bdg-detail-label">IBAN:</span>
                    <span class="bdg-detail-value bdg-copyable" id="bdg-iban"><?php echo esc_html($iban); ?></span>
                </div>
                <div class="bdg-detail-row">
                    <span class="bdg-detail-label">Beneficiario:</span>
                    <span class="bdg-detail-value"><?php echo esc_html($beneficiary); ?></span>
                </div>
                <div class="bdg-detail-row">
                    <span class="bdg-detail-label">Concepto (MUY IMPORTANTE):</span>
                    <span class="bdg-detail-value bdg-highlight bdg-copyable" id="bdg-concept"><?php echo esc_html($order_id); ?></span>
                </div>
            </div>

            <?php if ($instructions): ?>
            <div class="bdg-transfer-instructions">
                <h4>Instrucciones adicionales</h4>
                <p><?php echo nl2br(esc_html($instructions)); ?></p>
            </div>
            <?php endif; ?>

            <div class="biodevas-alert biodevas-alert--info">
                <p>Tu inscripción quedará como <strong>pendiente</strong> hasta que verifiquemos el ingreso (suele tardar 24-48h hábiles).</p>
            </div>

            <div class="bdg-proof-upload">
                <h4>Adjuntar justificante de pago</h4>
                <p class="text-muted">Si adjuntas el justificante en PDF, podremos validar tu pago mucho más rápido.</p>

                <?php if ($this->upload_success): ?>
                    <div class="biodevas-alert biodevas-alert--success">
                        ✅ Justificante enviado correctamente. Revisaremos tu pago pronto.
                    </div>
                <?php elseif ($this->upload_error): ?>
                    <div class="biodevas-alert biodevas-alert--danger">
                        ❌ <?php echo esc_html($this->upload_error); ?>
                    </div>
                <?php endif; ?>

                <?php 
                $proof_file = get_post_meta($pago_id, '_bdg_proof_file', true);
                if (!$this->upload_success && !$proof_file): 
                ?>
                <form method="post" enctype="multipart/form-data" class="bdg-upload-form">
                    <?php wp_nonce_field('bdg_proof_upload_action', 'bdg_proof_nonce'); ?>
                    <input type="hidden" name="bdg_upload_proof" value="1">
                    <div class="form-group">
                        <input type="file" name="bdg_proof_file" accept=".pdf,image/*" required>
                        <button type="submit" class="wp-block-button__link">Enviar justificante</button>
                    </div>
                </form>
                <?php elseif ($proof_file): ?>
                    <div class="bdg-proof-exists">
                        📄 Ya has enviado un justificante. Si necesitas cambiarlo, contacta con nosotros.
                    </div>
                <?php endif; ?>
            </div>

            <div class="bdg-actions">
                <a href="<?php echo esc_url(remove_query_arg('bdg_method')); ?>" class="bdg-back-link">
                    &larr; Volver a elegir método
                </a>
                <button type="button" class="wp-block-button__link" onclick="window.print()">
                    🖨️ Imprimir instrucciones
                </button>
            </div>
        </div>
        <style>
            .bdg-transfer-view .bdg-success-icon { font-size: 3rem; margin-bottom: 1rem; }
            .bdg-transfer-details { 
                background: #fcfcfc; 
                border: 1px solid #eee; 
                border-radius: 12px; 
                padding: 1.5rem; 
                margin: 1.5rem 0;
            }
            .bdg-detail-row { 
                display: flex; 
                justify-content: space-between; 
                padding: 0.75rem 0; 
                border-bottom: 1px solid #f0f0f0; 
            }
            .bdg-detail-row:last-child { border-bottom: none; }
            .bdg-detail-label { font-weight: 700; color: #666; font-size: 0.9rem; }
            .bdg-detail-value { font-family: monospace; font-size: 1.1rem; color: #333; }
            .bdg-highlight { color: var(--wp--preset--color--naranja, #ff8700); font-weight: 800; }
            .bdg-copyable { cursor: pointer; position: relative; }
            .bdg-copyable:hover { text-decoration: underline; }
            .bdg-transfer-instructions { 
                text-align: left; 
                margin: 1.5rem 0; 
                padding: 1rem; 
                background: #fff8f0; 
                border-radius: 8px; 
            }
            .bdg-transfer-instructions h4 { margin-top: 0; color: #a65d00; }
            .bdg-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; }
            .bdg-back-link { font-size: 0.9rem; color: #888; text-decoration: none; }
            .bdg-back-link:hover { color: #333; }
            .bdg-proof-upload { 
                margin-top: 2rem; 
                padding: 1.5rem; 
                background: #f8f9fa; 
                border-radius: 12px; 
                border: 1px dashed #ced4da; 
                text-align: left;
            }
            .bdg-proof-upload h4 { margin-top: 0; }
            .bdg-upload-form .form-group { display: flex; gap: 1rem; align-items: center; margin-top: 1rem; }
            .bdg-upload-form input[type="file"] { flex-grow: 1; font-size: 0.9rem; }
            .bdg-proof-exists { color: #28a745; font-weight: 600; padding: 0.5rem 0; }
        </style>
        <script>
            document.querySelectorAll('.bdg-copyable').forEach(el => {
                el.addEventListener('click', () => {
                    const text = el.innerText;
                    navigator.clipboard.writeText(text).then(() => {
                        const originalText = el.innerText;
                        el.innerText = '¡Copiado!';
                        setTimeout(() => el.innerText = originalText, 1000);
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle proof of payment upload.
     */
    private function handle_proof_upload(int $pago_id): bool|\WP_Error
    {
        if (empty($_FILES['bdg_proof_file']['name'])) {
            return new \WP_Error('no_file', 'No se ha seleccionado ningún archivo.');
        }

        // Validate size (5MB limit)
        $max_size = 5 * 1024 * 1024;
        if ($_FILES['bdg_proof_file']['size'] > $max_size) {
            return new \WP_Error('file_too_large', 'El archivo es demasiado grande. El límite es de 5MB.');
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        // Use the smaller of our limit and the server's max upload size
        $max_size = min(5 * 1024 * 1024, wp_max_upload_size());
        if ($_FILES['bdg_proof_file']['size'] > $max_size) {
            $max_mb = $max_size / 1024 / 1024;
            return new \WP_Error('file_too_large', esc_html__("El archivo es demasiado grande. El límite es de {$max_mb}MB.", 'convoca-gateway'));
        }

        $uploaded_file = $_FILES['bdg_proof_file'];
        $upload_overrides = ['test_form' => false];

        // Validate file type using WordPress's built-in function (handles mime_content_type fallback)
        $filetype = wp_check_filetype_and_ext($uploaded_file['tmp_name'], $uploaded_file['name']);
        if (!$filetype['type'] || !$filetype['ext']) {
            return new \WP_Error('invalid_type', 'El tipo de archivo no está permitido. Solo se aceptan PDF, JPG y PNG.');
        }

        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($filetype['type'], $allowed_types)) {
            return new \WP_Error('invalid_mime', 'El contenido del archivo no coincide con una extensión permitida.');
        }

        // Regenerate filename with UUID to prevent path traversal via original name
        $uploaded_file['name'] = wp_generate_uuid4() . '.' . $filetype['ext'];

        // Validate real MIME type (Task 41)
        if (function_exists('mime_content_type')) {
            $real_mime = mime_content_type($uploaded_file['tmp_name']);
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($real_mime, $allowed_mimes)) {
                return new \WP_Error('invalid_mime', 'El contenido del archivo no coincide con su extensión o no es un tipo permitido.');
            }
        }

        // Delete previous file if exists
        $old_url = get_post_meta($pago_id, '_bdg_proof_file', true);
        if ($old_url) {
            $upload_dir = wp_upload_dir();
            $old_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_url);
            if (file_exists($old_path)) {
                @unlink($old_path);
            }
        }

        $movefile = wp_handle_upload($uploaded_file, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            update_post_meta($pago_id, '_bdg_proof_file', $movefile['url']);
            
            // Protect the upload directory against script execution
            $upload_dir = wp_upload_dir();
            $htaccess_path = $upload_dir['basedir'] . '/.htaccess';
            if (!file_exists($htaccess_path)) {
                file_put_contents($htaccess_path, "Options -ExecCGI\nphp_flag engine off\n<Files *.php>\n    deny from all\n</Files>\n");
            }
            
            // Add a note to the payment
            $notes = get_post_meta($pago_id, '_bdg_notes', true);
            $notes .= "\n\n[USER] Justificante de pago adjuntado el " . wp_date('d/m/Y H:i') . ": " . $movefile['url'];
            update_post_meta($pago_id, '_bdg_notes', $notes);

            \Convoca\Core\Logger::info(
                "Justificante de pago subido para Pago #$pago_id",
                'Gateway/ProofUpload',
                $pago_id
            );

            return true;
        } else {
            return new \WP_Error('upload_error', $movefile['error']);
        }
    }


    /**
     * Render a manual payment form when no link is provided.
     */
    private function render_manual_form(string $error = ''): string
    {
        ob_start();
        ?>
        <div class="bdg-payment-wrapper biodevas-form biodevas-card card-glass">
            <div class="bdg-payment-summary">
                <h3 class="text-gradient"><?php _e('Emitir Pago Nuevo', 'convoca-gateway'); ?></h3>
                <p><?php _e('Introduce los datos para realizar un pago seguro.', 'convoca-gateway'); ?></p>
            </div>

            <?php if ($error): ?>
                <div class="biodevas-alert biodevas-alert--danger"><?php echo esc_html($error); ?></div>
            <?php endif; ?>

            <form method="post" action="" class="bdg-manual-form">
                <?php wp_nonce_field('bdg_manual_payment_action', 'bdg_manual_nonce'); ?>
                <input type="hidden" name="bdg_manual_payment" value="1">

                <div class="form-group">
                    <label for="amount"><?php _e('Importe (€)', 'convoca-gateway'); ?></label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.50" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label for="description"><?php _e('Concepto o Actividad', 'convoca-gateway'); ?></label>
                    <input type="text" name="description" id="description" placeholder="<?php esc_attr_e('Ej: Inscripción Taller Aves', 'convoca-gateway'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email"><?php _e('Email para el recibo', 'convoca-gateway'); ?></label>
                    <input type="email" name="email" id="email" placeholder="tu@email.com" required>
                </div>

                <div class="form-actions">
                    <button type="submit" class="wp-block-button__link">
                        <?php _e('Continuar al pago', 'convoca-gateway'); ?> &rarr;
                    </button>
                </div>
            </form>

            <p class="bdg-security-note">
                🔒 <?php _e('Pago seguro gestionado por Redsys. Biodevas no almacena tus datos bancarios.', 'convoca-gateway'); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle the manual form submission.
     */
    private function handle_manual_payment_submission(): string
    {
        $amount = (float) str_replace(',', '.', $_POST['amount'] ?? 0);
        $desc = sanitize_text_field($_POST['description'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        if ($amount < 0.50) {
            return $this->render_manual_form('El importe mínimo es de 0,50€.');
        }

        if (empty($desc) || empty($email)) {
            return $this->render_manual_form('Todos los campos son obligatorios.');
        }

        $pago_id = CPT_Pago::create_link_payment([
            'amount' => $amount,
            'concepto' => $desc,
            'email' => $email,
            'method' => 'any',
            'expires_at' => 'never',
        ]);

        if (is_wp_error($pago_id)) {
            return $this->render_manual_form($pago_id->get_error_message());
        }

        $token = get_post_meta($pago_id, '_bdg_link_key', true);
        $url = self::get_payment_link($pago_id, $token);

        return '<script>window.location.href="' . esc_url_raw($url) . '";</script>' . 
               '<div class="biodevas-alert biodevas-alert--info">Generando orden de pago... Si no eres redirigido, <a href="' . esc_url($url) . '">haz clic aquí</a>.</div>';
    }

    /**
     * Render payment page for legacy system (24h expiration).
     */
    private function render_legacy_payment_page(int $pago_id, int $ts, string $key): string
    {
        $persistent_hash = hash_hmac('sha256', $pago_id . '|' . $ts . '_bdg_payment', self::get_persistent_salt());
        $legacy_hash = wp_hash($pago_id . '|' . $ts . '_bdg_payment');

        if (!hash_equals($persistent_hash, $key) && !hash_equals($legacy_hash, $key)) {
            return '<div class="biodevas-alert biodevas-alert--danger">Enlace de pago inválido o corrupto.</div>';
        }

        if ($ts < (time() - DAY_IN_SECONDS)) {
            return '<div class="biodevas-alert biodevas-alert--warning">El enlace de pago ha caducado. Por favor, solicita uno nuevo.</div>';
        }

        $meta = CPT_Pago::get_meta($pago_id);
        if ($meta['status'] === 'paid') {
            return '<div class="biodevas-alert biodevas-alert--success">✅ Este pago ya ha sido completado.</div>';
        }

        $selected_method = sanitize_text_field($_GET['bdg_method'] ?? '');

        if ($selected_method && in_array($selected_method, ['tarjeta', 'bizum'], true)) {
            update_post_meta($pago_id, '_bdg_method', $selected_method);
            return $this->render_redsys_redirect($pago_id, $meta, $selected_method);
        }

        if ($selected_method === 'transferencia') {
            update_post_meta($pago_id, '_bdg_method', 'transferencia');
            return $this->render_transfer_instructions($pago_id, $meta);
        }

        return $this->render_method_selector($pago_id, $meta);
    }

    /**
     * Render payment method selection screen.
     */
    private function render_method_selector(int $pago_id, array $meta): string
    {
        $amount_display = CPT_Pago::format_amount((int) $meta['amount_cents']);
        $base_url = self::get_payment_link($pago_id);

        $card_url  = add_query_arg('bdg_method', 'tarjeta', $base_url);
        $bizum_url = add_query_arg('bdg_method', 'bizum', $base_url);
        $transfer_url = add_query_arg('bdg_method', 'transferencia', $base_url);

        $settings = get_option('bdg_settings', []);
        $transfer_enabled = !empty($settings['iban']);
        $bizum_enabled = !empty(Redsys_Client::bizum_merchant_code()) || !empty(Redsys_Client::merchant_code());

        ob_start();
        ?>
        <div class="bdg-payment-wrapper biodevas-form" role="region" aria-label="Selección de método de pago">
            <div class="bdg-payment-summary">
                <h3>Resumen del pago</h3>
                <div class="bdg-amount">
                    <?php echo esc_html($amount_display); ?>
                </div>
                <?php if ($meta['product_desc']): ?>
                    <p class="bdg-desc">
                        <?php echo esc_html($meta['product_desc']); ?>
                    </p>
                <?php endif; ?>
            </div>

            <h4>Selecciona un método de pago</h4>
            <div class="bdg-methods">
                <a href="<?php echo esc_url($card_url); ?>" class="bdg-method-card">
                    <span class="bdg-method-icon">💳</span>
                    <span class="bdg-method-label">Tarjeta</span>
                    <span class="bdg-method-desc">Visa, Mastercard, etc.</span>
                </a>
                <?php if ($bizum_enabled): ?>
                    <a href="<?php echo esc_url($bizum_url); ?>" class="bdg-method-card">
                        <span class="bdg-method-icon">📱</span>
                        <span class="bdg-method-label">Bizum</span>
                        <span class="bdg-method-desc">Pago instantáneo con tu móvil</span>
                    </a>
                <?php endif; ?>
                <?php if ($transfer_enabled): ?>
                    <a href="<?php echo esc_url($transfer_url); ?>" class="bdg-method-card">
                        <span class="bdg-method-icon">🍀</span>
                        <span class="bdg-method-label">Transferencia</span>
                        <span class="bdg-method-desc">Ingresa desde tu banco</span>
                    </a>
                <?php endif; ?>
            </div>

            <p class="bdg-security-note">
                🔒 Pago seguro gestionado por Redsys (Caja Rural de Asturias).
                Tus datos bancarios nunca pasan por nuestro servidor.
            </p>
        </div>
        <style>
            .bdg-payment-wrapper {
                max-width: 480px;
                margin: 2rem auto;
                text-align: center;
            }

            .bdg-payment-summary {
                background: var(--wp--preset--color--gris-suave, #f5f5f5);
                border-radius: 12px;
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .bdg-amount {
                font-size: 2rem;
                font-weight: 700;
                color: var(--wp--preset--color--naranja, #E86833);
            }

            .bdg-desc {
                color: #666;
                margin-top: .5rem;
            }

            .bdg-methods {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
                margin: 1rem 0 1.5rem;
            }

            .bdg-method-card {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 1.5rem 1rem;
                border: 2px solid #e0e0e0;
                border-radius: 12px;
                text-decoration: none;
                color: inherit;
                transition: border-color .2s, box-shadow .2s;
            }

            .bdg-method-card:hover {
                border-color: var(--wp--preset--color--naranja, #E86833);
                box-shadow: 0 4px 12px rgba(232, 104, 51, .15);
            }

            .bdg-method-icon {
                font-size: 2.5rem;
                margin-bottom: .5rem;
            }

            .bdg-method-label {
                font-weight: 700;
                font-size: 1.1rem;
            }

            .bdg-method-desc {
                font-size: .85rem;
                color: #888;
                margin-top: .25rem;
            }

            .bdg-security-note {
                font-size: .8rem;
                color: #999;
                margin-top: 1rem;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the Redsys auto-submit redirect.
     */
    private function render_redsys_redirect(int $pago_id, array $meta, string $method): string
    {
        // Check if already paid to prevent double payment attempts
        if (($meta['status'] ?? '') === 'paid') {
            return '<div class="biodevas-alert biodevas-alert--success">✅ Este pago ya ha sido completado correctamente. No es necesario realizarlo de nuevo.</div>';
        }

        $amount_cents = (int) ($meta['amount_cents'] ?? 0);

        if ($amount_cents <= 0) {
            \Convoca\Core\Logger::error("Intento de pago con importe zero. Pago ID: $pago_id", 'Gateway/Redsys', $pago_id);
            return '<div class="biodevas-alert biodevas-alert--danger">Error: El importe del pago no es válido (0.00€).</div>';
        }

        $pay_method = ($method === 'bizum') ? Redsys_Client::METHOD_BIZUM : Redsys_Client::METHOD_CARD;

        $settings = get_option('bdg_settings', []);
        $ok_page  = (int) ($settings['ok_page_id'] ?? 0);
        $ko_page  = (int) ($settings['ko_page_id'] ?? 0);

        $url_ok = $ok_page ? add_query_arg('bdg_pago', $pago_id, get_permalink($ok_page)) : home_url('/pago-completado/?bdg_pago=' . $pago_id);
        $url_ko = $ko_page ? add_query_arg('bdg_pago', $pago_id, get_permalink($ko_page)) : home_url('/pago-error/?bdg_pago=' . $pago_id);

        $notify_url = get_rest_url(null, 'biodevas-gateway/v1/notify');

        // Validate configuration to prevent Redsys error.
        if (empty(Redsys_Client::merchant_code()) || empty(Redsys_Client::secret_key())) {
            return '<div class="biodevas-alert biodevas-alert--danger">
                <h4>➠️ Error de configuración de pagos</h4>
                <p>No se han configurado las claves de Redsys (FUC o Clave Secreta).<br>
                Por favor, contacta con el administrador del sitio para revisar los ajustes de <em>Biodevas Gateway</em>.</p>
            </div>';
        }

        wp_enqueue_script('bdg-redsys');

        $tokenize = get_post_meta($pago_id, '_bdg_tokenize', true) === '1';

        $form = Redsys_Client::build_form([
            'order_id' => $meta['order_id'],
            'amount_cents' => $amount_cents,
            'product_desc' => $meta['product_desc'],
            'pay_methods' => $pay_method,
            'url_ok' => $url_ok,
            'url_ko' => $url_ko,
            'url_notify' => $notify_url,
            'is_bizum' => ($method === 'bizum'),
            'tokenize' => $tokenize,
        ]);

        return '<div class="bdg-redirect-wrapper">
            <p class="biodevas-text-center" style="padding:2rem">⏳ Redirigiendo a la pasarela de pago seguro...</p>'
            . $form .
            '</div>';
    }

    /**
     * Check if the notification comes from a known Redsys IP.
     */
    private function is_redsys_ip(): bool
    {
        // Allow localhost/local network if in dev or if WP_DEBUG is enabled
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (empty($ip)) {
            return false;
        }

        // Bypass for local IPs during development/testing
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true) || defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        // Common Redsys IP ranges (Europe).
        // Official docs mention specific IPs, but they often use 195.76.9.0/24
        if (str_starts_with($ip, '195.76.9.')) {
            return true;
        }

        $allowed_ips = [
            '193.16.243.33',
            '195.76.9.187',
            '195.76.9.182',
            '195.76.9.222'
        ];

        // Allow extending the IP list via filter (e.g., if Redsys changes their ranges)
        $allowed_ips = apply_filters('biodevas_gateway_redsys_allowed_ips', $allowed_ips);

        return in_array($ip, $allowed_ips, true);
    }

    /**
     * Core processing logic for Redsys notifications.
     *
     * @param array $post_data Data from Redsys POST.
     * @return bool|\WP_Error True on success, error otherwise.
     */
    public function process_notification(array $post_data): bool|\WP_Error
    {
        if (!$this->is_redsys_ip()) {
            \Convoca\Core\Logger::error('Notificación rechazada: IP de origen no autorizada (' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ').', 'Gateway/Notification');
            return new \WP_Error('unauthorized_ip', 'Unauthorized IP');
        }

        $data = Redsys_Client::verify_notification($post_data);

        if ($data === false) {
            return new \WP_Error('invalid_signature', 'Invalid signature');
        }

        $order_id = $data['Ds_Order'] ?? '';
        $response_code = $data['Ds_Response'] ?? '9999';
        $auth_code = $data['Ds_AuthorisationCode'] ?? '';

        global $wpdb;

        // Use savepoints for pseudo-nested transactions instead of static blocking
        static $savepoint_depth = 0;
        $savepoint_name = 'bdg_sp_' . $savepoint_depth;

        if ($savepoint_depth === 0) {
            $wpdb->query('START TRANSACTION');
        } else {
            $wpdb->query("SAVEPOINT $savepoint_name");
        }
        $savepoint_depth++;
        $outer_transaction = false;

        // 2. Find and LOCK the payment in a single step to prevent race conditions
        $pago_id = CPT_Pago::find_by_order_locked($order_id);

        if (!$pago_id) {
            $savepoint_depth--;
            if ($savepoint_depth === 0) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query("ROLLBACK TO $savepoint_name");
            }
            return new \WP_Error('order_not_found', 'Order not found');
        }

        // 3. Re-check status AFTER acquiring the lock
        $current_status = get_post_meta($pago_id, '_bdg_status', true);
        if ($current_status === 'paid') {
            $savepoint_depth--;
            if ($savepoint_depth === 0) {
                $wpdb->query('COMMIT');
            }
            \Convoca\Core\Logger::info("Notificación duplicada para Order {$order_id} ignorada (ya pagado).", 'Gateway/Notification', $pago_id);
            return true;
        }

        $is_approved = Redsys_Client::is_approved($response_code);
        $new_status = $is_approved ? 'paid' : 'failed';

        try {
            update_post_meta($pago_id, '_bdg_status', $new_status);
            update_post_meta($pago_id, '_bdg_redsys_response', $response_code);
            update_post_meta($pago_id, '_bdg_redsys_auth_code', $auth_code);
            update_post_meta($pago_id, '_bdg_redsys_full_log', wp_json_encode($data));

            if (!empty($data['Ds_MerchantIdentifier'])) {
                update_post_meta($pago_id, '_bdg_redsys_merchant_id', sanitize_text_field($data['Ds_MerchantIdentifier']));
            }

            if ($is_approved) {
                update_post_meta($pago_id, '_bdg_paid_at', current_time('mysql'));

                // Get fresh meta for the hooks
                $meta = CPT_Pago::get_meta($pago_id);
                \Convoca\Core\Utils::do_action('biodevas_gateway_payment_completed', 'biodevas_payment_completed', $pago_id, $meta['origin'], (int) $meta['origin_id'], $meta);
            } else {
                \Convoca\Core\Utils::do_action('biodevas_gateway_payment_failed', 'biodevas_payment_failed', $pago_id, $response_code);
            }

            if ($savepoint_depth === 0) {
                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            $savepoint_depth--;
            if ($savepoint_depth === 0) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query("ROLLBACK TO $savepoint_name");
            }
            \Convoca\Core\Logger::error('Error al procesar notificación de pago: ' . $e->getMessage(), 'Gateway/Notification', $pago_id);
            return new \WP_Error('processing_error', 'Error al procesar el pago');
        }

        return true;
    }

    /* ── Return pages ──────────────────────────── */

    public function render_ok_page($atts): string
    {
        $pago_id = (int) ($_GET['bdg_pago'] ?? 0);
        
        if (!$pago_id) {
            return '<div class="biodevas-alert biodevas-alert--danger">ID de pago no especificado.</div>';
        }

        $post = get_post($pago_id);
        if (!$post || $post->post_type !== 'pago') {
            return '<div class="biodevas-alert biodevas-alert--danger">Pago no encontrado.</div>';
        }

        $meta = CPT_Pago::get_meta($pago_id);

        // Safety check: if the payment is not paid, show an error and a link to retry.
        if (($meta['status'] ?? '') !== 'paid') {
            $token = get_post_meta($pago_id, '_bdg_link_key', true);
            $expires = get_post_meta($pago_id, '_bdg_expires_at', true);
            $url = self::get_payment_link($pago_id, $token, $expires);
            
            return '<div class="biodevas-alert biodevas-alert--warning">
                <h4>⚠️ Pago aún no confirmado</h4>
                <p>El sistema no ha recibido la confirmación del pago todavía. Si acabas de realizarlo, espera unos minutos y recarga la página.</p>
                <div style="margin-top:1.5rem">
                    <a href="' . esc_url($url) . '" class="wp-block-button__link">Intentar completar el pago</a>
                </div>
            </div>';
        }

        ob_start();
        ?>
        <div class="bdg-result biodevas-form" role="status" aria-live="polite">
            <div class="bdg-result-icon">&#x1F389;</div>
            <h3>&iexcl;Pago completado!</h3>
            <p>Tu pago de <strong>
                    <?php echo esc_html(CPT_Pago::format_amount((int) ($meta['amount_cents'] ?? 0))); ?>
                </strong>
                ha sido procesado correctamente.</p>
            <?php if (!empty($meta['product_desc'])): ?>
                <p class="bdg-desc">
                    <?php echo esc_html($meta['product_desc']); ?>
                </p>
            <?php endif; ?>
            <p>Recibir&aacute;s un email de confirmaci&oacute;n en breve.</p>
            <p><a href="<?php echo esc_url(home_url('/')); ?>" class="biodevas-btn biodevas-btn-primary">&larr; Volver al inicio</a></p>
        </div>
        <style>
            .bdg-result {
                max-width: 480px;
                margin: 2rem auto;
                text-align: center;
            }

            .bdg-result-icon {
                font-size: 4rem;
                margin-bottom: 1rem;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    public function render_ko_page($atts): string
    {
        $pago_id = (int) ($_GET['bdg_pago'] ?? 0);

        ob_start();
        ?>
        <div class="bdg-result biodevas-form" role="alert">
            <div class="bdg-result-icon">&#x1F61E;</div>
            <h3>Pago no completado</h3>
            <p>El pago no se ha podido procesar. Puede deberse a una cancelación o un problema con tu banco.</p>
            <p>Si el problema persiste, contacta con nosotros en
                <a href="mailto:coordinacion@biodevas.org">coordinacion@biodevas.org</a>.
            </p>
            <?php if ($pago_id): ?>
                <?php
                $meta      = CPT_Pago::get_meta($pago_id);
                $retry_url = self::get_payment_link($pago_id);
                ?>
                <p><a href="<?php echo esc_url($retry_url); ?>" class="biodevas-btn biodevas-btn-primary">🔄 Reintentar pago</a></p>
            <?php endif; ?>
            <p><a href="<?php echo esc_url(home_url('/')); ?>" class="biodevas-btn biodevas-btn-outline">&larr; Volver al inicio</a></p>
        </div>
        <style>
            .bdg-result {
                max-width: 480px;
                margin: 2rem auto;
                text-align: center;
            }

            .bdg-result-icon {
                font-size: 4rem;
                margin-bottom: 1rem;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}