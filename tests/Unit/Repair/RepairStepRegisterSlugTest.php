<?php

/**
 * Characterisation tests for where four repair steps read the register slug.
 *
 * StampJournalTypeOnGlTransactions, UnifyAnalyticalDimensions,
 * FoldDunningWriteoffIntoArInvoice and FoldExpensesAndHoursIntoProject each ask
 * for the configured register slug once, read every source schema from that
 * register, and turn a failing slug lookup into a warning instead of throwing.
 * These tests pin exactly that, over an empty store.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\FoldDunningWriteoffIntoArInvoice;
use OCA\Shillinq\Repair\FoldExpensesAndHoursIntoProject;
use OCA\Shillinq\Repair\StampJournalTypeOnGlTransactions;
use OCA\Shillinq\Repair\UnifyAnalyticalDimensions;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the register-slug wiring of four repair steps.
 *
 * @covers \OCA\Shillinq\Repair\StampJournalTypeOnGlTransactions
 * @covers \OCA\Shillinq\Repair\UnifyAnalyticalDimensions
 * @covers \OCA\Shillinq\Repair\FoldDunningWriteoffIntoArInvoice
 * @covers \OCA\Shillinq\Repair\FoldExpensesAndHoursIntoProject
 */
final class RepairStepRegisterSlugTest extends TestCase {

	/**
	 * The empty store; records every register slug it is pointed at.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Collected output messages, prefixed with their level.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Set up the empty recording store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = new class() {

			/**
			 * Every register slug passed to setRegister(), in order.
			 *
			 * @var array<int, string>
			 */
			public array $registers = [];

			/**
			 * Fluent register setter that records the slug.
			 *
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->registers[] = $register;

				return $this;

			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;

			}//end setSchema()

			/**
			 * The store is empty.
			 *
			 * @param array<string, mixed> $config The findAll options.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function findAll(array $config = []): array {
				return [];

			}//end findAll()
		};

	}//end setUp()

	/**
	 * A settings double whose slug lookup returns 'finance'.
	 *
	 * @return SettingsService The double.
	 */
	private function financeSlug(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->once())->method('getRegisterSlug')->willReturn('finance');

