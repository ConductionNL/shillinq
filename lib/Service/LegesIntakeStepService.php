<?php

/**
 * Leges Intake Step Service
 *
 * The `payment` step of a portaliq intake journey (ADR-085), on shillinq's
 * side. After the journey writes the object, this decides three things: is
 * there a fee for this type on this day, does a request already stand on the
 * object, and may the journey finish before the money arrives.
 *
 * WHY THE STEP DOES NOT GO THROUGH THE LEAF'S ACTION GATE
 * ------------------------------------------------------
 * `shillinq-payment-requests` refuses a caller without `payment.request`,
 * because that endpoint lets a caller demand an arbitrary amount from an
 * arbitrary object. This step demands nothing of its own: the amount, the
 * currency and the account all come from the fee schedule an administrator
 * published, and the subject is the object the journey just wrote. A citizen
 * completing their own application is exactly the caller that must be able to
 * reach it, and gating it on a finance group would make the step unreachable
 * for everyone it exists for. The gate that matters here is the schedule.
 *
 * A resumed run reuses its request rather than raising a second one. Without
 * that, a citizen who closed the tab and came back would owe the fee twice,
 * and the uniqueness invariant would refuse the second one anyway, turning a
 * resumption into an error.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;

/**
 * Resolves the fee, raises the request and gates the journey step.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-007)
 */
final class LegesIntakeStepService {
	/**
	 * The step completes: this type carries no fee.
	 *
	 * @var string
	 */
	public const OUTCOME_NO_FEE = 'noFee';

	/**
	 * The step completes with a request standing, paid or not.
	 *
	 * @var string
	 */
	public const OUTCOME_COMPLETE = 'complete';

	/**
	 * The step waits: the fee is required and the payment is not authorized yet.
	 *
	 * @var string
	 */
	public const OUTCOME_BLOCKED = 'blocked';

	/**
	 * The step cannot decide: a schedule exists but carries no usable amount.
	 *
	 * @var string
	 */
	public const OUTCOME_UNPRICED = 'unpriced';

	/**
	 * The states in which a payment counts as made for the purpose of the gate.
	 *
	 * `authorized` is enough on purpose: the money is reserved at the gateway and
	 * capture follows asynchronously, so waiting for `captured` would hold a
	 * citizen on a checkout page for a settlement that has nothing to do with
	 * them.
	 *
	 * @var array<int, string>
	 */
	public const SATISFIED_STATES = ['authorized', 'captured'];

	/**
	 * The schema holding payment requests.
	 *
	 * @var string
	 */
	private const SCHEMA_REQUEST = 'PaymentRequest';

	/**
	 * Constructor.
	 *
	 * @param FeeScheduleService $feeSchedules The published fees.
	 * @param ObjectPaymentRequestValidator $validator The request shape and the uniqueness invariant.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FeeScheduleService $feeSchedules,
		private readonly ObjectPaymentRequestValidator $validator,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Decide the step for one object the journey has just written.
	 *
	 * @param array<string, mixed> $context The step context: targetApp, register, schema, typeProperty, typeValue, objectId, subjectType, intakeChannel, onDate, debtor.
	 *
	 * @return array{outcome: string, request: ?array<string, mixed>, fee: ?array<string, mixed>, reason: string} The decision.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-007)
	 */
	public function evaluate(array $context): array {
		$schedule = $this->feeSchedules->resolve(
			tuple: $context,
			intakeChannel: (string)($context['intakeChannel'] ?? ''),
			onDate: (string)($context['onDate'] ?? ''),
		);

		if ($schedule === null) {
			return [
				'outcome' => self::OUTCOME_NO_FEE,
				'request' => null,
				'fee' => null,
				'reason' => 'This type carries no published fee, so there is nothing to pay.',
			];
		}

		$existing = $this->existingLegesRequest($context);
		if ($existing !== null) {
			// A resumed run. The request that already stands IS the answer; a
			// second one would be both a double charge and a refusal.
			return $this->gate($schedule, $existing);
		}

		$amount = ($schedule['amount'] ?? null);
		if (is_numeric($amount) === false || (float)$amount <= 0.0) {
			return [
				'outcome' => self::OUTCOME_UNPRICED,
				'request' => null,
				'fee' => $schedule,
				'reason' => 'A fee is published for this type but no amount can be read for it, so nothing is charged.',
			];
		}

		$request = $this->raise($context, $schedule);

		return $this->gate($schedule, $request);
	}//end evaluate()

