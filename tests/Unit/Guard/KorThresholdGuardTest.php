<?php

/**
 * Unit tests for KorThresholdGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\KorThresholdGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for KorThresholdGuard.
 *
 * Covers:
 * - currentYtdRevenue sums Invoice amounts (integer-cent safe)
 * - reachesWarning at 80% of the omzetdrempel
 * - reachesThreshold at 100% of the omzetdrempel
 * - empty adminId short-circuits to 0
 * - fail-open-to-zero on exception (no false threshold-crossing)
 */
class KorThresholdGuardTest extends TestCase
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
     * @var KorThresholdGuard
     */
    private KorThresholdGuard $guard;

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

        $this->guard = new KorThresholdGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * currentYtdRevenue sums Invoice amounts, integer-cent safe.
     *
     * @return void
     */
    public function testCurrentYtdRevenueSumsInvoices(): void
    {
        $invoices = [
            ['amount' => 0.1],
            ['amount' => 0.2],
            ['amount' => 14999.7],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub($invoices));

        $result = $this->guard->currentYtdRevenue(adminId: 'adm-1', year: 2026);

        self::assertEqualsWithDelta(15000.0, $result, 0.001);

    }//end testCurrentYtdRevenueSumsInvoices()

    /**
     * Empty adminId short-circuits to 0 without touching the container.
     *
     * @return void
     */
    public function testCurrentYtdRevenueEmptyAdminReturnsZero(): void
    {
        $this->container->expects($this->never())->method('get');

        self::assertSame(0.0, $this->guard->currentYtdRevenue(adminId: '', year: 2026));

    }//end testCurrentYtdRevenueEmptyAdminReturnsZero()

    /**
     * reachesWarning is true at exactly 80% of the threshold.
     *
     * @return void
     */
    public function testReachesWarningAtEightyPercent(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub([['amount' => 16000.0]]));

        $regime = ['administrationId' => 'adm-1', 'year' => 2026, 'thresholdAmount' => 20000];

        self::assertTrue($this->guard->reachesWarning($regime));

    }//end testReachesWarningAtEightyPercent()

    /**
     * reachesWarning is false below 80%.
     *
     * @return void
     */
    public function testReachesWarningFalseBelowEightyPercent(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub([['amount' => 15999.0]]));

        $regime = ['administrationId' => 'adm-1', 'year' => 2026, 'thresholdAmount' => 20000];

        self::assertFalse($this->guard->reachesWarning($regime));

    }//end testReachesWarningFalseBelowEightyPercent()

    /**
     * reachesThreshold is true at exactly 100% of the threshold.
     *
     * @return void
     */
    public function testReachesThresholdAtHundredPercent(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub([['amount' => 20000.0]]));

        $regime = ['administrationId' => 'adm-1', 'year' => 2026, 'thresholdAmount' => 20000];

        self::assertTrue($this->guard->reachesThreshold($regime));

    }//end testReachesThresholdAtHundredPercent()

    /**
     * reachesThreshold uses the statutory default when no thresholdAmount is set.
     *
     * @return void
     */
    public function testReachesThresholdUsesDefaultWhenUnset(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStub([['amount' => 20000.0]]));

        $regime = ['administrationId' => 'adm-1', 'year' => 2026];

        self::assertTrue($this->guard->reachesThreshold($regime));

    }//end testReachesThresholdUsesDefaultWhenUnset()

    /**
     * On exception, currentYtdRevenue returns 0 — no false threshold-crossing.
     *
     * @return void
     */
    public function testCurrentYtdRevenueReturnsZeroOnException(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStubThatThrows());

        self::assertSame(0.0, $this->guard->currentYtdRevenue(adminId: 'adm-1', year: 2026));

    }//end testCurrentYtdRevenueReturnsZeroOnException()

    /**
     * Build an ObjectService stub returning the given invoices for findAll().
     *
     * @param array<mixed> $invoices Invoice records to return.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $invoices): object
    {
        return new class($invoices) {

            private array $invoices;

            private bool $served = false;

            public function __construct(array $invoices)
            {
                $this->invoices = $invoices;
            }//end __construct()

            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param  array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->served === true) {
                    return [];
                }

                $this->served = true;
                return $this->invoices;
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
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param  array<string,mixed> $params
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                throw new \RuntimeException('DB error');
            }//end findAll()
        };
    }//end buildObjectServiceStubThatThrows()
}//end class
