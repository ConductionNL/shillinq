<?php

/**
 * Unit tests for LegesIntakeStepService.
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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-007)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\LegesIntakeStepService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the three outcomes of the journey's payment step, the gate per
 * `payAtIntake`, and the resumed run (REQ-SOPR-007).
 */
final class LegesIntakeStepServiceTest extends TestCase {
	/**
	 * Requests written during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 *
	 * @return LegesIntakeStepService The service.
	 */
	private function service(array $rows): LegesIntakeStepService {
		$saved = &$this->saved;
		$double = new class($rows, $saved) {
			/**
			 * The schema the fluent chain last selected.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(
				private array $rows,
				private array &$saved,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->rows[$this->schema] ?? []);
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = $object;
				return $object;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$objectService = new DuckObjectServiceAdapter(inner: $double);

		return new LegesIntakeStepService(
			feeSchedules: new FeeScheduleService(
				objectService: $objectService,
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			validator: new ObjectPaymentRequestValidator(),
			objectService: $objectService,
			appConfig: $appConfig,
		);
	}//end service()

	/**
	 * The context the journey hands the step.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(array $overrides = []): array {
		return array_merge(
			[
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'objectId' => 'zaak-7',
				'subjectType' => 'case',
				'intakeChannel' => 'portal',
				'onDate' => '2026-06-01',
			],
			$overrides
		);
	}//end context()

	/**
	 * One schedule row.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function schedule(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'fs-1',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'amount' => 245.0,
				'currency' => 'EUR',
				'payAtIntake' => 'required',
				'legalBasis' => [
					'regulation' => 'Legesverordening 2026',
					'article' => '2.3.1',
					'effectiveDate' => '2026-01-01',
				],
				'validFrom' => '2026-01-01',
				'validTo' => '2026-12-31',
				'intakeChannel' => '',
			],
			$overrides
		);
	}//end schedule()

	/**
	 * A pending leges request already standing on the case.
	 *
	 * @param string $state The request state.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function standingRequest(string $state): array {
		return [
			'id' => 'pr-1',
			'subjectKind' => 'object',
			'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-7'],
			'requestType' => 'leges',
			'amount' => 245.0,
			'currency' => 'EUR',
			'state' => $state,
		];
	}//end standingRequest()

	/**
	 * A required fee raises one pending request and BLOCKS the step until the
	 * payment is authorized (REQ-SOPR-007).
	 *
	 * @return void
	 */
	public function testARequiredFeeRaisesARequestAndBlocksTheStep(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule()]]);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_BLOCKED, $decision['outcome']);
		self::assertSame(245.0, $decision['request']['amount']);
		self::assertSame('leges', $decision['request']['requestType']);
		self::assertSame('pending', $decision['request']['state']);
		self::assertCount(1, $this->saved);
		self::assertStringContainsString('Legesverordening 2026', (string)$decision['request']['description']);
	}//end testARequiredFeeRaisesARequestAndBlocksTheStep()

	/**
	 * A type with no published fee completes with `noFee` and raises nothing. A
	 * step that invented a zero-amount request would put an unpayable line on
	 * every application that is free.
	 *
	 * @return void
	 */
	public function testATypeWithoutAFeeCompletesWithNoFee(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['typeValue' => 'bouwvergunning-oud'])]]);

		$decision = $service->evaluate($this->context(['typeValue' => 'melding']));

		self::assertSame(LegesIntakeStepService::OUTCOME_NO_FEE, $decision['outcome']);
		self::assertNull($decision['request']);
		self::assertSame([], $this->saved);
	}//end testATypeWithoutAFeeCompletesWithNoFee()

	/**
	 * An optional fee completes the step with the request still pending, so the
	 * citizen is not held on a checkout for a fee the council said may follow.
	 *
	 * @return void
	 */
	public function testAnOptionalFeeCompletesWithAPendingRequest(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['payAtIntake' => 'optional'])]]);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_COMPLETE, $decision['outcome']);
		self::assertSame('pending', $decision['request']['state']);
	}//end testAnOptionalFeeCompletesWithAPendingRequest()

	/**
	 * A fee due later completes the same way; the link travels with the receipt.
	 *
	 * @return void
	 */
	public function testAFeeDueLaterCompletesToo(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['payAtIntake' => 'later'])]]);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_COMPLETE, $decision['outcome']);
	}//end testAFeeDueLaterCompletesToo()

	/**
	 * Once the provider reports the payment authorized, the required step
	 * completes. Waiting for `captured` would hold a citizen on a page for a
	 * settlement that has nothing to do with them.
	 *
	 * @return void
	 */
	public function testAnAuthorizedPaymentCompletesARequiredStep(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [$this->schedule()],
				'PaymentRequest' => [$this->standingRequest('authorized')],
			]
		);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_COMPLETE, $decision['outcome']);
		self::assertSame([], $this->saved);
	}//end testAnAuthorizedPaymentCompletesARequiredStep()

	/**
	 * A resumed run REUSES the request that already stands. Raising a second one
	 * would both double the charge and be refused by the uniqueness invariant,
	 * turning a resumption into an error (REQ-SOPR-007).
	 *
	 * @return void
	 */
	public function testAResumedRunReusesItsRequest(): void {
		$service = $this->service(
			[
				'FeeSchedule' => [$this->schedule()],
				'PaymentRequest' => [$this->standingRequest('pending')],
			]
		);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_BLOCKED, $decision['outcome']);
		self::assertSame('pr-1', $decision['request']['id']);
		self::assertSame([], $this->saved);
	}//end testAResumedRunReusesItsRequest()

	/**
	 * A request on ANOTHER case is not this run's request, and a step that reused
	 * it would let one applicant's payment complete somebody else's application.
	 *
	 * @return void
	 */
	public function testARequestOnAnotherCaseIsNotReused(): void {
		$other = $this->standingRequest('authorized');
		$other['subject']['id'] = 'zaak-99';

		$service = $this->service(
			['FeeSchedule' => [$this->schedule()], 'PaymentRequest' => [$other]]
		);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_BLOCKED, $decision['outcome']);
		self::assertCount(1, $this->saved);
	}//end testARequestOnAnotherCaseIsNotReused()

	/**
	 * A failed earlier attempt does not block a fresh one: the citizen gets a new
	 * request rather than a dead end.
	 *
	 * @return void
	 */
	public function testAFailedRequestDoesNotBlockAFreshOne(): void {
		$service = $this->service(
			['FeeSchedule' => [$this->schedule()], 'PaymentRequest' => [$this->standingRequest('failed')]]
		);

		$decision = $service->evaluate($this->context());

		self::assertCount(1, $this->saved);
		self::assertSame(LegesIntakeStepService::OUTCOME_BLOCKED, $decision['outcome']);
	}//end testAFailedRequestDoesNotBlockAFreshOne()

	/**
	 * A schedule whose product cannot be priced reports `unpriced` and charges
	 * nothing, rather than raising a request for an amount nobody stands behind.
	 *
	 * @return void
	 */
	public function testAnUnpricedScheduleChargesNothing(): void {
		$service = $this->service(['FeeSchedule' => [$this->schedule(['productRef' => 'prod-gone'])]]);

		$decision = $service->evaluate($this->context());

		self::assertSame(LegesIntakeStepService::OUTCOME_UNPRICED, $decision['outcome']);
		self::assertNull($decision['request']);
		self::assertSame([], $this->saved);
	}//end testAnUnpricedScheduleChargesNothing()
}//end class
