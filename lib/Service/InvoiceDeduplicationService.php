<?php

/**
 * Invoice Deduplication Service
 *
 * Detects double-invoice attempts by comparing a requested set of source IDs
 * (timeEntryIds + expenseIds) against the BillableInvoice rows already on
 * file in draft or posted status (Task 13, issue #111, design D9).
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Detect timeEntryId / expenseId conflicts across BillableInvoice rows.
 */
class InvoiceDeduplicationService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Scan existing invoices for overlapping source IDs.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<int,string> $timeEntryIds Requested time entries.
	 * @param array<int,string> $expenseIds Requested expenses.
	 * @param string|null $excludeInvoiceId Optional invoice id to skip
	 *                                      (useful when re-validating
	 *                                      an existing draft).
	 *
	 * @return array{hasConflicts:bool,conflicts:array<int,array{invoiceId:string,invoiceNumber:string,status:string,timeEntryIds:array<int,string>,expenseIds:array<int,string>}>}
	 */
	public function deduplicateSourceIds(
		string $administrationId,
		array $timeEntryIds,
		array $expenseIds,
		?string $excludeInvoiceId = null,
	): array {
		if (count($timeEntryIds) === 0 && count($expenseIds) === 0) {
			return ['hasConflicts' => false, 'conflicts' => []];
		}

		$existing = $this->findAll(
			schema: 'BillableInvoice',
			filters: ['administrationId' => $administrationId]
		);

		$conflicts = [];

		foreach ($existing as $invoice) {
			$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
			if ($invoiceId === '' || $invoiceId === $excludeInvoiceId) {
				continue;
			}

			$status = (string)($invoice['status'] ?? 'draft');
			if (in_array($status, ['draft', 'posted'], true) === false) {
				continue;
			}

			$existingTimeIds = array_map('strval', (array)($invoice['timeEntryIds'] ?? []));
			$existingExpIds = array_map('strval', (array)($invoice['expenseIds'] ?? []));

			$timeOverlap = array_values(array_intersect($timeEntryIds, $existingTimeIds));
			$expOverlap = array_values(array_intersect($expenseIds, $existingExpIds));

			if (count($timeOverlap) === 0 && count($expOverlap) === 0) {
				continue;
			}

			$conflicts[] = [
				'invoiceId' => $invoiceId,
				'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
				'status' => $status,
				'timeEntryIds' => $timeOverlap,
				'expenseIds' => $expOverlap,
			];
		}//end foreach

		return [
			'hasConflicts' => (count($conflicts) > 0),
			'conflicts' => $conflicts,
		];

	}//end deduplicateSourceIds()

	/**
	 * Find all matching records via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rs = $svc
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);

			if (is_array($rs) === true) {
				return $rs;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->error('InvoiceDeduplicationService findAll failed: ' . $e->getMessage());
			return [];
		}

	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
