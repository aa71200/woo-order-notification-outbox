# WooCommerce Order Notification Outbox

A CodexaWP portfolio demo for sending an order's processing event in the background. Each order has one durable delivery record and a stable receiver idempotency key. A temporary delivery failure is retried up to twice, with 30-second and 120-second delays. Successful and failed deliveries are visible in the outbox table.

## Try it

Enable WooCommerce and set `CODEXAWP_OUTBOX_RECEIVER` to the HTTPS URL of a test receiver. Move a synthetic order to Processing. Action Scheduler runs delivery; the periodic sweep also recovers a due record when the immediate scheduled action is missed. The payload contains only an event ID, order ID and status. A real receiver should deduplicate using the `Idempotency-Key` header.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The fixture intercepts WordPress HTTP requests to model a temporary failure, a permanent failure and a lost response after the receiver accepted the event.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
