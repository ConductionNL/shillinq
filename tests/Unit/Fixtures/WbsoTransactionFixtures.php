<?php

/**
 * WBSO Transaction fixtures.
 *
 * Three canonical sample transactions covering each of the lifecycle states
 * (draft / posted / reversed) — used by the unit tests covering
 * REQ-WBSO-002 / REQ-WBSO-008.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-38
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Fixtures;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-38
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class WbsoTransactionFixtures {

	public const SAMPLE_ADMINISTRATION = 'adm-consultancy-nl';

	/**
	 * Posted invoice, draft receipt, and reversal of the invoice.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function transactions(): array {
		return [
			[
				'id' => 'txn-fixture-inv-1',
				'transactionNumber' => 'INV-2026-001',
				'transactionType' => 'invoice',
				'transactionDate' => '2026-01-15',
				'amount' => 1500.00,
				'description' => 'Sample posted invoice (fixture)',
				'status' => 'posted',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'createdBy' => 'system',
			],
			[
				'id' => 'txn-fixture-rec-1',
				'transactionNumber' => 'REC-2026-001',
				'transactionType' => 'receipt',
				'transactionDate' => '2026-01-20',
				'amount' => 250.00,
				'description' => 'Sample draft receipt (fixture)',
				'status' => 'draft',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'createdBy' => 'system',
			],
			[
				'id' => 'txn-fixture-inv-1-rev',
				'transactionNumber' => 'INV-2026-001-REV',
				'transactionType' => 'credit-note',
				'transactionDate' => '2026-01-25',
				'amount' => 1500.00,
				'description' => 'Reversal of Sample posted invoice (fixture)',
				'status' => 'reversed',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'reversalOfTransactionId' => 'txn-fixture-inv-1',
				'reversalReason' => 'Scope dispute (fixture)',
				'createdBy' => 'system',
			],
		];

	}//end transactions()
}//end class
