<?php

/**
 * Contract of ObjectRequestBankMatcher
 *
 * The public surface of ObjectRequestBankMatcher that other components call, so they depend
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

/**
 * Contract of ObjectRequestBankMatcher.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectRequestBankMatcherInterface {

	/**
	 * The line settles the one request it quotes.
	 *
	 * @var string
	 */
	public const DECISION_CONFIRM = 'confirm';

	/**
	 * The line quotes a request but the bookkeeper decides.
	 *
	 * @var string
	 */
	public const DECISION_CANDIDATE = 'candidate';

	/**
	 * Decide what a bank line is to the object requests.
	 *
	 * @param array<string, mixed> $line The bank statement line.
	 *
	 * @return array{decision: string, requests: array<int, array<string, mixed>>} The decision and the requests it names.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function match(array $line): array;
}//end interface
