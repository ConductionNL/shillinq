<?php

/**
 * Unit tests for AnnualReportSignoffRequestListener.
 *
 * Regression coverage for the shillinq-delegation-via-events orphaned-
 * capability defect: SignoffDecisionService::requestSignoff() was correctly
 * rewired onto the decidesk IEventDispatcher contract (DecisionRequestedEvent
 * / DecisionConcludedEvent), but nothing in production ever called it. This
 * suite proves the AnnualReport `opgemaakt` transition (shared by both
 * `vaststellen` and `vaststellenZonderReview`, the AV-adoption paths) now
 * actually reaches requestSignoff() (and only that state/schema), that the
 * request is idempotent per adoption cycle, and that the request-side mirror
 * (decisionRef / decisionOutcome) gets persisted back onto the AnnualReport
 * via OR.
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\AnnualReportSignoffRequestListener;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\GenericEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for AnnualReportSignoffRequestListener (REQ-SIGN-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AnnualReportSignoffRequestListenerTest extends TestCase {

	/**
	 * Every updateObject() write the listener issued, as ['id' => …] + payload.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $updates = [];

	/**
	 * Captured requestSignoff() invocations: each entry is
	 * [financeObject, subjectSchema, decisionType].
	 *
	 * @var array<int,array{0:array<string,mixed>,1:string,2:string}>
	 */
	private array $signoffCalls = [];

	/**
	 * Build the SUT wired to a recording ObjectService and a
	 * SignoffDecisionService mock whose requestSignoff() records every call
	 * into $this->signoffCalls.
	 *
	 * @param array<string,mixed>|null $requestResult The value requestSignoff()
	 *                                                returns, or null to make it throw
	 *                                                (simulating decidesk absent —
	 *                                                fail-closed).
	 *
	 * @return AnnualReportSignoffRequestListener
	 */
	private function makeListener(?array $requestResult): AnnualReportSignoffRequestListener {
		$this->signoffCalls = [];
		$this->updates = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('updateObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->updates[] = ['id' => $objectId] + $data;
				return new ObjectEntity();
			}
		);

		$signoffService = $this->createMock(SignoffDecisionService::class);
		$signoffService->method('requestSignoff')->willReturnCallback(
			function (
				array $financeObject,
				string $subjectSchema = 'document',
				string $decisionType = 'sign-off',
			) use ($requestResult): array {
				$this->signoffCalls[] = [$financeObject, $subjectSchema, $decisionType];
				if ($requestResult === null) {
					throw new RuntimeException(
						'decidesk is not installed; sign-off decision cannot be raised.'
					);
				}

				return $requestResult;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new AnnualReportSignoffRequestListener(
			$settings,
			$signoffService,
			new NullLogger(),
			$objectService,
		);

	}//end makeListener()

	/**
	 * Build an ObjectTransitionedEvent carrying the given payload/to/schema.
	 *
	 * @param array<string,mixed> $payload The object payload.
	 * @param string $to Target lifecycle state.
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 * @param string $from Source lifecycle state.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(
		array $payload,
		string $to = 'opgemaakt',
		string $schema = 'AnnualReport',
		string $id = 'ar-42',
		string $from = 'draft',
	): ObjectTransitionedEvent {
		$entity = (new ObjectEntity())
			->setSchema($schema)
			->setId($id)
			->setObject($payload);

		return new ObjectTransitionedEvent(
			$entity,
			'opmaken',
			$from,
			$to,
			'bestuurder1',
			'shillinq',
			$schema,
		);

	}//end makeEvent()

	/**
	 * The `opgemaakt` transition on AnnualReport calls requestSignoff() and
	 * persists the returned decisionRef + decisionOutcome.
	 *
	 * @return void
	 */
	public function testOpgemaaktTransitionCallsRequestSignoffAndPersists(): void {
		$listener = $this->makeListener(
			['id' => 'ar-42', 'decisionOutcome' => 'pending', 'decisionRef' => 'dec-42']
		);

		$listener->handle($this->makeEvent(['id' => 'ar-42', 'administrationId' => 'adm-1']));

		self::assertCount(1, $this->signoffCalls, 'requestSignoff() must be called exactly once.');
		[$financeObject, $subjectSchema, $decisionType] = $this->signoffCalls[0];
		self::assertSame('ar-42', $financeObject['id']);
		self::assertSame('AnnualReport', $subjectSchema);
		self::assertSame('adoption', $decisionType);

		self::assertCount(1, $this->updates);
		self::assertSame('ar-42', $this->updates[0]['id']);
		self::assertSame('pending', $this->updates[0]['decisionOutcome']);
		self::assertSame('dec-42', $this->updates[0]['decisionRef']);

	}//end testOpgemaaktTransitionCallsRequestSignoffAndPersists()

	/**
	 * A transition target other than `opgemaakt` is ignored entirely.
	 *
	 * @return void
	 */
	public function testNonOpgemaaktTargetIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'ar-42', 'decisionOutcome' => 'pending']);

		$listener->handle($this->makeEvent(['id' => 'ar-42'], to: 'determined', from: 'in-review'));

		self::assertCount(0, $this->signoffCalls);
		self::assertCount(0, $this->updates);

	}//end testNonOpgemaaktTargetIsIgnored()

	/**
	 * An `opgemaakt` transition on a different schema is ignored.
	 *
	 * @return void
	 */
	public function testNonAnnualReportSchemaIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'other-1', 'decisionOutcome' => 'pending']);

		$listener->handle($this->makeEvent(['id' => 'other-1'], schema: 'ACMReport'));

		self::assertCount(0, $this->signoffCalls);
		self::assertCount(0, $this->updates);

	}//end testNonAnnualReportSchemaIsIgnored()

	/**
	 * An AnnualReport already carrying a non-empty decisionOutcome (pending /
	 * approved / rejected) does not raise a duplicate request — covers the
	 * `in-review` -> `reviewAnnuleren` -> `opgemaakt` re-entry cycle.
	 *
	 * @return void
	 */
	public function testExistingDecisionOutcomeSkipsDuplicateRequest(): void {
		$listener = $this->makeListener(['id' => 'ar-42', 'decisionOutcome' => 'pending']);

		$listener->handle(
			$this->makeEvent(['id' => 'ar-42', 'decisionOutcome' => 'pending'], from: 'in-review')
		);

		self::assertCount(0, $this->signoffCalls);
		self::assertCount(0, $this->updates);

	}//end testExistingDecisionOutcomeSkipsDuplicateRequest()

	/**
	 * When requestSignoff() fails closed (decidesk absent), the listener
	 * swallows the exception (fail-soft) and does not persist anything —
	 * never marking the report approved on a failure path.
	 *
	 * @return void
	 */
	public function testFailSoftWhenRequestSignoffThrows(): void {
		$listener = $this->makeListener(null);

		$listener->handle($this->makeEvent(['id' => 'ar-42']));

		self::assertCount(1, $this->signoffCalls, 'requestSignoff() must still have been invoked.');
		self::assertCount(0, $this->updates, 'A fail-closed request must not persist a mirror.');

	}//end testFailSoftWhenRequestSignoffThrows()

	/**
	 * A non-ObjectTransitionedEvent is ignored without error.
	 *
	 * @return void
	 */
	public function testNonMatchingEventTypeIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'ar-42', 'decisionOutcome' => 'pending']);

		$listener->handle(new GenericEvent());

		self::assertCount(0, $this->signoffCalls);
		self::assertCount(0, $this->updates);

	}//end testNonMatchingEventTypeIsIgnored()
}//end class
