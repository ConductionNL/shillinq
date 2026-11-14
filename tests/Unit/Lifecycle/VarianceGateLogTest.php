<?php

/**
 * Characterisation tests pinning the VarianceGate denial log calls.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VarianceGate;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins every message, level and context VarianceGate logs when it denies.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
class VarianceGateLogTest extends TestCase {

	/**
	 * Recording logger.
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * ObjectService stub.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * The guard under test.
	 *
	 * @var VarianceGate
	 */
	private VarianceGate $gate;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = new class extends AbstractLogger {

			/** @var array<int,array{0:string,1:string,2:array<string,mixed>}> */
			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		$this->objectService = new class {

			public string $currentSchema = '';

			public array $lines = [];

			public array $reasons = [];

			public bool $throwOnLines = false;

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				if ($this->currentSchema === 'InventoryCycleCountLine') {
					if ($this->throwOnLines === true) {
						throw new \RuntimeException('lines down');
					}

					return $this->lines;
				}

				if ($this->currentSchema === 'InventoryVarianceReason') {
					return $this->reasons;
				}

				return [];
			}

			public function find(array $args = []): array {
				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->gate = new VarianceGate(
			container: $container,
			appConfig: $appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Unknown countType logs countId and countType at info.
	 *
	 * @return void
	 */
	public function testUnknownCountTypeLog(): void {
		self::assertFalse($this->gate->requireValidScope(['countType' => 'random', 'countId' => 'CC-1']));
		self::assertSame(
			[
				[
					'info',
					'VarianceGate: submit denied — unknown countType',
					[
						'countId' => 'CC-1',
						'countType' => 'random',
					],
				],
			],
			$this->logger->records
		);

	}//end testUnknownCountTypeLog()

	/**
	 * Partial count without scope logs countId at info.
	 *
	 * @return void
	 */
	public function testPartialWithoutScopeLog(): void {
		self::assertFalse($this->gate->requireValidScope(['countType' => 'partial', 'countId' => 'CC-2']));
		self::assertSame(
			[['info', 'VarianceGate: submit denied — partial count without scope', ['countId' => 'CC-2']]],
			$this->logger->records
		);

	}//end testPartialWithoutScopeLog()

	/**
	 * Missing ids log countId (null) at info.
	 *
	 * @return void
	 */
	public function testMissingIdsLog(): void {
		self::assertFalse($this->gate->requireReasonsOnPost(['administrationId' => 'adm-1']));
		self::assertSame(
			[['info', 'VarianceGate: post denied — countId or administrationId missing', ['countId' => null]]],
			$this->logger->records
		);

	}//end testMissingIdsLog()

	/**
	 * A flagged line without a reason logs countId and lineId.
	 *
	 * @return void
	 */
	public function testMissingReasonLog(): void {
		$this->objectService->lines = [
			[
				'lineId' => 'L-1',
				'expectedQuantity' => 100,
				'countedQuantity' => 50,
				'unitCost' => 1.0,
			],
		];
		self::assertFalse($this->gate->requireReasonsOnPost(['countId' => 'CC-3', 'administrationId' => 'adm-1']));
		self::assertSame(
			['info', 'VarianceGate: post denied — line requires reason but reasonCode empty', ['countId' => 'CC-3', 'lineId' => 'L-1']],
			end($this->logger->records)
		);

	}//end testMissingReasonLog()

	/**
	 * An inactive reason code logs countId, lineId and reasonCode.
	 *
	 * @return void
	 */
	public function testInactiveReasonLog(): void {
		$this->objectService->lines = [
			[
				'lineId' => 'L-2',
				'expectedQuantity' => 100,
				'countedQuantity' => 50,
				'unitCost' => 1.0,
				'reasonCode' => 'OLD',
			],
		];
		$this->objectService->reasons = [['reasonId' => 'DMG']];
		self::assertFalse($this->gate->requireReasonsOnPost(['countId' => 'CC-4', 'administrationId' => 'adm-1']));
		self::assertSame(
			[
				'info',
				'VarianceGate: post denied — reasonCode not active for this administration',
				[
					'countId' => 'CC-4',
					'lineId' => 'L-2',
					'reasonCode' => 'OLD',
				],
			],
			end($this->logger->records)
		);

	}//end testInactiveReasonLog()

	/**
	 * A failing line lookup logs once at error and the post passes on no lines.
	 *
	 * @return void
	 */
	public function testLineLookupFailureLogSequence(): void {
		$this->objectService->throwOnLines = true;
		$result = $this->gate->requireReasonsOnPost(['countId' => 'CC-5', 'administrationId' => 'adm-1']);
		$levels = array_map(fn (array $r): string => $r[0].' '.$r[1], $this->logger->records);
		self::assertTrue($result);
		self::assertSame(['error VarianceGate: failed to list lines for count'], $levels);

	}//end testLineLookupFailureLogSequence()
}//end class
