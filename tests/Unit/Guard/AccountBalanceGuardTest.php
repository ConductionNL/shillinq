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

        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        // Default: return the canonical register slug.
        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new AccountBalanceGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * requireZeroBalance returns true when the GLLine register is unavailable (T1).
     *
     * @return void
     */
    public function testRequireZeroBalancePermitsArchiveInT1State(): void
    {
        // Simulate T1: container throws when trying to get ObjectService.
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('ObjectService not found'));

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertTrue($result, 'T1 state: archive should be permitted when GLLine register is absent');

    }//end testRequireZeroBalancePermitsArchiveInT1State()

    /**
     * requireZeroBalance uses integer-cent arithmetic so 0.1 + 0.2 - 0.3 is treated as balanced (C1).
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

        self::assertTrue($result, 'C1: 0.1+0.2-0.3 must be treated as balanced via integer-cent arithmetic');

    }//end testRequireZeroBalanceTreatsFloatRoundingAsBalanced()

    /**
     * requireZeroBalance returns false when debit > credit (actual non-zero balance).
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

        self::assertFalse($result, 'Non-zero balance must deny archive');

    }//end testRequireZeroBalanceReturnsFalseForNonZeroBalance()

    /**
     * requireZeroBalance is fail-closed: returns false (denies archive) on exception.
     *
     * @return void
     */
    public function testRequireZeroBalanceIsFailClosedOnException(): void
    {
        $objectService = $this->buildObjectServiceStubThatThrows();
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireZeroBalance(['accountNumber' => '0001', 'administrationId' => 'adm-1']);

        self::assertFalse($result, 'Fail-closed: exception must deny archive');

    }//end testRequireZeroBalanceIsFailClosedOnException()

    /**
     * requireSingleClosingAccount returns true trivially when account is not a closing account.
     *
     * @return void
     */
    public function testRequireSingleClosingAccountPermitsNonClosingAccount(): void
    {
        // Container should not be touched for non-closing accounts.
        $this->container->expects($this->never())->method('get');

        $result = $this->guard->requireSingleClosingAccount(['isClosingAccount' => false]);

        self::assertTrue($result);

    }//end testRequireSingleClosingAccountPermitsNonClosingAccount()

    /**
     * requireSingleClosingAccount permits save when no other closing account exists.
     *
     * @return void
     */
    public function testRequireSingleClosingAccountPermitsFirstClosingAccount(): void
    {
        $objectService = $this->buildObjectServiceStub(lines: [], closingAccounts: []);
        $this->container->method('get')->willReturn($objectService);

        $result = $this->guard->requireSingleClosingAccount([
            'isClosingAccount' => true,
            'accountNumber'    => 'CLOSE',
            'administrationId' => 'adm-1',
        ]);

        self::assertTrue($result);

    }//end testRequireSingleClosingAccountPermitsFirstClosingAccount()

    /**
     * requireSingleClosingAccount denies save when another closing account exists.
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

        $result = $this->guard->requireSingleClosingAccount([
            'isClosingAccount' => true,
            'id'               => 'new-uuid',
            'accountNumber'    => 'CLOSE-NEW',
            'administrationId' => 'adm-1',
        ]);

        self::assertFalse($result, 'A second closing account must be denied');

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
            private array $lines;
            private array $closingAccounts;
            private string $currentSchema = '';

            public function __construct(array $lines, array $closingAccounts)
            {
                $this->lines           = $lines;
                $this->closingAccounts = $closingAccounts;
            }

            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->currentSchema === 'GLLine') {
                    return $this->lines;
                }
                return $this->closingAccounts;
            }
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
            public function setRegister(string $register): static
            {
                return $this;
            }

            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * @param array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                throw new \RuntimeException('DB error');
            }
        };
    }//end buildObjectServiceStubThatThrows()
}//end class
