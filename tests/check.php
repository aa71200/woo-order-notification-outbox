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
check( as_has_scheduled_action( \CodexaWP\Outbox\DELIVERY_HOOK, [ $order->get_id(), 0 ], \CodexaWP\Outbox\GROUP ), 'Action Scheduler queues delivery' );
check( wp_is_uuid( $row( $order->get_id() )->event_id, 4 ) && $row( $order->get_id() )->event_id !== $row( $other->get_id() )->event_id, 'New events have independent UUIDs' );
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
$runner = ActionScheduler_QueueRunner::instance();
$run_outbox = static function () use ( $runner, $row, $order, $other, $timeout_order ): void {
	foreach ( [ $order, $other, $timeout_order ] as $fixture_order ) {
		$order_id = $fixture_order->get_id();
		$ids = as_get_scheduled_actions( [
			'hook' => \CodexaWP\Outbox\DELIVERY_HOOK,
			'group' => \CodexaWP\Outbox\GROUP,
			'args' => [ $order_id, (int) $row( $order_id )->attempts ],
			'status' => ActionScheduler_Store::STATUS_PENDING,
			'date' => new DateTime( 'now', new DateTimeZone( 'UTC' ) ),
			'date_compare' => '<=',
		], 'ids' );
		foreach ( $ids as $action_id ) {
			$runner->process_action( $action_id, 'Outbox integration fixture' );
		}
	}
};
$run_outbox();
$retry_at = (int) $row( $id )->next_at;
check( 'pending' === $row( $id )->state && $retry_at > time(), '503 records a delayed retry' );
check( $retry_at === as_next_scheduled_action( \CodexaWP\Outbox\DELIVERY_HOOK, [ $id, 1 ], \CodexaWP\Outbox\GROUP ), 'The retry has a scheduled action at the persisted deadline' );
check( 'failed' === $row( $other->get_id() )->state, 'Permanent 400 records terminal failure' );
$timeout_id = $timeout_order->get_id();
$timeout_due = (int) $row( $timeout_id )->next_at;
as_unschedule_all_actions( \CodexaWP\Outbox\DELIVERY_HOOK, [ $timeout_id, 1 ], \CodexaWP\Outbox\GROUP );
sleep( max( 0, max( $retry_at, $timeout_due ) - time() ) + 1 );
do_action( \CodexaWP\Outbox\SWEEP_HOOK );
check( as_has_scheduled_action( \CodexaWP\Outbox\DELIVERY_HOOK, [ $timeout_id, 1 ], \CodexaWP\Outbox\GROUP ), 'The sweep requeues an overdue item with no scheduled action' );
$run_outbox();
check( 'delivered' === $row( $id )->state && 2 === $attempts[ $row( $id )->event_id ], 'The scheduled retry delivers exactly once' );
check( count( $received ) === 1 && 'delivered' === $row( $timeout_id )->state, 'Receiver deduplicates after a lost response' );

$original_prefix = $wpdb->prefix;
$wpdb->prefix .= 'pqa_' . strtolower( wp_generate_password( 6, false ) ) . '_';
$old_table = \CodexaWP\Outbox\table();
try {
	$wpdb->query( $wpdb->prepare(
		"CREATE TABLE %i (
			order_id bigint unsigned NOT NULL,
			state varchar(12) NOT NULL,
			attempts smallint unsigned NOT NULL DEFAULT 0,
			next_at bigint unsigned NOT NULL,
			response_code smallint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (order_id),
			KEY queued (state,next_at)
		) " . $wpdb->get_charset_collate(),
		$old_table
	) );
	$legacy_id = $order->get_id();
	$wpdb->insert( $old_table, [ 'order_id' => $legacy_id, 'state' => 'pending', 'next_at' => time() ] );
	$wpdb->insert( $old_table, [ 'order_id' => $other->get_id(), 'state' => 'delivered', 'next_at' => 0, 'attempts' => 2, 'response_code' => 200 ] );
	\CodexaWP\Outbox\install();
	\CodexaWP\Outbox\install();
	$legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $old_table, $legacy_id ) );
	check( $legacy && null === $legacy->event_id && 'pending' === $legacy->state, 'Schema upgrade preserves an old pending record and its existing delivery identity' );
	\CodexaWP\Outbox\deliver( $legacy_id );
	check( 1 === ( $attempts[ 'wc-processing-' . $legacy_id ] ?? 0 ), 'A legacy one-argument job keeps its original delivery key' );
	$delivered = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d', $old_table, $other->get_id() ) );
	check( 'delivered' === $delivered->state && 2 === (int) $delivered->attempts, 'Schema upgrade retains the completed queue history' );
} finally {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) );
	$wpdb->prefix = $original_prefix;
}
