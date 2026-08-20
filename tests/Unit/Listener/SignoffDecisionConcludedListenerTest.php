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
	class DecisionConcludedEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			private readonly string $sourceApp = '',
			private readonly string $status = 'pending',
			private readonly string $decisionId = '',
			private readonly ?string $subjectSchema = null,
			private readonly ?string $subjectId = null,
			private readonly string $externalReference = '',
			private readonly string $correlationId = '',
		) {
			parent::__construct();
		}//end __construct()

		public function getSourceApp(): string {
			return $this->sourceApp;
		}//end getSourceApp()

		public function getStatus(): string {
			return $this->status;
		}//end getStatus()

		public function getDecisionId(): string {
			return $this->decisionId;
		}//end getDecisionId()

		public function getSubjectSchema(): ?string {
			return $this->subjectSchema;
		}//end getSubjectSchema()

		public function getSubjectId(): ?string {
			return $this->subjectId;
		}//end getSubjectId()

		public function getExternalReference(): string {
			return $this->externalReference;
		}//end getExternalReference()

		public function getCorrelationId(): string {
			return $this->correlationId;
		}//end getCorrelationId()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Listener\SignoffDecisionConcludedListener;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SignoffDecisionConcludedListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SignoffDecisionConcludedListenerTest extends TestCase {

	/**
	 * Every updateObject() write the listener issued, as ['id' => …] + payload.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $updates = [];

	/**
	 * Build the SUT wired to the recording ObjectService.
	 *
	 * @param array<string,mixed>|null $found The object find() returns, or null.
	 *
	 * @return SignoffDecisionConcludedListener
	 */
	private function makeListener(?array $found): SignoffDecisionConcludedListener {
		$this->updates = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('find')->willReturnCallback(
			static function () use ($found): ?ObjectEntityInterface {
				if ($found === null) {
					return null;
				}

				return (new ObjectEntity())->setObject($found);
			}
		);
		$objectService->method('updateObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->updates[] = ['id' => $objectId] + $data;
				return new ObjectEntity();
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SignoffDecisionConcludedListener(
			$settings,
			new SignoffDecisionService($settings, $this->createMock(IEventDispatcher::class), new NullLogger()),
			new NullLogger(),
			$objectService,
		);

	}//end makeListener()

	/**
	 * A non-shillinq source app is ignored (no persist).
	 *
	 * @return void
	 */
	public function testForeignSourceAppIsIgnored(): void {
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

		$this->assertCount(0, $this->updates);

	}//end testForeignSourceAppIsIgnored()

	/**
	 * An approved decision projects approved + fires the GL consequence gate.
	 *
	 * @return void
	 */
	public function testApprovedProjectsOutcomeAndOpensGate(): void {
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

		$this->assertCount(1, $this->updates);
		$this->assertSame('approved', $this->updates[0]['decisionOutcome']);
		$this->assertSame('dec-1', $this->updates[0]['decisionRef']);
		$this->assertTrue($this->updates[0]['signoffGateOpen']);

	}//end testApprovedProjectsOutcomeAndOpensGate()

	/**
	 * A rejected decision projects rejected without opening the gate.
	 *
	 * @return void
	 */
	public function testRejectedProjectsOutcomeWithoutGate(): void {
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

		$this->assertCount(1, $this->updates);
		$this->assertSame('rejected', $this->updates[0]['decisionOutcome']);
		$this->assertArrayNotHasKey('signoffGateOpen', $this->updates[0]);

	}//end testRejectedProjectsOutcomeWithoutGate()

	/**
	 * A non-terminal status (withdrawn) is ignored.
	 *
	 * @return void
	 */
	public function testWithdrawnStatusIsIgnored(): void {
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

		$this->assertCount(0, $this->updates);

	}//end testWithdrawnStatusIsIgnored()

	/**
	 * A missing finance object is skipped without error (fail-soft).
	 *
	 * @return void
	 */
	public function testMissingObjectIsSkippedFailSoft(): void {
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

		$this->assertCount(0, $this->updates);

	}//end testMissingObjectIsSkippedFailSoft()
}//end class
