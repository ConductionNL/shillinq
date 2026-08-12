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
 * @spec openspec/specs/bookings-notification-triggers/spec.md
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
final class BookingNotificationTriggersFragmentTest extends TestCase
{

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/bookings-notification-triggers.json';

    /**
     * Absolute path to the monolith register file.
     *
     * @var string
     */
    private string $registerPath = __DIR__.'/../../../lib/Settings/shillinq_register.json';

    /**
     * Absolute path to the frontend manifest fragment (ADR-037).
     *
     * @var string
     */
    private string $manifestPath = __DIR__.'/../../../src/manifest.d/bookings-notification-triggers.json';


    /**
     * Invoke the private static SettingsService::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()


    /**
     * Decode the fragment file to an array.
     *
     * @return array<string,mixed>
     */
    private function fragment(): array
    {
        $data = json_decode((string) file_get_contents($this->fragmentPath), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($data);
        return $data;
    }//end fragment()


    /**
     * The fragment file is present and valid JSON with both schema blocks.
     *
     * @return void
     */
    public function testFragmentIsValidJsonWithBothSchemas(): void
    {
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
    public function testTriggerTypeEnumLists4Events(): void
    {
        $data    = $this->fragment();
        $trigger = $data['components']['schemas']['BookingNotificationTrigger'];
        $enum    = $trigger['properties']['triggerType']['enum'];

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
    public function testTriggerLifecycleDeclaresEnabledDisabledArchived(): void
    {
        $data      = $this->fragment();
        $lifecycle = $data['components']['schemas']['BookingNotificationTrigger']['x-openregister-lifecycle'];

        self::assertSame('enabled', $lifecycle['initialState']);
        self::assertArrayHasKey('enabled', $lifecycle['states']);
        self::assertArrayHasKey('disabled', $lifecycle['states']);
        self::assertArrayHasKey('archived', $lifecycle['states']);
        self::assertArrayHasKey('disable', $lifecycle['transitions']);
        self::assertArrayHasKey('enable', $lifecycle['transitions']);
    }//end testTriggerLifecycleDeclaresEnabledDisabledArchived()


    /**
     * Trigger fragment defines the x-openregister-notifications binding the
     * engine consumes (selectTemplate.byType maps each trigger type onto
     * the bookings-email-templates schemas).
     *
     * The trigger type and channel list are read off the stored trigger
     * object itself, expressed with the canonical `{{prop}}` interpolation
     * token — the legacy `@self.` form is the one gate-18 (ADR-031)
     * rejects inside a notification block.
     *
     * @return void
     */
    public function testTriggerNotificationBindsToEmailTemplates(): void
    {
        $data = $this->fragment();
        $bind = $data['components']['schemas']['BookingNotificationTrigger']['x-openregister-notifications']['onTriggerEventFired'];

        self::assertSame('{{triggerType}}', $bind['trigger']);
        self::assertSame('{{channels}}', $bind['channels']);
        self::assertSame('BookingConfirmationTemplate', $bind['selectTemplate']['byType']['booking.created']);
        self::assertSame('BookingCancellationTemplate', $bind['selectTemplate']['byType']['booking.cancelled']);
        self::assertSame('BookingReminderTemplate', $bind['selectTemplate']['byType']['booking.reminder']);
    }//end testTriggerNotificationBindsToEmailTemplates()


    /**
     * No rule in the trigger fragment may carry the retired `@self.`
     * interpolation token (ADR-031 / gate-18). Written as a whole-block
     * scan so a future rule cannot reintroduce the legacy dialect in a
     * key this test does not name individually.
     *
     * @return void
     */
    public function testNoNotificationRuleUsesTheLegacySelfToken(): void
    {
        $data          = $this->fragment();
        $notifications = ($data['components']['schemas']['BookingNotificationTrigger']['x-openregister-notifications'] ?? []);

        self::assertNotEmpty($notifications);
        self::assertStringNotContainsString(
            '@self.',
            (string) json_encode($notifications),
            'x-openregister-notifications must use {{prop}}, not the legacy @self. token.'
        );
    }//end testNoNotificationRuleUsesTheLegacySelfToken()


    /**
     * NotificationDelivery is immutable + retention-bounded per ADR-022.
     *
     * @return void
     */
    public function testDeliveryIsImmutableAuditRecord(): void
    {
        $data  = $this->fragment();
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
    public function testDeliveryStatusEnumCoversAllOutcomes(): void
    {
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
    public function testSeedObjectsCoverAllEventTypes(): void
    {
        $data    = $this->fragment();
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
    public function testFragmentMergesAdditively(): void
    {
        $monolith = json_decode((string) file_get_contents($this->registerPath), true);
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
    public function testManifestRegistersAdminPagesAndModalLauncher(): void
    {
        self::assertFileExists($this->manifestPath);
        $manifest = json_decode((string) file_get_contents($this->manifestPath), true);
        self::assertIsArray($manifest);

        $pageIds = [];
        foreach ((array) ($manifest['pages'] ?? []) as $page) {
            $pageIds[] = (string) ($page['id'] ?? '');
        }

        self::assertContains('NotificationTriggers', $pageIds);
        self::assertContains('NotificationTriggerDetail', $pageIds);
        self::assertContains('NotificationMonitor', $pageIds);
        self::assertContains('NotificationDeliveryDetail', $pageIds);

        // bookingDetailActions registers the modal launcher.
        $actions = (array) ($manifest['bookingDetailActions'] ?? []);
        self::assertNotEmpty($actions);
        self::assertSame('BookingNotificationConfigModal', (string) $actions[0]['modal']);
    }//end testManifestRegistersAdminPagesAndModalLauncher()


}//end class
