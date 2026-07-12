<?php

/**
 * Unit tests for CommitmentMaterialisationListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\CommitmentMaterialisationListener;
use OCA\Shillinq\Service\Commitment\CommitmentMaterialisationService;
use OCA\Shillinq\Service\Commitment\InsufficientCommitmentBudgetException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CommitmentMaterialisationListener per REQ-VPL-010 (Tasks 1+2).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CommitmentMaterialisationListenerTest extends TestCase
{

    /**
     * Mock materialisation service.
     *
     * @var CommitmentMaterialisationService&MockObject
     */
    private CommitmentMaterialisationService&MockObject $materialiser;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The listener under test.
     *
     * @var CommitmentMaterialisationListener
     */
    private CommitmentMaterialisationListener $listener;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->materialiser = $this->createMock(CommitmentMaterialisationService::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->listener     = new CommitmentMaterialisationListener(materialiser: $this->materialiser, logger: $this->logger);

    }//end setUp()

    /**
     * Build an ObjectEntity stub for the given schema + payload.
     *
     * @param string               $schema  Schema slug.
     * @param array<string, mixed> $payload Object payload.
     *
     * @return ObjectEntity
     */
    private function entity(string $schema, array $payload): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getSchema')->willReturn($schema);
        $entity->method('getObject')->willReturn($payload);
        return $entity;

    }//end entity()

    /**
     * A PurchaseOrder reaching `approved` forwards to
     * materialiseFromPurchaseOrder() and lets a budget-denial exception
     * propagate (fail-closed).
     *
     * @return void
     */
    public function testPurchaseOrderApprovedForwardsAndPropagatesDenial(): void
    {
        $payload = ['poNumber' => 'PO-2026-0207', 'statusCode' => 'approved'];
        $entity  = $this->entity('PurchaseOrder', $payload);
        $event   = $this->createConfiguredMock(
            ObjectTransitionedEvent::class,
            ['getObject' => $entity, 'getTo' => 'approved']
        );

        $this->materialiser->expects(self::once())
            ->method('materialiseFromPurchaseOrder')
            ->with($payload)
            ->willThrowException(new InsufficientCommitmentBudgetException('denied'));

        $this->expectException(InsufficientCommitmentBudgetException::class);
        $this->listener->handle($event);

    }//end testPurchaseOrderApprovedForwardsAndPropagatesDenial()

    /**
     * A PurchaseOrder transitioning to a state other than `approved` is ignored.
     *
     * @return void
     */
    public function testPurchaseOrderOtherTransitionIgnored(): void
    {
        $payload = ['poNumber' => 'PO-2026-0207', 'statusCode' => 'sent'];
        $entity  = $this->entity('PurchaseOrder', $payload);
        $event   = $this->createConfiguredMock(
            ObjectTransitionedEvent::class,
            ['getObject' => $entity, 'getTo' => 'sent']
        );

        $this->materialiser->expects(self::never())->method('materialiseFromPurchaseOrder');

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testPurchaseOrderOtherTransitionIgnored()

    /**
     * A Contract reaching `active` forwards to materialiseFromContract() and
     * a thrown exception is caught (fail-soft), never propagating.
     *
     * @return void
     */
    public function testContractActiveForwardsAndSwallowsException(): void
    {
        $payload = ['contractNumber' => 'C-2026-007', 'status' => 'active'];
        $entity  = $this->entity('Contract', $payload);
        $event   = $this->createConfiguredMock(
            ObjectTransitionedEvent::class,
            ['getObject' => $entity, 'getTo' => 'active']
        );

        $this->materialiser->expects(self::once())
            ->method('materialiseFromContract')
            ->with($payload)
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects(self::once())->method('warning');

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testContractActiveForwardsAndSwallowsException()

    /**
     * An ObjectCreatedEvent for a PurchaseOrder created directly in
     * `approved` state also forwards (design.md D4-style creation path).
     *
     * @return void
     */
    public function testPurchaseOrderCreatedDirectlyApprovedForwards(): void
    {
        $payload = ['poNumber' => 'PO-2026-0300', 'statusCode' => 'approved'];
        $entity  = $this->entity('PurchaseOrder', $payload);
        $event   = $this->createConfiguredMock(ObjectCreatedEvent::class, ['getObject' => $entity]);

        $this->materialiser->expects(self::once())
            ->method('materialiseFromPurchaseOrder')
            ->with($payload)
            ->willReturn(null);

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testPurchaseOrderCreatedDirectlyApprovedForwards()

    /**
     * An unrelated schema/event is ignored without touching the materialiser.
     *
     * @return void
     */
    public function testUnrelatedSchemaIgnored(): void
    {
        $entity = $this->entity('SupplierInvoice', ['statusCode' => 'approved']);
        $event  = $this->createConfiguredMock(
            ObjectTransitionedEvent::class,
            ['getObject' => $entity, 'getTo' => 'approved']
        );

        $this->materialiser->expects(self::never())->method('materialiseFromPurchaseOrder');
        $this->materialiser->expects(self::never())->method('materialiseFromContract');

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testUnrelatedSchemaIgnored()

    /**
     * A PurchaseOrderLine schema (which ends with the substring
     * "purchaseorderline") must never be mistaken for a PurchaseOrder.
     *
     * @return void
     */
    public function testPurchaseOrderLineNotMistakenForPurchaseOrder(): void
    {
        $entity = $this->entity('PurchaseOrderLine', ['statusCode' => 'approved']);
        $event  = $this->createConfiguredMock(
            ObjectTransitionedEvent::class,
            ['getObject' => $entity, 'getTo' => 'approved']
        );

        $this->materialiser->expects(self::never())->method('materialiseFromPurchaseOrder');

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testPurchaseOrderLineNotMistakenForPurchaseOrder()

    /**
     * A generic OCP event unrelated to OR object lifecycle is a no-op.
     *
     * @return void
     */
    public function testGenericEventIgnored(): void
    {
        $event = $this->createMock(Event::class);

        $this->materialiser->expects(self::never())->method('materialiseFromPurchaseOrder');
        $this->materialiser->expects(self::never())->method('materialiseFromContract');

        $this->listener->handle($event);
        self::assertTrue(true);

    }//end testGenericEventIgnored()
}//end class
