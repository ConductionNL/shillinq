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

use OCA\Shillinq\Kernel\AppIdentity;
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
	public function deduplicateSourceIds(string $administrationId, array $timeEntryIds, array $expenseIds, ?string $excludeInvoiceId = null): array {
		if (count($timeEntryIds) === 0 && count($expenseIds) === 0) {
			return ['hasConflicts' => false, 'conflicts' => []];
		}

		$claim = new SourceIdClaim();
		$claim->timeEntryIds = $timeEntryIds;
		$claim->expenseIds = $expenseIds;
		$claim->excludeInvoiceId = $excludeInvoiceId;
		$existing = $this->findAll(schema: 'BillableInvoice', filters: ['administrationId' => $administrationId]);
		$conflicts = array_values(
			array_filter(array_map(fn (array $invoice): ?array => $this->conflictWith(invoice: $invoice, claim: $claim), $existing))
		);

		return ['hasConflicts' => (count($conflicts) > 0), 'conflicts' => $conflicts];

	}//end deduplicateSourceIds()

	/**
	 * The conflict one existing invoice raises against the claim, or null
	 * when it is skipped or shares no source id.
	 *
	 * @param array<string,mixed> $invoice Existing BillableInvoice.
	 * @param SourceIdClaim       $claim   Requested source ids.
	 *
	 * @return array{invoiceId:string,invoiceNumber:string,status:string,timeEntryIds:array<int,string>,expenseIds:array<int,string>}|null
	 */
	private function conflictWith(array $invoice, SourceIdClaim $claim): ?array {
		$invoiceId = $this->candidateId(invoice: $invoice, claim: $claim);
		if ($invoiceId === '') {
			return null;
		}

		$timeOverlap = array_values(array_intersect($claim->timeEntryIds, array_map('strval', (array)($invoice['timeEntryIds'] ?? []))));
		$expOverlap = array_values(array_intersect($claim->expenseIds, array_map('strval', (array)($invoice['expenseIds'] ?? []))));
		if (count($timeOverlap) === 0 && count($expOverlap) === 0) {
			return null;
		}

		return [
			'invoiceId' => $invoiceId,
			'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
			'status' => (string)($invoice['status'] ?? 'draft'),
			'timeEntryIds' => $timeOverlap,
			'expenseIds' => $expOverlap,
		];

	}//end conflictWith()

	/**
	 * The invoice's id when it can conflict — a draft or posted invoice other
	 * than the excluded one — or '' when it is skipped.
	 *
	 * @param array<string,mixed> $invoice Existing BillableInvoice.
	 * @param SourceIdClaim       $claim   Requested source ids.
	 *
	 * @return string
	 */
	private function candidateId(array $invoice, SourceIdClaim $claim): string {
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		$status = (string)($invoice['status'] ?? 'draft');
		if ($invoiceId === $claim->excludeInvoiceId || in_array($status, ['draft', 'posted'], true) === false) {
			return '';
		}

		return $invoiceId;

	}//end candidateId()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
