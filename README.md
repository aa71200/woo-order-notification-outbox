# WooCommerce Order Notification Outbox

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-order-notification-outbox/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-order-notification-outbox/actions/workflows/checks.yml)

If an endpoint is temporarily down, the order-processing event needs a durable delivery record and a retry. This CodexaWP sample sends it in the background with Action Scheduler and a stable idempotency key. Failed deliveries retry after 30 seconds and then 120 seconds, and the outbox records their result.

## Try it

Enable WooCommerce and set `CODEXAWP_OUTBOX_RECEIVER` to the HTTPS URL of a test receiver. Move a synthetic order to Processing. Action Scheduler runs delivery; the periodic sweep also recovers a due record when the immediate scheduled action is missed. The payload contains only an event ID, order ID and status. A real receiver should deduplicate using the `Idempotency-Key` header.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The fixture intercepts WordPress HTTP requests to model a temporary failure, a permanent failure and a lost response after the receiver accepted the event.

In the fixture, a `503` schedules a delayed retry and the retry delivers the event. A permanent `400` records terminal failure. After a lost response, the same event is sent again and the receiver keeps a single record. [Recorded integration output](docs/verification.txt)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
