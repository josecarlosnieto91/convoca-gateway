<?php
/**
 * Email notifications for payments.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Email_Notifications
{
    public function __construct()
    {
        add_action('convoca_payment_completed', [$this, 'send_success_email'], 10, 4);
        add_action('convoca_payment_failed', [$this, 'send_failed_email'], 10, 2);
    }

    /**
     * Send success email.
     */
    public function send_success_email(int $payment_id, string $origin, int $origin_id, array $data): void
    {
        $this->maybe_send($payment_id, 'success');
    }

    /**
     * Send failure email.
     */
    public function send_failed_email(int $payment_id, string $response_code): void
    {
        $this->maybe_send($payment_id, 'failed');
    }

    /**
     * Generic sender logic.
     */
    private function maybe_send(int $payment_id, string $type): void
    {
        $settings = get_option('bdg_settings', []);
        $enabled  = ($settings['email_confirmation'] ?? '0') === '1';

        if (!$enabled) {
            return;
        }

        $email = get_post_meta($payment_id, '_bdg_payer_email', true);
        if (empty($email)) {
            return;
        }

        $sender_name  = $settings['email_sender_name'] ?? get_bloginfo('name');
        $sender_email = get_option('admin_email');
        
        $subject = $settings["email_{$type}_subject"] ?? '';
        $body    = $settings["email_{$type}_body"] ?? '';

        // Fallbacks if empty.
        if (empty($subject)) {
            $subject = ($type === 'success') 
                ? __('Confirmación de pago: {producto}', 'convoca-gateway')
                : __('Problema con tu pago: {producto}', 'convoca-gateway');
        }

        if (empty($body)) {
            $body = ($type === 'success')
                ? '<h2>' . __('Resumen de tu pago', 'convoca-gateway') . '</h2>
                   <p>' . __('Hemos recibido correctamente tu pago. Detalles:', 'convoca-gateway') . '</p>
                   <ul>
                       <li><strong>' . __('Importe', 'convoca-gateway') . ':</strong> {importe}</li>
                       <li><strong>' . __('Concepto', 'convoca-gateway') . ':</strong> {producto}</li>
                       <li><strong>' . __('Fecha', 'convoca-gateway') . ':</strong> {fecha}</li>
                   </ul>'
                : '<h2>' . __('Error en el pago', 'convoca-gateway') . '</h2>
                   <p>' . __('No hemos podido procesar tu pago para {producto}.', 'convoca-gateway') . '</p>
                   <p>' . __('Puedes volver a intentarlo aquí:', 'convoca-gateway') . ' <a href="{enlace_pago}">{enlace_pago}</a></p>';
        }

        // Replace variables.
        $vars = $this->get_template_vars($payment_id);
        $subject = str_replace(array_keys($vars), array_values($vars), $subject);
        $body    = str_replace(array_keys($vars), array_values($vars), $body);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $sender_name, $sender_email),
        ];

        wp_mail($email, $subject, $body, $headers);
    }

    /**
     * Build variables for replacement.
     */
    private function get_template_vars(int $payment_id): array
    {
        $amount_cents = (int) get_post_meta($payment_id, '_bdg_amount_cents', true);
        $method       = get_post_meta($payment_id, '_bdg_method', true);
        $origin       = get_post_meta($payment_id, '_bdg_origin', true);
        $enroll_url   = get_post_meta($payment_id, '_bdg_enroll_url', true);
        
        // Build payment link.
        $payment_url = Payment_Handler::get_payment_link($payment_id);

        return [
            '{importe}'            => CPT_Pago::format_amount($amount_cents),
            '{metodo}'             => ucfirst($method),
            '{fecha}'              => get_the_date('d/m/Y H:i', $payment_id),
            '{producto}'           => $origin,
            '{enlace_pago}'        => $payment_url,
            '{enlace_inscripcion}' => $enroll_url ?: '',
        ];
    }
}
