<?php

/**
 * Contract of PaymentSettlementService
 *
 * The public surface of PaymentSettlementService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of PaymentSettlementService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentSettlementServiceInterface {

	/**
	 * The amount a request says it owes, or null when that cannot be read.
	 *
	 * A caller asking this is about to fall back on the request's own amount,
	 * and null is its instruction to refuse rather than to fall back on zero.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return float|null The amount, or null when it is missing or not a number.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function amountOf(array $request): ?float;

	/**
	 * Append a settlement to a request WITHOUT touching its provider state.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param array<string, mixed> $settlement The settlement built above.
	 *
	 * @return array<string, mixed> The request with the settlement appended.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function append(array $request, array $settlement): array;

	/**
	 * Build one settlement record, refusing anything that could not be checked
	 * later.
	 *
	 * @param array<string, mixed> $input The settlement as the caller gave it: method, amount, reference, reason.
	 * @param string $actor The user recording it.
	 * @param string $settledAt When, as an ISO-8601 instant; now when empty.
	 *
	 * @return array<string, mixed> The settlement record.
	 *
	 * @throws InvalidArgumentException When the method, the amount or the evidence is missing.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function build(array $input, string $actor, string $settledAt = ''): array;

	/**
	 * The state a human reads, derived from the provider state and the
	 * settlements together.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array{state: string, settled: float|null, due: float|null, over: float|null} The report.
	 *                                                                                     `due` and `over` are null only
	 *                                                                                     when the amount could not be
	 *                                                                                     read, and `settled` is null
	 *                                                                                     only when a settlement could
	 *                                                                                     not be read.
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-003)
	 */
	public function report(array $request): array;

	/**
	 * Stamp the settled edge the first time the request reports paid.
	 *
	 * The owning app of a school contribution listens for this: the old object
	 * without `settledAt`, the new one with it (REQ-SCON-009). So it is written
	 * once, in the same save as the change that settled the request, and it is
	 * never cleared or moved. A capture that could not settle what it was for
	 * (`captured_unapplied`) is not stamped: an operator resolves that first.
	 *
	 * @param array<string, mixed> $request The request, after the change that may settle it.
	 * @param string $settledAt When it settled; now when empty.
	 * @param string $via `provider`, or the method of the settlement that completed it.
	 *
	 * @return array<string, mixed> The request, stamped when it has just settled.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-009)
	 */
	public function stampSettled(array $request, string $settledAt, string $via): array;
}//end interface
