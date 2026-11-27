<?php

/**
 * Unit tests for how FieldRequirements reads requirements back: find() by id,
 * listed() per administration with its titles and defaults, and the refusal
 * for a record type that is not kept per administration.
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
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FieldRequirements' reads.
 */
#[CoversClass(FieldRequirements::class)]
#[UsesClass(ObjectIdentifier::class)]
final class FieldRequirementsReadTest extends TestCase {

	/**
	 * The service over stored requirement rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Stored FieldRequirement rows.
	 *
	 * @return FieldRequirements
	 */
	private function service(array $rows): FieldRequirements {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$schemas = $this->createStub(SchemaDefinitions::class);
		$schemas->method('definition')->willReturnCallback(
			static fn (string $schema): ?array => match ($schema) {
				'Invoice' => [
					'slug'       => 'Invoice',
					'title'      => 'Invoice',
					'properties' => [
						'administrationId' => ['type' => 'string'],
						'costCenter'       => ['type' => 'string', 'title' => 'Cost centre'],
					],
					'required'   => [],
				],
				'Currency' => [
					'slug'       => 'Currency',
					'title'      => 'Currency',
					'properties' => ['code' => ['type' => 'string']],
					'required'   => [],
				],
				default => null,
			}
		);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new FieldRequirements(new InMemoryObjectServiceStub(['FieldRequirement' => $rows]), $settings, $schemas, $l10n);
	}//end service()

	/**
	 * A requirement row.
	 *
	 * @param string               $id     Its id.
	 * @param string               $admin  Its administration.
	 * @param array<string, mixed> $extra  More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $id, string $admin, array $extra = []): array {
		return array_merge(['id' => $id, 'administrationId' => $admin, 'schema' => 'Invoice', 'field' => 'costCenter', 'lifecycleState' => 'active'], $extra);
	}//end row()

	/**
	 * A type without an administration cannot carry a required field.
	 *
	 * @return void
	 */
	public function testATypeNotKeptPerAdministrationIsRefused(): void {
		$this->assertSame(
			['schema' => 'Currency is not kept per administration, so a field cannot be required on it.'],
			$this->service([])->refusal(['schema' => 'Currency', 'field' => 'code'])
		);
		$this->assertSame(
			['schema' => 'There is no record type Nope.'],
			$this->service([])->refusal(['schema' => ' Nope ', 'field' => 'code'])
		);
	}//end testATypeNotKeptPerAdministrationIsRefused()

	/**
	 * find() answers the stored requirement, or null for an unknown id.
	 *
	 * @return void
	 */
	public function testFindAnswersTheStoredRequirement(): void {
		$service = $this->service([$this->row('fr-1', 'adm-1', ['enabled' => false])]);

		$found = $service->find('fr-1');
		$this->assertSame('fr-1', $found['id']);
		$this->assertSame('costCenter', $found['field']);
		$this->assertFalse($found['enabled']);
		$this->assertNull($service->find('fr-404'));
	}//end testFindAnswersTheStoredRequirement()

	/**
	 * listed() answers each administration's requirements once, titled
	 * by field and type, enabled unless switched off.
	 *
	 * @return void
	 */
	public function testListedTitlesAndDefaultsEachRequirement(): void {
		$listed = $this->service(
			[
				$this->row('fr-1', 'adm-1'),
				$this->row('fr-2', 'adm-1', ['enabled' => false]),
				$this->row('fr-3', 'adm-1', ['schema' => 'Gone', 'field' => 'ref']),
				$this->row('fr-4', 'adm-2'),
			]
		)->listed(['adm-1', 'adm-1', '', 'adm-9']);

		$this->assertSame(['fr-1', 'fr-2', 'fr-3'], array_column($listed, 'id'));
		$this->assertSame([true, false, true], array_column($listed, 'enabled'));
		$this->assertSame(['Cost centre on Invoice', 'Cost centre on Invoice', 'ref on Gone'], array_column($listed, 'title'));
	}//end testListedTitlesAndDefaultsEachRequirement()
}//end class
