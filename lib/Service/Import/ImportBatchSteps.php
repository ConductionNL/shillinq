<?php

/**
 * Import Batch Steps
 *
 * What each ImportBatch transition does: run its part of the import pipeline
 * and return the batch with the result and the follow-up state.
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
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * The parse, mapping, validate and dry-run steps of an import batch (REQ-AIW-001).
 *
 * Each step works on the batch as it is being saved and returns it; none
 * saves the batch itself.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class ImportBatchSteps {

	/**
	 * Constructor.
	 *
	 * @param ImportPipelineService  $pipeline      Parses, maps, validates and dry-runs.
	 * @param ImportSourceReader     $reader        Reads the auditfile from Files.
	 * @param ImportPeriod           $period        Whether the migration date's year is open.
	 * @param ObjectServiceInterface $objectService Reads the batch's mapping rows.
	 * @param SettingsService        $settings      Supplies the register slug.
	 */
	public function __construct(
		private readonly ImportPipelineService $pipeline,
		private readonly ImportSourceReader $reader,
		private readonly ImportPeriod $period,
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Parse: stage the auditfile, then move on to staged.
	 *
	 * @param array<string,mixed> $batch The batch in state parsing.
	 *
	 * @return array<string,mixed> The batch in state staged.
	 *
	 * @throws DomainException When the file cannot be read or parsed (REQ-AIW-002).
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function parse(array $batch): array {
		$xaf    = $this->reader->readAuditfile(batch: $batch);
		$result = $this->pipeline->stageBatch(batch: array_merge($batch, ['sourceXaf' => $xaf]));
		foreach ($result['findings'] as $finding) {
			if (($finding['severity'] ?? '') === ImportPipelineService::SEVERITY_ERROR) {
				throw new DomainException((string)($finding['message'] ?? 'The auditfile cannot be parsed.'));
			}
		}

		$batch['stagingPayload'] = $result['stagingPayload'];
		$batch['stagedCounts']   = $result['stagedCounts'];
		$batch['idempotencyKey'] = $this->pipeline->computeIdempotencyKey(batch: $batch);
		$batch['status']         = 'staged';

		return $batch;

	}//end parse()

	/**
	 * Start mapping: save a mapping row per staged account.
	 *
	 * @param array<string,mixed> $batch The batch in state mapping.
	 *
	 * @return array<string,mixed> The batch, unchanged.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function startMapping(array $batch): array {
		$this->pipeline->resolveBatchMappings(batch: $batch, batchId: $this->idOf(batch: $batch));

		return $batch;

	}//end startMapping()

	/**
	 * Validate: write the findings; an error finding moves to validation failed.
	 *
	 * @param array<string,mixed> $batch The batch in state validated.
	 *
	 * @return array<string,mixed> The batch in state validated or validation_failed.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function validate(array $batch): array {
		$open   = $this->period->isOpen(
			administrationId: (string)($batch['administrationId'] ?? ''),
			date: (string)($batch['migrationDate'] ?? '')
		);
		$report = $this->pipeline->validate(
			batch: array_merge($batch, ['mappings' => $this->mappingsOf(batch: $batch), 'periodOpen' => $open])
		);

		$batch['validationReport'] = $report;
		if ($report['valid'] === false) {
			$batch['status'] = 'validation_failed';
		}

		return $batch;

	}//end validate()

	/**
	 * Dry-run: write the would-be opening entry, open items and relations.
	 *
	 * @param array<string,mixed> $batch The batch in state dry_run_complete.
	 *
	 * @return array<string,mixed> The batch with its dry-run report.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function dryRun(array $batch): array {
		$batch['dryRunReport'] = $this->pipeline->dryRun(
			batch: array_merge($batch, ['mappings' => $this->mappingsOf(batch: $batch)])
		);

		return $batch;

	}//end dryRun()

	/**
	 * The batch's mapping rows, reduced to what validation and the dry-run read.
	 *
	 * Reduced so the staged hash recorded at the dry-run does not change with
	 * metadata a later read adds to a row.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function mappingsOf(array $batch): array {
		$rows = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema('ImportMapping')
			->findAll(['filters' => ['batchReference' => $this->idOf(batch: $batch)]]);

		$mappings = [];
		foreach ($rows as $row) {
			$mapping = ObjectIdentifier::recordWithId(candidate: $row);
			if ($mapping === null) {
				continue;
			}

			$mappings[] = [
				'sourceCode'    => (string)($mapping['sourceCode'] ?? ''),
				'targetAccount' => ($mapping['targetAccount'] ?? null),
				'mappingSource' => (string)($mapping['mappingSource'] ?? ''),
				'confirmed'     => (($mapping['confirmed'] ?? false) === true),
			];
		}

		usort($mappings, static fn (array $left, array $right): int => strcmp($left['sourceCode'], $right['sourceCode']));

		return $mappings;

	}//end mappingsOf()

	/**
	 * The batch id.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @return string
	 *
	 * @throws DomainException When the batch carries no id.
	 */
	private function idOf(array $batch): string {
		$id = (string)($batch['id'] ?? ($batch['@self']['id'] ?? ''));
		if ($id === '') {
			throw new DomainException('This import batch has no id.');
		}

		return $id;

	}//end idOf()
}//end class