	/**
	 * Apply `payAtIntake` to a request that stands.
	 *
	 * @param array<string, mixed> $schedule The resolved schedule.
	 * @param array<string, mixed> $request The request standing on the object.
	 *
	 * @return array{outcome: string, request: array<string, mixed>, fee: array<string, mixed>, reason: string} The decision.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-007)
	 */
	private function gate(array $schedule, array $request): array {
		$payAtIntake = (string)($schedule['payAtIntake'] ?? 'required');
		$state = (string)($request['state'] ?? 'pending');

		if ($payAtIntake === 'required' && in_array($state, self::SATISFIED_STATES, true) === false) {
			return [
				'outcome' => self::OUTCOME_BLOCKED,
				'request' => $request,
				'fee' => $schedule,
				'reason' => 'This application is only complete once the fee has been paid.',
			];
		}

		return [
			'outcome' => self::OUTCOME_COMPLETE,
			'request' => $request,
			'fee' => $schedule,
			'reason' => ($payAtIntake === 'required'
				? 'The fee has been paid.'
				: 'The application is complete; the payment link travels with the receipt.'),
		];
	}//end gate()

	/**
	 * Append one pending leges request for the published fee.
	 *
	 * @param array<string, mixed> $context The step context.
	 * @param array<string, mixed> $schedule The resolved schedule.
	 *
	 * @return array<string, mixed> The request as written.
	 */
	private function raise(array $context, array $schedule): array {
		$request = [
			'subjectKind' => 'object',
			'subject' => [
				'type' => (string)($context['subjectType'] ?? 'object'),
				'register' => (string)($context['register'] ?? ''),
				'schema' => (string)($context['schema'] ?? ''),
				'id' => (string)($context['objectId'] ?? ''),
			],
			'requestType' => 'leges',
			'amount' => (float)$schedule['amount'],
			'currency' => (string)($schedule['currency'] ?? 'EUR'),
			'description' => $this->describe($context, $schedule),
			'state' => 'pending',
			'paymentGateway' => 'mollie',
		];

		if ((string)($schedule['revenueAccount'] ?? '') !== '') {
			$request['revenueAccount'] = (string)$schedule['revenueAccount'];
		}

		if (isset($context['debtor']) === true && is_array($context['debtor']) === true) {
			$request['debtor'] = $context['debtor'];
		}

		$this->validator->validate(request: $request, existing: []);

		$saved = $this->objectService->saveObject(
			object: $request,
			register: $this->registerSlug(),
			schema: self::SCHEMA_REQUEST,
		);

		$written = $saved->getObject();
		if ($saved->getUuid() !== null) {
			$written['id'] = $saved->getUuid();
		}

		return $written;
	}//end raise()

	/**
	 * The pending leges request already standing on this object, if any.
	 *
	 * @param array<string, mixed> $context The step context.
	 *
	 * @return array<string, mixed>|null The request, or null.
	 */
	private function existingLegesRequest(array $context): ?array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA_REQUEST)
			->findAll(['filters' => ['requestType' => 'leges'], 'limit' => 200]);

		if (is_array($rows) === false) {
			return null;
		}

		$key = $this->validator->subjectKey(
			[
				'register' => (string)($context['register'] ?? ''),
				'schema' => (string)($context['schema'] ?? ''),
				'id' => (string)($context['objectId'] ?? ''),
			]
		);

		foreach ($rows as $row) {
			if (is_array($row) === false || is_array($row['subject'] ?? null) === false) {
				continue;
			}

			if ((string)($row['requestType'] ?? '') !== 'leges') {
				continue;
			}

			if ($this->validator->subjectKey((array)$row['subject']) !== $key) {
				continue;
			}

			if (in_array((string)($row['state'] ?? ''), ['failed', 'expired', 'voided'], true) === true) {
				continue;
			}

			return $row;
		}

		return null;
	}//end existingLegesRequest()

	/**
	 * The plain-language reason shown on the checkout and repeated on the
	 * receipt, naming the regulation the fee comes from where the schedule says
	 * so. A citizen who is asked for money is owed the sentence that says why.
	 *
	 * @param array<string, mixed> $context The step context.
	 * @param array<string, mixed> $schedule The resolved schedule.
	 *
	 * @return string The description.
	 */
	private function describe(array $context, array $schedule): string {
		$type = (string)($context['typeValue'] ?? 'application');
		$basis = (string)($schedule['legalBasis'] ?? '');

		if ($basis === '') {
			return sprintf('Leges %s', $type);
		}

		return sprintf('Leges %s (%s)', $type, $basis);
	}//end describe()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
