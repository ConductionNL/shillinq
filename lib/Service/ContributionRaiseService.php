<?php

/**
 * Contribution Raise Service
 *
 * Bills a set of guardians for one school contribution: the ouderbijdrage, the
 * overblijfbijdrage, a schoolreisje, a club. Each guardian becomes one issued
 * ARInvoice with no order behind it and one PaymentRequest that stands on the
 * chargeable in the owning app and on the invoice, so the portal can list and
 * pay it, dunning can age it, and the owning app can find its payment state.
 *
 * Decision D19: school contributions are shillinq invoices paid from portaliq.
 * The owning app (learniq's FeeItem, portaliq's activityOffer) calls this with
 * its own reference and the charge; shillinq reads nothing from its schema and
 * never calls it back (ADR-066). The settled signal is on the request itself.
 *
 * The call is idempotent per chargeable and child, so an owning app chunks a
 * large school into calls of at most 200 and can retry a chunk safely. One
 * failing guardian is reported and never stops the others.
 *
 * ADR-031 exception: a caller-triggered bulk write across three schemas with
 * per-row failure isolation is not a lifecycle rule or a derived field.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises one invoice and one payment request per guardian for a chargeable.
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 */
final class ContributionRaiseService {
	/**
	 * A recipient that was billed.
	 *
	 * @var string
	 */
	public const STATUS_RAISED = 'raised';

	/**
	 * A recipient whose child already carries a request on this chargeable.
	 *
	 * @var string
	 */
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * A recipient that could not be billed.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param PaymentActionAuthorizer $authorizer Whether the caller carries payment.request.
	 * @param ContributionInvoiceBuilder $builder The charge check and the payloads.
	 * @param ContributionDebtorResolver $debtors The guardian's customer and portal link.
	 * @param ObjectPaymentRequestValidator $validator The request shape and uniqueness.
	 * @param PaymentRequestFinder $finder The requests already on the chargeable.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param ITimeFactory $timeFactory Today, for the default invoice date.
	 * @param LoggerInterface $logger Logs a write that failed, never a guardian's data.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PaymentActionAuthorizer $authorizer,
		private readonly ContributionInvoiceBuilder $builder,
		private readonly ContributionDebtorResolver $debtors,
		private readonly ObjectPaymentRequestValidator $validator,
		private readonly PaymentRequestFinder $finder,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Raise a contribution for every recipient in the call.
	 *
	 * The same array in and out as `POST /apps/shillinq/api/contributions/raise`
	 * (contract.md), so an app already inside the request can call it directly.
	 *
	 * @param array<string, mixed> $payload The call: chargeable, charge and recipients.
	 *
	 * @return array{batchId: string, raised: int, skipped: int, failed: int, results: array<int, array<string, mixed>>} The outcome per recipient.
	 *
	 * @throws RuntimeException When the caller lacks the payment.request action (message starts with 403).
	 * @throws InvalidArgumentException When the call cannot be raised as a whole.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function raise(array $payload): array {
		if ($this->authorizer->may(PaymentActionAuthorizer::ACTION_REQUEST) === false) {
			throw new RuntimeException('403 Raising school contributions needs the payment.request action.');
		}

		$charge = $this->builder->normaliseCharge(
			payload: $payload,
			today: $this->timeFactory->getDateTime()->format('Y-m-d')
		);
		$batchId = $this->builder->batchId(invoiceDate: (string)$charge['invoiceDate'], random: bin2hex(random_bytes(4)));

		$existing = $this->finder->onSubject(
			register: (string)$charge['chargeable']['register'],
			schema: (string)$charge['chargeable']['schema'],
			objectId: (string)$charge['chargeable']['id'],
			asSystem: true,
		);
		$taken = $this->takenBeneficiaries(existing: $existing);

		$results = [];
		$sequence = 0;
		foreach ($charge['recipients'] as $index => $recipient) {
			$outcome = $this->raiseOne(
				charge: $charge,
				recipient: $recipient,
				batchId: $batchId,
				sequence: $sequence,
				taken: $taken,
				existing: $existing,
			);
			$outcome = ['index' => $index] + $outcome;
			$results[] = $outcome;
		}

		$counts = array_count_values(array_column($results, 'status'));

		return [
			'batchId' => $batchId,
			'raised' => (int)($counts[self::STATUS_RAISED] ?? 0),
			'skipped' => (int)($counts[self::STATUS_SKIPPED] ?? 0),
			'failed' => (int)($counts[self::STATUS_FAILED] ?? 0),
			'results' => $results,
		];
	}//end raise()

