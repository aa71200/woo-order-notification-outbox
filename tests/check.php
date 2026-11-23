<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || ! getenv( 'CODEXAWP_TEST_WP_LOAD' ) ) {
	throw new RuntimeException( 'Set CODEXAWP_TEST_WP_LOAD to a disposable WordPress installation.' );
}
define( 'CODEXAWP_OUTBOX_RECEIVER', 'https://portfolio-receiver.example/notify' );
require getenv( 'CODEXAWP_TEST_WP_LOAD' );
if ( 'local' !== wp_get_environment_type() || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'This fixture requires local WordPress with WooCommerce active.' );
}

function check( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: $message\n";
}

global $wpdb;
$table = \CodexaWP\Outbox\table();
\CodexaWP\Outbox\install();
$order = wc_create_order();
$other = wc_create_order();
$timeout_order = wc_create_order();
$order->update_status( 'processing' );
$other->update_status( 'processing' );
$timeout_order->update_status( 'processing' );
do_action( 'woocommerce_order_status_processing', $order->get_id() );
$row = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id=%d", $id ) );
check( 'pending' === $row( $order->get_id() )->state, 'Processing event is durable before delivery' );
check( as_has_scheduled_action( \CodexaWP\Outbox\DELIVERY_HOOK, [ $order->get_id() ], \CodexaWP\Outbox\GROUP ), 'Action Scheduler queues delivery' );
$attempts = [];
$received = [];
add_filter( 'pre_http_request', static function ( $pre, $options, $url ) use ( &$attempts, &$received, $order, $other ): mixed {
	if ( CODEXAWP_OUTBOX_RECEIVER !== $url ) return $pre;
	$event = json_decode( $options['body'], true, 512, JSON_THROW_ON_ERROR );
	$key = $options['headers']['Idempotency-Key'];
	if ( $key !== $event['eventId'] ) throw new RuntimeException( 'No stable receiver key' );
	$attempts[$key] = ( $attempts[$key] ?? 0 ) + 1;
	if ( $event['orderId'] === $other->get_id() ) {
		$status = 400;
	} elseif ( $event['orderId'] === $order->get_id() ) {
		$status = 1 === $attempts[$key] ? 503 : 200;
	} else {
		$received[$key] ??= $event;
		if ( 1 === $attempts[$key] ) return new WP_Error( 'fixture_timeout', 'Receiver saved the event; response was lost.' );
		$status = 200;
	}
	return [ 'response' => [ 'code' => $status ], 'body' => '' ];
}, 10, 3 );
$id = $order->get_id();
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $id );
check( 'pending' === $row( $id )->state && (int) $row( $id )->next_at > time(), '503 schedules a delayed retry' );
$wpdb->update( $table, [ 'next_at' => time() ], [ 'order_id' => $id ] );
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $id );
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $id );
check( 'delivered' === $row( $id )->state && 2 === $attempts['wc-processing-' . $id], 'Retry succeeds and subsequent dispatch is ignored' );
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $other->get_id() );
check( 'failed' === $row( $other->get_id() )->state, 'Permanent 400 records terminal failure' );
$timeout_id = $timeout_order->get_id();
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $timeout_id );
$wpdb->update( $table, [ 'next_at' => time() ], [ 'order_id' => $timeout_id ] );
do_action( \CodexaWP\Outbox\DELIVERY_HOOK, $timeout_id );
check( count( $received ) === 1 && 'delivered' === $row( $timeout_id )->state, 'Receiver deduplicates after a lost response' );
