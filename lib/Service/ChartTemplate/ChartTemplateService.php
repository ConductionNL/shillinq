<?php

/**
 * Shillinq Chart Template Service
 *
 * Saves an administration's chart of accounts and analytical dimensions as a
 * template, seeds a new administration from one, and brings the
 * administrations that follow a template in step with its latest version.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\ChartTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\ChartTemplate;

use InvalidArgumentException;
use OCA\Shillinq\Service\RegisterBinding;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Copies a chart of accounts and its dimensions between administrations,
 * by template, never postings, relations or balances.
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md
 */
class ChartTemplateService {

	public const TEMPLATE_SCHEMA = 'AdministrationTemplate';

	/**
	 * The account fields a template carries. Everything else (ids, balances,
	 * administration, audit hashes) stays behind.
	 */
	private const ACCOUNT_FIELDS = [
		'accountNumber',
		'name',
		'accountType',
		'currency',
		'parentAccountNumber',
		'isClosingAccount',
		'description',
		'vatApplicable',
		'vatRate',
		'rgsCode',
		'rgsReferenceNumber',
		'controlAccountFor',
		'isSuspenseAccount',
	];

	/**
	 * The dimension fields a template carries. Budgets and spend stay behind.
	 */
	private const DIMENSION_FIELDS = [
		'code',
		'name',
		'description',
		'dimensionType',
		'parentCode',
		'isHierarchical',
		'dataType',
		'sortOrder',
	];

	/**
	 * The fields a sync brings in step on a record the follower already has.
	 */
	private const SYNCED_FIELDS = ['name', 'accountType', 'dimensionType', 'description'];

