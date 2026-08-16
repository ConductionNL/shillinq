<?php

/**
 * Unit tests for AccountantDashboardService.
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
 * @spec openspec/changes/accountant-portal/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AccountantDashboardService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the per-client status card composition (REQ-ACP-002) and its
 * fail-soft degradation when a signal cannot be resolved.
 */
final class AccountantDashboardServiceTest extends TestCase {
	/**
	 * Build the service with a fake ObjectService over the given rows and a
	 * stub AdministrationContextService returning the given accessible list.
	 *
	 * @param array<int,array<string,mixed>> $administrations Entries as returned by buildContext()['administrations'].
	 * @param array<string,array<int,array<string,mixed>>> $rowsBySchema Rows keyed by schema slug (FiscalPeriod, VATReturn, SupplierInvoice).
	 * @param array<int,array<string,mixed>> $assistantFlags Flags PeriodCloseAssistantService::analyse() should return.
	 *
	 * @return AccountantDashboardService
	 */
	private function buildService(array $administrations, array $rowsBySchema, array $assistantFlags = []): AccountantDashboardService {
		$objectService = new class($rowsBySchema) {

			/**
			 * Rows keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema (set via setSchema()).
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct the fake ObjectService.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Rows keyed by schema.
			 */
			public function __construct(array $data) {
				$this->data = $data;
			}//end __construct()

			/**
			 * Fluent register setter (no-op — tests use a single register).
			 *
			 * @param string $register Ignored.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Active schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows on the active schema matching the filter map.
			 *
			 * @param array<string,mixed> $params Query params (filters honoured for administrationId).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
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

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('accountant-1');
		$context->method('buildContext')->willReturn(
			[
				'userId' => 'accountant-1',
				'administrations' => $administrations,
				'activeAdministrationId' => ($administrations[0]['administrationId'] ?? null),
			]
		);

		$assistant = $this->createMock(PeriodCloseAssistantService::class);
		$assistant->method('analyse')->willReturn($assistantFlags);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new AccountantDashboardService(
			container: $container,
			context: $context,
			assistant: $assistant,
			appConfig: $appConfig,
			logger: new NullLogger(),
		);

	}//end buildService()

	/**
	 * A client mid-close with an unfiled overdue BTW return surfaces both
	 * signals correctly (REQ-ACP-002).
	 *
	 * @return void
	 */
	public function testPeriodCloseAndOverdueVatFilingSurfaced(): void {
		$administrations = [
			['administrationId' => 'adm-werk-001', 'administrationCode' => 'WERK-001', 'name' => 'Werk B.V.', 'role' => 'boekhouder'],
		];
		$rows = [
			'FiscalPeriod' => [
				['periodId' => '2026-Q1', 'administrationId' => 'adm-werk-001', 'state' => 'closing', 'endDate' => '2026-03-31'],
			],
			'BtwAangifte' => [
				[
					'administrationId' => 'adm-werk-001',
					'statusCode' => 'draft',
					'endDate' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d'),
				],
			],
		];

		$service = $this->buildService($administrations, $rows);
		$dashboard = $service->buildDashboard();

		self::assertSame('accountant-1', $dashboard['userId']);
		self::assertCount(1, $dashboard['administrations']);

		$card = $dashboard['administrations'][0];
		self::assertSame('closing', $card['periodClose']['state']);
		self::assertSame('draft', $card['vatFiling']['statusCode']);
		self::assertTrue($card['vatFiling']['overdue']);

	}//end testPeriodCloseAndOverdueVatFilingSurfaced()

	/**
	 * A client with no FiscalPeriod / VATReturn on file degrades gracefully —
	 * the card renders without error and open items is 0 (no period to
	 * analyse against).
	 *
	 * @return void
	 */
	public function testNoRecordsDegradesGracefully(): void {
		$administrations = [
			['administrationId' => 'adm-nieuw-001', 'administrationCode' => 'NIEUW-001', 'name' => 'Nieuw B.V.', 'role' => 'inkijker'],
		];

		$service = $this->buildService($administrations, []);
		$dashboard = $service->buildDashboard();

		$card = $dashboard['administrations'][0];
		self::assertNull($card['periodClose']);
		self::assertNull($card['vatFiling']);
		self::assertSame(0, $card['openItemsCount']);
		self::assertSame([], $card['attentionItems']);
		self::assertSame(0, $card['missingDocuments']);

	}//end testNoRecordsDegradesGracefully()

	/**
	 * Open items / attention flags are reused wholesale from
	 * PeriodCloseAssistantService::analyse() (REQ-ACP-002) — not re-derived.
	 *
	 * @return void
	 */
	public function testOpenItemsReusesCloseAssistantFlags(): void {
		$administrations = [
			['administrationId' => 'adm-werk-001', 'administrationCode' => 'WERK-001', 'name' => 'Werk B.V.', 'role' => 'controller'],
		];
		$rows = [
			'FiscalPeriod' => [
				['periodId' => '2026-Q1', 'administrationId' => 'adm-werk-001', 'state' => 'open', 'endDate' => '2026-03-31'],
			],
		];
		$flags = [
			['id' => 'flag-1', 'severity' => 'warning', 'message' => '2 open AP transactions', 'category' => 'ap'],
		];

		$service = $this->buildService($administrations, $rows, $flags);
		$dashboard = $service->buildDashboard();

		$card = $dashboard['administrations'][0];
		self::assertSame(1, $card['openItemsCount']);
		self::assertSame($flags, $card['attentionItems']);

	}//end testOpenItemsReusesCloseAssistantFlags()

	/**
	 * Missing-document count reflects SupplierInvoice rows with no source
	 * document recorded (REQ-ACP-002).
	 *
	 * @return void
	 */
	public function testMissingDocumentCount(): void {
		$administrations = [
			['administrationId' => 'adm-werk-001', 'administrationCode' => 'WERK-001', 'name' => 'Werk B.V.', 'role' => 'boekhouder'],
		];
		$rows = [
			'SupplierInvoice' => [
				['administrationId' => 'adm-werk-001', 'ublSourceUri' => 'files/inv-1.xml'],
				['administrationId' => 'adm-werk-001', 'ublSourceUri' => ''],
				['administrationId' => 'adm-werk-001'],
				// A different tenant's row must never be counted.
				['administrationId' => 'adm-beheer-001'],
			],
		];

		$service = $this->buildService($administrations, $rows);
		$dashboard = $service->buildDashboard();

		self::assertSame(2, $dashboard['administrations'][0]['missingDocuments']);

	}//end testMissingDocumentCount()

	/**
	 * An anonymous user gets an empty dashboard (REQ-ACP-003 boundary).
	 *
	 * @return void
	 */
	public function testAnonymousGetsEmptyDashboard(): void {
		$container = $this->createMock(ContainerInterface::class);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn(null);

		$assistant = $this->createMock(PeriodCloseAssistantService::class);
		$appConfig = $this->createMock(IAppConfig::class);

		$service = new AccountantDashboardService(
			container: $container,
			context: $context,
			assistant: $assistant,
			appConfig: $appConfig,
			logger: new NullLogger(),
		);

		$dashboard = $service->buildDashboard();
		self::assertNull($dashboard['userId']);
		self::assertSame([], $dashboard['administrations']);

	}//end testAnonymousGetsEmptyDashboard()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
