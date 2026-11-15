<?php

/**
 * Tests for the BBV account-mapping posting precondition.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BbvComplianceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every permitted and blocked outcome of allLinesMappedForMunicipalAdmin(), its log and its queries.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvMappingPreconditionTest extends TestCase {

	/**
	 * findAll() calls, as [schema, filters].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $queries = [];

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * A guard over the given rows per schema ('throw' makes that schema's lookup fail).
	 *
	 * @param array<string,mixed> $rows     Rows per schema.
	 * @param string              $gateDate The BBV gate installation date ('' for unset).
	 *
	 * @return BbvComplianceGuard
	 */
	private function guard(array $rows, string $gateDate = '2026-01-01'): BbvComplianceGuard {
		$service = new class($rows, $this->queries) {
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $rows    Rows per schema.
			 * @param array<int,mixed>    $queries The query sink.
			 */
			public function __construct(private readonly array $rows, private array &$queries) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config): array {
				$this->queries[] = [$this->schema, $config];
				$rows = ($this->rows[$this->schema] ?? []);
				if ($rows === 'throw') {
					throw new RuntimeException('down');
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($config): bool {
							foreach ($config['filters'] as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($key === 'bbv_gate_install_date' ? $gateDate : 'shillinq')
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new BbvComplianceGuard($container, $appConfig, $logger);

	}//end guard()

	/**
	 * A municipal administration with one mapped and one unmapped account.
	 *
	 * @return array<string,mixed>
	 */
	private static function rows(): array {
		return [
			'Administration' => [
				['id' => 'adm-id-1', 'administrationCode' => 'GEM-1', 'administrationType' => 'municipality'],
				['id' => 'adm-id-2', 'administrationCode' => 'BV-2', 'administrationType' => 'company'],
				['id' => 'adm-id-3', 'administrationCode' => 'WS-3', 'administrationType' => 'waterAuthority'],
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '4000'],
				['transactionId' => 'tx-1', 'accountNumber' => ''],
				['transactionId' => 'tx-1', 'accountNumber' => '8000'],
				['transactionId' => 'tx-2', 'accountNumber' => '4000'],
			],
			'BbvAccountMapping' => [
				['administrationId' => 'GEM-1', 'accountNumber' => '4000'],
				['administrationId' => 'adm-id-3', 'accountNumber' => '4000'],
			],
		];

	}//end rows()

	/**
	 * An unmapped account on a municipal administration blocks, after the mapped one, with the context logged.
	 *
	 * @return void
	 */
	public function testAnUnmappedAccountBlocks(): void {
		self::assertFalse($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['id' => 'tx-1', 'administrationId' => 'GEM-1', 'postingDate' => '2026-02-01']));

		self::assertSame(
			[
				['Administration', ['filters' => ['administrationCode' => 'GEM-1'], 'limit' => 1]],
				['GLLine', ['filters' => ['transactionId' => 'tx-1'], 'limit' => 1000]],
				['BbvAccountMapping', ['filters' => ['administrationId' => 'GEM-1', 'accountNumber' => '4000'], 'limit' => 1]],
				['BbvAccountMapping', ['filters' => ['administrationId' => 'GEM-1', 'accountNumber' => '8000'], 'limit' => 1]],
			],
			$this->queries
		);
		self::assertSame(
			[
				[
					'info',
					'BbvComplianceGuard: blocking GLTransaction.post — unmapped account on municipal administration',
					['administrationId' => 'GEM-1', 'administrationType' => 'municipality', 'accountNumber' => '8000', 'transactionId' => 'tx-1'],
				],
			],
			$this->log
		);

	}//end testAnUnmappedAccountBlocks()

	/**
	 * Mapped lines permit; the administration is found by id when the code misses; uuid stands in for id.
	 *
	 * @return void
	 */
	public function testMappedLinesPermitAndTheIdFallbackIsUsed(): void {
		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['uuid' => 'tx-2', 'administrationId' => 'adm-id-3', 'postingDate' => '']));
		self::assertSame(
			[
				['Administration', ['filters' => ['administrationCode' => 'adm-id-3'], 'limit' => 1]],
				['Administration', ['filters' => ['id' => 'adm-id-3'], 'limit' => 1]],
				['GLLine', ['filters' => ['transactionId' => 'tx-2'], 'limit' => 1000]],
				['BbvAccountMapping', ['filters' => ['administrationId' => 'adm-id-3', 'accountNumber' => '4000'], 'limit' => 1]],
			],
			$this->queries
		);
		self::assertSame([], $this->log);

	}//end testMappedLinesPermitAndTheIdFallbackIsUsed()

	/**
	 * Every permissive short-circuit permits without reaching the lines.
	 *
	 * @return void
	 */
	public function testPermissiveShortCircuits(): void {
		$tx = ['id' => 'tx-1', 'administrationId' => 'GEM-1', 'postingDate' => '2026-02-01'];

		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['id' => 'tx-1']), 'no administration');
		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['administrationId' => 'nope'] + $tx), 'unknown administration');
		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['administrationId' => 'BV-2'] + $tx), 'non-municipal');
		self::assertTrue($this->guard(self::rows(), gateDate: '')->allLinesMappedForMunicipalAdmin($tx), 'gate not installed');
		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['postingDate' => '2025-12-31'] + $tx), 'historic');
		self::assertTrue($this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['id' => 'tx-none'] + $tx), 'no lines');

		$this->queries = [];
		$this->guard(self::rows())->allLinesMappedForMunicipalAdmin(['postingDate' => '2025-12-31'] + $tx);
		self::assertSame([['Administration', ['filters' => ['administrationCode' => 'GEM-1'], 'limit' => 1]]], $this->queries);

	}//end testPermissiveShortCircuits()

	/**
	 * A failing administration or line lookup bypasses the gate; a failing mapping lookup counts as unmapped.
	 *
	 * @return void
	 */
	public function testFailingLookups(): void {
		$tx = ['id' => 'tx-1', 'administrationId' => 'GEM-1', 'postingDate' => '2026-02-01'];

		self::assertTrue($this->guard(['Administration' => 'throw'] + self::rows())->allLinesMappedForMunicipalAdmin($tx));
		self::assertTrue($this->guard(['GLLine' => 'throw'] + self::rows())->allLinesMappedForMunicipalAdmin($tx));
		self::assertFalse($this->guard(['BbvAccountMapping' => 'throw'] + self::rows())->allLinesMappedForMunicipalAdmin($tx));

		self::assertSame(
			[
				['debug', 'BbvComplianceGuard: administration lookup failed; bypassing gate', ['exception' => 'down']],
				['debug', 'BbvComplianceGuard: GLLine lookup failed; bypassing gate', ['transactionId' => 'tx-1', 'exception' => 'down']],
				['debug', 'BbvComplianceGuard: BbvAccountMapping lookup failed; treating as missing', ['administrationId' => 'GEM-1', 'accountNumber' => '4000', 'exception' => 'down']],
			],
			array_slice($this->log, 0, 3)
		);

	}//end testFailingLookups()
}//end class
