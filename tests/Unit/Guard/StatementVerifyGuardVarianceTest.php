<?php

/**
 * Unit tests for StatementVerifyGuard bank-balance tie-out flagging.
 *
 * Evidence test for payment-control-guards audit item 2 (bank-balance tie-out).
 * The statement-closing-balance vs GL-bank-account tie-out is ALREADY OWNED by
 * bookkeeping-reconciliation-reports' StatementVerifyGuard::verifyStatementBalance()
 * (REQ-REC-002) — this change does NOT re-implement it. These tests pin the
 * BAD PATH the audit cares about: when the statement closing balance does NOT
 * tie to the expected GL balance, a non-zero variance is FLAGGED (persisted onto
 * the BankReconciliation record), while a balance that ties flags nothing.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\StatementVerifyGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that StatementVerifyGuard flags a bank statement that does not tie out.
 */
class StatementVerifyGuardVarianceTest extends TestCase
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
     * Recording ObjectService stub shared with the guard.
     *
     * @var object
     */
    private object $objectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->appConfig->method('getValueString')->willReturn('shillinq');
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        // A fluent ObjectService stub: no GL lines (net activity 0), recording
        // every updateObject() so the test can read the persisted variance.
        $this->objectService = new class {

            /**
             * Captured updateObject payloads keyed by id.
             *
             * @var array<string, array<string, mixed>>
             */
            public array $updates = [];

            /**
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }

            /**
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }

            /**
             * @param array<string, mixed> $params Query params (unused).
             *
             * @return array<int, mixed> No GL lines — net activity is zero.
             */
            public function findAll(array $params=[]): array
            {
                return [];
            }

            /**
             * @param string               $id   Object id.
             * @param array<string, mixed> $data Fields to persist.
             *
             * @return array<string, mixed>
             */
            public function updateObject(string $id, array $data): array
            {
                $this->updates[$id] = $data;
                return $data;
            }
        };

        $this->container->method('get')->willReturn($this->objectService);

    }//end setUp()


    /**
     * Build the guard under test.
     *
     * @return StatementVerifyGuard
     */
    private function guard(): StatementVerifyGuard
    {
        return new StatementVerifyGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }//end guard()


    /**
     * A statement whose closing balance does NOT tie to GL flags a non-zero variance.
     *
     * opening 5000.00 + GL net activity 0.00 = expected 5000.00; the statement
     * closes at 5100.00, so the tie-out fails by 100.00 and that variance is
     * persisted (flagged) onto the reconciliation.
     *
     * @return void
     */
    public function testNonTyingBalanceIsFlagged(): void
    {
        $result = $this->guard()->verifyStatementBalance(
            [
                'id'             => 'rec-1',
                'bankAccountId'  => '1000',
                'openingBalance' => 5000.00,
                'closingBalance' => 5100.00,
                'statementPeriodStart' => '2026-01-01',
                'statementPeriodEnd'   => '2026-01-31',
            ]
        );

        // REQ-REC-002: variance surfaces a warning but does not block.
        self::assertTrue($result, 'A variance surfaces a warning but does not block the transition');
        self::assertArrayHasKey('rec-1', $this->objectService->updates);
        self::assertEqualsWithDelta(100.00, $this->objectService->updates['rec-1']['variance'], 0.001, 'The non-tying balance must flag a 100.00 variance');
        self::assertNotSame(0, $this->objectService->updates['rec-1']['variance'], 'A non-tying balance must not flag a zero variance');
        self::assertEqualsWithDelta(5000.00, $this->objectService->updates['rec-1']['expectedGLBalance'], 0.001);

    }//end testNonTyingBalanceIsFlagged()


    /**
     * A statement that ties out flags a zero variance.
     *
     * @return void
     */
    public function testTyingBalanceFlagsZeroVariance(): void
    {
        $result = $this->guard()->verifyStatementBalance(
            [
                'id'             => 'rec-2',
                'bankAccountId'  => '1000',
                'openingBalance' => 5000.00,
                'closingBalance' => 5000.00,
                'statementPeriodStart' => '2026-01-01',
                'statementPeriodEnd'   => '2026-01-31',
            ]
        );

        self::assertTrue($result);
        self::assertEqualsWithDelta(0.00, $this->objectService->updates['rec-2']['variance'], 0.001, 'A tying balance must flag a zero variance');

    }//end testTyingBalanceFlagsZeroVariance()
}//end class
