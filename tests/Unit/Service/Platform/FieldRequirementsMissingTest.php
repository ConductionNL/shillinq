<?php

/**
 * Unit tests for FieldRequirements::missing() and the remaining refusal()
 * outcomes: which active requirements an object leaves empty, with titles and
 * reasons, and why a requirement cannot be saved on a field.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Platform;

use OCA\Shillinq\Service\Platform\FieldRequirements;
use OCA\Shillinq\Service\Platform\SchemaDefinitions;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Missing required fields and requirement refusals.
 */
#[CoversClass(FieldRequirements::class)]
final class FieldRequirementsMissingTest extends TestCase {

	/**
	 * The Invoice definition used throughout.
	 *
	 * @return array{slug: string, title: string, properties: array<string, mixed>, required: list<string>}
	 */
	private static function invoice(): array {
		return [
			'slug'       => 'Invoice',
			'title'      => 'Sales invoice',
			'properties' => [
				'administrationId' => ['type' => 'string'],
				'costCenter'       => ['type' => 'string', 'title' => 'Cost centre'],
				'project'          => ['type' => 'string', 'title' => ''],
				'tags'             => ['type' => 'array'],
				'reference'        => ['type' => 'string', 'title' => 'Reference'],
				'customerId'       => ['type' => 'string', 'title' => 'Customer'],
			],
			'required'   => ['customerId'],
		];

	}//end invoice()

	/**
	 * Build the service over stored requirement rows.
	 *
	 * @param list<array<string, mixed>> $rows Stored FieldRequirement rows
	 *
	 * @return FieldRequirements
	 */
	private function service(array $rows): FieldRequirements {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$schemas = $this->createStub(SchemaDefinitions::class);
		$schemas->method('definition')->willReturnCallback(
			static fn (string $schema): ?array => ($schema === 'Invoice') ? self::invoice() : null
		);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new FieldRequirements(new InMemoryObjectServiceStub(['FieldRequirement' => $rows]), $settings, $schemas, $l10n);

	}//end service()

	/**
	 * A stored requirement row.
	 *
	 * @param string $field The field
	 * @param array<string, mixed> $extra Overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function requirement(string $field, array $extra = []): array {
		return array_merge(
			['id' => 'fr-' . $field, 'administrationId' => 'adm-1', 'schema' => 'Invoice', 'field' => $field, 'lifecycleState' => 'active'],
			$extra
		);

	}//end requirement()

	/**
	 * Empty values are reported with their title and trimmed reason; filled ones are not.
	 *
	 * @return void
	 */
	public function testMissingListsEmptyRequiredFields(): void {
		$service = $this->service(
			[
				self::requirement('costCenter', ['reason' => '  Needed for the cost report. ']),
				self::requirement('project'),
				self::requirement('tags'),
				self::requirement('reference'),
				self::requirement('unknownField'),
			]
		);

		$missing = $service->missing(
			self::invoice(),
			['administrationId' => ' adm-1 ', 'costCenter' => '   ', 'tags' => [], 'reference' => 'R-1', 'project' => 0]
		);

		$this->assertSame(
			[
				['field' => 'costCenter', 'title' => 'Cost centre', 'reason' => 'Needed for the cost report.'],
				['field' => 'tags', 'title' => 'tags', 'reason' => ''],
				['field' => 'unknownField', 'title' => 'unknownField', 'reason' => ''],
			],
			$missing
		);

	}//end testMissingListsEmptyRequiredFields()

	/**
	 * An absent or null value is missing; a field without a title is named by its key.
	 *
	 * @return void
	 */
	public function testAbsentAndNullValuesAreMissing(): void {
		$service = $this->service([self::requirement('project'), self::requirement('reference')]);

		$this->assertSame(
			[
				['field' => 'project', 'title' => 'project', 'reason' => ''],
				['field' => 'reference', 'title' => 'Reference', 'reason' => ''],
			],
			$service->missing(self::invoice(), ['administrationId' => 'adm-1', 'reference' => null])
		);

	}//end testAbsentAndNullValuesAreMissing()

	/**
	 * Only enabled, active requirements of this administration and schema (any case) with a field apply.
	 *
	 * @return void
	 */
	public function testOnlyApplicableRequirementsCount(): void {
		$service = $this->service(
			[
				self::requirement('costCenter', ['schema' => 'invoice']),
				self::requirement('reference', ['enabled' => false]),
				self::requirement('project', ['administrationId' => 'adm-2']),
				self::requirement('tags', ['lifecycleState' => 'retired']),
				self::requirement('customerId', ['schema' => 'Order']),
				self::requirement(''),
			]
		);

		$this->assertSame(
			['costCenter'],
			array_column($service->missing(self::invoice(), ['administrationId' => 'adm-1']), 'field')
		);

	}//end testOnlyApplicableRequirementsCount()

	/**
	 * An object without an administration has nothing missing.
	 *
	 * @return void
	 */
	public function testNoAdministrationMeansNothingMissing(): void {
		$service = $this->service([self::requirement('costCenter')]);

		$this->assertSame([], $service->missing(self::invoice(), ['administrationId' => '  ']));
		$this->assertSame([], $service->missing(self::invoice(), []));

	}//end testNoAdministrationMeansNothingMissing()

	/**
	 * Requirements apply per administration, also on later objects checked by the same instance.
	 *
	 * @return void
	 */
	public function testRequirementsApplyPerAdministration(): void {
		$service = $this->service([self::requirement('costCenter')]);

		$this->assertCount(1, $service->missing(self::invoice(), ['administrationId' => 'adm-1']));
		$this->assertSame([], $service->missing(self::invoice(), ['administrationId' => 'adm-1', 'costCenter' => 'CC-1']));
		$this->assertSame([], $service->missing(self::invoice(), ['administrationId' => 'adm-2']));

	}//end testRequirementsApplyPerAdministration()

	/**
	 * A field the schema does not have is refused on the field.
	 *
	 * @return void
	 */
	public function testRefusalForUnknownField(): void {
		$this->assertSame(
			['field' => 'Sales invoice has no field colour.'],
			$this->service([])->refusal(['schema' => 'Invoice', 'field' => ' colour '])
		);

	}//end testRefusalForUnknownField()

	/**
	 * A field the schema always requires is refused with its title.
	 *
	 * @return void
	 */
	public function testRefusalForAlwaysRequiredField(): void {
		$this->assertSame(
			['field' => 'Customer is always required on Sales invoice.'],
			$this->service([])->refusal(['schema' => 'Invoice', 'field' => 'customerId'])
		);

	}//end testRefusalForAlwaysRequiredField()

	/**
	 * An optional field of an administration-kept schema can be required.
	 *
	 * @return void
	 */
	public function testRefusalIsEmptyForARequirableField(): void {
		$this->assertSame([], $this->service([])->refusal(['schema' => 'Invoice', 'field' => 'costCenter']));

	}//end testRefusalIsEmptyForARequirableField()
}//end class
