<?php

/**
 * Unit tests for the bookings-sms-reminder-channel register fragment.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md
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
 * Verifies the kind:config SMS reminder channel fragment is valid JSON,
 * declares the BookingSmsReminderChannel schema with its declarative
 * lifecycle / calculations / notifications / rbac, enforces the SMS
 * constraints (≤160-char template, NL/E.164 phone pattern), seeds the two
 * example channels, and merges additively onto the monolith (ADR-037).
 */
final class BookingSmsReminderChannelFragmentTest extends TestCase {
	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookings-sms-reminder-channel.json';

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
	private string $manifestPath = __DIR__ . '/../../../src/manifest.d/bookings-sms-reminder-channel.json';

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
	 * The fragment file is present and valid JSON with a schema block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('BookingSmsReminderChannel', $data['components']['schemas']);
	}//end testFragmentIsValidJson()

	/**
	 * The SMS channel schema declares the required REQ-SMS properties.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresRequiredProperties(): void {
		$schema = $this->fragment()['components']['schemas']['BookingSmsReminderChannel'];
		$props = $schema['properties'];

		foreach (['name', 'status', 'provider', 'providerConfig', 'messageTemplate', 'sendMinutesBefore', 'fallbackPhoneNumber', 'senderId', 'retryCount', 'retryIntervalSeconds'] as $field) {
			self::assertArrayHasKey($field, $props, "Schema must declare $field");
		}

		// Required fields per spec data model.
		self::assertContains('messageTemplate', $schema['required']);
		self::assertContains('providerConfig', $schema['required']);
		self::assertContains('sendMinutesBefore', $schema['required']);
	}//end testSchemaDeclaresRequiredProperties()

	/**
	 * REQ-SMS-003: message template is constrained to 160 characters.
	 *
	 * @return void
	 */
	public function testMessageTemplateHas160CharLimit(): void {
		$props = $this->fragment()['components']['schemas']['BookingSmsReminderChannel']['properties'];
		self::assertSame(160, $props['messageTemplate']['maxLength']);
		// REQ-SMS-011: sender ID capped at 11 chars (MessageBird/Twilio).
		self::assertSame(11, $props['senderId']['maxLength']);
	}//end testMessageTemplateHas160CharLimit()

	/**
	 * REQ-SMS-004: fallback phone number enforces the NL/E.164 pattern.
	 *
	 * @return void
	 */
	public function testFallbackPhonePatternAcceptsNlAndE164(): void {
		$props = $this->fragment()['components']['schemas']['BookingSmsReminderChannel']['properties'];
		$pattern = '/' . $props['fallbackPhoneNumber']['pattern'] . '/';

		self::assertSame(1, preg_match($pattern, '+31612345678'), 'E.164 NL number accepted');
		self::assertSame(1, preg_match($pattern, '0612345678'), 'NL domestic number accepted');
		self::assertSame(0, preg_match($pattern, '12345'), 'Too-short number rejected');
		self::assertSame(0, preg_match($pattern, '+44123456789'), 'Non-NL number rejected');
	}//end testFallbackPhonePatternAcceptsNlAndE164()

	/**
	 * REQ-SMS-006/007: the schema declares an active → inactive → archived
	 * lifecycle with no transition out of archived.
	 *
	 * @return void
	 */
	public function testLifecycleIsActiveInactiveArchived(): void {
		$schema = $this->fragment()['components']['schemas']['BookingSmsReminderChannel'];
		$lifecycle = $schema['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('active', $lifecycle['initialState']);
		self::assertSame(['active', 'inactive', 'archived'], array_keys($lifecycle['states']));

		// No transition may leave the archived state (REQ-SMS-007).
		foreach ($lifecycle['transitions'] as $name => $t) {
			self::assertNotSame('archived', $t['from'], "Transition $name must not start from archived");
		}
	}//end testLifecycleIsActiveInactiveArchived()

	/**
	 * REQ-SMS-009/010: rendering is declarative via x-openregister-calculations
	 * and dispatch is declarative via x-openregister-notifications (no PHP
	 * service class). RBAC maps the booking:sms-channel permission slugs.
	 *
	 * @return void
	 */
	public function testDeclarativeCalculationsNotificationsAndRbac(): void {
		$schema = $this->fragment()['components']['schemas']['BookingSmsReminderChannel'];

		self::assertArrayHasKey('x-openregister-calculations', $schema);
		self::assertArrayHasKey('renderedPreview', $schema['x-openregister-calculations']);
		self::assertArrayHasKey('renderedPreviewLength', $schema['x-openregister-calculations']);

		self::assertArrayHasKey('x-openregister-notifications', $schema);
		$notif = $schema['x-openregister-notifications']['onBookingReminderDue'];
		self::assertContains('sms', $notif['channels']);
		self::assertSame('booking.reminder-due', $notif['trigger']);

		$permMap = $schema['x-openregister-rbac']['permissionMap'];
		foreach (['booking:sms-channel:list', 'booking:sms-channel:create', 'booking:sms-channel:edit', 'booking:sms-channel:activate', 'booking:sms-channel:delete'] as $slug) {
			self::assertArrayHasKey($slug, $permMap, "RBAC must map $slug");
		}
	}//end testDeclarativeCalculationsNotificationsAndRbac()

	/**
	 * REQ-SMS-021: the schema declares a respectOptOut flag (default true) and
	 * the notification block skips opted-out recipients, so phone numbers
	 * (personal data) are not messaged against the recipient's wishes.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresOptOutRespect(): void {
		$schema = $this->fragment()['components']['schemas']['BookingSmsReminderChannel'];

		self::assertArrayHasKey('respectOptOut', $schema['properties']);
		self::assertTrue($schema['properties']['respectOptOut']['default'], 'respectOptOut must default to true');

		$notif = $schema['x-openregister-notifications']['onBookingReminderDue'];
		self::assertArrayHasKey('skipWhen', $notif, 'Notification must declare an opt-out skip rule');
		self::assertSame('smsOptOut', $notif['skipWhen']['recipientField']);
	}//end testSchemaDeclaresOptOutRespect()

	/**
	 * REQ-SMS-013: provider credentials live in the openconnector connector,
	 * never inline; only a connectorId reference is stored, and the block is
	 * flagged sensitive so it is masked / never returned.
	 *
	 * @return void
	 */
	public function testProviderCredentialsAreNotStoredInline(): void {
		$props = $this->fragment()['components']['schemas']['BookingSmsReminderChannel']['properties'];
		$providerConfig = $props['providerConfig'];

		self::assertArrayHasKey('connectorId', $providerConfig['properties']);
		self::assertContains('connectorId', $providerConfig['required']);
		self::assertTrue(($providerConfig['x-openregister-sensitive'] ?? false), 'providerConfig must be flagged sensitive');

		// No raw secret-bearing property names anywhere on the schema.
		$json = strtolower((string)json_encode($props));
		self::assertStringNotContainsString('apikey', $json, 'No inline apiKey property');
		self::assertStringNotContainsString('"secret"', $json, 'No inline secret property');
	}//end testProviderCredentialsAreNotStoredInline()

	/**
	 * Task 11: two example channels are seeded — MessageBird active and
	 * Twilio inactive — each within the 160-char template limit.
	 *
	 * @return void
	 */
	public function testSeedsTwoExampleChannels(): void {
		$objects = $this->fragment()['objects'];
		self::assertCount(2, $objects);

		$bySlug = [];
		foreach ($objects as $o) {
			self::assertSame('BookingSmsReminderChannel', $o['@self']['schema']);
			self::assertSame('shillinq', $o['@self']['register']);
			$bySlug[$o['@self']['slug']] = $o;
			self::assertLessThanOrEqual(160, mb_strlen($o['messageTemplate']), 'Seed template must fit one SMS');
		}

		self::assertSame('active', $bySlug['sms-messagebird-nl']['status']);
		self::assertSame('messagebird', $bySlug['sms-messagebird-nl']['provider']);
		self::assertSame('inactive', $bySlug['sms-twilio-nl']['status']);
		self::assertSame('twilio', $bySlug['sms-twilio-nl']['provider']);
	}//end testSeedsTwoExampleChannels()

	/**
	 * Merging the fragment onto the monolith adds the schema and concatenates
	 * the seed channels onto objects[] without dropping anything (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);
		$objectCountBefore = count($base['objects']);

		$merged = $this->merge($base, $frag);

		self::assertArrayHasKey('BookingSmsReminderChannel', $merged['components']['schemas']);
		self::assertSame($schemaCountBefore + 1, count($merged['components']['schemas']), 'Exactly one schema added');
		self::assertSame($objectCountBefore + 2, count($merged['objects']), 'Exactly two seed objects added');

		// Pre-existing monolith schemas survive the merge.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $merged['components']['schemas'], "Monolith schema $name must survive merge");
		}
	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * The frontend manifest exposes the SMS channel index + detail pages and a
	 * navigation entry (REQ-SMS-015), all bound to the new schema.
	 *
	 * @return void
	 */
	public function testManifestExposesSmsChannelPagesAndMenu(): void {
		$manifest = json_decode((string)file_get_contents($this->manifestPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		$pages = [];
		foreach ($manifest['pages'] as $page) {
			$pages[$page['id']] = $page;
		}

		self::assertArrayHasKey('SmsReminderChannels', $pages);
		self::assertArrayHasKey('SmsReminderChannelDetail', $pages);
		self::assertSame('BookingSmsReminderChannel', $pages['SmsReminderChannels']['config']['schema']);
		self::assertSame('index', $pages['SmsReminderChannels']['type']);
		self::assertSame('detail', $pages['SmsReminderChannelDetail']['type']);

		// A menu entry routes to the index page.
		$found = false;
		$walk = function (array $items) use (&$walk, &$found): void {
			foreach ($items as $item) {
				if (($item['route'] ?? null) === 'SmsReminderChannels') {
					$found = true;
				}

				if (isset($item['children']) === true) {
					$walk($item['children']);
				}
			}
		};
		$walk($manifest['menu']);
		self::assertTrue($found, 'Menu must route to SmsReminderChannels');
	}//end testManifestExposesSmsChannelPagesAndMenu()
}//end class
