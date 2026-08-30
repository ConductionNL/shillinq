<?php

/**
 * Invoice Generation Request
 *
 * Immutable value object capturing the inputs to the
 * InvoiceGenerationService::draftInvoice() flow (Task 23, issue #111).
 *
 * @category Request
 * @package  OCA\Shillinq\Request
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Request;

use InvalidArgumentException;

/**
 * Validated input shape for invoice drafting.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * Pre-existing debt (issue #506): this DTO's constructor/validation
 * covers every invoice field the domain requires; inherent complexity.
 * Deferred to a follow-up.
 */
final class InvoiceGenerationRequest {
	public const MODELS = ['t_and_m', 'fixed_fee', 'milestone', 'retainer', 'mixed', 'usage'];

	/**
	 * Construct a validated invoice-generation request.
	 *
	 * @param string $administrationId Server-resolved tenant scope (NOT client-supplied).
	 * @param string $billingModel One of MODELS.
	 * @param string $customerId FK to customer (Nextcloud contact).
	 * @param string $fromDate ISO date — start of
	 *                         source-record period.
	 * @param string $toDate ISO date — end of
	 *                       source-record period.
	 * @param array<int,string> $timeEntryIds FKs to UrenRegistratie rows.
	 * @param array<int,string> $expenseIds FKs to ExpenseClaimEntry rows.
	 * @param string|null $rateCardId Required for t_and_m / mixed / retainer overage.
	 * @param string|null $retainerScheduleId Required for retainer / mixed.
	 * @param int|null $fixedFeeCents Required for fixed_fee / mixed setup fee.
	 * @param string|null $milestoneId Required for milestone.
	 * @param string|null $projectId Optional FK to Project.
	 * @param string|null $notes Free-text notes.
	 * @param array<int,string> $meterReadingIds FKs to MeterReading rows; required for usage.
	 * @param string|null $usageRatePlanId Default UsageRatePlan FK when a reading declares none.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $billingModel,
		public readonly string $customerId,
		public readonly string $fromDate,
		public readonly string $toDate,
		public readonly array $timeEntryIds = [],
		public readonly array $expenseIds = [],
		public readonly ?string $rateCardId = null,
		public readonly ?string $retainerScheduleId = null,
		public readonly ?int $fixedFeeCents = null,
		public readonly ?string $milestoneId = null,
		public readonly ?string $projectId = null,
		public readonly ?string $notes = null,
		public readonly array $meterReadingIds = [],
		public readonly ?string $usageRatePlanId = null,
	) {
		$this->assertValid();

	}//end __construct()

	/**
	 * Build from a request body array; throws if invalid.
	 *
	 * @param string $administrationId Server-resolved scope.
	 * @param array<string,mixed> $body Decoded request body.
	 *
	 * @return self
	 */
	public static function fromArray(string $administrationId, array $body): self {
		$fixedFee = $body['fixedFeeCents'] ?? null;
		if ($fixedFee !== null) {
			$fixedFee = (int)$fixedFee;
		}

		if (isset($body['rateCardId']) === true) {
			$rateCardId = (string)$body['rateCardId'];
		} else {
			$rateCardId = null;
		}

		if (isset($body['retainerScheduleId']) === true) {
			$retainerScheduleId = (string)$body['retainerScheduleId'];
		} else {
			$retainerScheduleId = null;
		}

		if (isset($body['milestoneId']) === true) {
			$milestoneId = (string)$body['milestoneId'];
		} else {
			$milestoneId = null;
		}

		if (isset($body['projectId']) === true) {
			$projectId = (string)$body['projectId'];
		} else {
			$projectId = null;
		}

		if (isset($body['notes']) === true) {
			$notes = (string)$body['notes'];
		} else {
			$notes = null;
		}

		if (isset($body['usageRatePlanId']) === true) {
			$usageRatePlanId = (string)$body['usageRatePlanId'];
		} else {
			$usageRatePlanId = null;
		}

		return new self(
			administrationId: $administrationId,
			billingModel: (string)($body['billingModel'] ?? ''),
			customerId: (string)($body['customerId'] ?? ''),
			fromDate: (string)($body['fromDate'] ?? ''),
			toDate: (string)($body['toDate'] ?? ''),
			timeEntryIds: array_values(array_map('strval', (array)($body['timeEntryIds'] ?? []))),
			expenseIds: array_values(array_map('strval', (array)($body['expenseIds'] ?? []))),
			rateCardId: $rateCardId,
			retainerScheduleId: $retainerScheduleId,
			fixedFeeCents: $fixedFee,
			milestoneId: $milestoneId,
			projectId: $projectId,
			notes: $notes,
			meterReadingIds: array_values(array_map('strval', (array)($body['meterReadingIds'] ?? []))),
			usageRatePlanId: $usageRatePlanId,
		);

	}//end fromArray()

	/**
	 * Assert structural validity per the billing-model contract.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	private function assertValid(): void {
		if ($this->administrationId === '') {
			throw new InvalidArgumentException('administrationId is required.');
		}

		if (in_array($this->billingModel, self::MODELS, true) === false) {
			throw new InvalidArgumentException(sprintf('billingModel must be one of %s', implode(', ', self::MODELS)));
		}

		if ($this->customerId === '') {
			throw new InvalidArgumentException('customerId is required.');
		}

		if ($this->fromDate === '' || $this->toDate === '') {
			throw new InvalidArgumentException('fromDate and toDate are required.');
		}

		if ($this->fromDate > $this->toDate) {
			throw new InvalidArgumentException('fromDate must be <= toDate.');
		}

		if ($this->billingModel === 't_and_m' && $this->rateCardId === null) {
			throw new InvalidArgumentException('rateCardId is required for t_and_m model.');
		}

		if (in_array($this->billingModel, ['retainer', 'mixed'], true) === true && $this->retainerScheduleId === null) {
			throw new InvalidArgumentException(sprintf('retainerScheduleId is required for %s model.', $this->billingModel));
		}

		if ($this->billingModel === 'fixed_fee' && ($this->fixedFeeCents === null || $this->fixedFeeCents <= 0)) {
			throw new InvalidArgumentException('fixedFeeCents > 0 is required for fixed_fee model.');
		}

		if ($this->billingModel === 'milestone' && $this->milestoneId === null) {
			throw new InvalidArgumentException('milestoneId is required for milestone model.');
		}

		if ($this->billingModel === 'usage' && $this->meterReadingIds === []) {
			throw new InvalidArgumentException('meterReadingIds is required for usage model.');
		}

	}//end assertValid()
}//end class
