<?php

/**
 * Gap tests for VarianceGate: fail-closed catches, per-administration
 * thresholds, unreadable store answers and the register fallback.
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
 * @spec openspec/changes/inventory-cycle-count/specs/inventory-cycle-count/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VarianceGate;
use OCA\Shillinq\Lifecycle\VariancePostCheck;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the branches the main VarianceGate suite leaves open (REQ-ICC-002/004/005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VarianceGate::class)]
#[UsesClass(VariancePostCheck::class)]
final class VarianceGateGapsTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * The duck ObjectService handed out by the container.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Build the gate.
	 *
	 * @param array<string,mixed> $answers    findAll answer per schema; a Throwable is thrown.
	 * @param array<string,mixed> $config     IAppConfig answers per key; a Throwable is thrown; others default.
	 * @param bool                $infoThrows Whether logger->info() throws.
	 *
	 * @return VarianceGate
	 */
	private function gate(array $answers, array $config = [], bool $infoThrows = false): VarianceGate {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class($answers) {
			/** @var list<string> registers set, in order */
			public array $registers = [];

			/** @var list<array{0:string,1:array<string,mixed>}> schema and query per findAll */
			public array $queries = [];

			private string $schema = '';

			public function __construct(private readonly array $answers) {
			}

			public function setRegister(string $register): self {
				$this->registers[] = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): mixed {
				$this->queries[] = [$this->schema, $args];
				$answer = ($this->answers[$this->schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($config): string {
				$answer = ($config[$key] ?? $default);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level, $infoThrows): void {
					$this->log[] = [$level, $message, $context];
					if ($level === 'info' && $infoThrows === true) {
						throw new RuntimeException('log sink down');
					}
				}
			);
		}

		return new VarianceGate($container, $appConfig, $logger);

	}//end gate()

	/**
	 * A count with an 8% quantity variance on a cheap item.
	 *
	 * @return array<string,mixed>
	 */
	private static function eightPercentLine(): array {
		return ['lineId' => 'l-1', 'expectedQuantity' => 100, 'countedQuantity' => 108, 'unitCost' => 1];
	}//end eightPercentLine()

	/**
	 * A failure while logging the scope denial still denies, through the fail-closed path.
	 *
	 * @return void
	 */
	public function testScopeCheckFailsClosed(): void {
		$gate = $this->gate([], infoThrows: true);

		self::assertFalse($gate->requireValidScope(['countId' => 'cc-1', 'countType' => 'rolling']));
		self::assertSame(
			[
				['info', 'VarianceGate: submit denied — unknown countType', ['countId' => 'cc-1', 'countType' => 'rolling']],
				['error', 'VarianceGate: scope check failed — denying submit (fail-closed)', ['countId' => 'cc-1', 'exception' => 'log sink down']],
			],
			$this->log
		);

	}//end testScopeCheckFailsClosed()

	/**
	 * A failure while logging the post denial still denies, through the fail-closed path.
	 *
	 * @return void
	 */
	public function testReasonCheckFailsClosed(): void {
		$gate = $this->gate([], infoThrows: true);

		self::assertFalse($gate->requireReasonsOnPost(['countId' => 'cc-2']));
		self::assertSame(
			['error', 'VarianceGate: reason check failed — denying transition (fail-closed)', ['countId' => 'cc-2', 'exception' => 'log sink down']],
			$this->log[1]
		);

	}//end testReasonCheckFailsClosed()

	/**
	 * An uncounted line is never flagged, so it needs no reason.
	 *
	 * @return void
	 */
	public function testAnUncountedLineNeedsNoReason(): void {
		$gate = $this->gate(['InventoryCycleCountLine' => [['lineId' => 'l-9', 'expectedQuantity' => 10, 'countedQuantity' => null, 'unitCost' => 999]]]);

		self::assertTrue($gate->requireReasonsOnPost(['countId' => 'cc-3', 'administrationId' => 'adm-1']));
		self::assertSame([], $this->log);

	}//end testAnUncountedLineNeedsNoReason()

	/**
	 * A per-administration quantity threshold replaces the 5% default.
	 *
	 * @return void
	 */
	public function testAPerAdministrationQuantityThresholdApplies(): void {
		$count = ['countId' => 'cc-4', 'administrationId' => 'adm-1'];
		$lines = ['InventoryCycleCountLine' => [self::eightPercentLine()]];

		// Default 5%: the 8% line is flagged and has no reason.
		self::assertFalse($this->gate($lines)->requireReasonsOnPost($count));

		// 10% for adm-1: the line is within tolerance.
		$this->log = [];
		self::assertTrue($this->gate($lines, ['cyclecount_qty_threshold_pct_adm-1' => '10'])->requireReasonsOnPost($count));
		self::assertSame([], $this->log);

		// A non-numeric override is ignored.
		self::assertFalse($this->gate($lines, ['cyclecount_qty_threshold_pct_adm-1' => 'ten'])->requireReasonsOnPost($count));

	}//end testAPerAdministrationQuantityThresholdApplies()

	/**
	 * A per-administration value threshold replaces the 500 default.
	 *
	 * @return void
	 */
	public function testAPerAdministrationValueThresholdApplies(): void {
		$count = ['countId' => 'cc-5', 'administrationId' => 'adm-2'];
		$lines = ['InventoryCycleCountLine' => [self::eightPercentLine()]];

		$gate = $this->gate($lines, ['cyclecount_qty_threshold_pct_adm-2' => '10', 'cyclecount_value_threshold_abs_adm-2' => '7.5']);

		self::assertFalse($gate->requireReasonsOnPost($count));
		self::assertSame(
			[['info', 'VarianceGate: post denied — line requires reason but reasonCode empty', ['countId' => 'cc-5', 'lineId' => 'l-1']]],
			$this->log
		);

	}//end testAPerAdministrationValueThresholdApplies()

	/**
	 * An unreadable threshold config keeps the defaults and is logged at debug.
	 *
	 * @return void
	 */
	public function testAnUnreadableThresholdConfigKeepsTheDefaults(): void {
		$gate = $this->gate(
			['InventoryCycleCountLine' => [self::eightPercentLine()]],
			['cyclecount_qty_threshold_pct_adm-3' => new RuntimeException('config offline')]
		);

		self::assertFalse($gate->requireReasonsOnPost(['countId' => 'cc-6', 'administrationId' => 'adm-3']));
		self::assertSame(
			['debug', 'VarianceGate: failed to read per-administration thresholds; using defaults', ['administrationId' => 'adm-3', 'exception' => 'config offline']],
			$this->log[0]
		);
		self::assertSame('VarianceGate: post denied — line requires reason but reasonCode empty', $this->log[1][1]);

	}//end testAnUnreadableThresholdConfigKeepsTheDefaults()

	/**
	 * A non-list answer for the lines reads as no lines.
	 *
	 * @return void
	 */
	public function testANonListLinesAnswerReadsAsNoLines(): void {
		$gate = $this->gate(['InventoryCycleCountLine' => 'unexpected']);

		self::assertTrue($gate->requireReasonsOnPost(['countId' => 'cc-7', 'administrationId' => 'adm-1']));
		self::assertSame(
			[
				['InventoryCycleCountLine', ['filters' => ['administrationId' => 'adm-1', 'countId' => 'cc-7']]],
				['InventoryVarianceReason', ['filters' => ['administrationId' => 'adm-1', 'isActive' => true]]],
			],
			$this->store->queries
		);

	}//end testANonListLinesAnswerReadsAsNoLines()

	/**
	 * A non-list answer for the reasons reads as no active reasons.
	 *
	 * @return void
	 */
	public function testANonListReasonsAnswerReadsAsNoActiveReasons(): void {
		$gate = $this->gate(
			[
				'InventoryCycleCountLine' => [['reasonCode' => 'DMG'] + self::eightPercentLine()],
				'InventoryVarianceReason' => 'unexpected',
			]
		);

		self::assertFalse($gate->requireReasonsOnPost(['countId' => 'cc-8', 'administrationId' => 'adm-1']));
		self::assertSame(
			[['info', 'VarianceGate: post denied — reasonCode not active for this administration', ['countId' => 'cc-8', 'lineId' => 'l-1', 'reasonCode' => 'DMG']]],
			$this->log
		);

	}//end testANonListReasonsAnswerReadsAsNoActiveReasons()

	/**
	 * A failing reason lookup is logged and reads as no active reasons.
	 *
	 * @return void
	 */
	public function testAFailingReasonLookupReadsAsNoActiveReasons(): void {
		$gate = $this->gate(
			[
				'InventoryCycleCountLine' => [['reasonCode' => 'DMG'] + self::eightPercentLine()],
				'InventoryVarianceReason' => new RuntimeException('reasons offline'),
			]
		);

		self::assertFalse($gate->requireReasonsOnPost(['countId' => 'cc-9', 'administrationId' => 'adm-4']));
		self::assertSame(
			[
				['error', 'VarianceGate: failed to list active reasons', ['administrationId' => 'adm-4', 'exception' => 'reasons offline']],
				['info', 'VarianceGate: post denied — reasonCode not active for this administration', ['countId' => 'cc-9', 'lineId' => 'l-1', 'reasonCode' => 'DMG']],
			],
			$this->log
		);

	}//end testAFailingReasonLookupReadsAsNoActiveReasons()

	/**
	 * An empty configured register falls back to 'shillinq'; a set one is used.
	 *
	 * @return void
	 */
	public function testTheRegisterFallsBackToShillinq(): void {
		$count = ['countId' => 'cc-10', 'administrationId' => 'adm-1'];

		$this->gate([], ['register' => ''])->requireReasonsOnPost($count);
		self::assertSame(['shillinq', 'shillinq'], $this->store->registers);

		$this->gate([], ['register' => 'stock'])->requireReasonsOnPost($count);
		self::assertSame(['stock', 'stock'], $this->store->registers);

	}//end testTheRegisterFallsBackToShillinq()
}//end class
