# WooCommerce Order Notification Outbox

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-order-notification-outbox/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-order-notification-outbox/actions/workflows/checks.yml)

If an endpoint is temporarily down, the order-processing event needs a durable delivery record and a retry. This CodexaWP sample stores a UUID for each new event and schedules delivery with Action Scheduler. Retry deadlines are 30 and 120 seconds. A periodic sweep recovers overdue rows whose action is missing. Records from before the schema upgrade keep their original idempotency key.

## Try it

Enable WooCommerce and set `CODEXAWP_OUTBOX_RECEIVER` to the HTTPS URL of a test receiver. Move a synthetic order to Processing. Action Scheduler runs delivery; the periodic sweep also recovers a due record when the immediate scheduled action is missed. The payload contains only an event ID, order ID and status. A real receiver should deduplicate using the `Idempotency-Key` header.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The fixture runs due Action Scheduler jobs against an intercepted test receiver. It checks temporary and permanent failures, a lost response after acceptance and recovery by the sweep after a scheduled action is removed. It waits about 31 seconds for the first retry to become due.

## Queue data during an upgrade

The schema change adds a nullable event ID. Existing rows and their delivery keys are retained. A direct downgrade to the original 1.0.0 delivery code is unsafe once UUID-backed events exist because that code derives a different key. For a rollback that preserves delivery identity, pause this plugin's deliveries, retain its outbox table and use a build that can still read both forms of event ID before resuming. The upgrade fixture covers pending legacy rows and completed history.

In the fixture, a `503` schedules a delayed retry and the retry delivers the event. A permanent `400` records terminal failure. After a lost response, the same event is sent again and the receiver keeps a single record. [Recorded integration output](docs/verification.txt)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
