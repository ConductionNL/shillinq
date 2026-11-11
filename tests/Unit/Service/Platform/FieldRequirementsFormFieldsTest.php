<?php

/**
 * Golden tests for FieldRequirements::formFields: how each field of a schema is marked.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Platform
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Platform;

use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Each field is marked always, administration or no, with its title and label.
 */
class FieldRequirementsFormFieldsTest extends TestCase {

	/**
	 * The service over a store holding the given requirement rows.
	 *
	 * @param list<array<string, mixed>> $rows The requirement rows.
	 *
	 * @return FieldRequirements
	 */
	private function service(array $rows): FieldRequirements {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$schemas = $this->createStub(SchemaDefinitions::class);
		$schemas->method('definition')->willReturnCallback(
			static fn (string $schema): ?array => $schema !== 'Widget' ? null : [
				'slug'       => 'Widget',
				'title'      => 'Widget',
				'properties' => [
					'id'        => ['type' => 'string', 'readOnly' => true],
					'name'      => ['type' => 'string', 'title' => 'Name'],
					'costCenter' => ['type' => 'string', 'title' => 'Cost centre'],
					'project'   => ['type' => 'string', 'title' => ''],
					'both'      => ['type' => 'string'],
					'7'         => ['type' => 'string'],
					'loose'     => 'not-an-array',
					'locked'    => ['readOnly' => 'yes'],
				],
				'required'   => ['name', 'both'],
			]
		);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '['.$text.']');

		$store = new InMemoryObjectServiceStub(['FieldRequirement' => $rows]);

		return new FieldRequirements($store, $settings, $schemas, $l10n);

	}//end service()

	/**
	 * A requirement row.
	 *
	 * @param string $schema The schema slug.
	 * @param mixed  $field  The field.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, mixed $field): array {
		return ['administrationId' => 'adm-1', 'schema' => $schema, 'field' => $field, 'lifecycleState' => 'active'];

	}//end row()

	/**
	 * Every field marked, read-only fields left out.
	 *
	 * @return void
	 */
	public function testMarksEveryWritableField(): void {
		$service = $this->service(
			[
				$this->row(schema: 'widget', field: 'costCenter'),
				$this->row(schema: 'Widget', field: 'both'),
				$this->row(schema: 'Widget', field: '7'),
				$this->row(schema: 'Other', field: 'project'),
				array_merge($this->row(schema: 'Widget', field: 'loose'), ['enabled' => false]),
			]
		);

		$rows = $service->formFields(requirement: ['schema' => ' Widget ', 'administrationId' => 'adm-1']);

		$this->assertSame(
			[
				['field' => 'name', 'title' => 'Name', 'required' => 'always', 'requiredLabel' => '[Always]'],
				['field' => 'costCenter', 'title' => 'Cost centre', 'required' => 'administration', 'requiredLabel' => '[In this administration]'],
				['field' => 'project', 'title' => 'project', 'required' => 'no', 'requiredLabel' => '[No]'],
				['field' => 'both', 'title' => 'both', 'required' => 'always', 'requiredLabel' => '[Always]'],
				['field' => '7', 'title' => '7', 'required' => 'administration', 'requiredLabel' => '[In this administration]'],
				['field' => 'loose', 'title' => 'loose', 'required' => 'no', 'requiredLabel' => '[No]'],
				['field' => 'locked', 'title' => 'locked', 'required' => 'no', 'requiredLabel' => '[No]'],
			],
			$rows
		);

	}//end testMarksEveryWritableField()

	/**
	 * Without an administration only the schema's own required fields are marked.
	 *
	 * @return void
	 */
	public function testWithoutAdministrationOnlySchemaRequiredFieldsAreMarked(): void {
		$rows = $this->service([$this->row(schema: 'Widget', field: 'costCenter')])->formFields(requirement: ['schema' => 'Widget']);

		$this->assertSame(
			['name' => 'always', 'costCenter' => 'no', 'project' => 'no', 'both' => 'always', '7' => 'no', 'loose' => 'no', 'locked' => 'no'],
			array_column($rows, 'required', 'field')
		);

	}//end testWithoutAdministrationOnlySchemaRequiredFieldsAreMarked()

	/**
	 * A requirement whose field is stored as a number does not mark the field.
	 *
	 * @return void
	 */
	public function testNumericRequirementFieldIsComparedStrictly(): void {
		$rows = $this->service([$this->row(schema: 'Widget', field: 7)])->formFields(requirement: ['schema' => 'Widget', 'administrationId' => 'adm-1']);

		$this->assertSame('no', array_column($rows, 'required', 'field')[7]);

	}//end testNumericRequirementFieldIsComparedStrictly()

	/**
	 * An unknown or missing schema has no fields.
	 *
	 * @return void
	 */
	public function testUnknownSchemaHasNoFields(): void {
		$service = $this->service([]);

		$this->assertSame([], $service->formFields(requirement: ['schema' => 'Nope', 'administrationId' => 'adm-1']));
		$this->assertSame([], $service->formFields(requirement: []));

	}//end testUnknownSchemaHasNoFields()
}//end class
