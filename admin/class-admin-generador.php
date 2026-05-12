<?php
/**
 * Admin page: Generador de enlaces de pago.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Generador
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'bdg-payments',
            __('Generador de Enlaces de Pago', 'convoca-gateway'),
            __('Generar Enlace', 'convoca-gateway'),
            'bdg_manage_payments',
            'bdg-generador',
            [$this, 'render_page']
        );

        add_submenu_page(
            'bdg-payments',
            __('Enlaces de Pago', 'convoca-gateway'),
            __('Enlaces de Pago', 'convoca-gateway'),
            'bdg_manage_payments',
            'bdg-links',
            [new Admin_Links(), 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, 'bdg-generador') === false && strpos($hook, 'bdg-links') === false) {
            return;
        }

        wp_enqueue_style(
            'biodevas-common-admin',
            \BDV_COMMON_URL . 'assets/css/biodevas-common.css',
            [],
            \BDV_COMMON_VERSION
        );

        wp_enqueue_script(
            'bdg-generador',
            \BDG_URL . 'assets/js/generador.js',
            ['biodevas-common-admin-js'],
            \BDG_VERSION,
            true
        );
    }

    public function render_page(): void
    {
        $message = '';
        $generated_link = '';

        if (isset($_POST['bdg_generate_link']) && check_admin_referer('bdg_generate_link_nonce', 'bdg_generate_link_nonce')) {
            $result = $this->process_generation($_POST);

            if (is_wp_error($result)) {
                $message = '<div class="biodevas-alert biodevas-alert--danger" style="display:block;margin-bottom:20px;"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $message = '<div class="biodevas-alert biodevas-alert--success" style="display:block;margin-bottom:20px;"><p>Enlace generado correctamente. Copia el enlace y envíaselo al cliente.</p></div>';
            }
        }

        $offline_methods = $this->get_offline_methods();
        ?>
        <div class="wrap bdg-generador">
            <h1><?php esc_html_e('Generador de Enlaces de Pago', 'convoca-gateway'); ?></h1>
            
            <?php echo $message; ?>

            <div class="bdg-generador-card">
                <form method="post" action="">
                    <?php wp_nonce_field('bdg_generate_link_nonce', 'bdg_generate_link_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="bdg_amount"><?php esc_html_e('Cantidad (€)', 'convoca-gateway'); ?> *</label>
                            </th>
                            <td>
                                <input type="number" name="bdg_amount" id="bdg_amount" 
                                       class="regular-text" step="0.01" min="0.50" required
                                       placeholder="Ej: 50.00">
                                <p class="description">Importe en euros (mínimo 0.50€)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="bdg_concepto"><?php esc_html_e('Concepto', 'convoca-gateway'); ?> *</label>
                            </th>
                            <td>
                                <input type="text" name="bdg_concepto" id="bdg_concepto" 
                                       class="regular-text" maxlength="125" required
                                       placeholder="Ej: Cuota mensual de socio">
                                <p class="description">Descripción del pago (máx. 125 caracteres)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="bdg_method"><?php esc_html_e('Método de pago', 'convoca-gateway'); ?></label>
                            </th>
                            <td>
                                <select name="bdg_method" id="bdg_method">
                                    <option value="any"><?php esc_html_e('Cualquiera (usuario elige)', 'convoca-gateway'); ?></option>
                                    <option value="tarjeta"><?php esc_html_e('Tarjeta', 'convoca-gateway'); ?></option>
                                    <option value="bizum"><?php esc_html_e('Bizum', 'convoca-gateway'); ?></option>
                                    <?php if (!empty($offline_methods)): ?>
                                        <option value="transferencia"><?php esc_html_e('Transferencia', 'convoca-gateway'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="bdg_email"><?php esc_html_e('Email del destinatario', 'convoca-gateway'); ?></label>
                            </th>
                            <td>
                                <input type="email" name="bdg_email" id="bdg_email" 
                                       class="regular-text" 
                                       placeholder="Ej: cliente@email.com">
                                <p class="description">Pre-rellena el email en el formulario de pago</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="bdg_params"><?php esc_html_e('Parámetros personalizados', 'convoca-gateway'); ?></label>
                            </th>
                            <td>
                                <textarea name="bdg_params" id="bdg_params" rows="4" class="large-text"
                                          placeholder="referencia=12345&#10;factura=ABC-001&#10;concepto_extra=Pago mensual"></textarea>
                                <p class="description">Clave=Valor por línea. Se mostrarán en el formulario como datos adicionales.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="bdg_expires"><?php esc_html_e('Fecha de caducidad', 'convoca-gateway'); ?></label>
                            </th>
                            <td>
                                <input type="date" name="bdg_expires" id="bdg_expires" class="regular-text"
                                       min="<?php echo esc_attr(wp_date('Y-m-d', strtotime('+1 day'))); ?>">
                                <p class="description">Dejar vacío para usar la validez por defecto (7 días)</p>
                                <label style="display: block; margin-top: 10px;">
                                    <input type="checkbox" name="bdg_never_expires" id="bdg_never_expires" value="1">
                                    <?php esc_html_e('El enlace no caduca nunca', 'convoca-gateway'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" name="bdg_generate_link" class="button button-primary">
                            <?php esc_html_e('Generar enlace', 'convoca-gateway'); ?>
                        </button>
                    </p>
                </form>

                <?php if ($generated_link): ?>
                <hr>
                <h2><?php esc_html_e('Enlace generado', 'convoca-gateway'); ?></h2>
                <p><?php esc_html_e('Copia este enlace y envíaselo al cliente:', 'convoca-gateway'); ?></p>
                <div class="bdg-link-output">
                    <input type="text" value="<?php echo esc_attr($generated_link); ?>" readonly class="large-text" id="bdg_generated_link">
                    <button type="button" class="button" onclick="bdg_copy_link()">
                        <?php esc_html_e('Copiar al portapapeles', 'convoca-gateway'); ?>
                    </button>
                </div>
                <style>
                .bdg-link-output {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    margin-top: 10px;
                }
                .bdg-link-output input {
                    flex: 1;
                }
                </style>
                <script>
                function bdg_copy_link() {
                    const input = document.getElementById('bdg_generated_link');
                    const btn = event.currentTarget;
                    const link = input.value;

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(link).then(() => {
                            showSuccess(btn);
                        });
                    } else {
                        input.select();
                        try {
                            document.execCommand('copy');
                            showSuccess(btn);
                        } catch (err) {
                            console.error('Error al copiar', err);
                        }
                    }
                }

                function showSuccess(btn) {
                    const originalText = btn.textContent;
                    btn.textContent = '¡Copiado!';
                    btn.classList.add('button-primary');
                    setTimeout(() => {
                        btn.textContent = originalText;
                        btn.classList.remove('button-primary');
                    }, 2000);
                }
                </script>
                <?php endif; ?>
            </div>

            <div class="bdg-generador-info">
                <h2><?php esc_html_e('Información', 'convoca-gateway'); ?></h2>
                <ul>
                    <li>El enlace permite al cliente realizar el pago directamente desde cualquier dispositivo.</li>
                    <li>Si seleccionas "Cualquiera", el cliente podrá elegir entre tarjeta, Bizum o transferencia.</li>
                    <li>Los parámetros personalizados se mostrarán como texto informativo en el formulario.</li>
                    <li>El enlace caducará automáticamente en la fecha seleccionada o a los 7 días si no se especifica.</li>
                    <li>Una vez completado el pago, el enlace ya no será válido.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    private function process_generation(array $post): array|\WP_Error
    {
        $amount = (float) ($post['bdg_amount'] ?? 0);
        if ($amount < 0.50) {
            return new \WP_Error('invalid_amount', 'El importe mínimo es 0.50€');
        }

        $concepto = sanitize_text_field($post['bdg_concepto'] ?? '');
        if (empty($concepto)) {
            return new \WP_Error('missing_concept', 'El concepto es obligatorio');
        }

        if (mb_strlen($concepto) > 125) {
            return new \WP_Error('long_concept', 'El concepto no puede exceder 125 caracteres');
        }

        $method = sanitize_text_field($post['bdg_method'] ?? 'any');
        $valid_methods = ['any', 'tarjeta', 'bizum', 'transferencia'];
        if (!in_array($method, $valid_methods, true)) {
            return new \WP_Error('invalid_method', 'Método de pago no válido');
        }

        $email = sanitize_email($post['bdg_email'] ?? '');
        $params = sanitize_textarea_field($post['bdg_params'] ?? '');
        $expires = sanitize_text_field($post['bdg_expires'] ?? '');
        $never_expires = !empty($post['bdg_never_expires']);

        $pago_id = CPT_Pago::create_link_payment([
            'amount' => $amount,
            'concepto' => $concepto,
            'method' => $method,
            'email' => $email,
            'params' => $params,
            'expires_at' => $never_expires ? 'never' : $expires,
        ]);

        if (is_wp_error($pago_id)) {
            return $pago_id;
        }

        $token = get_post_meta($pago_id, '_bdg_link_key', true);
        $expires_ts = get_post_meta($pago_id, '_bdg_expires_at', true);

        $url = Payment_Handler::get_payment_link($pago_id, $token, $expires_ts);

        return [
            'pago_id' => $pago_id,
            'url' => $url,
        ];
    }

    private function get_offline_methods(): array
    {
        $settings = get_option('bdg_settings', []);
        $transfer_enabled = !empty($settings['iban']);
        return $transfer_enabled ? ['transferencia'] : [];
    }
}