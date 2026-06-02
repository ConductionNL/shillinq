<?php

/**
 * Unit tests for BbvComplianceGuard.
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
 * @spec openspec/changes/bookkeeping-bbv-compliance/specs/bookkeeping-bbv-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BbvComplianceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BbvComplianceGuard.
 *
 * Covers REQ-BBV-001 (RGS mapping verplicht), REQ-BBV-002 (taakveld-classificatie),
 * REQ-BBV-003 (meerjarenraming sluitend), REQ-BBV-004 (reserve/voorziening route),
 * REQ-BBV-005 (MVA-activering), plus non-BBV bypass and fail-closed behaviour.
 */
class BbvComplianceGuardTest extends TestCase
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
     * @var BbvComplianceGuard
     */
    private BbvComplianceGuard $guard;

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
        $this->appConfig->method('getValueInt')->willReturn(5000000);

        $this->guard = new BbvComplianceGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /*
     * REQ-BBV-001: RGS-decentraal verplicht voor BBV-tenants.
     */

    /**
     * Non-BBV tenant may save an account without rgsDecentraalCode.
     *
     * @return void
     */
    public function testNonBbvAccountWithoutRgsMappingIsPermitted(): void
    {
        $result = $this->guard->requireBbvAccountMapping([
            'accountNumber'      => '4100',
            'administrationType' => 'mkb',
        ]);

        self::assertTrue($result, 'REQ-BBV-001: non-BBV tenants are exempt');

    }//end testNonBbvAccountWithoutRgsMappingIsPermitted()

    /**
     * BBV tenant may not save an account without rgsDecentraalCode.
     *
     * @return void
     */
    public function testBbvAccountWithoutRgsMappingIsRejected(): void
    {
        $result = $this->guard->requireBbvAccountMapping([
            'accountNumber'      => '4100',
            'administrationType' => 'gemeente',
        ]);

        self::assertFalse($result, 'REQ-BBV-001: BBV account without rgsDecentraalCode is rejected');

    }//end testBbvAccountWithoutRgsMappingIsRejected()

    /**
     * BBV tenant with a valid rgsDecentraalCode is permitted.
     *
     * @return void
     */
    public function testBbvAccountWithRgsMappingIsPermitted(): void
    {
        $result = $this->guard->requireBbvAccountMapping([
            'accountNumber'      => '4100',
            'administrationType' => 'gemeente',
            'rgsDecentraalCode'  => 'WLasLes',
        ]);

        self::assertTrue($result);

    }//end testBbvAccountWithRgsMappingIsPermitted()

    /*
     * REQ-BBV-002: Taakveld-classificatie op exploitatie-boekingen.
     */

    /**
     * Exploitatie line missing taakveld/economische_categorie is rejected.
     *
     * @return void
     */
    public function testExploitatieLineWithoutTaakveldIsRejected(): void
    {
        $account = ['accountNumber' => '4310', 'administrationId' => 'gem-1', 'bbvClassificatie' => 'exploitatie'];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '4310',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
        ]);

        self::assertFalse($result, 'REQ-BBV-002: exploitatie line without taakveld is rejected');

    }//end testExploitatieLineWithoutTaakveldIsRejected()

    /**
     * Exploitatie line with taakveld + economische_categorie is permitted.
     *
     * @return void
     */
    public function testExploitatieLineWithClassificationIsPermitted(): void
    {
        $account = ['accountNumber' => '4310', 'administrationId' => 'gem-1', 'bbvClassificatie' => 'exploitatie'];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'        => '4310',
            'administrationId'     => 'gem-1',
            'administrationType'   => 'gemeente',
            'taakveld'             => '7.5',
            'economischeCategorie' => '3.4.3',
        ]);

        self::assertTrue($result);

    }//end testExploitatieLineWithClassificationIsPermitted()

    /**
     * Non-BBV exploitatie line bypasses classification entirely.
     *
     * @return void
     */
    public function testNonBbvLineBypassesClassification(): void
    {
        $this->container->expects($this->never())->method('get');

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '4310',
            'administrationType' => 'zzp',
        ]);

        self::assertTrue($result);

    }//end testNonBbvLineBypassesClassification()

    /*
     * REQ-BBV-004: Reserves en voorzieningen — correcte mutatieroute.
     */

    /**
     * Reserve mutation off taakveld 0.10 is rejected.
     *
     * @return void
     */
    public function testReserveMutationOffTaakveld010IsRejected(): void
    {
        $account = ['accountNumber' => '2310', 'administrationId' => 'gem-1', 'bbvClassificatie' => 'reserve'];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '2310',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
            'taakveld'           => '4.2',
        ]);

        self::assertFalse($result, 'REQ-BBV-004: reserve mutation requires taakveld 0.10');

    }//end testReserveMutationOffTaakveld010IsRejected()

    /**
     * Reserve mutation on taakveld 0.10 is permitted.
     *
     * @return void
     */
    public function testReserveMutationOnTaakveld010IsPermitted(): void
    {
        $account = ['accountNumber' => '2310', 'administrationId' => 'gem-1', 'bbvClassificatie' => 'reserve'];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '2310',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
            'taakveld'           => '0.10',
        ]);

        self::assertTrue($result);

    }//end testReserveMutationOnTaakveld010IsPermitted()

    /**
     * Voorziening mutation on the gekoppelde taakveld is permitted.
     *
     * @return void
     */
    public function testVoorzieningMutationOnGekoppeldTaakveldIsPermitted(): void
    {
        $account = [
            'accountNumber'    => '2420',
            'administrationId' => 'gem-1',
            'bbvClassificatie' => 'voorziening',
            'taakveld'         => '2.1',
        ];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '2420',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
            'taakveld'           => '2.1',
        ]);

        self::assertTrue($result);

    }//end testVoorzieningMutationOnGekoppeldTaakveldIsPermitted()

    /**
     * Voorziening mutation on a different taakveld is rejected.
     *
     * @return void
     */
    public function testVoorzieningMutationOnWrongTaakveldIsRejected(): void
    {
        $account = [
            'accountNumber'    => '2420',
            'administrationId' => 'gem-1',
            'bbvClassificatie' => 'voorziening',
            'taakveld'         => '2.1',
        ];
        $this->container->method('get')->willReturn($this->buildAccountStub(account: $account));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '2420',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
            'taakveld'           => '7.2',
        ]);

        self::assertFalse($result, 'REQ-BBV-004: voorziening mutation must use the gekoppelde taakveld');

    }//end testVoorzieningMutationOnWrongTaakveldIsRejected()

    /**
     * Line is fail-closed when the account cannot be resolved.
     *
     * @return void
     */
    public function testLineWithUnresolvableAccountIsFailClosed(): void
    {
        $this->container->method('get')->willReturn($this->buildAccountStub(account: null));

        $result = $this->guard->requireLineClassification([
            'accountNumber'      => '9999',
            'administrationId'   => 'gem-1',
            'administrationType' => 'gemeente',
        ]);

        self::assertFalse($result, 'Unresolvable account must fail closed for BBV tenants');

    }//end testLineWithUnresolvableAccountIsFailClosed()

    /*
     * REQ-BBV-003: Meerjarenraming T+0 t/m T+3 sluitend.
     */

    /**
     * Sluitende meerjarenraming permits publication.
     *
     * @return void
     */
    public function testSluitendeMeerjarenramingPermitsPublish(): void
    {
        $rows = [
            ['meerjarenHorizon' => 0, 'batenCents' => 100, 'lastenCents' => 90, 'mutatieReservesCents' => 0],
            ['meerjarenHorizon' => 1, 'batenCents' => 100, 'lastenCents' => 100, 'mutatieReservesCents' => 5],
        ];
        $this->container->method('get')->willReturn($this->buildBudgetStub(rows: $rows));

        $result = $this->guard->requireMeerjarenramingSluitend([
            'administrationType' => 'gemeente',
            'administrationId'   => 'gem-1',
            'boekjaar'           => 2026,
        ]);

        self::assertTrue($result, 'REQ-BBV-003: all horizons sluitend → publish permitted');

    }//end testSluitendeMeerjarenramingPermitsPublish()

    /**
     * Non-sluitende horizon blocks publication.
     *
     * @return void
     */
    public function testNonSluitendeMeerjarenramingBlocksPublish(): void
    {
        $rows = [
            ['meerjarenHorizon' => 0, 'batenCents' => 100, 'lastenCents' => 90, 'mutatieReservesCents' => 0],
            ['meerjarenHorizon' => 2, 'batenCents' => 100, 'lastenCents' => 220, 'mutatieReservesCents' => 0],
        ];
        $this->container->method('get')->willReturn($this->buildBudgetStub(rows: $rows));

        $result = $this->guard->requireMeerjarenramingSluitend([
            'administrationType' => 'gemeente',
            'administrationId'   => 'gem-1',
            'boekjaar'           => 2026,
        ]);

        self::assertFalse($result, 'REQ-BBV-003: a negative horizon-saldo blocks publication');

    }//end testNonSluitendeMeerjarenramingBlocksPublish()

    /**
     * Raadsbesluit override permits a non-sluitende publicatie.
     *
     * @return void
     */
    public function testRaadsbesluitOverridePermitsPublish(): void
    {
        // Override short-circuits before any budget lookup.
        $this->container->expects($this->never())->method('get');

        $result = $this->guard->requireMeerjarenramingSluitend([
            'administrationType' => 'gemeente',
            'administrationId'   => 'gem-1',
            'boekjaar'           => 2026,
            'raadsbesluitNummer' => 'RB-2026-12',
            'raadsbesluitDatum'  => '2026-06-26',
        ]);

        self::assertTrue($result, 'REQ-BBV-003: raadsbesluit override unblocks publication');

    }//end testRaadsbesluitOverridePermitsPublish()

    /**
     * Meerjarenraming lookup failure is fail-closed.
     *
     * @return void
     */
    public function testMeerjarenramingLookupFailureIsFailClosed(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('DB error'));

        $result = $this->guard->requireMeerjarenramingSluitend([
            'administrationType' => 'gemeente',
            'administrationId'   => 'gem-1',
            'boekjaar'           => 2026,
        ]);

        self::assertFalse($result, 'Fail-closed: lookup failure denies publication');

    }//end testMeerjarenramingLookupFailureIsFailClosed()

    /*
     * REQ-BBV-005: MVA-activering.
     */

    /**
     * Maatschappelijk-nut investering above grens without a termijn is rejected.
     *
     * @return void
     */
    public function testMaatschappelijkNutAboveGrensWithoutTermijnIsRejected(): void
    {
        $result = $this->guard->requireMvaActivation([
            'administrationType' => 'gemeente',
            'omschrijving'       => 'Rondweg',
            'mvaCategorie'       => 'maatschappelijk-nut',
            'aanschafwaardeCents' => 840000000,
        ]);

        self::assertFalse($result, 'REQ-BBV-005: investering boven grens moet geactiveerd worden');

    }//end testMaatschappelijkNutAboveGrensWithoutTermijnIsRejected()

    /**
     * Maatschappelijk-nut investering above grens with a termijn is permitted.
     *
     * @return void
     */
    public function testMaatschappelijkNutAboveGrensWithTermijnIsPermitted(): void
    {
        $result = $this->guard->requireMvaActivation([
            'administrationType'       => 'gemeente',
            'omschrijving'             => 'Rondweg',
            'mvaCategorie'             => 'maatschappelijk-nut',
            'aanschafwaardeCents'      => 840000000,
            'afschrijvingstermijnJaar' => 40,
        ]);

        self::assertTrue($result);

    }//end testMaatschappelijkNutAboveGrensWithTermijnIsPermitted()

    /**
     * Maatschappelijk-nut investering below grens is permitted (no activation forced).
     *
     * @return void
     */
    public function testMaatschappelijkNutBelowGrensIsPermitted(): void
    {
        $result = $this->guard->requireMvaActivation([
            'administrationType'  => 'gemeente',
            'omschrijving'        => 'Bankje',
            'mvaCategorie'        => 'maatschappelijk-nut',
            'aanschafwaardeCents' => 100000,
        ]);

        self::assertTrue($result);

    }//end testMaatschappelijkNutBelowGrensIsPermitted()

    /**
     * Economisch-nut MVA is out of scope for the activation constraint.
     *
     * @return void
     */
    public function testEconomischNutMvaIsExempt(): void
    {
        $result = $this->guard->requireMvaActivation([
            'administrationType'  => 'gemeente',
            'omschrijving'        => 'Gemeentehuis',
            'mvaCategorie'        => 'economisch-nut',
            'aanschafwaardeCents' => 5000000000,
        ]);

        self::assertTrue($result);

    }//end testEconomischNutMvaIsExempt()

    /**
     * Build an ObjectService stub that returns a single Account record.
     *
     * @param array<string,mixed>|null $account The account to return, or null for empty.
     *
     * @return object
     */
    private function buildAccountStub(?array $account): object
    {
        return new class($account) {
            /**
             * @var array<string,mixed>|null
             */
            private ?array $account;

            /**
             * @param array<string,mixed>|null $account
             */
            public function __construct(?array $account)
            {
                $this->account = $account;
            }

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
                if ($this->account === null) {
                    return [];
                }
                return [$this->account];
            }
        };
    }//end buildAccountStub()

    /**
     * Build an ObjectService stub that returns MeerjarenBudget rows.
     *
     * @param array<int,array<string,mixed>> $rows The budget rows to return.
     *
     * @return object
     */
    private function buildBudgetStub(array $rows): object
    {
        return new class($rows) {
            /**
             * @var array<int,array<string,mixed>>
             */
            private array $rows;

            /**
             * @param array<int,array<string,mixed>> $rows
             */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

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
                return $this->rows;
            }
        };
    }//end buildBudgetStub()
}//end class
