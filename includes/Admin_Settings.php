<?php

/**
 * Convoca Gateway
 *
 * @package    Convoca\Gateway
 * @subpackage Includes
 *
 * @copyright  Copyright (C) 2026 Jose Carlos Nieto Ramos
 * @license    GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * Admin settings page for Redsys gateway credentials.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Settings {


	/** Option key. */
	private const OPTION = 'convoca_gateway_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_convoca_diagnostic_run', array( $this, 'ajax_diagnostic_run' ) );
		add_action( 'wp_ajax_convoca_diagnostic_fix', array( $this, 'ajax_diagnostic_fix' ) );
		add_action( 'admin_notices', array( $this, 'secret_key_warning' ) );
	}

	/**
	 * Show admin notice if secret key decryption failed.
	 */
	public function secret_key_warning(): void {
		if ( ! get_option( 'convoca_gateway_secret_needs_reentry' ) ) {
			return;
		}
		?>
		<div class="convoca-alert convoca-alert--danger" style="display:block;margin-bottom:20px;">
			<p>
				<strong>Convoca Gateway:</strong> La clave secreta de Redsys no se pudo descifrar correctamente.
				Esto puede deberse a un cambio en las claves de seguridad de WordPress.
				Por favor, <a href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-settings' ) ); ?>">vuelve a introducir la clave secreta</a>.
			</p>
		</div>
		<?php
	}

	public function add_menu(): void {
		add_submenu_page(
			'conv-gateway-payments',
			__( 'Ajustes del TPV', 'convoca-gateway' ),
			__( 'Configuración', 'convoca-gateway' ),
			'manage_options',
			'conv-gateway-settings',
			array( $this, 'render_page' )
		);

		// Add hidden detail page for tab selection (it's called from Admin_Payments).
		add_submenu_page(
			null, // Hidden.
			__( 'Detalle de Pago', 'convoca-gateway' ),
			__( 'Detalle', 'convoca-gateway' ),
			'convoca_gateway_view_payments',
			'conv-gateway-payments-detail',
			array( new Admin_Payments(), 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'convoca_gateway_settings_group',
			self::OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section(
			'convoca_gateway_redsys',
			__( 'Configuración Redsys', 'convoca-gateway' ),
			fn() => print '<p>' . esc_html__( 'Introduce los datos proporcionados por tu banco para el TPV Virtual.', 'convoca-gateway' ) . '</p>',
			'conv-gateway-settings'
		);

		$fields = array(
			'merchant_code'       => array(
				'label' => 'FUC (Código comercio)',
				'type'  => 'text',
				'desc'  => 'Ejemplo: 999008881 (test)',
			),
			'bizum_merchant_code' => array(
				'label' => 'FUC Bizum',
				'type'  => 'text',
				'desc'  => 'Si se rellena, se habilitará el pago por Bizum',
			),
			'terminal'            => array(
				'label' => 'Terminal',
				'type'  => 'text',
				'desc'  => 'Normalmente 001',
			),
			'secret_key'          => array(
				'label' => 'Clave secreta (SHA-256)',
				'type'  => 'password',
				'desc'  => 'La clave de firma proporcionada por el banco',
			),
			'environment'         => array(
				'label'   => 'Entorno',
				'type'    => 'select',
				'options' => array(
					'test'       => 'Test (sandbox)',
					'production' => 'Producción',
				),
				'desc'    => '⚠️ Usa "Test" durante el desarrollo',
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'convoca_gateway_' . $key,
				$field['label'],
				fn() => $this->render_form_field( $key, $field ),
				'conv-gateway-settings',
				'convoca_gateway_redsys'
			);
		}

		// Transfer section.
		add_settings_section(
			'convoca_gateway_transfer',
			__( 'Configuración Transferencia Bancaria', 'convoca-gateway' ),
			fn() => print '<p>' . esc_html__( 'Datos para mostrar a los usuarios que elijan pagar por transferencia.', 'convoca-gateway' ) . '</p>',
			'conv-gateway-settings'
		);

		$transfer_fields = array(
			'iban'         => array(
				'label' => 'IBAN',
				'type'  => 'text',
				'desc'  => 'ESxx xxxx xxxx xxxx xxxx xxxx',
			),
			'beneficiary'  => array(
				'label' => 'Titular / Beneficiario',
				'type'  => 'text',
				'desc'  => 'Nombre de la asociación',
			),
			'instructions' => array(
				'label' => 'Instrucciones adicionales',
				'type'  => 'textarea',
				'desc'  => 'Ej: "Indica tu nombre y DNI en el concepto"',
			),
		);

		foreach ( $transfer_fields as $key => $field ) {
			add_settings_field(
				'convoca_gateway_' . $key,
				$field['label'],
				fn() => $this->render_form_field( $key, $field ),
				'conv-gateway-settings',
				'convoca_gateway_transfer'
			);
		}

		// Emails section (Standard).
		add_settings_section(
			'convoca_gateway_emails',
			__( 'Notificaciones por Email', 'convoca-gateway' ),
			fn() => print '<p>' . esc_html__( 'Configura los correos automáticos tras un pago con éxito.', 'convoca-gateway' ) . '</p>',
			'conv-gateway-settings'
		);

		$email_fields = array(
			'email_confirmation' => array(
				'label' => __( 'Habilitar confirmación de pago', 'convoca-gateway' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Envía un email al usuario cuando el pago se completa.', 'convoca-gateway' ),
			),
			'email_sender_name'  => array(
				'label' => __( 'Nombre del remitente', 'convoca-gateway' ),
				'type'  => 'text',
				'desc'  => 'Ej: ' . get_bloginfo('name'),
			),
		);

		foreach ( $email_fields as $key => $field ) {
			add_settings_field(
				'convoca_gateway_' . $key,
				$field['label'],
				fn() => $this->render_form_field( $key, $field ),
				'conv-gateway-settings',
				'convoca_gateway_emails'
			);
		}

		// Email Templates section (Tab: Correos).
		$email_templates = array(
			'email_success_subject' => array(
				'label' => __( 'Asunto (Éxito)', 'convoca-gateway' ),
				'type'  => 'text',
				'desc'  => __( 'Asunto del correo tras un pago exitoso.', 'convoca-gateway' ),
			),
			'email_success_body'    => array(
				'label' => __( 'Cuerpo (Éxito)', 'convoca-gateway' ),
				'type'  => 'textarea',
				'desc'  => __( 'Contenido del correo tras un pago exitoso.', 'convoca-gateway' ),
			),
			'email_failed_subject'  => array(
				'label' => __( 'Asunto (Fallo)', 'convoca-gateway' ),
				'type'  => 'text',
				'desc'  => __( 'Asunto del correo tras un pago fallido.', 'convoca-gateway' ),
			),
			'email_failed_body'     => array(
				'label' => __( 'Cuerpo (Fallo)', 'convoca-gateway' ),
				'type'  => 'textarea',
				'desc'  => __( 'Contenido del correo tras un pago fallido.', 'convoca-gateway' ),
			),
		);

		add_settings_section(
			'convoca_gateway_email_templates',
			__( 'Personalización de Plantillas', 'convoca-gateway' ),
			fn() => print '<p>' . esc_html__( 'Usa variables: {importe}, {metodo}, {fecha}, {producto}, {enlace_inscripcion}', 'convoca-gateway' ) . '</p>',
			'conv-gateway-settings-emails'
		);

		foreach ( $email_templates as $key => $field ) {
			add_settings_field(
				'convoca_gateway_' . $key,
				$field['label'],
				fn() => $this->render_form_field( $key, $field ),
				'conv-gateway-settings-emails',
				'convoca_gateway_email_templates'
			);
		}

		// Pages section.
		add_settings_section(
			'convoca_gateway_pages',
			__( 'Páginas de pago', 'convoca-gateway' ),
			fn() => print '<p>' . esc_html__( 'Crea páginas con los shortcodes indicados y selecciónalas aquí.', 'convoca-gateway' ) . '</p>',
			'conv-gateway-settings'
		);

		// Recurring payments section (PRO).
		add_settings_section(
			'convoca_gateway_recurring',
			__( '💳 Pagos Recurrentes', 'convoca-gateway' ),
			function () {
				if ( \Convoca\Core\License_Manager::has_pro( 'gateway' ) ) {
					print '<p>' . esc_html__( 'Configura suscripciones y pagos periódicos con tarjeta o domiciliación.', 'convoca-gateway' ) . '</p>';
				} else {
					print '<div class="convoca-alert convoca-alert--info" style="display:block;margin-bottom:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;"><p style="margin:0;">🔒 <strong>' . esc_html__( 'Pagos Recurrentes', 'convoca-gateway' ) . '</strong> ' . esc_html__( 'es una funcionalidad PRO.', 'convoca-gateway' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=convoca-license' ) ) . '" style="font-weight:600;">' . esc_html__( 'Activa tu licencia', 'convoca-gateway' ) . '</a> ' . esc_html__( 'para desbloquear suscripciones y pagos periódicos.', 'convoca-gateway' ) . '</p></div>';
				}
			},
			'conv-gateway-settings'
		);

		$page_fields = array(
			'payment_page_id' => __( 'Página de pago ([convoca_pago])', 'convoca-gateway' ),
			'ok_page_id'      => __( 'Página de éxito ([convoca_pago_ok])', 'convoca-gateway' ),
			'ko_page_id'      => __( 'Página de error ([convoca_pago_ko])', 'convoca-gateway' ),
		);

		foreach ( $page_fields as $key => $label ) {
			add_settings_field(
				'convoca_gateway_' . $key,
				$label,
				fn() => $this->render_page_dropdown( $key ),
				'conv-gateway-settings',
				'convoca_gateway_pages'
			);
		}

		// Recurring payments fields (PRO).
		if ( \Convoca\Core\License_Manager::has_pro( 'gateway' ) ) {
			$recurring_fields = array(
				'recurring_enabled'       => array(
					'label' => __( 'Habilitar pagos recurrentes', 'convoca-gateway' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Permite configurar suscripciones y domiciliaciones para cuotas periódicas.', 'convoca-gateway' ),
				),
				'recurring_period'        => array(
					'label'   => __( 'Periodo por defecto', 'convoca-gateway' ),
					'type'    => 'select',
					'options' => array(
						'monthly'  => 'Mensual',
						'quarterly' => 'Trimestral',
						'yearly'   => 'Anual',
					),
					'desc'    => __( 'Periodicidad por defecto para nuevas suscripciones.', 'convoca-gateway' ),
				),
				'recurring_max_charges'   => array(
					'label' => __( 'Número máximo de cobros', 'convoca-gateway' ),
					'type'  => 'number',
					'desc'  => __( '0 = ilimitado (hasta que se cancele).', 'convoca-gateway' ),
				),
				'recurring_grace_period'  => array(
					'label' => __( 'Días de gracia', 'convoca-gateway' ),
					'type'  => 'number',
					'desc'  => __( 'Días de espera antes de marcar un recibo como fallido.', 'convoca-gateway' ),
				),
			);

			foreach ( $recurring_fields as $key => $field ) {
				add_settings_field(
					'convoca_gateway_' . $key,
					$field['label'],
					fn() => $this->render_form_field( $key, $field ),
					'conv-gateway-settings',
					'convoca_gateway_recurring'
				);
			}
		}
	}

	/**
	 * Render a standard form field.
	 */
	private function render_form_field( string $key, array $field ): void {
		// Check if constant is defined in wp-config.php.
		$is_constant = false;
		if ( $key === 'secret_key' && defined( 'CONVOCA_GATEWAY_SECRET_KEY' ) ) {
			$is_constant = true;
		}
		if ( $key === 'merchant_code' && defined( 'CONVOCA_GATEWAY_MERCHANT_CODE' ) ) {
			$is_constant = true;
		}
		if ( $key === 'bizum_merchant_code' && defined( 'CONVOCA_GATEWAY_BIZUM_MERCHANT_CODE' ) ) {
			$is_constant = true;
		}

		if ( $is_constant ) {
			printf(
				'<input type="text" value="********" class="regular-text" disabled>
                 <p class="description"><em>%s</em></p>',
				esc_html__( 'Definido en wp-config.php', 'convoca-gateway' )
			);
			return;
		}

		$settings = get_option( self::OPTION, array() );
		$value    = $settings[ $key ] ?? '';
		$type     = $field['type'] ?? 'text';

		// Don't show encrypted secret key value.
		if ( $key === 'secret_key' && str_starts_with( $value, 'enc:' ) ) {
			$value = '';
		}

		if ( $type === 'select' ) {
			printf( '<select name="%s[%s]">', esc_attr( self::OPTION ), esc_attr( $key ) );
			foreach ( $field['options'] as $v => $l ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $value, $v, false ), esc_html( $l ) );
			}
			echo '</select>';
		} elseif ( $type === 'checkbox' ) {
			printf(
				'<input type="checkbox" name="%s[%s]" value="1" %s>',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				checked( $value, '1', false )
			);
		} elseif ( $type === 'textarea' ) {
			printf(
				'<textarea name="%s[%s]" class="regular-text" rows="4">%s</textarea>',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				esc_textarea( $value )
			);
		} else {
			printf(
				'<input type="%s" name="%s[%s]" value="%s" class="regular-text" %s>',
				esc_attr( $type ),
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				esc_attr( $value ),
				$key === 'secret_key' ? 'placeholder="' . esc_attr__( 'Introduce clave para actualizar...', 'convoca-gateway' ) . '"' : ''
			);
		}

		if ( ! empty( $field['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['desc'] ) );
		}
	}

	/**
	 * Render a page dropdown.
	 */
	private function render_page_dropdown( string $key ): void {
		$settings = get_option( self::OPTION, array() );
		$selected = (int) ( $settings[ $key ] ?? 0 );

		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::OPTION . '[' . $key . ']' ),
				'selected'          => (int) $selected,
				'show_option_none'  => __( '— Seleccionar página —', 'convoca-gateway' ),
				'option_none_value' => 0,
			)
		);
	}

	/**
	 * Sanitize settings before saving.
	 */
	public function sanitize( array $input ): array {
		// Invalidate diagnostic cache to reflect changes immediately.
		Diagnostic::run_all( true );

		$old_settings = get_option( self::OPTION, array() );
		$new_secret   = $input['secret_key'] ?? '';
		$errors       = array();

		// Validate merchant_code: must be exactly 9 digits (FUC).
		$merchant_code = sanitize_text_field( $input['merchant_code'] ?? '' );
		if ( ! empty( $merchant_code ) && ! preg_match( '/^\d{9}$/', $merchant_code ) ) {
			$errors[] = __( 'El código de comercio debe tener exactamente 9 dígitos.', 'convoca-gateway' );
		}

		// Validate terminal: must be numeric, max 3 digits.
		$terminal = sanitize_text_field( $input['terminal'] ?? '001' );
		if ( ! empty( $terminal ) && ! preg_match( '/^\d{1,3}$/', $terminal ) ) {
			$errors[] = __( 'El número de terminal debe tener entre 1 y 3 dígitos.', 'convoca-gateway' );
		}

		// Validate environment.
		$environment = in_array( $input['environment'] ?? '', array( 'test', 'production' ), true ) ? $input['environment'] : 'test';

		// Validate IBAN format if provided.
		$iban = sanitize_text_field( $input['iban'] ?? '' );
		if ( ! empty( $iban ) && ! preg_match( '/^[A-Z]{2}\d{2}[\dA-Z]{10,30}$/', strtoupper( str_replace( ' ', '', $iban ) ) ) ) {
			$errors[] = __( 'El IBAN no tiene un formato válido.', 'convoca-gateway' );
		}

		// Show errors if any.
		if ( ! empty( $errors ) ) {
			add_settings_error( 'convoca_gateway_settings', 'convoca_gateway_validation', implode( '<br>', $errors ), 'error' );
		}

		// Handle secret key: only update if new value provided.
		if ( empty( $new_secret ) ) {
			$secret_to_save = $old_settings['secret_key'] ?? '';
		} else {
			if ( strlen( $new_secret ) < 16 ) {
				add_settings_error( 'convoca_gateway_settings', 'convoca_gateway_secret_short', __( 'La clave secreta debe tener al menos 16 caracteres.', 'convoca-gateway' ), 'error' );
			}
			$secret_to_save = Redsys_Client::encrypt_key( $new_secret );
		}

		return array(
			'merchant_code'         => sanitize_text_field( $input['merchant_code'] ?? '' ),
			'bizum_merchant_code'   => sanitize_text_field( $input['bizum_merchant_code'] ?? '' ),
			'terminal'              => sanitize_text_field( $input['terminal'] ?? '001' ),
			'secret_key'            => $secret_to_save,
			'environment'           => in_array( $input['environment'] ?? '', array( 'test', 'production' ) ) ? $input['environment'] : 'test',
			'iban'                  => sanitize_text_field( $input['iban'] ?? '' ),
			'beneficiary'           => sanitize_text_field( $input['beneficiary'] ?? '' ),
			'instructions'          => sanitize_textarea_field( $input['instructions'] ?? '' ),
			'payment_page_id'       => absint( $input['payment_page_id'] ?? 0 ),
			'ok_page_id'            => absint( $input['ok_page_id'] ?? 0 ),
			'ko_page_id'            => absint( $input['ko_page_id'] ?? 0 ),
			'email_confirmation'    => isset( $input['email_confirmation'] ) ? '1' : '0',
			'email_sender_name'     => sanitize_text_field( $input['email_sender_name'] ?? '' ),
			'email_success_subject' => sanitize_text_field( $input['email_success_subject'] ?? '' ),
			'email_success_body'    => wp_kses_post( $input['email_success_body'] ?? '' ),
			'email_failed_subject'  => sanitize_text_field( $input['email_failed_subject'] ?? '' ),
			'email_failed_body'     => wp_kses_post( $input['email_failed_body'] ?? '' ),
			'recurring_enabled'     => isset( $input['recurring_enabled'] ) ? '1' : '0',
			'recurring_period'      => in_array( $input['recurring_period'] ?? '', array( 'monthly', 'quarterly', 'yearly' ) ) ? $input['recurring_period'] : 'monthly',
			'recurring_max_charges' => absint( $input['recurring_max_charges'] ?? 0 ),
			'recurring_grace_period' => absint( $input['recurring_grace_period'] ?? 7 ),
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Security Guard: Check if critical dependencies are missing.
		if ( ! class_exists( '\\Convoca\\Core\\Utils' ) ) {
			echo '<div class="notice notice-warning"><p>⚠️ ' . esc_html__( 'Convoca Common no está activo. Algunas funciones de la pasarela podrían no estar disponibles.', 'convoca-gateway' ) . '</p></div>';
		}

		$settings   = get_option( self::OPTION, array() );
		$env        = $settings['environment'] ?? 'test';
		$active_tab = wp_unslash( $_GET['tab'] ?? 'general' );
		?>
		<div class="wrap conv-gateway-settings-wrap">
			<div class="conv-gateway-admin-header" style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
				<img src="<?php echo esc_url( CONVOCA_IMAGES_URL . 'logo.png' ); ?>" alt="Convoca Gateway" style="width: 80px; height: 80px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
				<div>
					<h1 style="margin: 0; padding: 0;"><?php esc_html_e( 'Pasarela de pago — Redsys', 'convoca-gateway' ); ?></h1>
					<p style="margin: 5px 0 0; color: #666; font-size: 1.1em;"><?php esc_html_e( 'Configuración y estado de transacciones', 'convoca-gateway' ); ?></p>
				</div>
			</div>

			<nav class="nav-tab-wrapper">
				<a href="?page=conv-gateway-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'convoca-gateway' ); ?>
				</a>
				<a href="?page=conv-gateway-settings&tab=emails" class="nav-tab <?php echo $active_tab === 'emails' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Correos', 'convoca-gateway' ); ?>
				</a>
				<a href="?page=conv-gateway-settings&tab=status" class="nav-tab <?php echo $active_tab === 'status' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Estado', 'convoca-gateway' ); ?>
					<?php
					$badge = Diagnostic::get_menu_badge();
					if ( $badge['severity'] !== 'ok' ) :
						?>
						<span class="conv-gateway-diagnostic-badge conv-gateway-badge--<?php echo esc_attr( $badge['severity'] ); ?>">
							<?php echo $badge['severity'] === 'error' ? '✗' : '⚠'; ?>
						</span>
					<?php endif; ?>
				</a>
			</nav>

			<?php if ( $active_tab === 'status' ) : ?>
				<?php $this->render_status_tab(); ?>
			<?php elseif ( $active_tab === 'general' ) : ?>
				<?php if ( $env === 'test' ) : ?>
					<div class="convoca-alert convoca-alert--info" style="display:block;margin-bottom:20px;">
						<p>🧪 <strong>Modo TEST activo.</strong> Los pagos se procesan en el sandbox de Redsys.
							Tarjeta de prueba: <code>4548 8120 4940 0004</code> — CVV: <code>123</code> — Caducidad: <code>12/34</code>
						</p>
					</div>
				<?php else : ?>
					<div class="convoca-alert convoca-alert--warning" style="display:block;margin-bottom:20px;">
						<p>⚡ <strong><?php esc_html_e( 'Modo PRODUCCIÓN activo.', 'convoca-gateway' ); ?></strong> <?php echo esc_html( sprintf( __( 'Los pagos son reales y van a %s.', 'convoca-gateway' ), apply_filters( 'convoca_gateway_bank_entity', __( 'tu entidad bancaria', 'convoca-gateway' ) ) ) ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $active_tab !== 'status' ) : ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'convoca_gateway_settings_group' );

				if ( $active_tab === 'emails' ) {
					do_settings_sections( 'conv-gateway-settings-emails' );
					?>
					<hr>
					<button type="button" class="button js-conv-gateway-preview-email" data-type="success"><?php esc_html_e( 'Previsualizar Éxito', 'convoca-gateway' ); ?></button>
					<button type="button" class="button js-conv-gateway-preview-email" data-type="failed"><?php esc_html_e( 'Previsualizar Fallo', 'convoca-gateway' ); ?></button>
					<?php
				} else {
					do_settings_sections( 'conv-gateway-settings' );
				}

				submit_button( __( 'Guardar configuración', 'convoca-gateway' ) );
				?>
			</form>
			<?php endif; ?>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Preview Emails.
			document.querySelectorAll('.js-conv-gateway-preview-email').forEach(function(btn) {
				btn.addEventListener('click', function() {
					const type = this.dataset.type;
					const subjectInput = type === 'success' ? document.querySelector('input[name="convoca_gateway_settings[email_success_subject]"]') : document.querySelector('input[name="convoca_gateway_settings[email_failed_subject]"]');
					const bodyInput = type === 'success' ? document.querySelector('textarea[name="convoca_gateway_settings[email_success_body]"]') : document.querySelector('textarea[name="convoca_gateway_settings[email_failed_body]"]');
					
					const previewWindow = window.open('', '_blank', 'width=600,height=400');
					if (previewWindow) {
						previewWindow.document.write('<h3>' + (subjectInput ? subjectInput.value : '') + '</h3><hr>' + (bodyInput ? bodyInput.value.replace(/\\n/g, '<br>') : ''));
						previewWindow.document.close();
					}
				});
			});

			// Run Diagnostic.
			const btnDiagnostic = document.getElementById('conv-gateway-run-diagnostic');
			if (btnDiagnostic) {
				btnDiagnostic.addEventListener('click', function() {
					const btn = this;
					btn.disabled = true;
					btn.textContent = 'Ejecutando...';
					
					const fd = new FormData();
					fd.append('action', 'convoca_gateway_diagnostic_run');
					fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'convoca_gateway_diagnostic_nonce' ) ); ?>');

					fetch(ajaxurl, {
						method: 'POST',
						body: fd
					})
					.then(r => r.json())
					.then(response => {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message || 'Error al ejecutar diagnóstico');
							btn.disabled = false;
							btn.textContent = 'Forzar comprobación';
						}
					}).catch(() => {
						alert('Error de conexión.');
						btn.disabled = false;
						btn.textContent = 'Forzar comprobación';
					});
					});
					}

					// Diagnostic Fixes.
					document.querySelectorAll('.conv-gateway-fix-button').forEach(function(btn) {
					btn.addEventListener('click', function() {
					const fix = this.dataset.fix;
					this.disabled = true;
					this.textContent = 'Aplicando...';
				
					const fd = new FormData();
					fd.append('action', 'convoca_gateway_diagnostic_fix');
					fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'convoca_gateway_diagnostic_nonce' ) ); ?>');
					fd.append('fix', fix);
				
					fetch(ajaxurl, {
						method: 'POST',
						body: fd
					})
					.then(r => r.json())
					.then(response => {
						if (response.success) {
							alert(response.data.message);
							location.reload();
						} else {
							alert(response.data.message || 'Error al aplicar reparación');
							this.disabled = false;
							this.textContent = 'Reparar';
						}
					}).catch(() => {
						alert('Error de conexión.');
						this.disabled = false;
						this.textContent = 'Reparar';
					});
					});
					});
					});
		</script>
		<style>
		.conv-gateway-diagnostic-badge { margin-left: 5px; }
		.conv-gateway-badge--ok { color: #46b450; }
		.conv-gateway-badge--warning { color: #f56e28; }
		.conv-gateway-badge--error { color: #dc3232; }
		.conv-gateway-diagnostic-row { padding: 12px; border-bottom: 1px solid #e0e0e0; }
		.conv-gateway-diagnostic-row:last-child { border-bottom: none; }
		.conv-gateway-diagnostic-row .conv-gateway-severity-icon { font-size: 1.2em; margin-right: 8px; }
		.conv-gateway-diagnostic-row .conv-gateway-severity-ok { color: #46b450; }
		.conv-gateway-diagnostic-row .conv-gateway-severity-warning { color: #f56e28; }
		.conv-gateway-diagnostic-row .conv-gateway-severity-error { color: #dc3232; }
		.conv-gateway-diagnostic-row .conv-gateway-message { display: block; margin-top: 4px; color: #666; font-size: 0.9em; }
		.conv-gateway-diagnostic-row .conv-gateway-fix-info { display: block; margin-top: 4px; color: #dc3232; font-size: 0.9em; }
		.conv-gateway-diagnostic-children { margin-left: 20px; border-left: 2px solid #e0e0e0; padding-left: 10px; margin-top: 8px; }
		.conv-gateway-summary { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; }
		.conv-gateway-summary-icon { font-size: 2em; }
		.conv-gateway-summary-text h3 { margin: 0; }
		.conv-gateway-summary-text p { margin: 5px 0 0 0; color: #666; }
		</style>
		<?php
	}

	private function render_status_tab(): void {
		$results      = Diagnostic::run_all();
		$has_errors   = Diagnostic::has_errors( $results );
		$has_warnings = Diagnostic::has_warnings( $results );

		if ( $has_errors ) {
			$summary_icon  = '✗';
			$summary_class = 'error';
			$summary_text  = 'Hay errores de configuración que deben resolverse';
			$summary_title = 'Estado: Errores detectados';
		} elseif ( $has_warnings ) {
			$summary_icon  = '⚠';
			$summary_class = 'warning';
			$summary_text  = 'Hay advertencias que podrían afectar el funcionamiento';
			$summary_title = 'Estado: Advertencias';
		} else {
			$summary_icon  = '✓';
			$summary_class = 'success';
			$summary_text  = 'Todos los componentes están configurados correctamente';
			$summary_title = 'Estado: Todo correcto';
		}
		?>
		<div class="conv-gateway-diagnostic-wrapper">
			<div class="conv-gateway-summary">
				<div class="conv-gateway-summary-icon conv-gateway-badge--<?php echo esc_attr( $summary_class ); ?>">
					<?php echo esc_html( $summary_icon ); ?>
				</div>
				<div class="conv-gateway-summary-text">
					<h3><?php echo esc_html( $summary_title ); ?></h3>
					<p><?php echo esc_html( $summary_text ); ?></p>
				</div>
				<button type="button" id="conv-gateway-run-diagnostic" class="button button-primary" style="margin-left: auto;">
					Forzar comprobación
				</button>
			</div>

			<div class="conv-gateway-diagnostic-results">
				<?php foreach ( $results as $result ) : ?>
					<?php
					$severity = $result['severity'];
					$icon     = $severity === 'ok' ? '✓' : ( $severity === 'warning' ? '⚠' : '✗' );
					?>
					<div class="conv-gateway-diagnostic-row">
						<div class="conv-gateway-diagnostic-header">
							<span class="conv-gateway-severity-icon conv-gateway-severity-<?php echo esc_attr( $severity ); ?>">
								<?php echo esc_html( $icon ); ?>
							</span>
							<strong><?php echo esc_html( $result['title'] ); ?></strong>
						</div>
						<span class="conv-gateway-message"><?php echo esc_html( $result['message'] ); ?></span>
						<?php if ( ! empty( $result['fix'] ) ) : ?>
							<span class="conv-gateway-fix-info"><?php echo esc_html( $result['fix'] ); ?></span>
							<?php if ( ! empty( $result['fix_callback'] ) ) : ?>
								<button type="button" class="button button-small conv-gateway-fix-button" data-fix="<?php echo esc_attr( $result['slug'] ); ?>">
									Reparar
								</button>
							<?php endif; ?>
						<?php endif; ?>
						
						<?php if ( ! empty( $result['children'] ) ) : ?>
							<div class="conv-gateway-diagnostic-children">
								<?php foreach ( $result['children'] as $child ) : ?>
									<?php
									$child_severity = $child['severity'];
									$child_icon     = $child_severity === 'ok' ? '✓' : ( $child_severity === 'warning' ? '⚠' : '✗' );
									?>
									<div class="conv-gateway-diagnostic-row">
										<span class="conv-gateway-severity-icon conv-gateway-severity-<?php echo esc_attr( $child_severity ); ?>">
											<?php echo esc_html( $child_icon ); ?>
										</span>
										<span><?php echo esc_html( $child['title'] ); ?></span>
										<span class="conv-gateway-message"><?php echo esc_html( $child['message'] ); ?></span>
										<?php if ( ! empty( $child['fix'] ) ) : ?>
											<span class="conv-gateway-fix-info"><?php echo esc_html( $child['fix'] ); ?></span>
											<?php if ( ! empty( $child['fix_callback'] ) ) : ?>
												<button type="button" class="button button-small conv-gateway-fix-button" data-fix="<?php echo esc_attr( $child['slug'] ); ?>">
													Reparar
												</button>
											<?php endif; ?>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public function ajax_diagnostic_run(): void {
		check_ajax_referer( 'convoca_gateway_diagnostic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos', 'convoca-gateway' ) ) );
		}

		Diagnostic::run_all( true );
		wp_send_json_success( array( 'message' => __( 'Diagnóstico completado', 'convoca-gateway' ) ) );
	}

	public function ajax_diagnostic_fix(): void {
		check_ajax_referer( 'convoca_gateway_diagnostic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos', 'convoca-gateway' ) ) );
		}

		$fix = sanitize_text_field( wp_unslash( $_POST['fix'] ?? '' ) );

		$result = array(
			'success' => false,
			'message' => __( 'Acción no encontrada', 'convoca-gateway' ),
		);

		switch ( $fix ) {
			case 'terminal':
				$result = Diagnostic::fix_default_terminal();
				break;
			case 'ok_page':
			case 'ko_page':
			case 'return_pages':
				$result = Diagnostic::fix_create_pages();
				break;
		}

		if ( $result['success'] ) {
			Diagnostic::run_all( true );
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	private function has_errors( array $results ): bool {
		foreach ( $results as $result ) {
			if ( isset( $result['children'] ) ) {
				foreach ( $result['children'] as $child ) {
					if ( $child['severity'] === 'error' ) {
						return true;
					}
				}
			}
			if ( ( $result['severity'] ?? '' ) === 'error' ) {
				return true;
			}
		}
		return false;
	}

	private function has_warnings( array $results ): bool {
		foreach ( $results as $result ) {
			if ( isset( $result['children'] ) ) {
				foreach ( $result['children'] as $child ) {
					if ( $child['severity'] === 'warning' ) {
						return true;
					}
				}
			}
			if ( ( $result['severity'] ?? '' ) === 'warning' ) {
				return true;
			}
		}
		return false;
	}
}
