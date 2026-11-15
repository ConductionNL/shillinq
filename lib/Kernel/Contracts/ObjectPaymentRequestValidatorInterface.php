<?php

/**
 * Contract of ObjectPaymentRequestValidator
 *
 * The public surface of ObjectPaymentRequestValidator that other components call, so they depend
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
 * Contract of ObjectPaymentRequestValidator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectPaymentRequestValidatorInterface {

	/**
	 * Refuse a request that does not satisfy its own subject kind, or that
	 * duplicates an open request of the same type on the same subject.
	 *
	 * @param array<string, mixed> $request The request about to be written.
	 * @param array<int, array<string, mixed>> $existing Requests already on the same subject.
	 * @param array<int, array<string, mixed>> $referenceHolders Requests anywhere that carry the same paymentReference.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the shape or the uniqueness is refused.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-001)
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 */
	public function validate(array $request, array $existing = [], array $referenceHolders = []): void;

	/**
	 * The stable key of a semantic reference, used to compare two subjects.
	 *
	 * @param array<string, mixed> $subject The semantic reference.
	 *
	 * @return string The key, over register, schema and id in a fixed order.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function subjectKey(array $subject): string;
}//end interface
