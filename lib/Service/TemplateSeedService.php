<?php

/**
 * Shillinq template seeds
 *
 * Seeds the shipped templates and reference rules (rate cards, Selectielijst
 * retention rules, allocation rules, retention policies and mandate templates)
 * from the files under lib/Settings/seeds/. Rows that already exist are
 * skipped, so operator edits survive a re-run.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedMessages;

/**
 * Imports the template and reference-rule seed files through the seed importer.
 *
 * SettingsService runs these seeds and supplies the OpenRegister availability
 * check and the register slug.
 *
 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
 */
class TemplateSeedService {

	/**
	 * Result message when OpenRegister is missing.
	 *
	 * @var string
	 */
	private const NO_OPENREGISTER = 'OpenRegister is not installed or enabled.';

	/**
	 * Result message when an administration seed gets no administrationId.
	 *
	 * @var string
	 */
	private const NO_ADMINISTRATION = 'administrationId must not be empty.';

	/**
	 * The single-file seeds: file, items key, message when it holds no items,
	 * and the read failures (missing, unreadable, invalid) when they differ
	 * from the importer's defaults.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: array{missing: string, unreadable: string, invalid: string}|null}>
	 */
	private const FILES = [
		'rateCards' => ['rate-card-templates.json', 'rateCards', 'Seed file contains no rateCards.', null],
		'selectielijst' => [
			'selectielijst-gemeenten-2020.json',
			'retentionRules',
			'Seed file contains no retention rules.',
			[
				'missing' => 'Seed file selectielijst-gemeenten-2020.json not found.',
				'unreadable' => 'Failed to read selectielijst seed file.',
				'invalid' => 'Failed to parse selectielijst seed file: ',
			],
		],
		'retentionPolicies' => [
			'retention-policies.json',
			'policies',
			'Retention policy seed file contains no policies.',
			[
				'missing' => 'Retention policy seed file not found.',
				'unreadable' => 'Failed to read retention policy seed file.',
				'invalid' => 'Failed to parse retention policy seed file: ',
			],
		],
		'mandates' => ['mandaat-templates.json', 'mandaatTemplates', 'Seed file contains no mandaatTemplates.', null],
	];

	/**
	 * The allocation rule seed files, under the allocation-rules/ directory.
	 *
	 * @var array<int, string>
	 */
	private const ALLOCATION_RULE_FILES = [
		'overhead-by-headcount.json',
		'it-by-volume.json',
		'facility-by-fixed-percentage.json',
	];

	/**
	 * Constructor for the TemplateSeedService.
	 *
	 * @param SettingsService $settings The settings service
	 * @param SeedImporter $importer The seed file importer
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settings,
		private SeedImporter $importer,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed default rate-card templates from rate-card-templates.json, as the system user.
	 *
	 * Deduplication key is level + effectiveFrom + administrationId.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
	 */
	public function seedRateCardTemplates(string $administrationId): array {
		$read = $this->administrationFileRows(seed: 'rateCards', administrationId: $administrationId);
		if (isset($read['error']) === true) {
			return $read['error'];
		}

		return $this->importer->importAsSystem(
			import: new SeedImport(
				schema: 'RateCard',
				rows: $read['rows'],
				match: static fn ($rateCard): array => [
					'level' => $rateCard['level'],
					'effectiveFrom' => $rateCard['effectiveFrom'],
					'administrationId' => $administrationId,
				],
				administrationId: $administrationId,
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: rate-card templates seeded',
				seededMessage: 'Rate-card templates seeded successfully.',
				failedLog: 'Shillinq: rate-card templates seeding failed',
			)
		);
	}//end seedRateCardTemplates()

	/**
	 * Seed retention rules from the Selectielijst Gemeenten 2020 seed file, as the system user.
	 *
	 * Rows are matched by selectionListCode with no administrationId, so
	 * operator-authored overrides are kept (REQ-ARC-002).
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	public function seedSelectielijst(): array {
		$read = $this->fileRows(seed: 'selectielijst');
		if (isset($read['error']) === true) {
			if ($read['error']['message'] === self::FILES['selectielijst'][3]['missing']) {
				$this->logger->error('Shillinq: selectielijst-gemeenten-2020.json not found at ' . SeedImporter::SEED_DIR . self::FILES['selectielijst'][0]);
			}

			return $read['error'];
		}

		return $this->importer->importAsSystem(
			import: new SeedImport(
				schema: 'RetentionRule',
				rows: $read['rows'],
				match: static fn ($rule): array => [
					'selectionListCode' => ($rule['selectionListCode'] ?? ''),
					'administrationId' => null,
				],
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: Selectielijst retention rules seeded',
				seededMessage: 'Selectielijst retention rules seeded.',
				failedLog: 'Shillinq: Selectielijst seeding failed',
			)
		);
	}//end seedSelectielijst()

	/**
	 * Seed the example allocation rules from the allocation-rules/ seed files, as the system user.
	 *
	 * Rows are matched by name + administrationId. A seed file that is missing
	 * or cannot be read is logged and left out. Seeds ship paused for operator
	 * review (REQ-CC-004).
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
	 */
	public function seedAllocationRules(string $administrationId): array {
		$refusal = $this->administrationRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$rules = [];
		foreach (self::ALLOCATION_RULE_FILES as $seedFile) {
			$rules = array_merge($rules, $this->allocationRules(seedFile: $seedFile));
		}

		return $this->importer->importAsSystem(
			import: new SeedImport(
				schema: 'AllocationRule',
				rows: $rules,
				match: static fn ($rule): array => [
					'name' => $rule['name'],
					'administrationId' => $administrationId,
				],
				administrationId: $administrationId,
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: allocation rule seeds imported',
				seededMessage: 'Allocation rule seeds imported successfully.',
				failedLog: 'Shillinq: allocation rule seeding failed',
			)
		);
	}//end seedAllocationRules()

