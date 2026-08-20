<?php

/**
 * Unit tests for ACMReportSignTransitionListener.
 *
 * Regression coverage for the shillinq-signing-via-events defect where the
 * `ACMReport.sign` lifecycle transition (draft -> ready-for-submission) had
 * no handler: SigningDelegationService::requestSignature() was correctly
 * rewired onto the docudesk IEventDispatcher contract, but nothing in
 * production ever called it. This suite proves the `sign` transition now
 * actually reaches requestSignature() (and only that transition/schema),
 * and that the request-side mirror (signingRequestRef / signingStatus) gets
 * persisted back onto the ACMReport via OR.
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
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
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
use OCA\Shillinq\Listener\ACMReportSignTransitionListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\GenericEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ACMReportSignTransitionListener (REQ-SIGN-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ACMReportSignTransitionListenerTest extends TestCase {

	/**
	 * Every updateObject() write the listener issued, as ['id' => …] + payload.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $updates = [];

	/**
	 * Captured requestSignature() invocations: each entry is
	 * [financeObject, subjectSchema, documentClass].
	 *
	 * @var array<int,array{0:array<string,mixed>,1:string,2:string}>
	 */
	private array $signingCalls = [];

	/**
	 * Build the SUT wired to a recording ObjectService and a
	 * SigningDelegationService mock whose requestSignature() records every
	 * call into $this->signingCalls.
	 *
	 * @param array<string,mixed>|null $requestResult The value requestSignature()
	 *                                                returns, or null to make it throw
	 *                                                (simulating docudesk absent —
	 *                                                fail-closed).
	 * @param string $schemaSlug Slug the schema resolver reports for
	 *                           the event entity.
	 *
	 * @return ACMReportSignTransitionListener
	 */
	private function makeListener(
		?array $requestResult,
		string $schemaSlug = 'ACMReport',
	): ACMReportSignTransitionListener {
		$this->signingCalls = [];
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

		$signingService = $this->createMock(SigningDelegationService::class);
		$signingService->method('requestSignature')->willReturnCallback(
			function (
				array $financeObject,
				string $subjectSchema = 'document',
				string $documentClass = 'document',
			) use ($requestResult): array {
				$this->signingCalls[] = [$financeObject, $subjectSchema, $documentClass];
				if ($requestResult === null) {
					throw new RuntimeException(
						'docudesk is not installed; document signing request cannot be raised.'
					);
				}

				return $requestResult;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('schemaSlug')->willReturn($schemaSlug);

		return new ACMReportSignTransitionListener(
			$settings,
			$signingService,
			$schemaResolver,
			new NullLogger(),
			$objectService,
		);

	}//end makeListener()

	/**
	 * Build an ObjectTransitionedEvent carrying the given payload/action/schema.
	 *
	 * The entity carries the numeric schema **id**, exactly as OpenRegister
	 * stamps it (`setSchema((string) $schema->getId())`) — the slug only ever
	 * reaches the listener through {@see ListenerSchemaResolver}.
	 *
	 * @param array<string,mixed> $payload The object payload.
	 * @param string $action Transition action id.
	 * @param string $schemaId Numeric schema id as OR stamps it.
	 * @param string $schemaSlug Schema slug carried by the event envelope.
	 * @param string $id Object id.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(
		array $payload,
		string $action = 'sign',
		string $schemaId = '3001',
		string $schemaSlug = 'ACMReport',
		string $id = 'acm-42',
	): ObjectTransitionedEvent {
		$entity = (new ObjectEntity())
			->setSchema($schemaId)
			->setId($id)
			->setObject($payload);

		return new ObjectTransitionedEvent(
			$entity,
			$action,
			'draft',
			'ready-for-submission',
			'concerncontroller1',
			'shillinq',
			$schemaSlug,
		);

	}//end makeEvent()

	/**
	 * The `sign` transition on ACMReport calls requestSignature() and
	 * persists the returned signingRequestRef + signingStatus.
	 *
	 * @return void
	 */
	public function testSignTransitionCallsRequestSignatureAndPersists(): void {
		$listener = $this->makeListener(
			['id' => 'acm-42', 'signingStatus' => 'requested', 'signingRequestRef' => 'ds-req-42']
		);

		$listener->handle($this->makeEvent(['id' => 'acm-42', 'administrationId' => 'adm-1']));

		self::assertCount(1, $this->signingCalls, 'requestSignature() must be called exactly once.');
		[$financeObject, $subjectSchema, $documentClass] = $this->signingCalls[0];
		self::assertSame('acm-42', $financeObject['id']);
		self::assertSame('ACMReport', $subjectSchema);
		self::assertSame('acm-report', $documentClass);

		self::assertCount(1, $this->updates);
		self::assertSame('acm-42', $this->updates[0]['id']);
		self::assertSame('requested', $this->updates[0]['signingStatus']);
		self::assertSame('ds-req-42', $this->updates[0]['signingRequestRef']);

	}//end testSignTransitionCallsRequestSignatureAndPersists()

	/**
	 * A transition action other than `sign` is ignored entirely.
	 *
	 * @return void
	 */
	public function testNonSignActionIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'acm-42', 'signingStatus' => 'requested']);

		$listener->handle($this->makeEvent(['id' => 'acm-42'], action: 'submit'));

		self::assertCount(0, $this->signingCalls);
		self::assertCount(0, $this->updates);

	}//end testNonSignActionIsIgnored()

	/**
	 * A `sign` transition on a different schema is ignored.
	 *
	 * @return void
	 */
	public function testNonAcmReportSchemaIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'other-1', 'signingStatus' => 'requested'], 'AnnualReport');

		$listener->handle(
			$this->makeEvent(['id' => 'other-1'], schemaId: '3002', schemaSlug: 'AnnualReport')
		);

		self::assertCount(0, $this->signingCalls);
		self::assertCount(0, $this->updates);

	}//end testNonAcmReportSchemaIsIgnored()

	/**
	 * An ACMReport already carrying a non-idle signingStatus (requested /
	 * in-progress / signed) does not raise a duplicate request.
	 *
	 * @return void
	 */
	public function testAlreadyRequestedStatusSkipsDuplicateRequest(): void {
		$listener = $this->makeListener(['id' => 'acm-42', 'signingStatus' => 'requested']);

		$listener->handle(
			$this->makeEvent(['id' => 'acm-42', 'signingStatus' => 'requested'])
		);

		self::assertCount(0, $this->signingCalls);
		self::assertCount(0, $this->updates);

	}//end testAlreadyRequestedStatusSkipsDuplicateRequest()

	/**
	 * When requestSignature() fails closed (docudesk absent), the listener
	 * swallows the exception (fail-soft) and does not persist anything.
	 *
	 * @return void
	 */
	public function testFailSoftWhenRequestSignatureThrows(): void {
		$listener = $this->makeListener(null);

		$listener->handle($this->makeEvent(['id' => 'acm-42']));

		self::assertCount(1, $this->signingCalls, 'requestSignature() must still have been invoked.');
		self::assertCount(0, $this->updates, 'A fail-closed request must not persist a mirror.');

	}//end testFailSoftWhenRequestSignatureThrows()

	/**
	 * A non-ObjectTransitionedEvent is ignored without error.
	 *
	 * @return void
	 */
	public function testNonMatchingEventTypeIsIgnored(): void {
		$listener = $this->makeListener(['id' => 'acm-42', 'signingStatus' => 'requested']);

		$listener->handle(new GenericEvent());

		self::assertCount(0, $this->signingCalls);
		self::assertCount(0, $this->updates);

	}//end testNonMatchingEventTypeIsIgnored()
}//end class
