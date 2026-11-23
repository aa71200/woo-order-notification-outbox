<?php
/**
 * Plugin Name: CodexaWP Order Notification Outbox
 * Description: Queues WooCommerce order events and retries delivery to a sample receiver.
 * Version: 1.1.0
 * Requires Plugins: woocommerce
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace CodexaWP\Outbox;
defined( 'ABSPATH' ) || exit;

const DELIVERY_HOOK = 'codexawp_deliver_order_event';
const SWEEP_HOOK = 'codexawp_sweep_order_events';
const GROUP = 'codexawp-order-events';
const SCHEMA_OPTION = 'codexawp_outbox_schema';

function table(): string {
	global $wpdb;
	return $wpdb->prefix . 'codexawp_order_outbox';
}

function install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = table();
	dbDelta( "CREATE TABLE $table (
		order_id bigint unsigned NOT NULL,
		event_id char(36) DEFAULT NULL,
		state varchar(12) NOT NULL,
		attempts smallint unsigned NOT NULL DEFAULT 0,
		next_at bigint unsigned NOT NULL,
		response_code smallint unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (order_id),
		KEY queued (state,next_at)
	) " . $wpdb->get_charset_collate() . ';' );
	if ( 'event_id' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( 'event_id' ) ) ) ) {
		update_option( SCHEMA_OPTION, '2', false );
	} else {
		log_failure( 'Could not upgrade outbox schema.' );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\install' );
add_action( 'init', static function (): void {
	if ( '2' !== get_option( SCHEMA_OPTION ) ) {
		install();
	}
}, 0 );

function log_failure( string $message, int $order_id = 0 ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->error( $message, [ 'source' => GROUP, 'order_id' => $order_id ] );
	}
}

function schedule( int $order_id, int $attempt, int $due ): void {
	if ( ! did_action( 'action_scheduler_init' ) ) {
		return;
	}
	$args = [ $order_id, $attempt ];
	if ( ! as_has_scheduled_action( DELIVERY_HOOK, $args, GROUP ) &&
		! as_schedule_single_action( $due, DELIVERY_HOOK, $args, GROUP, true ) ) {
		log_failure( 'Could not schedule outbox delivery.', $order_id );
	}
}

add_action( 'action_scheduler_init', static function (): void {
	if ( ! as_has_scheduled_action( SWEEP_HOOK, [], GROUP ) ) {
		as_schedule_recurring_action( time() + 60, 60, SWEEP_HOOK, [], GROUP, true );
	}
} );
register_deactivation_hook( __FILE__, static function (): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( SWEEP_HOOK, [], GROUP );
	}
} );

add_action( 'woocommerce_order_status_processing', static function ( int $order_id ): void {
	global $wpdb;
	$table = table();
	$due = time();
	$written = $wpdb->query( $wpdb->prepare(
		"INSERT IGNORE INTO $table (order_id,event_id,state,next_at) VALUES (%d,%s,'pending',%d)",
		$order_id,
		wp_generate_uuid4(),
		$due
	) );
	if ( false === $written ) {
		log_failure( 'Could not record order event in the outbox.', $order_id );
	} elseif ( 1 === $written ) {
		schedule( $order_id, 0, $due );
	}
} );

add_action( SWEEP_HOOK, static function (): void {
	global $wpdb;
	$table = table();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT order_id, attempts, next_at FROM $table WHERE state = 'pending' AND next_at <= %d ORDER BY next_at LIMIT 25",
		time()
	) );
	foreach ( $rows as $row ) {
		schedule( (int) $row->order_id, (int) $row->attempts, (int) $row->next_at );
	}
} );

add_action( DELIVERY_HOOK, __NAMESPACE__ . '\deliver', 10, 2 );

function deliver( int $order_id, ?int $expected_attempt = null ): void {
	global $wpdb;
	$lock = 'codexa.outbox.' . md5( $wpdb->prefix . $order_id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		return;
	}
	try {
		$table = table();
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id = %d", $order_id ) );
		if ( ! $event || 'pending' !== $event->state || (int) $event->next_at > time() ||
			( null !== $expected_attempt && $expected_attempt !== (int) $event->attempts ) ) {
			return;
		}
		$url = defined( 'CODEXAWP_OUTBOX_RECEIVER' ) ? CODEXAWP_OUTBOX_RECEIVER : '';
		if ( ! is_string( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			log_failure( 'Outbox receiver must be configured with HTTPS.', $order_id );
			if ( false === $wpdb->update( $table, [ 'state' => 'failed' ], [ 'order_id' => $order_id ] ) ) {
				log_failure( 'Could not save terminal outbox state.', $order_id );
			}
			return;
		}
		// Preserve the idempotency key for records created before the schema upgrade.
		$key = $event->event_id ?: 'wc-processing-' . $order_id;
		$response = wp_safe_remote_post( $url, [
			'timeout' => 5,
			'redirection' => 0,
			'headers' => [ 'Content-Type' => 'application/json', 'Idempotency-Key' => $key ],
			'body' => wp_json_encode( [ 'eventId' => $key, 'orderId' => $order_id, 'status' => 'processing' ] ),
		] );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$attempt = (int) $event->attempts + 1;
		$retryable = 0 === $code || 429 === $code || 500 <= $code;
		$retry = $retryable && $attempt < 3;
		$state = 200 <= $code && 300 > $code ? 'delivered' : ( $retry ? 'pending' : 'failed' );
		$next_at = $retry ? time() + ( 1 === $attempt ? 30 : 120 ) : 0;
		$written = $wpdb->update( $table, [
			'state' => $state, 'response_code' => $code, 'attempts' => $attempt, 'next_at' => $next_at,
		], [ 'order_id' => $order_id ] );
		if ( false === $written ) {
			log_failure( 'Could not save outbox delivery result.', $order_id );
		} elseif ( $retry ) {
			schedule( $order_id, $attempt, $next_at );
		}
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}
