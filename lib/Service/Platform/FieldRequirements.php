<?php

/**
 * FieldRequirements: the fields an administration requires on top of the shipped ones.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Platform
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

namespace OCA\Shillinq\Service\Platform;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\SettingsService;
use OCP\IL10N;

/**
 * Reads the active FieldRequirement records of an administration and checks objects and requirements against them.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */
class FieldRequirements {
	use ReadsSourceRowsInBatches;

	/**
	 * The schema holding one requirement.
	 */
	public const SCHEMA = 'FieldRequirement';

	/**
	 * Active requirements read in this request, by administration.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param SettingsService        $settings      Supplies the register slug.
	 * @param SchemaDefinitions      $schemas       The schemas' properties and shipped required lists.
	 * @param IL10N                  $l10n          Translator.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly SchemaDefinitions $schemas,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The required fields an object of a schema leaves empty, with their titles and reasons.
	 *
	 * @param array{slug: string, title: string, properties: array<string, mixed>, required: list<string>} $definition The object's schema.
	 * @param array<string, mixed>                                                                          $object     The object.
	 *
	 * @return list<array{field: string, title: string, reason: string}> The missing fields.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function missing(array $definition, array $object): array {
		$administrationId = trim((string)($object['administrationId'] ?? ''));
		if ($administrationId === '') {
			return [];
		}

		$missing = [];
		foreach ($this->active(administrationId: $administrationId, schema: $definition['slug']) as $requirement) {
			$field = (string)$requirement['field'];
			if ($this->isEmpty(value: ($object[$field] ?? null)) === false) {
				continue;
			}

			$missing[] = [
				'field'  => $field,
				'title'  => $this->title(definition: $definition, field: $field),
				'reason' => trim((string)($requirement['reason'] ?? '')),
			];
		}

		return $missing;

	}//end missing()

	/**
	 * Why a requirement cannot be saved, by the field to correct; empty when it can.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 *
	 * @return array<string, string> The refusal by field.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function refusal(array $requirement): array {
		$schema = trim((string)($requirement['schema'] ?? ''));
		$field = trim((string)($requirement['field'] ?? ''));
		$definition = $this->schemas->definition(schema: $schema);
		if ($definition === null) {
			return ['schema' => $this->l10n->t('There is no record type %s.', [$schema])];
		}

		if (isset($definition['properties']['administrationId']) === false) {
			return ['schema' => $this->l10n->t('%s is not kept per administration, so a field cannot be required on it.', [$definition['title']])];
		}

		if (isset($definition['properties'][$field]) === false) {
			return ['field' => $this->l10n->t('%1$s has no field %2$s.', [$definition['title'], $field])];
		}

		if (in_array($field, $definition['required'], true) === true) {
			return ['field' => $this->l10n->t('%1$s is always required on %2$s.', [$this->title(definition: $definition, field: $field), $definition['title']])];
		}

		return [];

	}//end refusal()

	/**
	 * The fields of a requirement's schema, each marked always, administration or no.
	 *
	 * @param array<string, mixed> $requirement The requirement.
	 *
	 * @return list<array{field: string, title: string, required: string}> The fields.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function formFields(array $requirement): array {
		$definition = $this->schemas->definition(schema: trim((string)($requirement['schema'] ?? '')));
		if ($definition === null) {
			return [];
		}

		$own = array_column(
			$this->active(administrationId: (string)($requirement['administrationId'] ?? ''), schema: $definition['slug']),
			'field'
		);

		$rows = [];
		foreach ($definition['properties'] as $field => $property) {
			if (is_array($property) === true && ($property['readOnly'] ?? false) === true) {
				continue;
			}

			$required = 'no';
			if (in_array((string)$field, $own, true) === true) {
				$required = 'administration';
			}

			if (in_array((string)$field, $definition['required'], true) === true) {
				$required = 'always';
			}

			$rows[] = [
				'field'    => (string)$field,
				'title'    => $this->title(definition: $definition, field: (string)$field),
				'required' => $required,
			];
		}//end foreach

		return $rows;

	}//end formFields()

	/**
	 * One requirement by id, null when absent.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The requirement.
	 *
	 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
	 */
	public function find(string $id): ?array {
		$found = $this->objectService->find(
			id: $id,
			register: $this->settings->getRegisterSlug(),
			schema: self::SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		if ($found === null) {
			return null;
		}

		return $this->rowPayload(row: $found);

	}//end find()

	/**
	 * The active requirements of an administration on a schema.
	 *
	 * @param string $administrationId The administration.
	 * @param string $schema           The schema slug.
	 *
	 * @return list<array<string, mixed>> The requirements.
	 */
	private function active(string $administrationId, string $schema): array {
		if ($administrationId === '') {
			return [];
		}

		if (isset($this->cache[$administrationId]) === false) {
			$rows = $this->readAllRows(
				objectService: $this->objectService,
				registerSlug: $this->settings->getRegisterSlug(),
				schema: self::SCHEMA,
				filters: ['administrationId' => $administrationId, 'lifecycleState' => 'active']
			);
			$this->cache[$administrationId] = array_map(fn (mixed $row): array => $this->rowPayload(row: $row), $rows);
		}

		return array_values(
			array_filter(
				$this->cache[$administrationId],
				static fn (array $row): bool => strcasecmp((string)($row['schema'] ?? ''), $schema) === 0 && (string)($row['field'] ?? '') !== ''
			)
		);

	}//end active()

	/**
	 * A field's title from its schema, the field name when it has none.
	 *
	 * @param array{slug: string, title: string, properties: array<string, mixed>, required: list<string>} $definition The schema.
	 * @param string                                                                                        $field      The field.
	 *
	 * @return string The title.
	 */
	private function title(array $definition, string $field): string {
		$property = ($definition['properties'][$field] ?? []);
		if (is_array($property) === true && is_string($property['title'] ?? null) === true && $property['title'] !== '') {
			return $property['title'];
		}

		return $field;

	}//end title()

	/**
	 * Whether a value counts as not filled in: absent, null, blank text or an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when empty.
	 */
	private function isEmpty(mixed $value): bool {
		if (is_string($value) === true) {
			return trim($value) === '';
		}

		return $value === null || $value === [];

	}//end isEmpty()
}//end class
