<?php

/**
 * Golden tests for BBVComplianceWidget::buildEnvelope: lookups, row shapes, counts and summary.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Dashboard;

use OCA\Shillinq\Dashboard\BBVComplianceWidget;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\SettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the envelope and every lookup behind it.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BBVComplianceWidgetGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build the envelope once.
	 *
	 * @param array<string,mixed> $setup or (bool), rows (per schema, or Throwable), envelopes (per cache key).
	 * @param int|null            $fy    Fiscal year.
	 * @param string|null         $admin Administration.
	 *
	 * @return array<string,mixed>
	 */
	private function envelope(array $setup, ?int $fy=null, ?string $admin=null): array {
		$store = new class($this, ($setup['rows'] ?? [])) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param BBVComplianceWidgetGoldenTest $test Recorder.
			 * @param array<string,mixed>           $rows Rows per schema.
			 */
			public function __construct(private BBVComplianceWidgetGoldenTest $test, private array $rows) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int|string,mixed>
			 */
			public function findAll(array $params): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$rows = ($this->rows[$this->schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(($setup['or'] ?? true));
		$settings->method('getRegisterSlug')->willReturn('reg-g');
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use ($setup): mixed {
				$this->calls[] = ['cache', $key];
				return ($setup['envelopes'][$key] ?? null);
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$cacheFactory->method('createLocal')->willReturn($cache);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		$widget = new BBVComplianceWidget($container, $settings, new ComplianceService($container, $settings, $cacheFactory, $logger), $logger);

		return $widget->buildEnvelope(fiscalYear: $fy, administrationId: $admin);
	}//end envelope()

	/**
	 * An envelope as the cache would answer it.
	 *
	 * @param string    $status  Status.
	 * @param int|float $budget  Budget.
	 * @param int|float $ytd     Year-to-date spend.
	 *
	 * @return array<string,mixed>
	 */
	private static function cached(string $status, int|float $budget, int|float $ytd): array {
		return ['status' => $status, 'budget' => $budget, 'ytdSpend' => $ytd, 'utilization' => 0.5];
	}//end cached()

	/**
	 * Rows, counts, summary and lookups for a scoped fiscal year.
	 *
	 * @return void
	 */
	public function testScopedEnvelope(): void {
		$entity = new class {
			/**
			 * Payload.
			 *
			 * @return array<string,string>
			 */
			public function getObject(): array {
				return ['programmeCode' => 'P2', 'name' => 'Two'];
			}//end getObject()
		};
		$fallback = new class implements \JsonSerializable {
			/**
			 * Not an array.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'x';
			}//end getObject()

			/**
			 * Payload.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['programmeCode' => 'P3', 'totalBudget' => 'own'];
			}//end jsonSerialize()
		};
		$setup = [
			'rows' => [
				'BBVProgramme' => [5 => ['programmeCode' => 'P1'], 9 => $entity, 'k' => $fallback, 'scalar'],
				'BudgetBBVMapping' => [['m' => 1], ['m' => 2]],
			],
			'envelopes' => [
				'shillinq-bbv-compliance:P1:fy:2026' => self::cached(status: 'on-track', budget: 100, ytd: 40),
				'shillinq-bbv-compliance:P2:fy:2026' => self::cached(status: 'weird', budget: 50, ytd: 60),
				'shillinq-bbv-compliance:P3:fy:2026' => self::cached(status: 'on-track', budget: 50, ytd: 0),
			],
		];
		$out = $this->envelope(setup: $setup, fy: 2026, admin: 'adm-1');
		self::assertSame(expected: ['widgets', 'programmes', 'mappings', 'counts', 'summary', 'generatedAt'], actual: array_keys($out));
		self::assertSame(expected: ['bbv-kpis', 'bbv-pie', 'bbv-trend', 'bbv-table'], actual: array_column($out['widgets'], 'id'));
		self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', string: $out['generatedAt']);
		self::assertSame(
			expected: [
				['programmeCode' => 'P1', 'totalBudget' => 100, 'ytdSpend' => 40, 'utilization' => 0.5, 'complianceStatus' => 'on-track'],
				['programmeCode' => 'P2', 'name' => 'Two', 'totalBudget' => 50, 'ytdSpend' => 60, 'utilization' => 0.5, 'complianceStatus' => 'weird'],
				['programmeCode' => 'P3', 'totalBudget' => 'own', 'ytdSpend' => 0, 'utilization' => 0.5, 'complianceStatus' => 'on-track'],
				['totalBudget' => 0, 'ytdSpend' => 0, 'utilization' => 0.0, 'complianceStatus' => 'unconfigured'],
			],
			actual: $out['programmes']
		);
		self::assertSame(expected: [['m' => 1], ['m' => 2]], actual: $out['mappings']);
		$actual = $out['counts'];
		self::assertSame(expected: ['unconfigured' => 1, 'on-track' => 2, 'at-risk' => 0, 'non-compliant' => 0, 'weird' => 1], actual: $actual);
		self::assertSame(
			expected: ['programmeCount' => 4, 'mappingCount' => 2, 'totalBudget' => 200, 'totalYtdSpend' => 100, 'utilization' => 0.5],
			actual: $out['summary']
		);
		self::assertSame(
			expected: [
				['findAll', 'BBVProgramme', ['filters' => ['status' => 'active', 'fiscalYear' => 2026, 'administrationId' => 'adm-1'], 'limit' => 1000]],
				['findAll', 'BudgetBBVMapping', ['filters' => ['administrationId' => 'adm-1'], 'limit' => 1000]],
			],
			actual: array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'findAll'))
		);
	}//end testScopedEnvelope()

	/**
	 * Without scope no filter is added; a blank administration is ignored; zero budget means zero utilization.
	 *
	 * @return void
	 */
	public function testUnscopedAndEmpty(): void {
		$out = $this->envelope(setup: [], admin: '');
		self::assertSame(expected: [], actual: $out['programmes']);
		$actual = $out['summary'];
		$expected = ['programmeCount' => 0, 'mappingCount' => 0, 'totalBudget' => 0, 'totalYtdSpend' => 0, 'utilization' => 0.0];
		self::assertSame(expected: $expected, actual: $actual);
		self::assertSame(
			expected: [
				['setRegister', 'reg-g'],
				['findAll', 'BBVProgramme', ['filters' => ['status' => 'active'], 'limit' => 1000]],
				['setRegister', 'reg-g'],
				['findAll', 'BudgetBBVMapping', ['filters' => [], 'limit' => 1000]],
			],
			actual: $this->calls
		);
	}//end testUnscopedAndEmpty()

	/**
	 * Without OpenRegister nothing is looked up; a failing lookup is logged with its schema and reads as empty.
	 *
	 * @return void
	 */
	public function testUnavailableAndFailing(): void {
		$this->envelope(setup: ['or' => false], fy: 2026);
		self::assertSame(expected: [], actual: $this->calls);
		$out = $this->envelope(setup: ['rows' => ['BBVProgramme' => new \RuntimeException('down'), 'BudgetBBVMapping' => [['m' => 1]]]]);
		self::assertSame(expected: [['m' => 1]], actual: $out['mappings']);
		self::assertSame(
			expected: [['error', 'Shillinq BBV compliance widget: register lookup failed', ['schema' => 'BBVProgramme', 'exception' => 'down']]],
			actual: array_values(array_filter($this->calls, fn (array $c): bool => $c[0] === 'error'))
		);
	}//end testUnavailableAndFailing()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
