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
 * Borrado de registros de pago (y de enlaces) desde el escritorio, con confirmación.
 *
 * Los registros viven en el CPT `pago` con `show_ui => false`, así que WordPress no
 * ofrece ni pantalla ni papelera: sin esto no había forma de eliminar un pago, ni un
 * enlace de pago, desde la administración. El borrado es **definitivo** y con
 * confirmación en dos pasos (acción → pantalla de aviso → envío firmado), porque son
 * registros económicos: el aviso dice qué se va a borrar, si está pagado y si tiene
 * recibo, para que nadie lo haga sin verlo.
 *
 * @package Convoca\Gateway
 */
namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Acciones de registro (borrado y avisos) compartidas por los listados.
 */
class Admin_Record_Actions {

	/** Capacidad necesaria: la misma que genera enlaces. */
	public const CAP = 'convoca_manage_payments';

	/** Acción de admin-post que ejecuta el borrado. */
	public const ACTION = 'convoca_gateway_delete_records';

	/** Nombre del nonce y del campo de acción. */
	public const NONCE = 'convoca_gateway_delete_records';

	/** Marca de la URL que trae el resultado (para el aviso). */
	public const FLAG = 'convoca_deleted';

	/** Marca de la URL cuando el borrado no se pudo hacer. */
	public const FLAG_ERROR = 'convoca_delete_error';

	/**
	 * Engancha el manejador.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_delete' ) );
	}

	/*
	 * ── Consultas de estado ────────────────────────────────────────────────
	 */

