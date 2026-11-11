<?php

/**
 * Golden test for ContractCostRollupJob::run.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\ContractCostRollupJob;
use OCA\Shillinq\Service\ContractCostRollupService;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins which contracts are rolled up, the reads, the writes and every log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ContractCostRollupJobGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the job over the given contracts.
	 *
	 * @param array<int,mixed>|null $contracts The contracts findAll answers; null makes it throw.
	 *
	 * @return void
	 */
	private function runJob(array|null $contracts): void {
		$store  = new class ($this, $contracts) {
			/**
			 * Constructor.
			 *
			 * @param ContractCostRollupJobGoldenTest $test      The test, for its call log.
			 * @param array|null                      $contracts The contracts.
			 */
			public function __construct(private ContractCostRollupJobGoldenTest $test, private array|null $contracts) {
			}

			/**
			 * Record the register.
			 *
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
				$this->test->calls[] = ['setRegister', $register];
			}

			/**
			 * Record the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
				$this->test->calls[] = ['setSchema', $schema];
			}

			/**
			 * The contracts, or a failure.
			 *
			 * @param array $config The query.
			 *
			 * @return array
			 */
			public function findAll(array $config=[]): array {
				$this->test->calls[] = ['findAll', $config];
				if ($this->contracts === null) {
					throw new RuntimeException('store down');
				}

				return $this->contracts;
			}

			/**
			 * Record the save; a contract marked fail throws.
			 *
			 * @param array       $object   The contract.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 *
			 * @return array
			 */
			public function saveObject(array $object, string|null $register=null, string|null $schema=null): array {
				$this->test->calls[] = ['save', ($object['id'] ?? null), $object['incurredCostUnreadableLinks'], $register, $schema];
				if (($object['fail'] ?? false) === true) {
					throw new RuntimeException('save refused ' . ($object['id'] ?? '-'));
				}

				return $object;
			}
		};
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default=''): string {
				$this->calls[] = ['config', $app, $key, $default];
				return 'reg';
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$objects = new DuckObjectServiceAdapter(inner: $store);
		$rollup  = new ContractCostRollupService($objects, $this->createMock(SubjectCostService::class), $config);
		$job     = new ContractCostRollupJob($this->createMock(ITimeFactory::class), $rollup, $objects, $config, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * A failed read is one error and nothing else.
	 *
	 * @return void
	 */
	public function testUnreadableContracts(): void {
		$this->runJob(contracts: null);
		$expected = [
			['config', 'shillinq', 'register', 'shillinq'],
			['setRegister', 'reg'],
			['setSchema', 'Contract'],
			['findAll', ['limit' => 1000]],
			['error', 'Shillinq: the contract cost roll-up could not read the contracts', ['exception' => 'store down']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testUnreadableContracts()

	/**
	 * Only contracts with a non-empty link list are rolled up; one failure is a warning and the rest go on.
	 *
	 * @return void
	 */
	public function testRollsUpLinkedContracts(): void {
		$contracts = [
			'scalar',
			['id' => 'c0'],
			['id' => 'c1', 'linkedObjects' => 'x'],
			['id' => 'c2', 'linkedObjects' => []],
			['id' => 'c3', 'linkedObjects' => ['x']],
			['id' => 7, 'linkedObjects' => ['x'], 'fail' => true],
			['linkedObjects' => ['x', 'y'], 'fail' => true],
			['id' => 'c4', 'linkedObjects' => ['x']],
		];
		$this->runJob(contracts: $contracts);
		$read     = [['config', 'shillinq', 'register', 'shillinq'], ['setRegister', 'reg'], ['setSchema', 'Contract'], ['findAll', ['limit' => 1000]]];
		$saveRead = ['config', 'shillinq', 'register', 'shillinq'];
		$expected = array_merge(
			$read,
			[
				$saveRead,
				['setSchema', 'Contract'],
				['setRegister', 'reg'],
				['save', 'c3', 1, 'reg', 'Contract'],
				$saveRead,
				['setSchema', 'Contract'],
				['setRegister', 'reg'],
				['save', 7, 1, 'reg', 'Contract'],
				['warning', 'Shillinq: one contract could not be rolled up', ['contract' => '7', 'exception' => 'save refused 7']],
				$saveRead,
				['setSchema', 'Contract'],
				['setRegister', 'reg'],
				['save', null, 2, 'reg', 'Contract'],
				['warning', 'Shillinq: one contract could not be rolled up', ['contract' => '', 'exception' => 'save refused -']],
				$saveRead,
				['setSchema', 'Contract'],
				['setRegister', 'reg'],
				['save', 'c4', 1, 'reg', 'Contract'],
				['info', 'Shillinq: contract cost roll-up finished', ['contracts' => 2]],
			]
		);
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRollsUpLinkedContracts()
}//end class
