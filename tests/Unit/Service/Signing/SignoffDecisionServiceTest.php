<?php

/**
 * Unit tests for SignoffDecisionService.
 *
 * Covers: requestSignoff idempotency + decidesk event dispatch + fail-closed
 * paths + field-writing; onDecisionCallback field-mapping, idempotency,
 * consequence firing, and unknown-outcome rejection.
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
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Decidesk\Event;

// Minimal in-test stub of the decidesk DecisionRequestedEvent contract so that
// SignoffDecisionService::requestSignoff can class_exists()-guard, dispatch,
// and read back isHandled()/getDecisionId() without the decidesk app present.
// The real class lives in decidesk; shillinq only consumes this shape.
if (class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class, false) === false) {
	class DecisionRequestedEvent extends \OCP\EventDispatcher\Event {

		private bool $handled = false;

		private ?string $decisionId = null;

		/**
		 * @param array<string,mixed> $payload
		 */
		public function __construct(
			public readonly string $sourceApp = '',
			public readonly string $subjectRegister = '',
			public readonly string $subjectSchema = '',
			public readonly string $subjectId = '',
			public readonly string $subjectLabel = '',
			public readonly string $decisionType = 'contract',
			public readonly string $actorId = '',
			public readonly array $payload = [],
			public readonly string $externalReference = '',
			public readonly string $correlationId = '',
		) {
			parent::__construct();
		}//end __construct()

		public function setHandled(bool $handled): void {
			$this->handled = $handled;
		}//end setHandled()

		public function isHandled(): bool {
			return $this->handled;
		}//end isHandled()

		public function setDecisionId(?string $decisionId): void {
			$this->decisionId = $decisionId;
		}//end setDecisionId()

		public function getDecisionId(): ?string {
			return $this->decisionId;
		}//end getDecisionId()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use InvalidArgumentException;
use OCA\Decidesk\Event\DecisionRequestedEvent;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SignoffDecisionService (REQ-SIGN-002/005/006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SignoffDecisionServiceTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Event dispatcher mock.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

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
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->svc = new SignoffDecisionService(
			settingsService: $this->settings,
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Make the dispatcher simulate decidesk handling the request by stamping
	 * isHandled()=true + a decisionId on the dispatched event.
	 *
	 * @param string $decisionId The id decidesk "returns".
	 *
	 * @return void
	 */
	private function expectHandledDispatch(string $decisionId): void {
		$this->dispatcher->expects(self::once())
			->method('dispatchTyped')
			->willReturnCallback(
				function (Event $event) use ($decisionId): void {
					self::assertInstanceOf(DecisionRequestedEvent::class, $event);
					self::assertSame('shillinq', $event->sourceApp);
					$event->setHandled(true);
					$event->setDecisionId($decisionId);
				}
			);

	}//end expectHandledDispatch()

	/**
	 * requestSignoff dispatches the decidesk event and writes
	 * decisionOutcome=pending with the returned decision id.
	 */
	public function testRequestSignoffWritesOutcomePending(): void {
		$this->expectHandledDispatch('dec-001');

		$result = $this->svc->requestSignoff(
			financeObject: ['id' => 'av-1', 'administrationId' => 'adm-1'],
			subjectSchema: 'ActuarialValuation',
		);

		self::assertSame('dec-001', $result['decisionRef']);
		self::assertSame('pending', $result['decisionOutcome']);

	}//end testRequestSignoffWritesOutcomePending()

	/**
	 * requestSignoff is idempotent when decisionOutcome==approved (no dispatch).
	 */
	public function testRequestSignoffIsIdempotentWhenApproved(): void {
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		$obj = ['id' => 'av-2', 'decisionOutcome' => 'approved', 'decisionRef' => 'dec-002'];

		$result = $this->svc->requestSignoff(
			financeObject: $obj,
			subjectSchema: 'ActuarialValuation',
		);

		self::assertSame('dec-002', $result['decisionRef']);
		self::assertSame('approved', $result['decisionOutcome']);

	}//end testRequestSignoffIsIdempotentWhenApproved()

	/**
	 * requestSignoff fails closed when decidesk did not handle the event
	 * (isHandled()=false / no decision id) — never auto-approves.
	 */
	public function testRequestSignoffFailsClosedWhenNotHandled(): void {
		$this->dispatcher->expects(self::once())
			->method('dispatchTyped')
			->willReturnCallback(
				function (Event $event): void {
					// decidesk listener never ran: handled stays false, id null.
				}
			);

		$this->expectException(RuntimeException::class);

		$this->svc->requestSignoff(
			financeObject: ['id' => 'av-3'],
			subjectSchema: 'ACMReport',
		);

	}//end testRequestSignoffFailsClosedWhenNotHandled()

	/**
	 * onDecisionCallback with outcome=approved writes fields and fires the consequence.
	 */
	public function testOnDecisionCallbackApprovedWritesFieldsAndFiresConsequence(): void {
		$fired = false;
		$firedWith = null;

		$result = $this->svc->onDecisionCallback(
			financeObject: ['id' => 'av-4', 'decisionOutcome' => 'pending'],
			outcome: 'approved',
			decisionRef: 'dec-004',
			consequenceCallback: function (array $obj) use (&$fired, &$firedWith): void {
				$fired = true;
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
	public function testOnDecisionCallbackRejectedDoesNotFireConsequence(): void {
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
	public function testOnDecisionCallbackIsIdempotent(): void {
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
	public function testOnDecisionCallbackRejectsUnknownOutcome(): void {
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
	public function testOnDecisionCallbackNullCallbackApprovedNoError(): void {
		$result = $this->svc->onDecisionCallback(
			financeObject: ['id' => 'av-8', 'decisionOutcome' => 'pending'],
			outcome: 'approved',
			decisionRef: 'dec-008',
		);

		self::assertSame('approved', $result['decisionOutcome']);

	}//end testOnDecisionCallbackNullCallbackApprovedNoError()
}//end class
