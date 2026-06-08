<?php

/**
 * Unit tests for ProgrammabegrotingGuard.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-18
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ProgrammabegrotingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the behandelen/vaststellen preconditions (REQ-007, REQ-011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProgrammabegrotingGuardTest extends TestCase
{

    /**
     * The seven verplichte paragraaftypen.
     *
     * @var array<int,string>
     */
    private const PARAGRAAF_TYPES = [
        'lokaleHeffingen',
        'weerstandsvermogenRisicobeheersing',
        'onderhoudKapitaalgoederen',
        'financiering',
        'bedrijfsvoering',
        'verbondenPartijen',
        'grondbeleid',
    ];

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
     * @var ProgrammabegrotingGuard
     */
    private ProgrammabegrotingGuard $guard;

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

        $this->guard = new ProgrammabegrotingGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Build paragraaf rows for the given types with the given narrative.
     *
     * @param array<int,string> $types     The paragraaftypen to create.
     * @param string            $narrative The narrative to set on each.
     *
     * @return array<int,array<string,mixed>>
     */
    private function paragrafen(array $types, string $narrative): array
    {
        $rows = [];
        foreach ($types as $type) {
            $rows[] = ['type' => $type, 'narrative' => $narrative, 'begrotingId' => 'pb-1'];
        }

        return $rows;
    }//end paragrafen()

    /**
     * Stub the container's ObjectService to return the given paragrafen.
     *
     * @param array<int,array<string,mixed>> $paragrafen The paragraaf rows to return.
     *
     * @return void
     */
    private function stubParagrafen(array $paragrafen): void
    {
        $stub = new class($paragrafen) {

            /**
             * Paragraaf rows.
             *
             * @var array<int,array<string,mixed>>
             */
            private array $rows;

            /**
             * Constructor.
             *
             * @param array<int,array<string,mixed>> $rows Paragraaf rows.
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
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
             * Fluent schema setter.
             *
             * @param string $schema Schema slug (unused).
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Return the paragraaf rows.
             *
             * @param array<string,mixed> $params Query params (unused).
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params=[]): array
            {
                return $this->rows;
            }//end findAll()
        };

        $this->container->method('get')->willReturn($stub);
    }//end stubParagrafen()

    /**
     * REQ-007/D7: behandelen allowed when all seven paragrafen exist and nominale set.
     *
     * @return void
     */
    public function testCanBehandelenWhenSevenParagrafenAndNominaleSet(): void
    {
        $this->stubParagrafen($this->paragrafen(self::PARAGRAAF_TYPES, ''));
        $begroting = ['id' => 'pb-1', 'nominaleOntwikkeling' => 2.0];
        self::assertTrue($this->guard->canBehandelen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanBehandelenWhenSevenParagrafenAndNominaleSet()

    /**
     * REQ-007: behandelen denied when a paragraaf type is missing.
     *
     * @return void
     */
    public function testCanBehandelenDeniedWhenParagraafMissing(): void
    {
        $missing = array_slice(self::PARAGRAAF_TYPES, 0, 6);
        $this->stubParagrafen($this->paragrafen($missing, ''));
        $begroting = ['id' => 'pb-1', 'nominaleOntwikkeling' => 2.0];
        self::assertFalse($this->guard->canBehandelen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanBehandelenDeniedWhenParagraafMissing()

    /**
     * D7: behandelen denied when nominaleOntwikkeling is unset.
     *
     * @return void
     */
    public function testCanBehandelenDeniedWhenNominaleUnset(): void
    {
        $this->stubParagrafen($this->paragrafen(self::PARAGRAAF_TYPES, ''));
        $begroting = ['id' => 'pb-1', 'nominaleOntwikkeling' => null];
        self::assertFalse($this->guard->canBehandelen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanBehandelenDeniedWhenNominaleUnset()

    /**
     * REQ-011: vaststellen allowed when narratives non-empty and raadsbesluit set.
     *
     * @return void
     */
    public function testCanVaststellenWhenNarrativesAndBesluitSet(): void
    {
        $this->stubParagrafen($this->paragrafen(self::PARAGRAAF_TYPES, 'tekst'));
        $begroting = ['id' => 'pb-1', 'vaststellingsBesluit' => 'raadsbesluit-1'];
        self::assertTrue($this->guard->canVaststellen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanVaststellenWhenNarrativesAndBesluitSet()

    /**
     * REQ-007 scenario: vaststellen blocked when a paragraaf narrative is empty.
     *
     * @return void
     */
    public function testCanVaststellenDeniedWhenNarrativeEmpty(): void
    {
        $rows = $this->paragrafen(self::PARAGRAAF_TYPES, 'tekst');
        $rows[2]['narrative'] = '';
        $this->stubParagrafen($rows);
        $begroting = ['id' => 'pb-1', 'vaststellingsBesluit' => 'raadsbesluit-1'];
        self::assertFalse($this->guard->canVaststellen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanVaststellenDeniedWhenNarrativeEmpty()

    /**
     * REQ-011: vaststellen denied without a raadsbesluit FK.
     *
     * @return void
     */
    public function testCanVaststellenDeniedWithoutRaadsbesluit(): void
    {
        $this->stubParagrafen($this->paragrafen(self::PARAGRAAF_TYPES, 'tekst'));
        $begroting = ['id' => 'pb-1', 'vaststellingsBesluit' => ''];
        self::assertFalse($this->guard->canVaststellen(begrotingId: 'pb-1', object: $begroting));

    }//end testCanVaststellenDeniedWithoutRaadsbesluit()

    /**
     * An exception during lookup causes a fail-closed denial.
     *
     * @return void
     */
    public function testFailsClosedOnException(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('OR down'));
        $begroting = ['id' => 'pb-1', 'vaststellingsBesluit' => 'raadsbesluit-1'];
        self::assertFalse($this->guard->canVaststellen(begrotingId: 'pb-1', object: $begroting));

    }//end testFailsClosedOnException()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
