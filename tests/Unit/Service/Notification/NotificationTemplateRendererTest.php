<?php

/**
 * Unit tests for NotificationTemplateRenderer.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use OCA\Shillinq\Service\Notification\NotificationTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {{ booking.* }} substitution, nested paths, date filter,
 * and the REQ-BNT-002 missing-variable-as-empty rule.
 */
final class NotificationTemplateRendererTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var NotificationTemplateRenderer
     */
    private NotificationTemplateRenderer $renderer;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->renderer = new NotificationTemplateRenderer();

    }//end setUp()


    /**
     * Substitutes a single top-level variable.
     *
     * @return void
     */
    public function testRendersSingleVariable(): void
    {
        $out = $this->renderer->render(
            template: 'Hallo {{ booking.guestName }}!',
            variables: ['booking' => ['guestName' => 'Alice']]
        );

        self::assertSame('Hallo Alice!', $out);
    }//end testRendersSingleVariable()


    /**
     * Substitutes the recipient + system namespaces.
     *
     * @return void
     */
    public function testRendersRecipientAndSystemNamespaces(): void
    {
        $out = $this->renderer->render(
            template: '{{ system.appName }} → {{ recipient.email }}',
            variables: [
                'system'    => ['appName' => 'Bookings'],
                'recipient' => ['email' => 'alice@example.com'],
            ]
        );

        self::assertSame('Bookings → alice@example.com', $out);
    }//end testRendersRecipientAndSystemNamespaces()


    /**
     * Missing variables render as empty string per REQ-BNT-002.
     *
     * @return void
     */
    public function testMissingVariablesRenderEmpty(): void
    {
        $out = $this->renderer->render(
            template: 'Locatie: {{ booking.location }}',
            variables: ['booking' => ['guestName' => 'Alice']]
        );

        self::assertSame('Locatie: ', $out);
    }//end testMissingVariablesRenderEmpty()


    /**
     * date('format') filter applies the format string to the parsed value.
     *
     * @return void
     */
    public function testDateFilterFormatsDate(): void
    {
        $out = $this->renderer->render(
            template: 'Boeking op {{ booking.startTime | date(\'d-m-Y H:i\') }}',
            variables: ['booking' => ['startTime' => '2026-06-15T10:30:00Z']]
        );

        self::assertStringContainsString('15-06-2026', $out);
    }//end testDateFilterFormatsDate()


    /**
     * upper / lower filters work and unknown filter falls through to value.
     *
     * @return void
     */
    public function testCaseFiltersAndUnknownFallthrough(): void
    {
        $out = $this->renderer->render(
            template: '{{ booking.organizer | upper }} · {{ booking.organizer | lower }} · {{ booking.organizer | mystery }}',
            variables: ['booking' => ['organizer' => 'Jan Peeters']]
        );

        self::assertSame('JAN PEETERS · jan peeters · Jan Peeters', $out);
    }//end testCaseFiltersAndUnknownFallthrough()


    /**
     * Non-recursive: rendered output cannot reintroduce a variable
     * reference (defence against injection via user-controlled fields).
     *
     * @return void
     */
    public function testRenderIsNonRecursive(): void
    {
        $out = $this->renderer->render(
            template: 'Hi {{ booking.guestName }}!',
            variables: ['booking' => ['guestName' => '{{ booking.organizer }}', 'organizer' => 'Eve']]
        );

        // The {{ booking.organizer }} string must appear literally in
        // the output — not be substituted in a second pass.
        self::assertSame('Hi {{ booking.organizer }}!', $out);
    }//end testRenderIsNonRecursive()


    /**
     * Boolean values stringify as 'true' / 'false', not 1 / 0.
     *
     * @return void
     */
    public function testBooleansStringifyAsWords(): void
    {
        $out = $this->renderer->render(
            template: 'Active={{ booking.active }}',
            variables: ['booking' => ['active' => true]]
        );

        self::assertSame('Active=true', $out);
    }//end testBooleansStringifyAsWords()


}//end class
