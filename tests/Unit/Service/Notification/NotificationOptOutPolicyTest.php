<?php

/**
 * Unit tests for NotificationOptOutPolicy.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use OCA\Shillinq\Service\Notification\NotificationOptOutPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the per-trigger and per-channel opt-out gates (REQ-BNT-009).
 */
final class NotificationOptOutPolicyTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var NotificationOptOutPolicy
     */
    private NotificationOptOutPolicy $policy;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->policy = new NotificationOptOutPolicy();

    }//end setUp()


    /**
     * No opt-out map → never opted out.
     *
     * @return void
     */
    public function testNoMapMeansNoOptOut(): void
    {
        self::assertFalse(
            $this->policy->isOptedOut(
                trigger: ['respectOptOut' => true, 'triggerType' => 'booking.created'],
                recipient: []
            )
        );
    }//end testNoMapMeansNoOptOut()


    /**
     * Trigger-type opt-out skips dispatch.
     *
     * @return void
     */
    public function testTriggerTypeOptOutFires(): void
    {
        self::assertTrue(
            $this->policy->isOptedOut(
                trigger: ['respectOptOut' => true, 'triggerType' => 'booking.reminder'],
                recipient: ['notificationOptOut' => ['booking.reminder' => true]]
            )
        );
    }//end testTriggerTypeOptOutFires()


    /**
     * Global "all" opt-out covers every trigger type.
     *
     * @return void
     */
    public function testAllOptOutFires(): void
    {
        self::assertTrue(
            $this->policy->isOptedOut(
                trigger: ['respectOptOut' => true, 'triggerType' => 'booking.created'],
                recipient: ['notificationOptOut' => ['all' => true]]
            )
        );
    }//end testAllOptOutFires()


    /**
     * Triggers with respectOptOut=false ignore the opt-out (transactional
     * channels — explicit operator opt-in).
     *
     * @return void
     */
    public function testRespectOptOutFalseBypasses(): void
    {
        self::assertFalse(
            $this->policy->isOptedOut(
                trigger: ['respectOptOut' => false, 'triggerType' => 'booking.created'],
                recipient: ['notificationOptOut' => ['all' => true]]
            )
        );
    }//end testRespectOptOutFalseBypasses()


    /**
     * Channel opt-out blocks the named channel but not others.
     *
     * @return void
     */
    public function testCanUseChannelHonoursChannelOptOut(): void
    {
        $recipient = ['notificationOptOut' => ['channels' => ['sms']]];

        self::assertFalse($this->policy->canUseChannel(recipient: $recipient, channel: 'sms'));
        self::assertTrue($this->policy->canUseChannel(recipient: $recipient, channel: 'email'));
    }//end testCanUseChannelHonoursChannelOptOut()


}//end class
