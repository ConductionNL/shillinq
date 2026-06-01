<?php

/**
 * Unit tests for UrencriteriumGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-zzp-tax-regime/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\UrencriteriumGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for UrencriteriumGuard.
 *
 * Covers:
 * - excluded-category hours are filtered out of the YTD total (REQ-ZZP-002)
 * - qualifies() is true at >= 1225 qualifying hours
 * - empty personId short-circuits to 0
 * - returns 0 on exception (no false qualification)
 */
class UrencriteriumGuardTest extends TestCase
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
     * @var UrencriteriumGuard
     */
    private UrencriteriumGuard $guard;

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

        $this->guard = new UrencriteriumGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Excluded hours (sick etc.) do not count toward the qualifying total.
     *
     * 1400 total hours including 200 excluded → 1200 qualifying (REQ-ZZP-002 scenario).
     *
     * @return void
     */
    public function testCurrentYtdHoursExcludesNonQualifyingCategories(): void
    {
        $rows = [
            ['hours' => 1200, 'category' => 'billable'],
            ['hours' => 200, 'category' => 'excluded', 'excludedReason' => 'sick'],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub($rows));

        $result = $this->guard->currentYtdHours(personId: 'person-1', year: 2026);

        self::assertEqualsWithDelta(1200.0, $result, 0.001);

    }//end testCurrentYtdHoursExcludesNonQualifyingCategories()

    /**
     * qualifies() is true at exactly 1225 qualifying hours.
     *
     * @return void
     */
    public function testQualifiesAtThreshold(): void
    {
        $rows = [
            ['hours' => 1225, 'category' => 'billable'],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub($rows));

        self::assertTrue($this->guard->qualifies(['personId' => 'person-1', 'year' => 2026]));

    }//end testQualifiesAtThreshold()

    /**
     * qualifies() is false below 1225 qualifying hours.
     *
     * @return void
     */
    public function testDoesNotQualifyBelowThreshold(): void
    {
        $rows = [
            ['hours' => 1224, 'category' => 'billable'],
        ];

        $this->container->method('get')->willReturn($this->buildObjectServiceStub($rows));

        self::assertFalse($this->guard->qualifies(['personId' => 'person-1', 'year' => 2026]));

    }//end testDoesNotQualifyBelowThreshold()

    /**
     * Empty personId short-circuits to 0 without touching the container.
     *
     * @return void
     */
    public function testCurrentYtdHoursEmptyPersonReturnsZero(): void
    {
        $this->container->expects($this->never())->method('get');

        self::assertSame(0.0, $this->guard->currentYtdHours(personId: '', year: 2026));

    }//end testCurrentYtdHoursEmptyPersonReturnsZero()

    /**
     * Returns 0 on exception — no false qualification.
     *
     * @return void
     */
    public function testCurrentYtdHoursReturnsZeroOnException(): void
    {
        $this->container->method('get')->willReturn($this->buildObjectServiceStubThatThrows());

        self::assertSame(0.0, $this->guard->currentYtdHours(personId: 'person-1', year: 2026));

    }//end testCurrentYtdHoursReturnsZeroOnException()

    /**
     * Build an ObjectService stub returning the given rows for findAll().
     *
     * @param array<mixed> $rows UrenRegistratie records to return.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $rows): object
    {
        return new class($rows) {

            private array $rows;

            private bool $served = false;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
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
                return $this->rows;
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
