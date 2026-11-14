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
	 * Refusal message per billing model when its required input is missing.
	 *
	 * @var array<string,string>
	 */
	private const MODEL_INPUT_MESSAGES = [
		'retainer' => 'retainerScheduleId is required for retainer model.',
		'mixed' => 'retainerScheduleId is required for mixed model.',
		'fixed_fee' => 'fixedFeeCents > 0 is required for fixed_fee model.',
		'milestone' => 'milestoneId is required for milestone model.',
		'usage' => 'meterReadingIds is required for usage model.',
	];

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
	 * @param string|null $rateCardId An old RateCard to price hours by; a time and material invoice needs none, each hour carries its own rate.
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

		return new self(
			administrationId: $administrationId,
			billingModel: (string)($body['billingModel'] ?? ''),
			customerId: (string)($body['customerId'] ?? ''),
			fromDate: (string)($body['fromDate'] ?? ''),
			toDate: (string)($body['toDate'] ?? ''),
			timeEntryIds: self::stringList(body: $body, key: 'timeEntryIds'),
			expenseIds: self::stringList(body: $body, key: 'expenseIds'),
			rateCardId: self::optionalString(body: $body, key: 'rateCardId'),
			retainerScheduleId: self::optionalString(body: $body, key: 'retainerScheduleId'),
			fixedFeeCents: $fixedFee,
			milestoneId: self::optionalString(body: $body, key: 'milestoneId'),
			projectId: self::optionalString(body: $body, key: 'projectId'),
			notes: self::optionalString(body: $body, key: 'notes'),
			meterReadingIds: self::stringList(body: $body, key: 'meterReadingIds'),
			usageRatePlanId: self::optionalString(body: $body, key: 'usageRatePlanId'),
		);

	}//end fromArray()

	/**
	 * Read an optional string field: null when it is absent or null.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @param string              $key  The field name.
	 *
	 * @return string|null The value as a string, or null.
	 */
	private static function optionalString(array $body, string $key): string|null {
		if (isset($body[$key]) === true) {
			return (string)$body[$key];
		}

		return null;

	}//end optionalString()

	/**
	 * Read an id-list field as a list of strings (a scalar becomes one entry).
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @param string              $key  The field name.
	 *
	 * @return array<int,string> The ids.
	 */
	private static function stringList(array $body, string $key): array {
		return array_values(array_map('strval', (array)($body[$key] ?? [])));

	}//end stringList()

	/**
	 * Assert structural validity per the billing-model contract: scope first,
	 * then the period, then the inputs the billing model needs.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	private function assertValid(): void {
		$this->assertScope();
		$this->assertPeriod();

		if ($this->hasModelInputs() === false) {
			throw new InvalidArgumentException(self::MODEL_INPUT_MESSAGES[$this->billingModel]);
		}

	}//end assertValid()

	/**
	 * Administration, a known billing model and a customer are required.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	private function assertScope(): void {
		if ($this->administrationId === '') {
			throw new InvalidArgumentException('administrationId is required.');
		}

		if (in_array($this->billingModel, self::MODELS, true) === false) {
			throw new InvalidArgumentException(sprintf('billingModel must be one of %s', implode(', ', self::MODELS)));
		}

		if ($this->customerId === '') {
			throw new InvalidArgumentException('customerId is required.');
		}

	}//end assertScope()

	/**
	 * Both dates are required and fromDate must not lie after toDate.
	 *
	 * @throws InvalidArgumentException
	 *
	 * @return void
	 */
	private function assertPeriod(): void {
		if ($this->fromDate === '' || $this->toDate === '') {
			throw new InvalidArgumentException('fromDate and toDate are required.');
		}

		if ($this->fromDate > $this->toDate) {
			throw new InvalidArgumentException('fromDate must be <= toDate.');
		}

	}//end assertPeriod()

	/**
	 * True when the request carries the input its billing model needs.
	 *
	 * @return bool True when the model's required input is present.
	 */
	private function hasModelInputs(): bool {
		return match ($this->billingModel) {
			'retainer', 'mixed' => $this->retainerScheduleId !== null,
			'fixed_fee' => $this->fixedFeeCents !== null && $this->fixedFeeCents > 0,
			'milestone' => $this->milestoneId !== null,
			'usage' => $this->meterReadingIds !== [],
			default => true,
		};

	}//end hasModelInputs()
}//end class
