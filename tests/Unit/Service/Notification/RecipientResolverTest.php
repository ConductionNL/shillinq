<?php

/**
 * Unit tests for RecipientResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use OCA\Shillinq\Service\Notification\RecipientConditionEvaluator;
use OCA\Shillinq\Service\Notification\RecipientResolver;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the rule-list walk: conditional skip, role resolution, per-rule
 * channel override, missing-address fallthrough.
 */
final class RecipientResolverTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var RecipientResolver
     */
    private RecipientResolver $resolver;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->resolver = new RecipientResolver(evaluator: new RecipientConditionEvaluator());

    }//end setUp()


    /**
     * Resolves customer + organizer roles to their booking-payload addresses.
     *
     * @return void
     */
    public function testResolvesCustomerAndOrganizer(): void
    {
        $trigger = [
            'channels'   => ['email'],
            'recipients' => [
                ['role' => 'customer', 'channels' => ['email']],
                ['role' => 'organizer', 'channels' => ['email']],
            ],
        ];
        $booking = [
            'customerEmail'  => 'alice@example.com',
            'customerName'   => 'Alice de Vries',
            'organizerEmail' => 'jan@example.com',
            'organizer'      => 'Jan Peeters',
        ];

        $out = $this->resolver->resolve(trigger: $trigger, booking: $booking);

        self::assertCount(2, $out);
        self::assertSame('customer', $out[0]['role']);
        self::assertSame('alice@example.com', $out[0]['address']);
        self::assertSame('Alice de Vries', $out[0]['name']);
        self::assertNull($out[0]['skipReason']);
        self::assertSame('organizer', $out[1]['role']);
        self::assertSame('jan@example.com', $out[1]['address']);
    }//end testResolvesCustomerAndOrganizer()


    /**
     * Rules with a false condition are kept in the output but flagged
     * as `condition-false` so the audit trail records the skip.
     *
     * @return void
     */
    public function testConditionalSkipFlagsRule(): void
    {
        $trigger = [
            'channels'   => ['email'],
            'recipients' => [
                ['role' => 'admin_group', 'condition' => 'booking.price > 100'],
            ],
        ];
        $booking = ['price' => 50];

        $out = $this->resolver->resolve(trigger: $trigger, booking: $booking);

        self::assertCount(1, $out);
        self::assertSame('condition-false', $out[0]['skipReason']);
    }//end testConditionalSkipFlagsRule()


    /**
     * Missing customer email produces a no-recipient-address skip flag.
     *
     * @return void
     */
    public function testMissingAddressFlagsSkip(): void
    {
        $trigger = [
            'channels'   => ['email'],
            'recipients' => [
                ['role' => 'customer', 'channels' => ['email']],
            ],
        ];

        $out = $this->resolver->resolve(trigger: $trigger, booking: []);

        self::assertCount(1, $out);
        self::assertSame('no-recipient-address', $out[0]['skipReason']);
        self::assertNull($out[0]['address']);
    }//end testMissingAddressFlagsSkip()


    /**
     * admin_group role expands to a single logical recipient (engine
     * fans out at runtime).
     *
     * @return void
     */
    public function testAdminGroupRoleExpands(): void
    {
        $trigger = [
            'channels'   => ['email'],
            'recipients' => [
                ['role' => 'admin_group', 'channels' => ['email']],
            ],
        ];

        $out = $this->resolver->resolve(trigger: $trigger, booking: ['price' => 1000]);

        self::assertCount(1, $out);
        self::assertSame('admin_group', $out[0]['role']);
        self::assertSame('group:admin', $out[0]['address']);
        self::assertSame('Administrators', $out[0]['name']);
    }//end testAdminGroupRoleExpands()


    /**
     * Per-rule channels override the trigger-level channels list.
     *
     * @return void
     */
    public function testPerRuleChannelsOverrideDefault(): void
    {
        $trigger = [
            'channels'   => ['email'],
            'recipients' => [
                ['role' => 'customer', 'channels' => ['sms', 'email']],
            ],
        ];
        $booking = ['customerEmail' => 'alice@example.com'];

        $out = $this->resolver->resolve(trigger: $trigger, booking: $booking);

        self::assertSame(['sms', 'email'], $out[0]['channels']);
    }//end testPerRuleChannelsOverrideDefault()


}//end class
