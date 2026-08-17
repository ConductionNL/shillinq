<?php

/**
 * Unit tests for BudgetOverrunGuard.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BudgetOverrunGuard;
use OCA\Shillinq\Service\BegrotingswijzigingStacker;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the budget-overrun precondition on GL posting (REQ-010).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetOverrunGuardTest extends TestCase {

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var BudgetOverrunGuard
	 */
	private BudgetOverrunGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(
			store: $this->objectServiceStub(taskFields: [], wijzigingen: [], glLines: [])
		);

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return BudgetOverrunGuard
	 */
	private function buildGuard(object $store): BudgetOverrunGuard {
		return new BudgetOverrunGuard(
			appConfig: $this->appConfig,
			stacker: new BegrotingswijzigingStacker(),
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * REQ-010: a posting within the authorized lasten stays within budget.
	 *
	 * @return void
	 */
	public function testIsWithinBudgetWhenUnderAuthorized(): void {
		self::assertTrue($this->guard->isWithinBudget(authorizedExpenses: 500.0, alreadyPosted: 450.0, attempted: 50.0));

	}//end testIsWithinBudgetWhenUnderAuthorized()

	/**
	 * REQ-010: a posting that would exceed the authorized lasten fails.
	 *
	 * @return void
	 */
	public function testNotWithinBudgetWhenOverAuthorized(): void {
		self::assertFalse($this->guard->isWithinBudget(authorizedExpenses: 500.0, alreadyPosted: 450.0, attempted: 100.0));

	}//end testNotWithinBudgetWhenOverAuthorized()

	/**
	 * A posting hitting the budget exactly is allowed (≤, not <).
	 *
	 * @return void
	 */
	public function testExactBudgetIsAllowed(): void {
		self::assertTrue($this->guard->isWithinBudget(authorizedExpenses: 500.0, alreadyPosted: 400.0, attempted: 100.0));

	}//end testExactBudgetIsAllowed()

	/**
	 * REQ-010 scenario: canPost honours the stacked authorized lasten + prior postings.
	 *
	 * @return void
	 */
	public function testCanPostWithinStackedBudgetSucceeds(): void {
		$this->guard = $this->buildGuard(
			store: $this->objectServiceStub(
				taskFields: [['taskFieldCode' => '1.2', 'revenue' => 0.0, 'expenses' => 500.0]],
				wijzigingen: [['status' => 'determined', 'movements' => [['taskFieldCode' => '1.2', 'lasten_delta' => 100.0]]]],
				glLines: [['taskFieldCode' => '1.2', 'side' => 'debit', 'amount' => 400.0]]
			)
		);

		// Authorized = 500 + 100 = 600; already 400; attempt 150 → 550 ≤ 600.
		self::assertTrue($this->guard->canPost(budgetId: 'pb-1', taskFieldCode: '1.2', attempted: 150.0));

	}//end testCanPostWithinStackedBudgetSucceeds()

	/**
	 * REQ-010 scenario: canPost denies a posting beyond the stacked budget.
	 *
	 * @return void
	 */
	public function testCanPostOverBudgetFails(): void {
		$this->guard = $this->buildGuard(
			store: $this->objectServiceStub(
				taskFields: [['taskFieldCode' => '1.2', 'revenue' => 0.0, 'expenses' => 500.0]],
				wijzigingen: [],
				glLines: [['taskFieldCode' => '1.2', 'side' => 'debit', 'amount' => 450.0]]
			)
		);

		// Authorized 500; already 450; attempt 100 → 550 > 500.
		self::assertFalse($this->guard->canPost(budgetId: 'pb-1', taskFieldCode: '1.2', attempted: 100.0));

	}//end testCanPostOverBudgetFails()

	/**
	 * Empty identifiers are fail-closed (denied).
	 *
	 * @return void
	 */
	public function testCanPostFailsClosedOnEmptyIds(): void {
		self::assertFalse($this->guard->canPost(budgetId: '', taskFieldCode: '1.2', attempted: 1.0));

	}//end testCanPostFailsClosedOnEmptyIds()

	/**
	 * A lookup throwing causes a fail-closed denial (CWE-863).
	 *
	 * @return void
	 */
	public function testCanPostFailsClosedOnException(): void {
		$this->guard = $this->buildGuard(store: $this->buildUnavailableObjectServiceStub());
		self::assertFalse($this->guard->canPost(budgetId: 'pb-1', taskFieldCode: '1.2', attempted: 1.0));

	}//end testCanPostFailsClosedOnException()

	/**
	 * Build a schema-aware ObjectService stub returning rows per schema slug.
	 *
	 * @param array<int,array<string,mixed>> $taskFields Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen Begrotingswijziging rows.
	 * @param array<int,array<string,mixed>> $glLines GLLine rows.
	 *
	 * @return object The fluent ObjectService stub.
	 */
	private function objectServiceStub(array $taskFields, array $wijzigingen, array $glLines): object {
		return new class($taskFields, $wijzigingen, $glLines) {
			/**
			 * Currently-selected schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Taakveld rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $taskFields;

			/**
			 * Begrotingswijziging rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $wijzigingen;

			/**
			 * GLLine rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $glLines;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $taskFields Taakveld rows.
			 * @param array<int,array<string,mixed>> $wijzigingen Begrotingswijziging rows.
			 * @param array<int,array<string,mixed>> $glLines GLLine rows.
			 */
			public function __construct(array $taskFields, array $wijzigingen, array $glLines) {
				$this->taskFields = $taskFields;
				$this->wijzigingen = $wijzigingen;
				$this->glLines = $glLines;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter that records the selected schema.
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
			 * Return the rows for the currently-selected schema.
			 *
			 * @param array<string,mixed> $params Query params (unused).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'Taakveld') {
					return $this->taskFields;
				}

				if ($this->schema === 'Begrotingswijziging') {
					return $this->wijzigingen;
				}

				if ($this->schema === 'GLLine') {
					return $this->glLines;
				}

				return [];
			}//end findAll()
		};
	}//end objectServiceStub()

	/**
	 * Build a store that models an unavailable OpenRegister.
	 *
	 * Before ADR-084 this scenario was expressed as
	 * `$container->method('get')->willThrowException(...)`. The container is no
	 * longer consulted, so the refusal has to come from the store itself; every
	 * read throws exactly as a downed ObjectService would, which is what the
	 * guard's fail-closed arm is there to catch.
	 *
	 * @return object
	 */
	private function buildUnavailableObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse every list query.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('OR down');
			}//end findAll()

			/**
			 * Refuse every single-object lookup.
			 *
			 * @param string|int $id Object ID.
			 *
			 * @return object|null
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string|int $id): ?object {
				throw new \RuntimeException('OR down');
			}//end find()
		};
	}//end buildUnavailableObjectServiceStub()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
