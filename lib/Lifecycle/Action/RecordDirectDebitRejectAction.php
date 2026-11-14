<?php

/**
 * Record Direct Debit Reject Action
 *
 * The declared action of the DirectDebitCollection `reject` transition: a
 * rejected collection keeps its invoice open and must say why, so the
 * transition is refused without a pain.002 reason code (AM04, MD01, ...). The
 * code is stored upper-cased; the invoice is not touched.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-collection-outcome-settles-or-keeps-the-invoice-req-sdd-013
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use RuntimeException;

/**
 * Requires and normalises the reason code of a rejected collection.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-collection-outcome-settles-or-keeps-the-invoice-req-sdd-013
 */
class RecordDirectDebitRejectAction implements LifecycleActionInterface {

	/**
	 * The shape of an ISO 20022 status reason code (four letters and digits).
	 *
	 * @var string
	 */
	private const REASON_PATTERN = '/^[A-Z0-9]{4}$/';

	/**
	 * The collection with its reason code.
	 *
	 * @param array<string,mixed> $objectData   The collection after the transition.
	 * @param array<string,mixed> $previousData The collection before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The collection with pain002ReasonCode upper-cased.
	 *
	 * @throws RuntimeException When the reason code is missing or malformed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.4
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$code = strtoupper(trim((string)($objectData['pain002ReasonCode'] ?? '')));
		if (preg_match(self::REASON_PATTERN, $code) !== 1) {
			throw new RuntimeException('A rejected collection needs its four-character reason code, such as AM04.');
		}

		$objectData['pain002ReasonCode'] = $code;

		return $objectData;

	}//end execute()
}//end class