	/**
	 * ¿Puede el usuario actual borrar o editar registros de pago?
	 *
	 * Vale la capacidad del plugin **o** ser administrador del sitio: la capacidad
	 * la concede `convoca-core` al activarse, y en una instalación donde no se haya
	 * concedido (o con roles propios) el administrador se quedaría sin poder tocar
	 * sus propios registros. Para borrar dinero, el suelo es el administrador.
	 */
	public static function user_can(): bool {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Nombre del campo con las casillas de cada listado.
	 *
	 * @param string $tipo 'pago' o 'enlace'.
	 */
	public static function field( string $tipo ): string {
		return 'enlace' === $tipo ? 'enlace' : 'pago';
	}

	/**
	 * URL del listado de origen (donde se vuelve y donde se pinta el aviso).
	 *
	 * @param string $tipo 'pago' o 'enlace'.
	 */
	public static function list_url( string $tipo ): string {
		return 'enlace' === $tipo
			? admin_url( 'admin.php?page=conv-gateway-links' )
			: admin_url( 'admin.php?page=conv-gateway-payments' );
	}

	/**
	 * Acción de borrado solicitada en la petición actual, si la hay.
	 *
	 * Atiende al enlace de la fila (`action=delete&id=N`) y a la acción en bloque,
	 * que en WP_List_Table viaja como `action` (botón de arriba) o `action2` (abajo).
	 */
	public static function pending_action(): string {
		$accion = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( '' === $accion || '-1' === $accion ) {
			$accion = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}

		return 'convoca_delete' === $accion ? 'convoca_delete' : '';
	}

	/**
	 * Identificadores a borrar, saneados y limitados a registros de pago reales.
	 *
	 * Acepta el enlace de una fila (`id`) y la selección múltiple (`pago[]` o
	 * `enlace[]`). Todo lo que no sea un entero positivo o no sea un `pago` se
	 * descarta: la pantalla de confirmación no debe listar lo que no va a borrar.
	 *
	 * @param string $tipo 'pago' o 'enlace'.
	 * @return array<int>
	 */
	public static function requested_ids( string $tipo ): array {
		$campo = self::field( $tipo );
		$crudo = array();

		if ( ! empty( $_REQUEST['id'] ) ) {
			$crudo[] = wp_unslash( $_REQUEST['id'] );
		}

		if ( isset( $_REQUEST[ $campo ] ) && is_array( $_REQUEST[ $campo ] ) ) {
			$crudo = array_merge( $crudo, wp_unslash( $_REQUEST[ $campo ] ) );
		}

		$ids = array();
		foreach ( $crudo as $valor ) {
			$id = absint( is_scalar( $valor ) ? $valor : 0 );
			if ( $id && 'pago' === get_post_type( $id ) ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/*
	 * ── Pantalla de confirmación ───────────────────────────────────────────
	 */

	/**
	 * Pantalla de aviso: lista lo que se va a borrar y pide confirmación.
	 *
	 * @param string    $tipo 'pago' o 'enlace'.
	 * @param array<int> $ids Identificadores confirmados.
	 */
	public static function render_confirm( string $tipo, array $ids ): void {
		$es_enlace = 'enlace' === $tipo;
		$volver    = $es_enlace
			? admin_url( 'admin.php?page=conv-gateway-links' )
			: admin_url( 'admin.php?page=conv-gateway-payments' );

		if ( ! self::user_can() ) {
			wp_die( esc_html__( 'No tienes permiso para eliminar registros de pago.', 'convoca-gateway' ) );
		}

		$registros = array();
		foreach ( $ids as $id ) {
			$registros[ $id ] = CPT_Pago::get_meta( $id );
		}

		// ¿Cuántos pagos nacieron de un enlace? Se conservan: hijo no se borra con padre.
		$hijos = 0;
		if ( $es_enlace && $ids ) {
			$hijos = count(
				get_posts(
					array(
						'post_type'      => 'pago',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'     => '_convoca_origin_id',
								'value'   => $ids,
								'compare' => 'IN',
							),
						),
					)
				)
			);
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php
				echo $es_enlace
					? esc_html__( 'Eliminar enlaces de pago', 'convoca-gateway' )
					: esc_html__( 'Eliminar pagos', 'convoca-gateway' );
				?>
			</h1>
			<hr class="wp-header-end">

			<?php if ( ! $registros ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No hay nada que eliminar: no se ha seleccionado ningún registro.', 'convoca-gateway' ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Volver', 'convoca-gateway' ); ?></a></p>
				<?php
				return;
			endif;
			?>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Esta acción no se puede deshacer.', 'convoca-gateway' ); ?></strong>
					<?php
					printf(
						/* translators: %d: número de registros. */
						esc_html( _n( 'Se va a eliminar %d registro de forma definitiva.', 'Se van a eliminar %d registros de forma definitiva.', count( $registros ), 'convoca-gateway' ) ),
						count( $registros )
					);
					?>
				</p>
				<?php if ( $hijos ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: número de pagos. */
							esc_html( _n( 'Las %d aportaciones hechas con estos enlaces se conservan: son pagos independientes.', 'Las %d aportaciones hechas con estos enlaces se conservan: son pagos independientes.', $hijos, 'convoca-gateway' ) ),
							(int) $hijos
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Pedido', 'convoca-gateway' ); ?></th>
						<?php if ( $es_enlace ) : ?>
							<th><?php esc_html_e( 'Concepto', 'convoca-gateway' ); ?></th>
							<th><?php esc_html_e( 'Importe', 'convoca-gateway' ); ?></th>
						<?php else : ?>
							<th><?php esc_html_e( 'Importe', 'convoca-gateway' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'convoca-gateway' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'convoca-gateway' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'A tener en cuenta', 'convoca-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $registros as $id => $meta ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $meta['order_id'] ?? '' ); ?></strong><br><span class="description">#<?php echo (int) $id; ?></span></td>
							<?php if ( $es_enlace ) : ?>
								<td><?php echo esc_html( $meta['product_desc'] ?: '—' ); ?></td>
								<td><?php echo esc_html( ! empty( $meta['open_amount'] ) ? __( 'Importe libre', 'convoca-gateway' ) : CPT_Pago::format_amount( (int) $meta['amount_cents'] ) ); ?></td>
							<?php else : ?>
								<td><?php echo esc_html( ! empty( $meta['open_amount'] ) ? __( 'Importe libre', 'convoca-gateway' ) : CPT_Pago::format_amount( (int) $meta['amount_cents'] ) ); ?></td>
								<td><?php echo wp_kses_post( CPT_Pago::badge( $meta['status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( $meta['created_at'] ?? '' ); ?></td>
							<?php endif; ?>
							<td><?php echo self::warnings_html( $id, $meta, $tipo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio ya escapado. ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5rem;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="tipo" value="<?php echo esc_attr( $es_enlace ? 'enlace' : 'pago' ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php foreach ( array_keys( $registros ) as $id ) : ?>
					<input type="hidden" name="ids[]" value="<?php echo (int) $id; ?>">
				<?php endforeach; ?>

				<button type="submit" class="button button-primary">
					<?php
					echo $es_enlace
						? esc_html__( 'Sí, eliminar los enlaces', 'convoca-gateway' )
						: esc_html__( 'Sí, eliminar los pagos', 'convoca-gateway' );
					?>
				</button>
				<a class="button" href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Cancelar', 'convoca-gateway' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Avisos por registro: lo que conviene saber antes de borrarlo.
	 *
	 * @param int                  $id   Identificador.
	 * @param array<string, mixed> $meta Metadatos del registro.
	 * @param string               $tipo 'pago' o 'enlace'.
	 */
	private static function warnings_html( int $id, array $meta, string $tipo ): string {
		$avisos = array();

		if ( 'enlace' === $tipo ) {
			if ( ! empty( $meta['open_amount'] ) ) {
				$avisos[] = __( 'Enlace de donativo: cada aportación se registra por separado.', 'convoca-gateway' );
			}
			if ( ! empty( $meta['expires_at'] ) && (int) $meta['expires_at'] < time() ) {
				$avisos[] = __( 'Ya estaba caducado.', 'convoca-gateway' );
			}
			if ( 'paid' === ( $meta['status'] ?? '' ) ) {
				$avisos[] = __( 'Ya se usó una vez.', 'convoca-gateway' );
			}
			if ( post_type_exists( 'enroll' ) ) {
				$avisos[] = __( 'Si el enlace está publicado en una web o un email, dejará de funcionar.', 'convoca-gateway' );
			}
		} else {
			if ( 'paid' === ( $meta['status'] ?? '' ) ) {
				$avisos[] = __( 'Está marcado como PAGADO: cuenta en los informes de ingresos.', 'convoca-gateway' );
			}
			$recibo = get_post_meta( $id, '_convoca_receipt_number', true );
			if ( $recibo ) {
				$avisos[] = sprintf(
					/* translators: %s: número de recibo. */
					__( 'Tiene recibo emitido (%s).', 'convoca-gateway' ),
					$recibo
				);
			}
			if ( ! empty( $meta['paid_at'] ) ) {
				$avisos[] = sprintf(
					/* translators: %s: fecha de pago. */
					__( 'Cobrado el %s.', 'convoca-gateway' ),
					$meta['paid_at']
				);
			}
		}

		if ( ! $avisos ) {
			return '<span class="description">—</span>';
		}

		return '<ul style="margin:0; padding-left:1.1rem; list-style:disc;">' .
			implode( '', array_map( static fn( $a ) => '<li>' . esc_html( $a ) . '</li>', $avisos ) ) .
			'</ul>';
	}

	/*
	 * ── Borrado ────────────────────────────────────────────────────────────
	 */

	/**
	 * Manejador de admin-post: valida y borra, y vuelve al listado con el aviso.
	 */
	public function handle_delete(): void {
		check_admin_referer( self::NONCE );

		if ( ! self::user_can() ) {
			wp_die(
				esc_html__( 'No tienes permiso para eliminar registros de pago.', 'convoca-gateway' ),
				403
			);
		}

		$tipo    = isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : 'pago';
		$crudo   = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		$ids     = array_values( array_filter( array_map( 'absint', $crudo ) ) );
		$borrados = self::delete_records( $ids );

		wp_safe_redirect(
			add_query_arg( self::FLAG, $borrados, self::list_url( $tipo ) )
		);
		exit;
	}

	/**
	 * Borra registros de pago (y sus datos asociados).
	 *
	 * Sin guards de petición a propósito: es la parte reutilizable y comprobable.
	 * El borrado es definitivo (`wp_delete_post( $id, true )`): la papelera dejaría
	 * registros económicos vivos en la base contando en las consultas.
	 *
	 * @param array<int> $ids Identificadores.
	 * @return int Cuántos se borraron de verdad.
	 */
	public static function delete_records( array $ids ): int {
		$borrados = 0;

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ! $id || 'pago' !== get_post_type( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}

			$meta    = CPT_Pago::get_meta( $id );
			$detalle = sprintf(
				'Registro #%d eliminado desde el escritorio. Pedido %s, importe %s, estado %s, origen %s.',
				$id,
				(string) ( $meta['order_id'] ?? '' ),
				CPT_Pago::format_amount( (int) ( $meta['amount_cents'] ?? 0 ) ),
				(string) ( $meta['status'] ?? '' ),
				(string) ( $meta['origin'] ?? '' )
			);

			if ( wp_delete_post( $id, true ) ) {
				$borrados++;
				\Convoca\Core\Logger::info( $detalle, 'Gateway/Delete', $id );
			} else {
				\Convoca\Core\Logger::error( 'No se pudo eliminar: ' . $detalle, 'Gateway/Delete', $id );
			}
		}

		return $borrados;
	}

	/**
	 * Aviso de resultado tras volver al listado. Se llama desde los dos listados.
	 *
	 * @param string $tipo 'pago' o 'enlace'.
	 */
	public static function maybe_notice( string $tipo ): void {
		if ( isset( $_GET[ self::FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			$n = absint( wp_unslash( $_GET[ self::FLAG ] ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: número de registros eliminados. */
						_n( 'Se ha eliminado %d registro.', 'Se han eliminado %d registros.', $n, 'convoca-gateway' ),
						$n
					)
				)
			);
		}

		if ( isset( $_GET[ self::FLAG_ERROR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'No se pudo completar la operación: faltan permisos o la sesión ha caducado.', 'convoca-gateway' )
			);
		}
	}
}