	/**
	 * The two kinds of record a template carries.
	 *
	 * @var array<string, array{schema: string, key: string, fields: string[]}>
	 */
	private const KINDS = [
		'accounts' => ['schema' => 'Account', 'key' => 'accountNumber', 'fields' => self::ACCOUNT_FIELDS],
		'dimensions' => ['schema' => 'AnalyticalDimension', 'key' => 'code', 'fields' => self::DIMENSION_FIELDS],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterBinding $binding     The shillinq register.
	 * @param ITimeFactory    $timeFactory The date of a sync record.
	 */
	public function __construct(
		private readonly RegisterBinding $binding,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Save an administration's accounts and dimensions as a new template at version 1.
	 *
	 * @param string $administrationId The administration to copy.
	 * @param string $name             The template's name.
	 *
	 * @return array<string, mixed> The saved template.
	 *
	 * @throws InvalidArgumentException When the name or administration is empty.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
	 */
	public function saveAsTemplate(string $administrationId, string $name): array {
		$name = trim($name);
		if ($administrationId === '' || $name === '' || mb_strlen($name) > 200) {
			throw new InvalidArgumentException('A template needs an administration and a name of at most 200 characters.');
		}

		$template = [
			'name' => $name,
			'sourceAdministrationId' => $administrationId,
			'version' => 1,
			'lifecycleState' => 'active',
		];
		foreach (self::KINDS as $kind => $config) {
			$template[$kind] = array_map(
				static fn (array $row): array => self::pick(row: $row, fields: $config['fields']),
				$this->rowsOf(schema: $config['schema'], filters: ['administrationId' => $administrationId])
			);
		}

		return $this->save(schema: self::TEMPLATE_SCHEMA, row: $template);
	}//end saveAsTemplate()

	/**
	 * The active templates, for the setup wizard's template step.
	 *
	 * @return array<int, array{id: string, name: string, version: int, accounts: int, dimensions: int}>
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	public function activeTemplates(): array {
		$templates = [];
		foreach ($this->rowsOf(schema: self::TEMPLATE_SCHEMA, filters: ['lifecycleState' => 'active']) as $row) {
			$templates[] = [
				'id' => self::idOf(row: $row),
				'name' => (string)($row['name'] ?? ''),
				'version' => (int)($row['version'] ?? 1),
				'accounts' => count((array)($row['accounts'] ?? [])),
				'dimensions' => count((array)($row['dimensions'] ?? [])),
			];
		}

		return $templates;
	}//end activeTemplates()

	/**
	 * Seed an administration from a template: every account and dimension it
	 * does not have yet. Existing records are left as they are.
	 *
	 * @param string $templateId       The template.
	 * @param string $administrationId The administration to seed.
	 *
	 * @return array{added: string[], updated: string[], skipped: string[]}
	 *
	 * @throws InvalidArgumentException When the template does not exist or the administration is empty.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-a-new-administration-starts-from-an-office-template-req-lma-004
	 */
	public function seedFromTemplate(string $templateId, string $administrationId): array {
		if ($administrationId === '') {
			throw new InvalidArgumentException('An administration to seed is required.');
		}

		return $this->apply(template: $this->template(templateId: $templateId), administrationId: $administrationId, update: false);
	}//end seedFromTemplate()

	/**
	 * Bring every administration that follows the template, and is behind
	 * its version, in step with it, and record each run.
	 *
	 * @param string $templateId The template.
	 *
	 * @return array<string, array{added: string[], updated: string[], skipped: string[]}> Report per administration id.
	 *
	 * @throws InvalidArgumentException When the template does not exist.
	 *
	 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-followers-receive-template-changes-req-lma-005
	 */
	public function syncFollowers(string $templateId): array {
		$template = $this->template(templateId: $templateId);
		$version = (int)($template['version'] ?? 1);
		$reports = [];
		foreach ($this->rowsOf(schema: 'Administration', filters: ['followsTemplateId' => $templateId]) as $follower) {
			if ((int)($follower['followedTemplateVersion'] ?? 0) >= $version) {
				continue;
			}

			$followerId = self::idOf(row: $follower);
			$report = $this->apply(template: $template, administrationId: $followerId, update: true);
			$this->save(schema: 'Administration', row: ['followedTemplateVersion' => $version] + $follower);
			$this->record(templateId: $templateId, template: $template, followerId: $followerId, report: $report);
			$reports[$followerId] = $report;
		}

		return $reports;
	}//end syncFollowers()

	/**
	 * Add what the administration lacks and, when updating, bring the synced
	 * fields of what it has in step, except where it deviates.
	 *
	 * @param array<string, mixed> $template         The template.
	 * @param string               $administrationId The administration.
	 * @param bool                 $update           Whether existing records are updated.
	 *
	 * @return array{added: string[], updated: string[], skipped: string[]}
	 */
	private function apply(array $template, string $administrationId, bool $update): array {
		$report = ['added' => [], 'updated' => [], 'skipped' => []];
		foreach (self::KINDS as $kind => $config) {
			$existing = [];
			foreach ($this->rowsOf(schema: $config['schema'], filters: ['administrationId' => $administrationId]) as $row) {
				$existing[(string)($row[$config['key']] ?? '')] = $row;
			}

			foreach ((array)($template[$kind] ?? []) as $entry) {
				$entry = self::pick(row: (array)$entry, fields: $config['fields']);
				$key = (string)($entry[$config['key']] ?? '');
				if ($key === '') {
					continue;
				}

				$label = $config['schema'] . ':' . $key;
				$current = ($existing[$key] ?? null);
				if ($current === null) {
					$this->save(schema: $config['schema'], row: $entry + ['administrationId' => $administrationId, 'lifecycleState' => 'active']);
					$report['added'][] = $label;
					continue;
				}

				$changes = self::changes(entry: $entry, current: $current);
				if ($update === false || $changes === []) {
					continue;
				}

				if (($current['deviatesFromTemplate'] ?? false) === true) {
					$report['skipped'][] = $label;
					continue;
				}

				$this->save(schema: $config['schema'], row: $changes + $current);
				$report['updated'][] = $label;
			}//end foreach
		}//end foreach

		return $report;
	}//end apply()

	/**
	 * Record a follower's run on an AdministrationMigration of kind template_sync.
	 *
	 * @param string                                                          $templateId The template.
	 * @param array<string, mixed>                                            $template   The template.
	 * @param string                                                          $followerId The follower.
	 * @param array{added: string[], updated: string[], skipped: string[]} $report     What the run did.
	 *
	 * @return void
	 */
	private function record(string $templateId, array $template, string $followerId, array $report): void {
		$date = $this->timeFactory->getDateTime()->format('Y-m-d');
		$this->save(
			schema: 'AdministrationMigration',
			row: [
				'migrationNumber' => 'SYNC-' . $templateId . '-v' . (int)($template['version'] ?? 1) . '-' . $followerId,
				'date' => $date,
				'sourceAdministrationId' => (string)($template['sourceAdministrationId'] ?? ''),
				'destinationAdministrationId' => $followerId,
				'kind' => 'template_sync',
				'templateId' => $templateId,
				'syncReport' => $report,
				'objectIds' => [],
				'status' => 'geboekt_beide',
			]
		);
	}//end record()

	/**
	 * The synced fields whose template value differs from the current one.
	 *
	 * @param array<string, mixed> $entry   The template's record.
	 * @param array<string, mixed> $current The administration's record.
	 *
	 * @return array<string, mixed>
	 */
	private static function changes(array $entry, array $current): array {
		$changes = [];
		foreach (self::SYNCED_FIELDS as $field) {
			if (array_key_exists($field, $entry) === true && ($current[$field] ?? null) !== $entry[$field]) {
				$changes[$field] = $entry[$field];
			}
		}

		return $changes;
	}//end changes()

	/**
	 * A template by id.
	 *
	 * @param string $templateId The template.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When it does not exist.
	 */
	private function template(string $templateId): array {
		$found = null;
		if ($templateId !== '') {
			try {
				$found = $this->binding->schema(schema: self::TEMPLATE_SCHEMA)->find(id: $templateId);
			} catch (\Throwable) {
				$found = null;
			}
		}

		if ($found === null) {
			throw new InvalidArgumentException('Template not found.');
		}

		return (array)$found->jsonSerialize();
	}//end template()

	/**
	 * The rows of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(string $schema, array $filters): array {
		$rows = [];
		foreach ($this->binding->schema(schema: $schema)->findAll(['filters' => $filters]) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Save a row and return it as stored.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The row; with an id it updates that record.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $row): array {
		$id = self::idOf(row: $row);
		unset($row['@self']);
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		return (array)$this->binding->schema(schema: $schema)->saveObject(object: $row, uuid: $uuid)->jsonSerialize();
	}//end save()

	/**
	 * The listed fields of a row that it carries.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param string[]             $fields The fields to keep.
	 *
	 * @return array<string, mixed>
	 */
	private static function pick(array $row, array $fields): array {
		return array_intersect_key($row, array_flip($fields));
	}//end pick()

	/**
	 * A row's id, top level or under `@self`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
