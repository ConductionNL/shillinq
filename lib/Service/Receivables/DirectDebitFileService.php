<?php

/**
 * Direct Debit File Service
 *
 * Writes the pain.008.001.02 file of a direct debit batch: it reads the
 * batch's collections, their mandates and invoices and the administration's
 * creditor details, renders the file, validates it and answers the batch with
 * `pain008Xml`, `collectionCount` and `controlSum`. A file with errors is not
 * stored: the service throws, so the `generate` transition fails and the
 * batch stays draft with the errors.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Receivables;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\PaymentRun\RenderedFile;
use OCA\Shillinq\Receivables\SepaPain008Generator;
use OCA\Shillinq\Receivables\SepaPain008Validator;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use RuntimeException;

/**
 * Renders, validates and returns the pain.008 file of a batch.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
class DirectDebitFileService {

	/**
	 * The most records read of one schema for one administration.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param SepaPain008Generator   $generator        Writes the pain.008 document.
	 * @param SepaPain008Validator   $validator        Checks it before it is stored.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly SepaPain008Generator $generator,
		private readonly SepaPain008Validator $validator,
	) {

	}//end __construct()

	/**
	 * The batch with its validated pain.008 file.
	 *
	 * @param array<string, mixed> $batch The DirectDebitBatch being generated.
	 *
	 * @return array<string, mixed> The batch with pain008Xml, collectionCount and controlSum.
	 *
	 * @throws RuntimeException When the file has errors; the message lists them.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function generate(array $batch): array {
		$batchId = ObjectIdentifier::resolve(saved: $batch);
		$administrationId = (string)($batch['administrationId'] ?? '');
		$collections = $this->collectionsOf(batchId: $batchId, administrationId: $administrationId);
		$creditor = $this->creditor(administrationId: $administrationId, collections: $collections);

		$file = $this->generator->render(batch: $batch, collections: $collections, creditor: $creditor);
		$errors = $this->validator->errors(xml: $file->content, collections: $collections, creditor: $creditor);
		if ($errors !== []) {
			throw new RuntimeException('The pain.008 file was not stored: ' . implode(' ', $errors));
		}

		$batch['pain008Xml'] = $file->content;
		$batch['collectionCount'] = count($collections);
		$batch['controlSum'] = $this->generator->controlSum(collections: $collections);

		return $batch;

	}//end generate()

	/**
	 * The stored file of a generated batch as a download, or null without one.
	 *
	 * @param array<string, mixed> $batch The DirectDebitBatch.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.3
	 */
	public function storedFile(array $batch): ?RenderedFile {
		$xml = (string)($batch['pain008Xml'] ?? '');
		if (trim($xml) === '') {
			return null;
		}

		$stem = (string)preg_replace('/[^A-Za-z0-9_-]/', '-', (string)($batch['messageId'] ?? ''));
		if ($stem === '') {
			$stem = 'direct-debit-batch';
		}

		return new RenderedFile(
			fileName: $stem . '.pain008.xml',
			mimeType: 'text/xml',
			format: SepaPain008Generator::FORMAT,
			content: $xml,
		);

	}//end storedFile()

	/**
	 * The batch's collections, each with its `mandate` and `remittanceInfo`.
	 *
	 * @param string $batchId          The batch id.
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function collectionsOf(string $batchId, string $administrationId): array {
		if ($batchId === '') {
			return [];
		}

		$mandates = $this->keyed(rows: $this->read(schema: 'SepaMandate', filters: ['administrationId' => $administrationId]));
		$invoices = $this->keyed(rows: $this->read(schema: 'ARInvoice', filters: ['administrationId' => $administrationId]));
		$collections = [];
		$rows = $this->read(
			schema: 'DirectDebitCollection',
			filters: ['administrationId' => $administrationId, 'submittedInBatchId' => $batchId]
		);
		foreach ($rows as $collection) {
			if ((string)($collection['submittedInBatchId'] ?? '') !== $batchId) {
				continue;
			}

			$invoice = ($invoices[(string)($collection['invoiceId'] ?? '')] ?? []);
			$collection['mandate'] = ($mandates[(string)($collection['mandateId'] ?? '')] ?? null);
			$collection['remittanceInfo'] = (string)($invoice['invoiceNumber'] ?? '');
			$collections[] = $collection;
		}

		usort(
			$collections,
			static fn (array $a, array $b): int => (string)($a['endToEndId'] ?? '') <=> (string)($b['endToEndId'] ?? '')
		);

		return $collections;

	}//end collectionsOf()

	/**
	 * The administration's name, IBAN and creditor identifier.
	 *
	 * Without an identifier on the administration, the one the mandates copied
	 * at signing is used, when they agree on it.
	 *
	 * @param string                     $administrationId The administration.
	 * @param list<array<string, mixed>> $collections      The collections with their mandates.
	 *
	 * @return array<string, string>
	 */
	private function creditor(string $administrationId, array $collections): array {
		$administration = ObjectIdentifier::findOne(
			scoped: $this->scoped(schema: 'Administration'),
			id: $administrationId,
			fallbackProperty: 'administrationCode'
		);
		$administration = ($administration ?? []);

		$identifier = trim((string)($administration['sepaCreditorIdentifier'] ?? ''));
		if ($identifier === '') {
			$fromMandates = [];
			foreach ($collections as $collection) {
				$copied = trim((string)($collection['mandate']['creditorIdentifier'] ?? ''));
				if ($copied !== '') {
					$fromMandates[$copied] = true;
				}
			}

			if (count($fromMandates) === 1) {
				$identifier = (string)array_key_first($fromMandates);
			}
		}

		return [
			'name' => trim((string)($administration['name'] ?? '')),
			'iban' => trim((string)($administration['primaryIban'] ?? '')),
			'bic' => trim((string)($administration['primaryBic'] ?? '')),
			'creditorIdentifier' => $identifier,
		];

	}//end creditor()

	/**
	 * Rows keyed by their id and, when they have one, their slug.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function keyed(array $rows): array {
		$keyed = [];
		foreach ($rows as $row) {
			foreach ([(string)($row['id'] ?? ''), (string)($row['@self']['slug'] ?? '')] as $key) {
				if ($key !== '') {
					$keyed[$key] = $row;
				}
			}
		}

		return $keyed;

	}//end keyed()

	/**
	 * The records of a schema matching the filters, each with its id.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters The property filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		$records = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters, 'limit' => self::READ_LIMIT]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end read()

	/**
	 * The object service scoped to one schema of the shillinq register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
