<?php

/**
 * Unit tests for ChartOfAccountsCandidateService (REQ-GAC-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Service\Extraction\ChartOfAccountsCandidateService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers: administration-scoped active-account resolution, exclusion of
 * blocked/archived accounts and other administrations' accounts, and
 * graceful (empty, non-throwing) degradation when OR is unavailable.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ChartOfAccountsCandidateServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service wired to an ObjectService stub returning the given
	 * Account rows for the equality-filtered findAll() call.
	 *
	 * @param array<int,array<string,mixed>> $rows All Account rows in the fixture.
	 *
	 * @return ChartOfAccountsCandidateService
	 */
	private function buildService(array $rows): ChartOfAccountsCandidateService {
		$stub = new class($rows) {

			/**
			 * The fixture Account rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $rows;

			/**
			 * Construct the stub with a fixture row set.
			 *
			 * @param array<int,array<string,mixed>> $rows Account rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Fluent register selector (no-op — the stub is single-register).
			 *
			 * @param string $r Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $r): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector (no-op — the stub is single-schema).
			 *
			 * @param string $s Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $s): static {
				return $this;
			}//end setSchema()

			/**
			 * Filter the fixture rows by the given equality filters.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->rows,
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

		return new ChartOfAccountsCandidateService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildService()

	/**
	 * REQ-GAC-002: candidates are scoped to the draft's own administration —
	 * another administration's account is excluded.
	 *
	 * @return void
	 */
	public function testCandidatesScopedToOwnAdministration(): void {
		$service = $this->buildService(
			[
				['accountNumber' => '4300', 'name' => 'Kantoorkosten', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
				['accountNumber' => '4400', 'name' => 'Representatiekosten', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
				['accountNumber' => '9999', 'name' => 'Other Tenant Account', 'administrationId' => 'adm-2', 'lifecycleState' => 'active'],
			]
		);

		$candidates = $service->activeCandidates(administrationId: 'adm-1');
		$codes = array_column($candidates, 'code');

		self::assertContains('4300', $codes);
		self::assertContains('4400', $codes);
		self::assertNotContains('9999', $codes);

	}//end testCandidatesScopedToOwnAdministration()

	/**
	 * REQ-GAC-002: blocked and archived accounts are excluded from candidates.
	 *
	 * @return void
	 */
	public function testBlockedAndArchivedAccountsExcluded(): void {
		$service = $this->buildService(
			[
				['accountNumber' => '4300', 'name' => 'Kantoorkosten', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
				['accountNumber' => '4999', 'name' => 'Blocked Account', 'administrationId' => 'adm-1', 'lifecycleState' => 'blocked'],
				['accountNumber' => '4998', 'name' => 'Archived Account', 'administrationId' => 'adm-1', 'lifecycleState' => 'archived'],
			]
		);

		$candidates = $service->activeCandidates(administrationId: 'adm-1');
		$codes = array_column($candidates, 'code');

		self::assertContains('4300', $codes);
		self::assertNotContains('4999', $codes);
		self::assertNotContains('4998', $codes);

	}//end testBlockedAndArchivedAccountsExcluded()

	/**
	 * An empty administration id yields an empty candidate set without
	 * ever touching OR.
	 *
	 * @return void
	 */
	public function testEmptyAdministrationIdYieldsNoCandidates(): void {
		$service = $this->buildService([]);

		self::assertSame([], $service->activeCandidates(administrationId: ''));

	}//end testEmptyAdministrationIdYieldsNoCandidates()

	/**
	 * REQ-GAC-006: when OR is unavailable, resolution degrades to an empty
	 * candidate set rather than throwing.
	 *
	 * @return void
	 */
	public function testDegradesGracefullyWhenOrUnavailable(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OpenRegister not installed'));

		$service = new ChartOfAccountsCandidateService(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame([], $service->activeCandidates(administrationId: 'adm-1'));

	}//end testDegradesGracefullyWhenOrUnavailable()
}//end class