	/**
	 * Seed the default Archiefwet retention policies, idempotently.
	 *
	 * The three organization-wide RetentionPolicy records (financial 5yr, tax
	 * 7yr, general 3yr, REQ-RET-012) are matched by slug, so operator edits
	 * survive upgrades. They carry no administrationId.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-archiefwet-retention/spec.md (Task 13, REQ-RET-012)
	 */
	public function seedRetentionPolicies(): array {
		$read = $this->fileRows(seed: 'retentionPolicies');
		if (isset($read['error']) === true) {
			return $read['error'];
		}

		return $this->importer->import(
			import: new SeedImport(
				schema: 'RetentionPolicy',
				rows: $read['rows'],
				match: static fn ($policy): array => ['slug' => ($policy['slug'] ?? '')],
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: retention policies seeded',
				seededMessage: 'Retention policies seeded successfully.',
				failedLog: 'Shillinq: retention policy seeding failed',
			)
		);
	}//end seedRetentionPolicies()

	/**
	 * Seed mandaat (signing-authority) templates from mandaat-templates.json, idempotently.
	 *
	 * Rows are matched by mandateCode + administrationId (REQ-VPL-002). The
	 * `doelgroep` field is template metadata, not a mandate property, and is
	 * dropped.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.7
	 */
	public function seedMandateTemplates(string $administrationId): array {
		$read = $this->administrationFileRows(seed: 'mandates', administrationId: $administrationId);
		if (isset($read['error']) === true) {
			return $read['error'];
		}

		$templates = array_map(
			static function ($template) {
				unset($template['doelgroep']);
				return $template;
			},
			$read['rows']
		);

		return $this->importer->import(
			import: new SeedImport(
				schema: 'SpendingMandate',
				rows: $templates,
				match: static fn ($template): array => [
					'mandateCode' => $template['mandateCode'],
					'administrationId' => $administrationId,
				],
				administrationId: $administrationId,
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: mandaat templates seeded',
				seededMessage: 'Mandate templates seeded successfully.',
				failedLog: 'Shillinq: mandaat template seeding failed',
			)
		);
	}//end seedMandateTemplates()

	/**
	 * Why an administration seed may not run, or null when it may.
	 *
	 * @param string $administrationId The administrationId the seed stamps.
	 *
	 * @return array<string, mixed>|null
	 */
	private function administrationRefusal(string $administrationId): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return $this->importer->failed(message: self::NO_OPENREGISTER);
		}

		if ($administrationId === '') {
			return $this->importer->failed(message: self::NO_ADMINISTRATION);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The rows of an administration seed file, after the administration checks.
	 *
	 * @param string $seed Key in FILES.
	 * @param string $administrationId The administrationId the seed stamps.
	 *
	 * @return array<string, mixed> ['rows' => list] or ['error' => failed result].
	 */
	private function administrationFileRows(string $seed, string $administrationId): array {
		$refusal = $this->administrationRefusal(administrationId: $administrationId);
		if ($refusal !== null) {
			return ['error' => $refusal];
		}

		return $this->readRows(seed: $seed);
	}//end administrationFileRows()

	/**
	 * The rows of a single-file seed, once OpenRegister is there.
	 *
	 * @param string $seed Key in FILES.
	 *
	 * @return array<string, mixed> ['rows' => list] or ['error' => failed result].
	 */
	private function fileRows(string $seed): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return ['error' => $this->importer->failed(message: self::NO_OPENREGISTER)];
		}

		return $this->readRows(seed: $seed);
	}//end fileRows()

	/**
	 * The rows of a single-file seed.
	 *
	 * @param string $seed Key in FILES.
	 *
	 * @return array<string, mixed> ['rows' => list] or ['error' => failed result].
	 */
	private function readRows(string $seed): array {
		[$fileName, $itemsKey, $emptyMessage, $messages] = self::FILES[$seed];
		$file = $this->importer->read(fileName: $fileName, messages: $messages);
		if (isset($file['error']) === true) {
			return $file;
		}

		$rows = ($file['data'][$itemsKey] ?? []);
		if (empty($rows) === true) {
			return ['error' => $this->importer->failed(message: $emptyMessage)];
		}

		return ['rows' => $rows];
	}//end readRows()

	/**
	 * The rules in one allocation rule seed file; none when it cannot be read.
	 *
	 * Seed files use the ConfigurationService::importFromApp shape with the
	 * rules under `objects`; older files keep them under `allocationRules`.
	 *
	 * @param string $seedFile The file name under allocation-rules/.
	 *
	 * @return array<int, mixed>
	 */
	private function allocationRules(string $seedFile): array {
		$seedPath = SeedImporter::SEED_DIR . 'allocation-rules/' . $seedFile;
		if (file_exists($seedPath) === false) {
			$this->logger->warning('Shillinq: allocation rule seed file not found at ' . $seedPath);
			return [];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			$this->logger->warning('Shillinq: failed to read allocation rule seed file: ' . $seedFile);
			return [];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->warning('Shillinq: failed to parse allocation rule seed file: ' . $seedFile);
			return [];
		}

		return ($data['objects'] ?? ($data['allocationRules'] ?? []));
	}//end allocationRules()
}//end class