		return $settings;

	}//end financeSlug()

	/**
	 * A settings double whose slug lookup throws.
	 *
	 * @return SettingsService The double.
	 */
	private function failingSlug(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willThrowException(new RuntimeException('config down'));

		return $settings;

	}//end failingSlug()

	/**
	 * A container that hands out the store as OpenRegister's ObjectService.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);

		return $container;

	}//end container()

	/**
	 * An output double that records every message with its level.
	 *
	 * @return IOutput The output double.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'info: ' . $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function ($message): void {
				$this->messages[] = 'warning: ' . $message;
			}
		);

		return $output;

	}//end recordingOutput()

	/**
	 * Assert every read went to the 'finance' register and nothing warned.
	 *
	 * @param string $expectedInfo The last info message the step reports.
	 *
	 * @return void
	 */
	private function assertReadFinanceOnly(string $expectedInfo): void {
		self::assertNotSame([], $this->store->registers);
		self::assertSame(['finance'], array_values(array_unique($this->store->registers)));
		self::assertSame([], preg_grep('/^warning: /', $this->messages));
		self::assertSame('info: ' . $expectedInfo, end($this->messages));

	}//end assertReadFinanceOnly()

	/**
	 * StampJournalTypeOnGlTransactions reads from the configured register.
	 *
	 * @return void
	 */
	public function testStampJournalTypeReadsTheConfiguredRegister(): void {
		(new StampJournalTypeOnGlTransactions($this->financeSlug(), $this->createMock(LoggerInterface::class), $this->container()))
			->run($this->recordingOutput());

		$this->assertReadFinanceOnly('Shillinq: journalType stamp complete — 0 closing, 0 intercompany GLTransaction(s) stamped.');

	}//end testStampJournalTypeReadsTheConfiguredRegister()

	/**
	 * StampJournalTypeOnGlTransactions reports a failing slug lookup.
	 *
	 * @return void
	 */
	public function testStampJournalTypeReportsSlugFailure(): void {
		(new StampJournalTypeOnGlTransactions($this->failingSlug(), $this->createMock(LoggerInterface::class), $this->container()))
			->run($this->recordingOutput());

		self::assertSame([], $this->store->registers);
		self::assertSame(['warning: Shillinq: journalType stamp failed: config down'], $this->messages);

	}//end testStampJournalTypeReportsSlugFailure()

	/**
	 * UnifyAnalyticalDimensions reads from the configured register.
	 *
	 * @return void
	 */
	public function testUnifyDimensionsReadsTheConfiguredRegister(): void {
		(new UnifyAnalyticalDimensions($this->financeSlug(), $this->createMock(LoggerInterface::class), $this->container()))
			->run($this->recordingOutput());

		$this->assertReadFinanceOnly(
			'Shillinq: UnifyAnalyticalDimensions complete — CostCenter: 0 created, 0 skipped; KostenDrager: 0 created, 0 skipped.'
		);

	}//end testUnifyDimensionsReadsTheConfiguredRegister()

	/**
	 * UnifyAnalyticalDimensions reports a failing slug lookup.
	 *
	 * @return void
	 */
	public function testUnifyDimensionsReportsSlugFailure(): void {
		(new UnifyAnalyticalDimensions($this->failingSlug(), $this->createMock(LoggerInterface::class), $this->container()))
			->run($this->recordingOutput());

		self::assertSame([], $this->store->registers);
		self::assertSame(['warning: Shillinq: UnifyAnalyticalDimensions failed: config down'], $this->messages);

	}//end testUnifyDimensionsReportsSlugFailure()

	/**
	 * FoldDunningWriteoffIntoArInvoice reads from the configured register.
	 *
	 * @return void
	 */
	public function testFoldDunningReadsTheConfiguredRegister(): void {
		(new FoldDunningWriteoffIntoArInvoice(
			$this->financeSlug(),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($this->store)
		))->run($this->recordingOutput());

		$this->assertReadFinanceOnly('Shillinq: no OninbaarAfschrijving rows — ARInvoice writeOff/dunning fold skipped.');

	}//end testFoldDunningReadsTheConfiguredRegister()

	/**
	 * FoldDunningWriteoffIntoArInvoice reports a failing slug lookup.
	 *
	 * @return void
	 */
	public function testFoldDunningReportsSlugFailure(): void {
		(new FoldDunningWriteoffIntoArInvoice(
			$this->failingSlug(),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($this->store)
		))->run($this->recordingOutput());

		self::assertSame([], $this->store->registers);
		self::assertSame(['warning: Shillinq: ARInvoice writeOff/dunning fold failed: config down'], $this->messages);

	}//end testFoldDunningReportsSlugFailure()

	/**
	 * FoldExpensesAndHoursIntoProject reads from the configured register.
	 *
	 * @return void
	 */
	public function testFoldExpensesReadsTheConfiguredRegister(): void {
		(new FoldExpensesAndHoursIntoProject(
			$this->financeSlug(),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($this->store)
		))->run($this->recordingOutput());

		$this->assertReadFinanceOnly('Shillinq: no Project records — expense/hours fold skipped.');

	}//end testFoldExpensesReadsTheConfiguredRegister()

	/**
	 * FoldExpensesAndHoursIntoProject reports a failing slug lookup.
	 *
	 * @return void
	 */
	public function testFoldExpensesReportsSlugFailure(): void {
		(new FoldExpensesAndHoursIntoProject(
			$this->failingSlug(),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new DuckObjectServiceAdapter($this->store)
		))->run($this->recordingOutput());

		self::assertSame([], $this->store->registers);
		self::assertSame(['warning: Shillinq: expense/hours fold failed: config down'], $this->messages);

	}//end testFoldExpensesReportsSlugFailure()
}//end class
