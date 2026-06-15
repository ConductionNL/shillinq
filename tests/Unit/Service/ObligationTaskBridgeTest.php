<?php

/**
 * Unit tests for ObligationTaskBridge.
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
 * @spec openspec/changes/contract-lifecycle-management/specs/contract-lifecycle-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ObligationTaskBridge;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObligationTaskBridge fail-closed behaviour (REQ-CLM-003).
 *
 * Covers:
 * - No backend available → taskLinkStatus 'failed', no throw.
 * - Container throwing during resolution → 'failed', no throw.
 * - Malformed / empty input → 'failed', no throw.
 */
class ObligationTaskBridgeTest extends TestCase
{

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The bridge under test.
     *
     * @var ObligationTaskBridge
     */
    private ObligationTaskBridge $bridge;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->bridge = new ObligationTaskBridge(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * With no NC Tasks/Deck backend available, the bridge returns 'failed'
     * without throwing (REQ-CLM-003 fail-closed degrade).
     *
     * @return void
     */
    public function testNoBackendReturnsFailedWithoutThrowing(): void
    {
        $this->container->method('has')->willReturn(false);

        $result = $this->bridge->createTaskForObligation(
            [
                'title'       => 'Provide annual insurance certificate',
                'dueDate'     => '2026-09-01',
                'responsible' => 'bob',
            ]
        );

        self::assertSame('failed', $result['taskLinkStatus']);
        self::assertNull($result['taskUri']);
    }//end testNoBackendReturnsFailedWithoutThrowing()

    /**
     * A throwing container during resolution degrades fail-closed, not throws.
     *
     * @return void
     */
    public function testThrowingContainerDegradesFailClosed(): void
    {
        $this->container->method('has')
            ->willThrowException(new \RuntimeException('container exploded'));

        $result = $this->bridge->createTaskForObligation(
            [
                'title'   => 'Quarterly SLA review',
                'dueDate' => '2026-07-15',
            ]
        );

        self::assertSame('failed', $result['taskLinkStatus']);
        self::assertNull($result['taskUri']);
    }//end testThrowingContainerDegradesFailClosed()

    /**
     * Malformed / empty input does not throw and returns 'failed'.
     *
     * @return void
     */
    public function testMalformedInputDoesNotThrow(): void
    {
        $this->container->method('has')->willReturn(false);

        // Empty obligation.
        $empty = $this->bridge->createTaskForObligation([]);
        self::assertSame('failed', $empty['taskLinkStatus']);
        self::assertNull($empty['taskUri']);

        // Missing dueDate.
        $noDue = $this->bridge->createTaskForObligation(['title' => 'X']);
        self::assertSame('failed', $noDue['taskLinkStatus']);

        // Non-string field values must not fatal.
        $weird = $this->bridge->createTaskForObligation(
            [
                'title'       => 'Valid title',
                'dueDate'     => '2026-09-01',
                'responsible' => 'carol',
            ]
        );
        self::assertSame('failed', $weird['taskLinkStatus']);
    }//end testMalformedInputDoesNotThrow()

    /**
     * The result always has the documented shape (taskUri + taskLinkStatus keys).
     *
     * @return void
     */
    public function testResultShapeIsStable(): void
    {
        $this->container->method('has')->willReturn(false);

        $result = $this->bridge->createTaskForObligation(['title' => 'A', 'dueDate' => '2026-01-01']);

        self::assertArrayHasKey('taskUri', $result);
        self::assertArrayHasKey('taskLinkStatus', $result);
    }//end testResultShapeIsStable()
}//end class
