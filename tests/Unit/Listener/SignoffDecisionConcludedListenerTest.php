<?php

/**
 * Unit tests for SignoffDecisionConcludedListener.
 *
 * Verifies the listener contract (shillinq-delegation-via-events
 * REQ-SIGN-005/006):
 *
 *  - getSourceApp() !== 'shillinq' is ignored.
 *  - approved status projects decisionOutcome=approved + fires the local GL
 *    consequence (signoffGateOpen) + persists the mirror.
 *  - rejected status projects decisionOutcome=rejected without opening the gate.
 *  - withdrawn / pending / unknown status is ignored (no projection).
 *  - a lookup failure is swallowed (fail-soft, never rethrown into decidesk).
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
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

// In-test stub of the decidesk DecisionConcludedEvent contract so the listener
// can class_exists()-guard, instanceof-check, and read its getters without the
// decidesk app installed. The real class lives in decidesk.
if (class_exists(\OCA\Decidesk\Event\DecisionConcludedEvent::class, false) === false) {
    class DecisionConcludedEvent extends \OCP\EventDispatcher\Event
    {
        public function __construct(
            private readonly string $sourceApp='',
            private readonly string $status='pending',
            private readonly string $decisionId='',
            private readonly ?string $subjectSchema=null,
            private readonly ?string $subjectId=null,
            private readonly string $externalReference='',
            private readonly string $correlationId='',
        ) {
            parent::__construct();
        }//end __construct()

        public function getSourceApp(): string
        {
            return $this->sourceApp;
        }//end getSourceApp()

        public function getStatus(): string
        {
            return $this->status;
        }//end getStatus()

        public function getDecisionId(): string
        {
            return $this->decisionId;
        }//end getDecisionId()

        public function getSubjectSchema(): ?string
        {
            return $this->subjectSchema;
        }//end getSubjectSchema()

        public function getSubjectId(): ?string
        {
            return $this->subjectId;
        }//end getSubjectId()

        public function getExternalReference(): string
        {
            return $this->externalReference;
        }//end getExternalReference()

        public function getCorrelationId(): string
        {
            return $this->correlationId;
        }//end getCorrelationId()
    }//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\Shillinq\Listener\SignoffDecisionConcludedListener;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCA\Shillinq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for SignoffDecisionConcludedListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SignoffDecisionConcludedListenerTest extends TestCase
{

    /**
     * Recording fake ObjectService: captures find() lookups and updateObject()
     * writes, and returns a configured object for the matching id.
     *
     * @var object
     */
    private object $objectService;

    /**
     * Build the SUT wired to the recording ObjectService.
     *
     * @param array<string,mixed>|null $found The object find() returns, or null.
     *
     * @return SignoffDecisionConcludedListener
     */
    private function makeListener(?array $found): SignoffDecisionConcludedListener
    {
        $this->objectService = new class($found) {

            /**
             * @var array<string,mixed>|null
             */
            private ?array $found;

            /**
             * @var array<int,array<string,mixed>>
             */
            public array $updates = [];

            /**
             * @param array<string,mixed>|null $found
             */
            public function __construct(?array $found)
            {
                $this->found = $found;
            }//end __construct()

            public function setRegister(string $r): self
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $s): self
            {
                return $this;
            }//end setSchema()

            /**
             * @return array<string,mixed>|null
             */
            public function find(string $id): ?array
            {
                return $this->found;
            }//end find()

            /**
             * @param array<string,mixed> $updates
             */
            public function updateObject(string $id, array $updates): void
            {
                $this->updates[] = ['id' => $id] + $updates;
            }//end updateObject()
        };

        $svc = $this->objectService;

        $container = new class($svc) implements ContainerInterface {

            private object $svc;

            public function __construct(object $svc)
            {
                $this->svc = $svc;
            }//end __construct()

            public function get(string $id): mixed
            {
                return $this->svc;
            }//end get()

            public function has(string $id): bool
            {
                return true;
            }//end has()
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getRegisterSlug')->willReturn('shillinq');

        return new SignoffDecisionConcludedListener(
            $container,
            $settings,
            new SignoffDecisionService($settings, $this->createMock(IEventDispatcher::class), new NullLogger()),
            new NullLogger(),
        );

    }//end makeListener()

    /**
     * A non-shillinq source app is ignored (no persist).
     *
     * @return void
     */
    public function testForeignSourceAppIsIgnored(): void
    {
        $listener = $this->makeListener(['id' => 'acm-1', 'decisionOutcome' => 'pending']);

        $listener->handle(
                new DecisionConcludedEvent(
            sourceApp: 'decidesk',
            status: 'approved',
            decisionId: 'dec-1',
            subjectSchema: 'ACMReport',
            subjectId: 'acm-1',
            externalReference: 'acm-1',
        )
                );

        $this->assertCount(0, $this->objectService->updates);

    }//end testForeignSourceAppIsIgnored()

    /**
     * An approved decision projects approved + fires the GL consequence gate.
     *
     * @return void
     */
    public function testApprovedProjectsOutcomeAndOpensGate(): void
    {
        $listener = $this->makeListener(['id' => 'acm-1', 'decisionOutcome' => 'pending']);

        $listener->handle(
                new DecisionConcludedEvent(
            sourceApp: 'shillinq',
            status: 'approved',
            decisionId: 'dec-1',
            subjectSchema: 'ACMReport',
            subjectId: 'acm-1',
            externalReference: 'acm-1',
        )
                );

        $this->assertCount(1, $this->objectService->updates);
        $this->assertSame('approved', $this->objectService->updates[0]['decisionOutcome']);
        $this->assertSame('dec-1', $this->objectService->updates[0]['decisionRef']);
        $this->assertTrue($this->objectService->updates[0]['signoffGateOpen']);

    }//end testApprovedProjectsOutcomeAndOpensGate()

    /**
     * A rejected decision projects rejected without opening the gate.
     *
     * @return void
     */
    public function testRejectedProjectsOutcomeWithoutGate(): void
    {
        $listener = $this->makeListener(['id' => 'av-1', 'decisionOutcome' => 'pending']);

        $listener->handle(
                new DecisionConcludedEvent(
            sourceApp: 'shillinq',
            status: 'rejected',
            decisionId: 'dec-2',
            subjectSchema: 'ActuarialValuation',
            subjectId: 'av-1',
            externalReference: 'av-1',
        )
                );

        $this->assertCount(1, $this->objectService->updates);
        $this->assertSame('rejected', $this->objectService->updates[0]['decisionOutcome']);
        $this->assertArrayNotHasKey('signoffGateOpen', $this->objectService->updates[0]);

    }//end testRejectedProjectsOutcomeWithoutGate()

    /**
     * A non-terminal status (withdrawn) is ignored.
     *
     * @return void
     */
    public function testWithdrawnStatusIsIgnored(): void
    {
        $listener = $this->makeListener(['id' => 'ar-1', 'decisionOutcome' => 'pending']);

        $listener->handle(
                new DecisionConcludedEvent(
            sourceApp: 'shillinq',
            status: 'withdrawn',
            decisionId: 'dec-3',
            subjectSchema: 'AnnualReport',
            subjectId: 'ar-1',
            externalReference: 'ar-1',
        )
                );

        $this->assertCount(0, $this->objectService->updates);

    }//end testWithdrawnStatusIsIgnored()

    /**
     * A missing finance object is skipped without error (fail-soft).
     *
     * @return void
     */
    public function testMissingObjectIsSkippedFailSoft(): void
    {
        $listener = $this->makeListener(null);

        $listener->handle(
                new DecisionConcludedEvent(
            sourceApp: 'shillinq',
            status: 'approved',
            decisionId: 'dec-4',
            subjectSchema: 'ACMReport',
            subjectId: 'missing',
            externalReference: 'missing',
        )
                );

        $this->assertCount(0, $this->objectService->updates);

    }//end testMissingObjectIsSkippedFailSoft()
}//end class
