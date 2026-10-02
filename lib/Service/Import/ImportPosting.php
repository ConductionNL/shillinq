<?php

/**
 * Import Posting
 *
 * The writes of a posted import and of its reversal: the customers first, then
 * the opening entry, saved as a draft and posted through the JournalEntry
 * lifecycle so the booking guards and the materialised GLTransaction apply.
 * A write the register or the books refuse stops the post, removes what this
 * post wrote, and comes back as an error finding: a post never reads `posted`
 * with nothing written.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes and unwinds the opening entry and customers of an import.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportPosting {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService The register.
	 * @param ObjectTransitionRunner $transitions   Posts journal entries through their lifecycle.
	 * @param ImportPostingPayloads  $payloads      Builds the records.
	 * @param SettingsService        $settings      Supplies the register slug.
	 * @param LoggerInterface        $logger        Refusal diagnostics.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly ImportPostingPayloads $payloads,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the customers and post the opening entry.
	 *
	 * @param array<string,mixed> $batch  The batch (stagingPayload with relations).
	 * @param array<string,mixed> $report The dry-run report of the batch.
	 *
	 * @return array{failed:bool,postingRefs:array<string,mixed>|null,findings:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function post(array $batch, array $report): array {
		$findings = [];
		$openItems = count(($report['arOpenItems'] ?? [])) + count(($report['apOpenItems'] ?? []));
		if ($openItems > 0) {
			return $this->failed(
				code: 'open-items-not-supported',
				message: 'The import holds open items, and the import cannot write open items yet. Remove them from the batch and run the dry-run again.',
				findings: $findings
			);
		}

		$entry     = null;
		$customers = [];
		try {
			if ($this->inScope(batch: $batch, part: 'openingBalance') === true) {
				$entry = $this->payloads->openingEntry(batch: $batch, journal: ($report['openingJournal'] ?? []));
			}

			if ($this->inScope(batch: $batch, part: 'relations') === true) {
				$customers = $this->customers(batch: $batch, findings: $findings);
			}
		} catch (DomainException $e) {
			return $this->failed(code: 'posting-refused', message: $e->getMessage(), findings: $findings);
		}

		if ($entry === null && $this->inScope(batch: $batch, part: 'openingBalance') === true) {
			$findings[] = $this->finding(
				severity: ImportPipelineService::SEVERITY_WARNING,
				code: 'no-opening-balance',
				message: 'The auditfile holds no opening balance to book.'
			);
		}

		$refs = ['openingJournalId' => null, 'masterIds' => [], 'linkedMasterIds' => []];
		try {
			$this->writeCustomers(customers: $customers, refs: $refs);
			if ($entry !== null) {
				$refs['openingJournalId'] = $this->save(schema: 'JournalEntry', record: $entry, name: (string)$entry['journalNumber']);
				$this->transitions->run(objectId: $refs['openingJournalId'], action: 'postDirect');
			}
		} catch (Throwable $e) {
			$this->logger->warning('ImportPosting: post refused, removing what it wrote', ['exception' => $e->getMessage()]);
			$this->remove(schema: 'JournalEntry', ids: array_filter([$refs['openingJournalId']]));
			$this->remove(schema: 'CustomerMaster', ids: $refs['masterIds']);
			return $this->failed(code: 'posting-refused', message: sprintf('The import was not posted: %s', $e->getMessage()), findings: $findings);
		}

		return ['failed' => false, 'postingRefs' => $refs, 'findings' => $findings];

	}//end post()

	/**
	 * Post the reversing entry and remove the customers the import wrote.
	 *
	 * @param array<string,mixed> $batch The posted batch with its postingRefs.
	 *
	 * @return array{reversalRefs:array<string,mixed>,findings:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function reverse(array $batch): array {
		$refs      = (array)($batch['postingRefs'] ?? []);
		$reversal  = ['reversingJournalId' => null, 'deletedMasterIds' => []];
		$openingId = (string)($refs['openingJournalId'] ?? '');
		try {
			if ($openingId !== '') {
				$reversal['reversingJournalId'] = $this->postReversal(batch: $batch, openingId: $openingId);
			}

			foreach ((array)($refs['masterIds'] ?? []) as $masterId) {
				$this->scoped(schema: 'CustomerMaster')->deleteObject((string)$masterId);
				$reversal['deletedMasterIds'][] = (string)$masterId;
			}
		} catch (Throwable $e) {
			$this->logger->warning('ImportPosting: reversal refused', ['exception' => $e->getMessage()]);
			return [
				'reversalRefs' => $reversal,
				'findings' => [
					$this->finding(
						severity: ImportPipelineService::SEVERITY_ERROR,
						code: 'reversal-refused',
						message: sprintf('The import was not reversed: %s', $e->getMessage())
					),
				],
			];
		}//end try

		return ['reversalRefs' => $reversal, 'findings' => []];

	}//end reverse()

	/**
	 * Save and post the reversing entry of the opening entry.
	 *
	 * @param array<string,mixed> $batch     The batch.
	 * @param string              $openingId The opening entry id.
	 *
	 * @return string The reversing entry id.
	 *
	 * @throws DomainException When the opening entry is gone.
	 */
	private function postReversal(array $batch, string $openingId): string {
		$opening = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'JournalEntry')->find($openingId));
		if ($opening === null) {
			throw new DomainException(sprintf('its opening entry %s cannot be found.', $openingId));
		}

		$reversing   = $this->payloads->reversingEntry(batch: $batch, opening: $opening);
		$reversingId = $this->save(schema: 'JournalEntry', record: $reversing, name: (string)$reversing['journalNumber']);
		try {
			$this->transitions->run(objectId: $reversingId, action: 'postDirect');
		} catch (Throwable $e) {
			$this->remove(schema: 'JournalEntry', ids: [$reversingId]);
			throw $e;
		}

		return $reversingId;

	}//end postReversal()

	/**
	 * The CustomerMaster payloads of the staged customers; the rest as warnings.
	 *
	 * @param array<string,mixed>            $batch    The batch.
	 * @param array<int,array<string,mixed>> $findings Findings (by reference).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function customers(array $batch, array &$findings): array {
		$customers = [];
		$suppliers = 0;
		foreach ((array)($batch['stagingPayload']['relations'] ?? []) as $relation) {
			if ($this->payloads->isCustomer(relation: $relation) === false) {
				$suppliers++;
				continue;
			}

			$customer = $this->payloads->customer(relation: $relation, administrationId: (string)($batch['administrationId'] ?? ''));
			if ($customer === null) {
				$findings[] = $this->finding(
					severity: ImportPipelineService::SEVERITY_WARNING,
					code: 'customer-without-email',
					message: sprintf('The customer %s has no email address and was not imported.', (string)($relation['name'] ?? ''))
				);
				continue;
			}

			$customers[] = $customer;
		}

		if ($suppliers > 0) {
			$findings[] = $this->finding(
				severity: ImportPipelineService::SEVERITY_WARNING,
				code: 'supplier-not-imported',
				message: sprintf('%d relations are not customers and were not imported: there is no supplier record to write them to.', $suppliers)
			);
		}

		return $customers;

	}//end customers()

	/**
	 * Link each customer that exists, write the others.
	 *
	 * @param array<int,array<string,mixed>> $customers The payloads.
	 * @param array<string,mixed>            $refs      The posting refs (by reference).
	 *
	 * @return void
	 */
	private function writeCustomers(array $customers, array &$refs): void {
		foreach ($customers as $customer) {
			$existing = $this->existingCustomer(customer: $customer);
			if ($existing !== null) {
				$refs['linkedMasterIds'][] = $existing;
				continue;
			}

			$refs['masterIds'][] = $this->save(schema: 'CustomerMaster', record: $customer, name: (string)$customer['customerId']);
		}

	}//end writeCustomers()

	/**
	 * The id of a customer of the administration with the same KvK number, VAT id or email.
	 *
	 * @param array<string,mixed> $customer The payload.
	 *
	 * @return string|null
	 */
	private function existingCustomer(array $customer): ?string {
		foreach (['kvkNumber', 'vatId', 'email'] as $key) {
			$value = (string)($customer[$key] ?? '');
			if ($value === '') {
				continue;
			}

			$rows = $this->scoped(schema: 'CustomerMaster')->findAll(
				['filters' => ['administrationId' => $customer['administrationId'], $key => $value], 'limit' => 1]
			);
			foreach ($rows as $row) {
				$record = ObjectIdentifier::recordWithId(candidate: $row);
				if ($record !== null) {
					return (string)$record['id'];
				}
			}
		}

		return null;

	}//end existingCustomer()

	/**
	 * Save a record and return its id.
	 *
	 * @param string              $schema The schema.
	 * @param array<string,mixed> $record The payload.
	 * @param string              $name   The record's business number, for the refusal.
	 *
	 * @return string The record id.
	 *
	 * @throws DomainException When the register returns no id.
	 */
	private function save(string $schema, array $record, string $name): string {
		$id = ObjectIdentifier::resolve(saved: $this->scoped(schema: $schema)->saveObject($record));
		if ($id === '') {
			throw new DomainException(sprintf('the %s %s was not saved.', $schema, $name));
		}

		return $id;

	}//end save()

	/**
	 * Remove what a refused post wrote; a failure here is logged, the refusal stands.
	 *
	 * @param string             $schema The schema.
	 * @param array<int,string>  $ids    The ids.
	 *
	 * @return void
	 */
	private function remove(string $schema, array $ids): void {
		foreach ($ids as $id) {
			try {
				$this->scoped(schema: $schema)->deleteObject((string)$id);
			} catch (Throwable $e) {
				$this->logger->error(
					'ImportPosting: could not remove a record of a refused post',
					['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}

	}//end remove()

	/**
	 * Whether the batch's scope includes a part; a part the scope does not name is included.
	 *
	 * @param array<string,mixed> $batch The batch.
	 * @param string              $part  openingBalance or relations.
	 *
	 * @return bool
	 */
	private function inScope(array $batch, string $part): bool {
		return (($batch['scope'][$part] ?? true) !== false);

	}//end inScope()

	/**
	 * The register scoped to a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()

	/**
	 * A failed post: the findings so far plus the error.
	 *
	 * @param string                         $code     The error code.
	 * @param string                         $message  The error message.
	 * @param array<int,array<string,mixed>> $findings The findings so far.
	 *
	 * @return array{failed:bool,postingRefs:null,findings:array<int,array<string,mixed>>}
	 */
	private function failed(string $code, string $message, array $findings): array {
		$findings[] = $this->finding(severity: ImportPipelineService::SEVERITY_ERROR, code: $code, message: $message);

		return ['failed' => true, 'postingRefs' => null, 'findings' => $findings];

	}//end failed()

	/**
	 * One finding.
	 *
	 * @param string $severity error or warning.
	 * @param string $code     The code.
	 * @param string $message  The message.
	 *
	 * @return array<string,mixed>
	 */
	private function finding(string $severity, string $code, string $message): array {
		return ['severity' => $severity, 'code' => $code, 'message' => $message, 'context' => []];

	}//end finding()
}//end class
