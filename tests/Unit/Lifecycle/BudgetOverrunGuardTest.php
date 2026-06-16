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
final class BudgetOverrunGuardTest extends TestCase
{

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
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new BudgetOverrunGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            stacker: new BegrotingswijzigingStacker(),
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * REQ-010: a posting within the authorized lasten stays within budget.
     *
     * @return void
     */
    public function testIsWithinBudgetWhenUnderAuthorized(): void
    {
        self::assertTrue($this->guard->isWithinBudget(authorizedLasten: 500.0, alreadyPosted: 450.0, attempted: 50.0));

    }//end testIsWithinBudgetWhenUnderAuthorized()

    /**
     * REQ-010: a posting that would exceed the authorized lasten fails.
     *
     * @return void
     */
    public function testNotWithinBudgetWhenOverAuthorized(): void
    {
        self::assertFalse($this->guard->isWithinBudget(authorizedLasten: 500.0, alreadyPosted: 450.0, attempted: 100.0));

    }//end testNotWithinBudgetWhenOverAuthorized()

    /**
     * A posting hitting the budget exactly is allowed (≤, not <).
     *
     * @return void
     */
    public function testExactBudgetIsAllowed(): void
    {
        self::assertTrue($this->guard->isWithinBudget(authorizedLasten: 500.0, alreadyPosted: 400.0, attempted: 100.0));

    }//end testExactBudgetIsAllowed()

    /**
     * REQ-010 scenario: canPost honours the stacked authorized lasten + prior postings.
     *
     * @return void
     */
    public function testCanPostWithinStackedBudgetSucceeds(): void
    {
        $this->container->method('get')->willReturn(
            $this->objectServiceStub(
                taakvelden: [['taakveldCode' => '1.2', 'baten' => 0.0, 'lasten' => 500.0]],
                wijzigingen: [['status' => 'vastgesteld', 'mutaties' => [['taakveldCode' => '1.2', 'lasten_delta' => 100.0]]]],
                glLines: [['taakveldCode' => '1.2', 'side' => 'debit', 'amount' => 400.0]]
            )
        );

        // Authorized = 500 + 100 = 600; already 400; attempt 150 → 550 ≤ 600.
        self::assertTrue($this->guard->canPost(begrotingId: 'pb-1', taakveldCode: '1.2', attempted: 150.0));

    }//end testCanPostWithinStackedBudgetSucceeds()

    /**
     * REQ-010 scenario: canPost denies a posting beyond the stacked budget.
     *
     * @return void
     */
    public function testCanPostOverBudgetFails(): void
    {
        $this->container->method('get')->willReturn(
            $this->objectServiceStub(
                taakvelden: [['taakveldCode' => '1.2', 'baten' => 0.0, 'lasten' => 500.0]],
                wijzigingen: [],
                glLines: [['taakveldCode' => '1.2', 'side' => 'debit', 'amount' => 450.0]]
            )
        );

        // Authorized 500; already 450; attempt 100 → 550 > 500.
        self::assertFalse($this->guard->canPost(begrotingId: 'pb-1', taakveldCode: '1.2', attempted: 100.0));

    }//end testCanPostOverBudgetFails()

    /**
     * Empty identifiers are fail-closed (denied).
     *
     * @return void
     */
    public function testCanPostFailsClosedOnEmptyIds(): void
    {
        self::assertFalse($this->guard->canPost(begrotingId: '', taakveldCode: '1.2', attempted: 1.0));

    }//end testCanPostFailsClosedOnEmptyIds()

    /**
     * A lookup throwing causes a fail-closed denial (CWE-863).
     *
     * @return void
     */
    public function testCanPostFailsClosedOnException(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('OR down'));
        self::assertFalse($this->guard->canPost(begrotingId: 'pb-1', taakveldCode: '1.2', attempted: 1.0));

    }//end testCanPostFailsClosedOnException()

    /**
     * Build a schema-aware ObjectService stub returning rows per schema slug.
     *
     * @param array<int,array<string,mixed>> $taakvelden  Taakveld rows.
     * @param array<int,array<string,mixed>> $wijzigingen Begrotingswijziging rows.
     * @param array<int,array<string,mixed>> $glLines     GLLine rows.
     *
     * @return object The fluent ObjectService stub.
     */
    private function objectServiceStub(array $taakvelden, array $wijzigingen, array $glLines): object
    {
        return new class($taakvelden, $wijzigingen, $glLines) {

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
            private array $taakvelden;

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
             * @param array<int,array<string,mixed>> $taakvelden  Taakveld rows.
             * @param array<int,array<string,mixed>> $wijzigingen Begrotingswijziging rows.
             * @param array<int,array<string,mixed>> $glLines     GLLine rows.
             */
            public function __construct(array $taakvelden, array $wijzigingen, array $glLines)
            {
                $this->taakvelden  = $taakvelden;
                $this->wijzigingen = $wijzigingen;
                $this->glLines     = $glLines;
            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug (unused).
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Fluent schema setter that records the selected schema.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
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
            public function findAll(array $params=[]): array
            {
                if ($this->schema === 'Taakveld') {
                    return $this->taakvelden;
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

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
