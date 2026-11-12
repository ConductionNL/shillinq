<?php

/**
 * Characterisation tests for SignoffDecisionService.
 *
 * Pins the decision-request event payload (provenance, label fallbacks, the
 * governance text), the refusal when decidesk answers without a decision id,
 * the log lines, and the callback's consequence and Activity emission.
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
 * @spec openspec/changes/shillinq-delegation-via-events/tasks.md
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
use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation tests for SignoffDecisionService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SignoffDecisionPayloadTest extends TestCase {

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
	 * Activity emitter mock.
	 *
	 * @var ApprovalActivityEmitter&MockObject
	 */
	private ApprovalActivityEmitter&MockObject $emitter;

	/**
	 * Service under test.
	 */
	private SignoffDecisionService $svc;

	/**
	 * The event the dispatcher received.
	 */
	private ?object $dispatched = null;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq-register');
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->emitter    = $this->createMock(ApprovalActivityEmitter::class);
		$this->svc        = new SignoffDecisionService(
			registerLocation: $settings,
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
			activityEmitter: $this->emitter,
		);
	}

	/**
	 * Make the dispatcher record the event and answer with the given id.
	 *
	 * @param string|null $decisionId Id to mark the event handled with; null leaves it unhandled.
	 */
	private function answerWith(?string $decisionId): void {
		$this->dispatcher->expects(self::once())->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($decisionId): void {
				$this->dispatched = $event;
				if ($decisionId !== null) {
					$event->setHandled(true);
					$event->setDecisionId($decisionId);
				}
			}
		);
	}

	/**
	 * The event carries provenance, label, decision type and the governance payload.
	 */
	public function testEventCarriesProvenanceAndGovernancePayload(): void {
		$this->answerWith('dec-1');
		$this->logger->expects(self::once())->method('info')->with(
			'SignoffDecisionService: Decision raised via decidesk event',
			['decisionRef' => 'dec-1', 'decisionType' => 'adoption']
		);

		$result = $this->svc->requestSignoff(['id' => 'ar-1', 'name' => 'Jaarverslag'], 'AnnualReport', 'adoption');

		self::assertSame(['id' => 'ar-1', 'name' => 'Jaarverslag', 'decisionRef' => 'dec-1', 'decisionOutcome' => 'pending'], $result);
		$event = $this->dispatched;
		self::assertSame(
			['shillinq', 'shillinq-register', 'AnnualReport', 'ar-1', 'Jaarverslag', 'adoption', '', 'ar-1', ''],
			[$event->sourceApp, $event->subjectRegister, $event->subjectSchema, $event->subjectId, $event->subjectLabel, $event->decisionType, $event->actorId, $event->externalReference, $event->correlationId]
		);
		self::assertSame(['title', 'text', 'decisionDate'], array_keys($event->payload));
		self::assertSame('Jaarverslag', $event->payload['title']);
		self::assertSame('Governance sign-off requested by shillinq for AnnualReport ar-1.', $event->payload['text']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $event->payload['decisionDate']);
	}

	/**
	 * The id falls back to _id, the label to title then the id; the type defaults to sign-off.
	 */
	public function testIdAndLabelFallbacks(): void {
		$this->answerWith('dec-2');
		$this->svc->requestSignoff(['_id' => 'x-2', 'title' => 'Titel'], 'ACMReport');
		self::assertSame(['x-2', 'Titel', 'sign-off'], [$this->dispatched->subjectId, $this->dispatched->subjectLabel, $this->dispatched->decisionType]);
	}

	/**
	 * Without name or title the label is the id.
	 */
	public function testLabelFallsBackToId(): void {
		$this->answerWith('dec-3');
		$this->svc->requestSignoff(['id' => 'z-3'], 'ACMReport');
		self::assertSame('z-3', $this->dispatched->subjectLabel);
	}

	/**
	 * Handled with an empty id is refused, with the warning context and no info log.
	 */
	public function testHandledWithEmptyIdIsRefused(): void {
		$this->answerWith('');
		$this->logger->expects(self::once())->method('warning')->with(
			'SignoffDecisionService: decidesk did not handle the decision request (fail closed)',
			['subjectSchema' => 'ACMReport', 'subjectId' => 'acm-9']
		);
		$this->logger->expects(self::never())->method('info');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('decidesk did not handle the sign-off decision request.');
		$this->svc->requestSignoff(['id' => 'acm-9'], 'ACMReport');
	}

	/**
	 * An unhandled event is refused as well.
	 */
	public function testUnhandledEventIsRefused(): void {
		$this->answerWith(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('decidesk did not handle the sign-off decision request.');
		$this->svc->requestSignoff(['id' => 'acm-8'], 'ACMReport');
	}

	/**
	 * Approved: fields written, consequence before the decision_made emit, callback log.
	 */
	public function testApprovedCallbackFiresConsequenceThenEmits(): void {
		$order = [];
		$this->emitter->expects(self::once())->method('emitDecisionMade')
			->with('AnnualReport', 'ar-2', '', 'approved', 'AnnualReport ar-2')
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'emit';
			});
		$this->logger->expects(self::once())->method('info')->with(
			'SignoffDecisionService: callback consumed',
			['outcome' => 'approved', 'decisionRef' => 'dec-4']
		);

		$result = $this->svc->onDecisionCallback(
			['id' => 'ar-2', 'decisionOutcome' => 'pending'],
			'approved',
			'dec-4',
			function (array $object) use (&$order): void {
				$order[] = 'consequence:' . $object['decisionOutcome'] . ':' . $object['decisionRef'];
			},
			'AnnualReport'
		);

		self::assertSame(['consequence:approved:dec-4', 'emit'], $order);
		self::assertSame(['id' => 'ar-2', 'decisionOutcome' => 'approved', 'decisionRef' => 'dec-4'], $result);
	}

	/**
	 * Rejected also emits decision_made, with the rejected status, and fires no consequence.
	 */
	public function testRejectedCallbackEmitsWithoutConsequence(): void {
		$this->emitter->expects(self::once())->method('emitDecisionMade')->with('ACMReport', 'r-1', '', 'rejected', 'ACMReport r-1');
		$fired = false;

		$this->svc->onDecisionCallback(['id' => 'r-1'], 'rejected', 'dec-5', function () use (&$fired): void {
			$fired = true;
		}, 'ACMReport');

		self::assertFalse($fired);
	}

	/**
	 * No schema, no emit; the idempotent no-op logs and emits nothing; unknown outcomes are refused first.
	 */
	public function testNoSchemaIdempotentAndUnknownOutcome(): void {
		$this->emitter->expects(self::never())->method('emitDecisionMade');
		$this->svc->onDecisionCallback(['id' => 'n-1'], 'approved', 'dec-6');

		$object = ['id' => 'n-2', 'decisionOutcome' => 'rejected'];
		self::assertSame($object, $this->svc->onDecisionCallback($object, 'rejected', 'dec-7', null, 'ACMReport'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown decision outcome: withdrawn');
		$this->svc->onDecisionCallback($object, 'withdrawn', 'dec-8');
	}
}
