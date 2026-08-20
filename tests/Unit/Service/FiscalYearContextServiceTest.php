<?php

/**
 * Unit tests for FiscalYearContextService.
 *
 * Covers REQ-BBVW-006 (fiscal-year scoping) and the slice-09 IDOR mask:
 * the active fiscal year is inherited from the Administration record's
 * fiscalYearStartMonth/Day; cross-tenant administrationId requests return
 * null so the caller can mask the response without leaking the existence
 * of the other tenant's data.
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-09-fiscal-audit/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies fiscal-year resolution + cross-tenant IDOR mask.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FiscalYearContextServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock administration context service.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service with an ObjectService stub backed by the given records.
	 *
	 * @param array<int,array<string,mixed>> $administrations Administration records.
	 *
	 * @return FiscalYearContextService
	 */
	private function buildService(array $administrations): FiscalYearContextService {
		$stub = new class($administrations) {

			/**
			 * Administrations data set.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $administrations;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $administrations Records.
			 */
			public function __construct(array $administrations) {
				$this->administrations = $administrations;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Single-object lookup.
			 *
			 * ⚠️ THROWS on a miss — it does not return null. Real
			 * ObjectService raises DoesNotExistException for anything that is
			 * not a resolvable uuid, so a caller that wants a fallback has to
			 * wrap this in its own try/catch. A double that returned null here
			 * would let a missing catch block pass in tests and blow up in
			 * production.
			 *
			 * @param string $id Object uuid.
			 *
			 * @return array<string,mixed>
			 *
			 * @throws DoesNotExistException When no object matches.
			 */
			public function find(string $id): array {
				foreach ($this->administrations as $row) {
					if (($row['uuid'] ?? null) === $id) {
						return $row;
					}
				}

				throw new DoesNotExistException(
					sprintf("Object with identifier '%s' not found in any magic table", $id)
				);
			}//end find()

			/**
			 * FindAll on Administration with simple equality filters.
			 *
			 * ⚠️ `filters` addresses the object's JSON PROPERTIES only.
			 *
			 * The ObjectEntity's `id` is the entity's own column — it is merged
			 * into the serialised output but is NOT something `filters` can
			 * match, so real OpenRegister returns ZERO rows for
			 * `['filters' => ['id' => ...]]` at every value, silently and
			 * without raising.
			 *
			 * This double used to match `id` like any other array key, which
			 * made it blind to exactly the bug it was covering: the service
			 * filtered on `id`, always got nothing back, and reported a null
			 * fiscal-year window — while these tests stayed green. The `id`
			 * rejection below is what gives them the power to fail.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema !== 'Administration') {
					return [];
				}

				$rows = $this->administrations;
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				// Mirror the real engine: a filter on a non-property matches
				// nothing at all, rather than falling back to the entity column.
				if (array_key_exists('id', $filters) === true) {
					return [];
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		$this->container->method('get')->willReturn($stub);

		return new FiscalYearContextService(
			container: $this->container,
			administrationContext: $this->administrationContext,
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildService()

	/**
	 * A calendar-year administration resolves the calendar year as its
	 * active fiscal year.
	 *
	 * @return void
	 */
	public function testCalendarYearAdministrationResolvesCurrentCalendarYear(): void {
		$this->administrationContext->method('canAccess')->willReturn(true);

		$admins = [
			[
				// `administrationCode` is the JSON property the lookup can
				// actually filter on; `id` is the entity column, kept here so
				// the fixture still mirrors a real serialised record.
				'id' => 'adm-werk-001',
				'administrationCode' => 'adm-werk-001',
				'fiscalYearStartMonth' => 1,
				'fiscalYearStartDay' => 1,
			],
		];
		$service = $this->buildService($admins);

		$window = $service->resolveActiveWindow(
			'adm-werk-001',
			new DateTimeImmutable('2026-06-15T00:00:00Z', new DateTimeZone('UTC'))
		);
		self::assertNotNull($window);
		self::assertSame(2026, $window['fiscalYear']);
		self::assertSame('2026-01-01', $window['startDate']);
		self::assertSame('2027-01-01', $window['endDate']);
		self::assertSame('adm-werk-001', $window['administrationId']);

	}//end testCalendarYearAdministrationResolvesCurrentCalendarYear()

	/**
	 * A July-1-start non-calendar boekjaar rolls over on July 1; July
	 * onwards belongs to the NEXT calendar year's FY label (matches the
	 * "FY 2026" convention where the FY ends in calendar 2026).
	 *
	 * @return void
	 */
	public function testNonCalendarBoekjaarRollsOverOnStartDate(): void {
		$this->administrationContext->method('canAccess')->willReturn(true);

		$admins = [
			[
				// See the note on the adm-werk-001 fixture above.
				'id' => 'adm-waterschap-1',
				'administrationCode' => 'adm-waterschap-1',
				'fiscalYearStartMonth' => 7,
				'fiscalYearStartDay' => 1,
			],
		];
		$service = $this->buildService($admins);

		// Before rollover: June 30 2026 → FY ends in 2026 (started July 2025).
		$windowJune = $service->resolveActiveWindow(
			'adm-waterschap-1',
			new DateTimeImmutable('2026-06-30T00:00:00Z', new DateTimeZone('UTC'))
		);
		self::assertNotNull($windowJune);
		self::assertSame(2026, $windowJune['fiscalYear']);
		self::assertSame('2025-07-01', $windowJune['startDate']);
		self::assertSame('2026-07-01', $windowJune['endDate']);

		// On rollover: July 1 2026 → FY ends in 2027 (started July 2026).
		$windowJuly = $service->resolveActiveWindow(
			'adm-waterschap-1',
			new DateTimeImmutable('2026-07-01T00:00:00Z', new DateTimeZone('UTC'))
		);
		self::assertNotNull($windowJuly);
		self::assertSame(2027, $windowJuly['fiscalYear']);
		self::assertSame('2026-07-01', $windowJuly['startDate']);
		self::assertSame('2027-07-01', $windowJuly['endDate']);

	}//end testNonCalendarBoekjaarRollsOverOnStartDate()

	/**
	 * A user without access to the administration receives null —
	 * the caller masks the response as 404 (REQ-MA-001 / ADR-005).
	 *
	 * @return void
	 */
	public function testCrossTenantRequestIsMaskedAsNull(): void {
		$this->administrationContext->method('canAccess')->willReturn(false);

		$admins = [
			[
				// `administrationCode` is the JSON property the lookup can
				// actually filter on; `id` is the entity column, kept here so
				// the fixture still mirrors a real serialised record.
				'id' => 'adm-werk-001',
				'administrationCode' => 'adm-werk-001',
				'fiscalYearStartMonth' => 1,
				'fiscalYearStartDay' => 1,
			],
		];
		$service = $this->buildService($admins);

		$window = $service->resolveActiveWindow('adm-werk-001');
		self::assertNull($window, 'Cross-tenant requests MUST resolve to null.');

	}//end testCrossTenantRequestIsMaskedAsNull()

	/**
	 * An administration that cannot be loaded yields null (the caller
	 * treats this as "no data" — empty envelope).
	 *
	 * @return void
	 */
	public function testUnknownAdministrationResolvesToNull(): void {
		$this->administrationContext->method('canAccess')->willReturn(true);
		$service = $this->buildService([]);

		self::assertNull($service->resolveActiveWindow('adm-missing'));

	}//end testUnknownAdministrationResolvesToNull()

	/**
	 * An administration that exists ONLY under its administrationCode still
	 * resolves — i.e. the lookup does not depend on `filters.id`.
	 *
	 * This is the regression guard for the silent-null bug. The service used
	 * to query `['filters' => ['id' => $administrationId]]`, which real
	 * OpenRegister answers with zero rows for every value because `id` is the
	 * entity's own column and `filters` addresses JSON properties only. The
	 * lookup therefore returned null for an administration that plainly
	 * existed, `resolveActiveWindow()` handed back null, and the BBV dashboard
	 * rendered without its fiscal-year label — no exception, no log, nothing
	 * that looked like a failure.
	 *
	 * The fixture below deliberately carries NO `id` key at all, so any future
	 * rewrite that reaches for `filters.id` fails here instead of shipping.
	 *
	 * @return void
	 */
	public function testAdministrationResolvesWithoutAnIdProperty(): void {
		$this->administrationContext->method('canAccess')->willReturn(true);

		$admins = [
			[
				'administrationCode' => 'ADM-001',
				'fiscalYearStartMonth' => 1,
				'fiscalYearStartDay' => 1,
			],
		];
		$service = $this->buildService($admins);

		$window = $service->resolveActiveWindow(
			'ADM-001',
			new DateTimeImmutable('2026-06-15T00:00:00Z', new DateTimeZone('UTC'))
		);

		self::assertNotNull($window, 'the administration must resolve by its code');
		self::assertSame(2026, $window['fiscalYear']);
		self::assertSame('ADM-001', $window['administrationId']);

	}//end testAdministrationResolvesWithoutAnIdProperty()

	/**
	 * An empty administrationId is rejected up-front.
	 *
	 * @return void
	 */
	public function testEmptyAdministrationIdIsRejected(): void {
		$service = $this->buildService([]);
		self::assertNull($service->resolveActiveWindow(''));

	}//end testEmptyAdministrationIdIsRejected()

	/**
	 * ResolveDefaultWindow falls back to the session's active admin.
	 *
	 * @return void
	 */
	public function testResolveDefaultWindowFollowsSessionContext(): void {
		$this->administrationContext->method('buildContext')->willReturn(
			[
				'userId' => 'controller',
				'administrations' => [],
				'activeAdministrationId' => 'adm-waterschap-1',
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$admins = [
			[
				// See the note on the adm-werk-001 fixture above.
				'id' => 'adm-waterschap-1',
				'administrationCode' => 'adm-waterschap-1',
				'fiscalYearStartMonth' => 1,
				'fiscalYearStartDay' => 1,
			],
		];
		$service = $this->buildService($admins);

		$window = $service->resolveDefaultWindow(
			new DateTimeImmutable('2026-03-01T00:00:00Z', new DateTimeZone('UTC'))
		);
		self::assertNotNull($window);
		self::assertSame(2026, $window['fiscalYear']);
		self::assertSame('adm-waterschap-1', $window['administrationId']);

	}//end testResolveDefaultWindowFollowsSessionContext()

	/**
	 * ResolveDefaultWindow returns null when the session has no active admin.
	 *
	 * @return void
	 */
	public function testResolveDefaultWindowNullWhenNoSession(): void {
		$this->administrationContext->method('buildContext')->willReturn(
			[
				'userId' => null,
				'administrations' => [],
				'activeAdministrationId' => null,
			]
		);

		$service = $this->buildService([]);
		self::assertNull($service->resolveDefaultWindow());

	}//end testResolveDefaultWindowNullWhenNoSession()
}//end class
