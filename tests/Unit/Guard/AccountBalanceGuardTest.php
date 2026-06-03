<?php

/**
 * Unit tests for AccountBalanceGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-chart-of-accounts/specs/bookkeeping-chart-of-accounts/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\AccountBalanceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AccountBalanceGuard.
 *
 * Covers:
 * - Float-to-cents fix: 0.1 + 0.2 - 0.3 is balanced
 * - GLLine register unavailable → archive permitted (T1 deferral)
 * - requireZeroBalance returns false when balance is non-zero
 * - requireSingleClosingAccount invariant
 * - Fail-closed on exception
 */
class AccountBalanceGuardTest extends TestCase
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
     * @var AccountBalanceGuard
     */
    private AccountBalanceGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

        // Default: return the canonical register slug.
        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new AccountBalanceGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * RequireZeroBalance returns true when the GLLine register is unavailable (T1).
     *
     * @return void
     */
    public function testRequireZeroBalancePermitsArchiveInT1State(): void
    {
        // Simulate T1: container throws when trying to get ObjectService.
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService not found'));

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertTrue(condition: $result, message: 'T1 state: archive should be permitted when GLLine register is absent');

    }//end testRequireZeroBalancePermitsArchiveInT1State()

    /**
     * RequireZeroBalance uses integer-cent arithmetic so 0.1 + 0.2 - 0.3 is treated as balanced (C1).
     *
     * IEEE-754: (float)(0.1 + 0.2 - 0.3) !== 0.0, but (int)round(0.1*100) + (int)round(0.2*100) - (int)round(0.3*100) === 0.
     *
     * @return void
     */
    public function testRequireZeroBalanceTreatsFloatRoundingAsBalanced(): void
    {
        $lines = [
            ['debit' => 0.1, 'credit' => 0.0],
            ['debit' => 0.2, 'credit' => 0.0],
            ['debit' => 0.0, 'credit' => 0.3],
        ];

        // The ObjectService stub: setRegister/setSchema returns itself, findAll returns $lines.
        $objectService = $this->buildObjectServiceStub(lines: $lines, closingAccounts: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertTrue(condition: $result, message: 'C1: 0.1+0.2-0.3 must be treated as balanced via integer-cent arithmetic');

    }//end testRequireZeroBalanceTreatsFloatRoundingAsBalanced()

    /**
     * RequireZeroBalance returns false when debit > credit (actual non-zero balance).
     *
     * @return void
     */
    public function testRequireZeroBalanceReturnsFalseForNonZeroBalance(): void
    {
        $lines = [
            ['debit' => 100.0, 'credit' => 0.0],
        ];

        $objectService = $this->buildObjectServiceStub(lines: $lines, closingAccounts: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertFalse(condition: $result, message: 'Non-zero balance must deny archive');

    }//end testRequireZeroBalanceReturnsFalseForNonZeroBalance()

    /**
     * RequireZeroBalance is fail-closed: returns false (denies archive) on exception.
     *
     * @return void
     */
    public function testRequireZeroBalanceIsFailClosedOnException(): void
    {
        $objectService = $this->buildObjectServiceStubThatThrows();
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertFalse(condition: $result, message: 'Fail-closed: exception must deny archive');

    }//end testRequireZeroBalanceIsFailClosedOnException()

    /**
     * RequireSingleClosingAccount returns true trivially when account is not a closing account.
     *
     * @return void
     */
    public function testRequireSingleClosingAccountPermitsNonClosingAccount(): void
    {
        // Container should not be touched for non-closing accounts.
        $this->container->expects($this->never())->method('get');

        $result = $this->guard->requireSingleClosingAccount(['isClosingAccount' => false]);

        self::assertTrue(condition: $result);

    }//end testRequireSingleClosingAccountPermitsNonClosingAccount()

    /**
     * RequireSingleClosingAccount permits save when no other closing account exists.
     *
     * @return void
     */
    public function testRequireSingleClosingAccountPermitsFirstClosingAccount(): void
    {
        $objectService = $this->buildObjectServiceStub(lines: [], closingAccounts: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireSingleClosingAccount(
                [
                    'isClosingAccount' => true,
                    'accountNumber'    => 'CLOSE',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertTrue(condition: $result);

    }//end testRequireSingleClosingAccountPermitsFirstClosingAccount()

    /**
     * RequireSingleClosingAccount denies save when another closing account exists.
     *
     * @return void
     */
    public function testRequireSingleClosingAccountDeniesDuplicateClosingAccount(): void
    {
        $existingClosing = [
            ['id' => 'other-uuid', 'accountNumber' => 'CLOSE-OLD', 'administrationId' => 'adm-1', 'isClosingAccount' => true],
        ];
        $objectService   = $this->buildObjectServiceStub(lines: [], closingAccounts: $existingClosing);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireSingleClosingAccount(
                [
                    'isClosingAccount' => true,
                    'id'               => 'new-uuid',
                    'accountNumber'    => 'CLOSE-NEW',
                    'administrationId' => 'adm-1',
                ]
                );

        self::assertFalse(condition: $result, message: 'A second closing account must be denied');

    }//end testRequireSingleClosingAccountDeniesDuplicateClosingAccount()

    /**
     * Build an anonymous ObjectService stub that returns the given lines and closingAccounts
     * for the respective findAll() calls. Implements the fluent setRegister/setSchema interface.
     *
     * @param array<mixed> $lines           GLLine records to return for balance queries.
     * @param array<mixed> $closingAccounts Account records to return for uniqueness queries.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $lines, array $closingAccounts): object
    {
        return new class($lines, $closingAccounts) {

            /**
             * GLLine records returned for balance queries.
             *
             * @var array<mixed>
             */
            private array $lines;

            /**
             * Account records returned for uniqueness queries.
             *
             * @var array<mixed>
             */
            private array $closingAccounts;

            /**
             * Current schema name for routing findAll results.
             *
             * @var string
             */
            private string $currentSchema = '';

            /**
             * Constructor.
             *
             * @param array<mixed> $lines           GLLine records.
             * @param array<mixed> $closingAccounts Account records.
             *
             * @return void
             */
            public function __construct(array $lines, array $closingAccounts)
            {
                $this->lines           = $lines;
                $this->closingAccounts = $closingAccounts;
            }//end __construct()

            /**
             * Set the register slug.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Set the schema name and track it for findAll routing.
             *
             * @param string $schema Schema name.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;
            }//end setSchema()

            /**
             * Return records for the given query params.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->currentSchema === 'GLLine') {
                    return $this->lines;
                }

                return $this->closingAccounts;
            }//end findAll()
        };
    }//end buildObjectServiceStub()

    /**
     * Build an ObjectService stub that throws on findAll().
     *
     * @return object
     */
    private function buildObjectServiceStubThatThrows(): object
    {
        return new class {
            /**
             * Set the register slug.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Set the schema name.
             *
             * @param string $schema Schema name.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Returns empty array for the availability probe (no 'filters' key),
             * and throws for the actual balance query (has 'filters' key).
             * This ensures isGLLineRegisterAvailable() returns true so the
             * fail-closed path inside requireZeroBalance() is actually exercised.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if (array_key_exists('filters', $params) === true) {
                    throw new \RuntimeException('DB error');
                }

                return [];
            }//end findAll()
        };
    }//end buildObjectServiceStubThatThrows()
}//end class
