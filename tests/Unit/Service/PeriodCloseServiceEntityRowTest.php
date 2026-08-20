<?php

/**
 * Regression guard: PeriodCloseService must accept ObjectEntity rows.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-period-close/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PeriodCloseService;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * OpenRegister's `ObjectService::findAll()` yields `ObjectEntity` instances,
 * not arrays. `PeriodCloseService::find()` did `(array) $found[0]` on them, and
 * PHP's object-to-array cast does NOT produce the record — it produces the
 * entity's own NUL-byte-prefixed private properties. Every field the service
 * then read (`periodId`, `state`, `administrationId`) came back missing.
 *
 * Measured on a live Nextcloud 32 + OpenRegister instance against a stored
 * period whose state IS `open`:
 *
 *   GET  /apps/shillinq/api/period-close/2026-08   → 200 with `data.id: null`
 *                                                    and no `periodId`
 *   POST /apps/shillinq/api/period-close/2026-08/start-close
 *                                                  → 409 "Period is not in the
 *                                                    required state (open)"
 *
 * The pre-existing PeriodCloseServiceTest could not catch this: its fake
 * `findAll()` returns plain arrays, so it is MORE PERMISSIVE than the
 * collaborator it stands in for and the suite stayed green against a contract
 * OpenRegister does not honour. This guard's fake returns entity objects with
 * `jsonSerialize()`, matching the real signature.
 *
 * @spec openspec/specs/bookkeeping-period-close/spec.md
 */
final class PeriodCloseServiceEntityRowTest extends TestCase {

	/**
	 * getPeriodForClose() returns the record's own fields, not the entity's internals.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-period-close/spec.md
	 */
	public function testGetPeriodForCloseReadsTheRecordOffAnEntity(): void {
		$service = $this->buildService(
			[
				'periodId' => '2026-04',
				'name' => 'April 2026',
				'administrationId' => 'adm-smb-1',
				'state' => 'open',
			]
		);

		$record = $service->getPeriodForClose(periodId: '2026-04', administrationId: 'adm-smb-1');

		self::assertIsArray($record);
		self::assertSame('2026-04', $record['periodId'] ?? null, 'periodId must survive the entity → array step.');
		self::assertSame('open', $record['state'] ?? null, 'state must survive the entity → array step.');
		self::assertSame('adm-smb-1', $record['administrationId'] ?? null);

	}//end testGetPeriodForCloseReadsTheRecordOffAnEntity()

	/**
	 * startClose() advances an `open` entity-backed period instead of 409-ing.
	 *
	 * The state check is what the broken cast actually broke: `requireState()`
	 * read a missing `state` and rejected a period that was genuinely open.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-period-close/spec.md
	 */
	public function testStartCloseAdvancesAnOpenEntityBackedPeriod(): void {
		$service = $this->buildService(
			[
				'periodId' => '2026-04',
				'administrationId' => 'adm-smb-1',
				'state' => 'open',
			]
		);

		$updated = $service->startClose(
			periodId: '2026-04',
			administrationId: 'adm-smb-1',
			userId: 'admin'
		);

		self::assertSame('closing', $updated['state'] ?? null);

	}//end testStartCloseAdvancesAnOpenEntityBackedPeriod()

	/**
	 * A period that does not exist is still reported as absent.
	 *
	 * Positive control: proves the two assertions above are not passing simply
	 * because the fake answers every lookup.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-period-close/spec.md
	 */
	public function testUnknownPeriodIsStillNull(): void {
		$service = $this->buildService(
			[
				'periodId' => '2026-04',
				'administrationId' => 'adm-smb-1',
				'state' => 'open',
			]
		);

		self::assertNull(
			$service->getPeriodForClose(periodId: '1999-01', administrationId: 'adm-smb-1')
		);

	}//end testUnknownPeriodIsStillNull()

	/**
	 * Build the service over an ObjectService whose findAll() yields entities.
	 *
	 * @param array<string,mixed> $record The single seeded FiscalPeriod record.
	 *
	 * @return PeriodCloseService
	 */
	private function buildService(array $record): PeriodCloseService {
		$objectService = new class($record) {

			/**
			 * The seeded record.
			 *
			 * @var array<string,mixed>
			 */
			private array $record;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $record The seeded record.
			 */
			public function __construct(array $record) {
				$this->record = $record;
			}//end __construct()

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter (no-op).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return matching rows as ENTITIES, exactly as OpenRegister does.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,object>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				foreach ($filters as $key => $value) {
					$field = ($key === 'id') ? 'periodId' : $key;
					if (($this->record[$field] ?? null) !== $value) {
						return [];
					}
				}

				return [$this->wrap(row: $this->record)];
			}//end findAll()

			/**
			 * Accept a save (no-op) and echo the payload back.
			 *
			 * @param array<string,mixed> $object The object.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				return $object;
			}//end saveObject()

			/**
			 * Wrap a row in an ObjectEntity-shaped object.
			 *
			 * @param array<string,mixed> $row The row.
			 *
			 * @return object
			 */
			private function wrap(array $row): object {
				return new class($row) implements \JsonSerializable {
					/**
					 * The wrapped row.
					 *
					 * @var array<string,mixed>
					 */
					private array $row;

					/**
					 * Constructor.
					 *
					 * @param array<string,mixed> $row The row.
					 */
					public function __construct(array $row) {
						$this->row = $row;
					}//end __construct()

					/**
					 * Render the row, as OpenRegister's ObjectEntity does.
					 *
					 * @return array<string,mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}//end jsonSerialize()
				};
			}//end wrap()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('hasUnresolvedItems')->willReturn(false);
		$ageing->method('agedUnmatchedItems')->willReturn(
			[
				'items' => [],
				'count' => 0,
				'oldestDaysOutstanding' => 0,
				'totalAmountCents' => 0,
			]
		);

		return new PeriodCloseService(
			appConfig: $appConfig,
			groupManager: $groupManager,
			suspenseAgeing: $ageing,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter(inner: $objectService),
		);

	}//end buildService()
}//end class
