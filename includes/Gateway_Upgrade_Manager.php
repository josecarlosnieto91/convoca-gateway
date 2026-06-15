<?php
/**
 * Upgrade Manager for Convoca Gateway.
 *
 * Handles database structure upgrades for the gateway plugin.
 *
 * To add a new upgrade:
 * 1. Increment CONV_GATEWAY_DB_VERSION in convoca-gateway.php
 * 2. Add a callback: '1.0.1' => [$this, 'upgrade_to_1_0_1']
 * 3. Implement the private method with idempotent logic.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

use Convoca\Core\Upgrade_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gateway_Upgrade_Manager extends Upgrade_Manager {

	public function __construct() {
		$this->init();
	}

	protected function get_db_version(): string {
		return defined( 'CONV_GATEWAY_DB_VERSION' ) ? CONV_GATEWAY_DB_VERSION : '0.0.0';
	}

	protected function get_option_name(): string {
		return 'conv_gateway_db_version';
	}

	protected function get_transient_prefix(): string {
		return 'conv';
	}

	protected function get_upgrade_callbacks(): array {
		return array(
			// Add upgrade callbacks here as needed.
			// '1.0.1' => [$this, 'upgrade_to_1_0_1'],
		);
	}
}
