<?php

/**
 * Unit tests for SignoffDecisionService.
 *
 * Covers: requestSignoff idempotency, registry call, field-writing;
 * onDecisionCallback field-mapping, idempotency, consequence firing,
 * and unknown-outcome rejection.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use InvalidArgumentException;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SignoffDecisionService (REQ-SIGN-002/005/006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SignoffDecisionServiceTest extends TestCase
{

    /**
     * Settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settings;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     */
    private SignoffDecisionService $svc;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = $this->createMock(SettingsService::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->svc      = new SignoffDecisionService(
            settingsService: $this->settings,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Helper: build a fake registry that returns a predictable decisionRef.
     *
     * @param string $returnRef The ref the registry will return.
     *
     * @return object
     */
    private function fakeRegistry(string $returnRef): object
    {
        return new class($returnRef) {
            /** @var string */
            private string $ref;

            public function __construct(string $ref)
            {
                $this->ref = $ref;

            }//end __construct()

            /**
             * @param array<string,mixed> $params
             */
            public function call(string $service, string $method, array $params): string
            {
                return $this->ref;

            }//end call()
        };

    }//end fakeRegistry()


    /**
     * requestSignoff raises a Decision and writes decisionOutcome=pending.
     */
    public function testRequestSignoffWritesOutcomePending(): void
    {
        $registry = $this->fakeRegistry('dec-001');

        $result = $this->svc->requestSignoff(
            financeObject: ['id' => 'av-1', 'administrationId' => 'adm-1'],
            registry: $registry,
        );

        self::assertSame('dec-001', $result['decisionRef']);
        self::assertSame('pending', $result['decisionOutcome']);

    }//end testRequestSignoffWritesOutcomePending()


    /**
     * requestSignoff is idempotent when decisionOutcome==approved.
     */
    public function testRequestSignoffIsIdempotentWhenApproved(): void
    {
        $registry = $this->fakeRegistry('dec-002-new');

        $obj = ['id' => 'av-2', 'decisionOutcome' => 'approved', 'decisionRef' => 'dec-002'];

        $result = $this->svc->requestSignoff(
            financeObject: $obj,
            registry: $registry,
        );

        self::assertSame('dec-002', $result['decisionRef']);
        self::assertSame('approved', $result['decisionOutcome']);

    }//end testRequestSignoffIsIdempotentWhenApproved()


    /**
     * requestSignoff propagates registry exceptions.
     */
    public function testRequestSignoffPropagatesRegistryException(): void
    {
        $badRegistry = new class {
            /**
             * @param array<string,mixed> $params
             */
            public function call(string $service, string $method, array $params): never
            {
                throw new \RuntimeException('decidesk registry offline');

            }//end call()
        };

        $this->expectException(\RuntimeException::class);

        $this->svc->requestSignoff(
            financeObject: ['id' => 'av-3'],
            registry: $badRegistry,
        );

    }//end testRequestSignoffPropagatesRegistryException()


    /**
     * onDecisionCallback with outcome=approved writes fields and fires the consequence.
     */
    public function testOnDecisionCallbackApprovedWritesFieldsAndFiresConsequence(): void
    {
        $fired     = false;
        $firedWith = null;

        $result = $this->svc->onDecisionCallback(
            financeObject: ['id' => 'av-4', 'decisionOutcome' => 'pending'],
            outcome: 'approved',
            decisionRef: 'dec-004',
            consequenceCallback: function (array $obj) use (&$fired, &$firedWith): void {
                $fired     = true;
                $firedWith = $obj;
            },
        );

        self::assertSame('approved', $result['decisionOutcome']);
        self::assertSame('dec-004', $result['decisionRef']);
        self::assertTrue($fired);
        self::assertNotNull($firedWith);

    }//end testOnDecisionCallbackApprovedWritesFieldsAndFiresConsequence()


    /**
     * onDecisionCallback with outcome=rejected does NOT fire the consequence.
     */
    public function testOnDecisionCallbackRejectedDoesNotFireConsequence(): void
    {
        $fired = false;

        $result = $this->svc->onDecisionCallback(
            financeObject: ['id' => 'av-5', 'decisionOutcome' => 'pending'],
            outcome: 'rejected',
            decisionRef: 'dec-005',
            consequenceCallback: function (array $obj) use (&$fired): void {
                $fired = true;
            },
        );

        self::assertSame('rejected', $result['decisionOutcome']);
        self::assertFalse($fired);

    }//end testOnDecisionCallbackRejectedDoesNotFireConsequence()


    /**
     * onDecisionCallback is idempotent when already in the same terminal state.
     */
    public function testOnDecisionCallbackIsIdempotent(): void
    {
        $fired = false;

        $result = $this->svc->onDecisionCallback(
            financeObject: ['id' => 'av-6', 'decisionOutcome' => 'approved', 'decisionRef' => 'dec-006'],
            outcome: 'approved',
            decisionRef: 'dec-006',
            consequenceCallback: function (array $obj) use (&$fired): void {
                $fired = true;
            },
        );

        self::assertSame('approved', $result['decisionOutcome']);
        self::assertFalse($fired, 'Consequence must not fire on idempotent callback');

    }//end testOnDecisionCallbackIsIdempotent()


    /**
     * onDecisionCallback rejects unknown outcomes.
     */
    public function testOnDecisionCallbackRejectsUnknownOutcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown decision outcome/');

        $this->svc->onDecisionCallback(
            financeObject: ['id' => 'av-7'],
            outcome: 'maybe',
            decisionRef: 'dec-007',
        );

    }//end testOnDecisionCallbackRejectsUnknownOutcome()


    /**
     * onDecisionCallback with null callback does not error on 'approved'.
     */
    public function testOnDecisionCallbackNullCallbackApprovedNoError(): void
    {
        $result = $this->svc->onDecisionCallback(
            financeObject: ['id' => 'av-8', 'decisionOutcome' => 'pending'],
            outcome: 'approved',
            decisionRef: 'dec-008',
        );

        self::assertSame('approved', $result['decisionOutcome']);

    }//end testOnDecisionCallbackNullCallbackApprovedNoError()


}//end class