	/**
	 * Raise for one recipient, or say why not.
	 *
	 * @param array<string, mixed> $charge The normalised charge.
	 * @param array<string, mixed> $recipient The recipient.
	 * @param string $batchId The batch.
	 * @param int $sequence The number of the last recipient billed in this batch, advanced here.
	 * @param array<string, string> $taken Beneficiary key => standing request id, grown here.
	 * @param array<int, array<string, mixed>> $existing Requests on the chargeable, grown here.
	 *
	 * @return array<string, mixed> The result, without its index.
	 */
	private function raiseOne(
		array $charge,
		array $recipient,
		string $batchId,
		int &$sequence,
		array &$taken,
		array &$existing,
	): array {
		$invoiceId = '';

		try {
			$debtor = $this->debtors->resolve(
				debtor: (array)$recipient['debtor'],
				administrationId: (string)$charge['administrationId'],
			);
			$customerId = $debtor['customerMasterId'];
			$beneficiary = $this->builder->beneficiaryFor(
				recipient: $recipient,
				customerMasterId: $customerId,
				registerSlug: $this->registerSlug(),
			);

			$key = $this->validator->beneficiaryKey(beneficiary: $beneficiary);
			if (isset($taken[$key]) === true) {
				return [
					'status' => self::STATUS_SKIPPED,
					'reason' => 'already-raised',
					'paymentRequestId' => $taken[$key],
				];
			}

			$amount = $this->builder->amountFor(charge: $charge, recipient: $recipient);

			// Check the request before the invoice is written, so a refusal never
			// leaves an invoice behind that nothing can pay.
			$probe = $this->builder->buildRequest(
				charge: $charge,
				amount: $amount,
				customerMasterId: $customerId,
				beneficiary: $beneficiary,
				invoiceId: 'pending-invoice',
				batchId: $batchId,
			);
			$this->validator->validate(request: $probe, existing: $existing);

			$sequence++;
			$invoice = $this->builder->buildInvoice(
				charge: $charge,
				amount: $amount,
				customerMasterId: $customerId,
				beneficiary: $beneficiary,
				batchId: $batchId,
				sequence: $sequence,
			);
			$invoiceId = $this->save(object: $invoice, schema: 'ARInvoice');

			$request = $probe;
			$request['invoiceReference'] = $invoiceId;
			$requestId = $this->save(object: $request, schema: 'PaymentRequest');

			$taken[$key] = $requestId;
			$existing[] = $request + ['id' => $requestId];

			return [
				'status' => self::STATUS_RAISED,
				'invoiceId' => $invoiceId,
				'invoiceNumber' => (string)$invoice['invoiceNumber'],
				'paymentRequestId' => $requestId,
				'customerMasterId' => $customerId,
				'portalLinked' => $debtor['portalLinked'],
			];
		} catch (InvalidArgumentException $e) {
			return $this->failed(reason: $e->getMessage(), invoiceId: $invoiceId);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: a school contribution could not be written', ['exception' => $e->getMessage()]);
			return $this->failed(reason: 'The contribution could not be written: ' . $e->getMessage(), invoiceId: $invoiceId);
		}//end try
	}//end raiseOne()

	/**
	 * A failed result, naming the invoice when one was already written so an
	 * operator can see it stands without a request.
	 *
	 * @param string $reason Why.
	 * @param string $invoiceId The invoice written before the failure, or ''.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function failed(string $reason, string $invoiceId): array {
		$result = ['status' => self::STATUS_FAILED, 'reason' => $reason];
		if ($invoiceId !== '') {
			$result['invoiceId'] = $invoiceId;
		}

		return $result;
	}//end failed()

	/**
	 * Beneficiary key => request id for every contribution request that still
	 * counts. A voided request does not: its invoice was credited or settled
	 * another way, and billing the child again is then a real new charge.
	 *
	 * @param array<int, array<string, mixed>> $existing Requests on the chargeable.
	 *
	 * @return array<string, string> The index.
	 */
	private function takenBeneficiaries(array $existing): array {
		$taken = [];
		foreach ($existing as $request) {
			if ((string)($request['requestType'] ?? '') !== ContributionInvoiceBuilder::REQUEST_TYPE
				|| (string)($request['state'] ?? 'pending') === 'voided'
			) {
				continue;
			}

			$key = $this->validator->beneficiaryKey(beneficiary: ($request['beneficiary'] ?? null));
			if ($key !== '' && isset($taken[$key]) === false) {
				$taken[$key] = (string)($request['id'] ?? '');
			}
		}

		return $taken;
	}//end takenBeneficiaries()

	/**
	 * Save one object in shillinq's register and answer its uuid.
	 *
	 * The action matrix is the authorization for a raise (REQ-SCON-002), so the
	 * write does not also demand an OpenRegister role on ARInvoice: a school
	 * coordinator carries payment.request, not the bookkeeper role.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param string $schema The schema slug.
	 *
	 * @return string The uuid.
	 *
	 * @throws RuntimeException When OpenRegister answers without an id.
	 */
	private function save(array $object, string $schema): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			register: $this->registerSlug(),
			schema: $schema,
			_rbac: false,
		);

		$uuid = ObjectIdentifier::resolve(saved: $saved);
		if ($uuid === '') {
			throw new RuntimeException(sprintf('The %s was saved but OpenRegister answered without an id.', $schema));
		}

		return $uuid;
	}//end save()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
