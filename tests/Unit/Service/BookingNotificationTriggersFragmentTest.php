<?php

/**
 * Unit tests for the bookings-notification-triggers register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the kind:config booking notification trigger fragment is valid
 * JSON, declares the BookingNotificationTrigger + NotificationDelivery
 * schemas with their declarative lifecycle / calculations / notifications
 * / rbac, seeds the default triggers, and merges additively onto the
 * monolith (ADR-037).
 */
final class BookingNotificationTriggersFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookings-notification-triggers.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Absolute path to the frontend manifest fragment (ADR-037).
	 *
	 * @var string
	 */
	private string $manifestPath = __DIR__ . '/../../../src/manifest.d/bookings-notification-triggers.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Decode the fragment file to an array.
	 *
	 * @return array<string,mixed>
	 */
	private function fragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with both schema blocks.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJsonWithBothSchemas(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('BookingNotificationTrigger', $data['components']['schemas']);
		self::assertArrayHasKey('NotificationDelivery', $data['components']['schemas']);
	}//end testFragmentIsValidJsonWithBothSchemas()

	/**
	 * Trigger schema declares the canonical event-type enum (REQ-BNT-001).
	 *
	 * @return void
	 */
	public function testTriggerTypeEnumLists4Events(): void {
		$data = $this->fragment();
		$trigger = $data['components']['schemas']['BookingNotificationTrigger'];
		$enum = $trigger['properties']['triggerType']['enum'];

		self::assertSame(
			['booking.created', 'booking.changed', 'booking.cancelled', 'booking.reminder'],
			$enum
		);
	}//end testTriggerTypeEnumLists4Events()

	/**
	 * Trigger schema declares an enabled → disabled → archived lifecycle
	 * (REQ-BNT-007/008).
	 *
	 * @return void
	 */
	public function testTriggerLifecycleDeclaresEnabledDisabledArchived(): void {
		$data = $this->fragment();
		$lifecycle = $data['components']['schemas']['BookingNotificationTrigger']['x-openregister-lifecycle'];

		self::assertSame('enabled', $lifecycle['initialState']);
		self::assertArrayHasKey('enabled', $lifecycle['states']);
		self::assertArrayHasKey('disabled', $lifecycle['states']);
		self::assertArrayHasKey('archived', $lifecycle['states']);
		self::assertArrayHasKey('disable', $lifecycle['transitions']);
		self::assertArrayHasKey('enable', $lifecycle['transitions']);
	}//end testTriggerLifecycleDeclaresEnabledDisabledArchived()

	/**
	 * The trigger fragment carries the fields a dispatching service needs, and
	 * declares no notification rule the engine cannot execute.
	 *
	 * This test used to assert `trigger === '{{triggerType}}'` and
	 * `channels === '{{channels}}'` on a rule named `onTriggerEventFired`,
	 * describing them as "the canonical {{prop}} interpolation token". That was
	 * a misreading of the dialect, and the test pinned it in place: `{{prop}}`
	 * interpolation applies to subject and message TEXT, never to the trigger
	 * itself — a trigger is compared literally, so `{{triggerType}}` could only
	 * ever match an event named `{{triggerType}}`. The rule also used five keys
	 * the dialect has no concept of. It was a design sketch of what a
	 * user-configured trigger OBJECT contains, sitting in the slot reserved for
	 * rules the engine dispatches FOR this schema, and it never fired.
	 *
	 * The sketch is preserved verbatim in the schema `_note`. What is asserted
	 * here is what must actually hold: the object carries the fields that design
	 * needs, and the schema declares no unexecutable rule.
	 *
	 * @return void
	 */
	public function testTriggerCarriesTheFieldsADispatcherNeedsAndNoUnexecutableRule(): void {
		$schema = $this->fragment()['components']['schemas']['BookingNotificationTrigger'];

		foreach (['triggerType', 'channels', 'recipients', 'appliesToBookingSlug', 'reminderHoursBeforeStart', 'templateOverrideSlug'] as $property) {
			self::assertArrayHasKey(
				$property,
				$schema['properties'],
				$property . ' is read by the service that dispatches these triggers'
			);
		}

		self::assertArrayNotHasKey(
			'x-openregister-notifications',
			$schema,
			'This schema describes user-configured triggers; a rule here would be dispatched FOR the schema, '
			. 'which is not what the sketch meant. See the schema _note.'
		);

		self::assertStringContainsString(
			'BookingConfirmationTemplate',
			(string) ($schema['_note'] ?? ''),
			'the template mapping the sketch defined must survive its removal'
		);
	}//end testTriggerCarriesTheFieldsADispatcherNeedsAndNoUnexecutableRule()

	/**
	 * No NOTIFICATION block in this fragment may carry the retired `@self.`
	 * interpolation token (ADR-031 / gate-18).
	 *
	 * Scoped to notification blocks on purpose. `@self.` is still the correct
	 * token in `x-openregister-calculations` — this fragment uses it in
	 * `count(@self.channels)` and three siblings — so a fragment-wide scan
	 * reports legitimate usage as a violation. The previous version was scoped
	 * correctly but asserted the block was non-empty first, which turned the
	 * removal of a rule into a failure rather than a pass.
	 *
	 * Both traps are avoided by anchoring on the schemas (always present) and
	 * scanning whatever notification blocks exist, however many that is.
	 *
	 * @return void
	 */
	public function testNoNotificationRuleUsesTheLegacySelfToken(): void {
		$schemas = ($this->fragment()['components']['schemas'] ?? []);

		self::assertNotEmpty($schemas, 'the fragment must declare schemas — an empty scan is not a pass');

		foreach ($schemas as $name => $schema) {
			$notifications = ($schema['x-openregister-notifications'] ?? null);
			if (is_array($notifications) === false) {
				continue;
			}

			self::assertStringNotContainsString(
				'@self.',
				(string) json_encode($notifications),
				$name . ': x-openregister-notifications must use {{prop}}, not the legacy @self. token.'
			);
		}
	}//end testNoNotificationRuleUsesTheLegacySelfToken()

	/**
	 * NotificationDelivery is immutable + retention-bounded per ADR-022.
	 *
	 * @return void
	 */
	public function testDeliveryIsImmutableAuditRecord(): void {
		$data = $this->fragment();
		$audit = $data['components']['schemas']['NotificationDelivery']['x-openregister-audit'];

		self::assertTrue($audit['immutable']);
		self::assertTrue($audit['tamperEvident']);
		self::assertSame(365, $audit['retentionDays']);
		self::assertContains('triggerName', $audit['writeOnce']);
		self::assertContains('recipient', $audit['writeOnce']);
		self::assertContains('sentAt', $audit['writeOnce']);
	}//end testDeliveryIsImmutableAuditRecord()

	/**
	 * NotificationDelivery status enum lists every audit outcome the
	 * orchestration emits.
	 *
	 * @return void
	 */
	public function testDeliveryStatusEnumCoversAllOutcomes(): void {
		$data = $this->fragment();
		$enum = $data['components']['schemas']['NotificationDelivery']['properties']['status']['enum'];

		self::assertSame(['sent', 'failed', 'skipped', 'queued'], $enum);
	}//end testDeliveryStatusEnumCoversAllOutcomes()

	/**
	 * Seeded objects include five default triggers covering all four
	 * event types plus a second reminder offset (24h + 1h).
	 *
	 * @return void
	 */
	public function testSeedObjectsCoverAllEventTypes(): void {
		$data = $this->fragment();
		$objects = $data['objects'];

		$types = [];
		foreach ($objects as $o) {
			$types[] = $o['triggerType'];
		}

		self::assertContains('booking.created', $types);
		self::assertContains('booking.changed', $types);
		self::assertContains('booking.cancelled', $types);
		self::assertContains('booking.reminder', $types);
	}//end testSeedObjectsCoverAllEventTypes()

	/**
	 * Fragment merges additively onto the monolith — neither schema
	 * already lives there (no clobber risk).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditively(): void {
		$monolith = json_decode((string)file_get_contents($this->registerPath), true);
		self::assertIsArray($monolith);
		self::assertArrayNotHasKey('BookingNotificationTrigger', ($monolith['components']['schemas'] ?? []));
		self::assertArrayNotHasKey('NotificationDelivery', ($monolith['components']['schemas'] ?? []));

		$merged = $this->merge(base: $monolith, overlay: $this->fragment());

		self::assertArrayHasKey('BookingNotificationTrigger', $merged['components']['schemas']);
		self::assertArrayHasKey('NotificationDelivery', $merged['components']['schemas']);
	}//end testFragmentMergesAdditively()

	/**
	 * Manifest fragment registers a NotificationTriggers + NotificationMonitor
	 * page pair and a per-booking modal action launcher.
	 *
	 * @return void
	 */
	public function testManifestRegistersAdminPagesAndModalLauncher(): void {
		self::assertFileExists($this->manifestPath);
		$manifest = json_decode((string)file_get_contents($this->manifestPath), true);
		self::assertIsArray($manifest);

		$pageIds = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			$pageIds[] = (string)($page['id'] ?? '');
		}

		self::assertContains('NotificationTriggers', $pageIds);
		self::assertContains('NotificationTriggerDetail', $pageIds);
		self::assertContains('NotificationMonitor', $pageIds);
		self::assertContains('NotificationDeliveryDetail', $pageIds);

		// bookingDetailActions registers the modal launcher.
		$actions = (array)($manifest['bookingDetailActions'] ?? []);
		self::assertNotEmpty($actions);
		self::assertSame('BookingNotificationConfigModal', (string)$actions[0]['modal']);
	}//end testManifestRegistersAdminPagesAndModalLauncher()

}//end class
