<?php

/**
 * Unit tests for the bookkeeping-credit-control-dunning register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the credit-control & dunning fragment is valid JSON, declares the seven
 * dunning schemas with their declarative calculation / lifecycle metadata, merges
 * additively onto the monolith (ADR-037), references the DunningGuard for the
 * cross-field preconditions (ADR-031 exception path), and ships seed objects.
 */
final class CreditControlDunningFragmentTest extends TestCase
{
    // PHPUnit assertions take positional ($actual, $expected, $message) arguments;
    // the custom named-parameter sniff does not apply to them.
    // phpcs:disable CustomSniffs.Functions.NamedParameters

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/bookkeeping-credit-control-dunning.json';

    /**
     * Absolute path to the monolith register file.
     *
     * @var string
     */
    private string $registerPath = __DIR__.'/../../../lib/Settings/shillinq_register.json';

    /**
     * Invoke the private static SettingsService::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()

    /**
     * Load the fragment as an array.
     *
     * @return array<mixed>
     */
    private function fragment(): array
    {
        return json_decode((string) file_get_contents($this->fragmentPath), true);

    }//end fragment()

    /**
     * The fragment file is present and valid JSON with the expected sections.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        $data = json_decode((string) file_get_contents($this->fragmentPath), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($data);
        self::assertArrayHasKey('schemas', $data['components']);
        self::assertArrayHasKey('objects', $data['components']);

    }//end testFragmentIsValidJson()

    /**
     * The fragment declares the seven new dunning schemas (REQ-CCD-001..010).
     *
     * @return void
     */
    public function testFragmentDeclaresSevenSchemas(): void
    {
        $schemas = $this->fragment()['components']['schemas'];

        $expected = [
            'DunningLadder',
            'KlantLadderOverride',
            'DunningRun',
            'IncassoKostenBerekening',
            'DunningPauseDispute',
            'CreditScore',
            'OninbaarAfschrijving',
        ];
        foreach ($expected as $name) {
            self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
            self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
        }

    }//end testFragmentDeclaresSevenSchemas()

    /**
     * State-bearing schemas declare an x-openregister-lifecycle on `state`
     * (design D6/D7/D9).
     *
     * @return void
     */
    public function testStateBearingSchemasDeclareLifecycle(): void
    {
        $schemas = $this->fragment()['components']['schemas'];

        $expected = [
            'KlantLadderOverride',
            'DunningRun',
            'DunningPauseDispute',
            'OninbaarAfschrijving',
        ];
        foreach ($expected as $name) {
            self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$name], "$name must declare a lifecycle");
            self::assertSame('state', $schemas[$name]['x-openregister-lifecycle']['field']);
        }

    }//end testStateBearingSchemasDeclareLifecycle()

    /**
     * The BIK-staffel and wettelijke-rente logic is declarative
     * (x-openregister-calculations, ADR-031 — no BIKStaffelCalculator.php).
     *
     * @return void
     */
    public function testBikStaffelAndRenteAreDeclarative(): void
    {
        $schemas = $this->fragment()['components']['schemas'];
        $calc    = $schemas['IncassoKostenBerekening']['x-openregister-calculations'];

        self::assertArrayHasKey('berekening.schaal1_0_2500', $calc);
        self::assertArrayHasKey('berekening.schaal2_2500_5000', $calc);
        self::assertArrayHasKey('berekening.schaal3_5000_10000', $calc);
        self::assertArrayHasKey('berekening.toegepast', $calc);
        self::assertArrayHasKey('wettelijkeRente.bedrag', $calc);
        self::assertArrayHasKey('totaalVerschuldigd', $calc);

        // Every calculation carries an expr (declarative formula).
        foreach ($calc as $field => $spec) {
            self::assertArrayHasKey('expr', $spec, "Calculation $field must carry an expr");
            self::assertNotSame('', trim((string) $spec['expr']));
        }

    }//end testBikStaffelAndRenteAreDeclarative()

    /**
     * No PHP dunning-calculation service ships: only the ADR-031 exception-path
     * DunningGuard exists under lib/Lifecycle.
     *
     * @return void
     */
    public function testNoDunningCalculationServiceShips(): void
    {
        $libDir = __DIR__.'/../../../lib';

        foreach (['Service/DunningCalculationService.php', 'Service/BIKStaffelCalculator.php', 'Service/IncassoKostenService.php'] as $forbidden) {
            self::assertFileDoesNotExist($libDir.'/'.$forbidden, "ADR-031: $forbidden must not exist");
        }

        self::assertFileExists($libDir.'/Lifecycle/DunningGuard.php', 'The ADR-031 exception-path guard must exist');

    }//end testNoDunningCalculationServiceShips()

    /**
     * Lifecycle transitions reference the DunningGuard where a cross-field
     * precondition is required (ADR-031 exception path).
     *
     * @return void
     */
    public function testLifecycleTransitionsReferenceGuard(): void
    {
        $schemas = $this->fragment()['components']['schemas'];

        $execute = $schemas['DunningRun']['x-openregister-lifecycle']['transitions']['execute'];
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\DunningGuard::canExecuteRun',
            $execute['requires']
        );

        $resolve = $schemas['DunningPauseDispute']['x-openregister-lifecycle']['transitions']['resolve'];
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\DunningGuard::canResolvePause',
            $resolve['requires']
        );

        $post = $schemas['OninbaarAfschrijving']['x-openregister-lifecycle']['transitions']['post'];
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\DunningGuard::canPostWriteOff',
            $post['requires']
        );

        $activate = $schemas['KlantLadderOverride']['x-openregister-lifecycle']['transitions']['activate'];
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\DunningGuard::canActivateOverride',
            $activate['requires']
        );

    }//end testLifecycleTransitionsReferenceGuard()

    /**
     * The €8.400 BIK-staffel seed evaluates to the worked €795 total (REQ-CCD-003).
     *
     * @return void
     */
    public function testSeedBikCalculationMatchesWorkedExample(): void
    {
        $objects = $this->fragment()['components']['objects'];

        $ik = null;
        foreach ($objects as $object) {
            if (($object['@self']['schema'] ?? '') === 'IncassoKostenBerekening') {
                $ik = $object;
                break;
            }
        }

        self::assertNotNull($ik, 'Fragment must seed an IncassoKostenBerekening example');
        self::assertSame(8400.00, $ik['hoofdsom']);
        self::assertSame(795.00, $ik['berekening']['totaal']);
        self::assertSame(795.00, $ik['berekening']['toegepast']);
        self::assertSame('HANDELSRENTE_B2B_6_119A_BW', $ik['wettelijkeRente']['type']);

    }//end testSeedBikCalculationMatchesWorkedExample()

    /**
     * Merging the fragment onto the monolith adds the seven schemas without
     * dropping any existing schema (ADR-037 additive union).
     *
     * @return void
     */
    public function testFragmentMergesAdditivelyOntoMonolith(): void
    {
        $base = json_decode((string) file_get_contents($this->registerPath), true);
        $frag = $this->fragment();

        $beforeSchemas = array_keys($base['components']['schemas']);

        $merged  = $this->merge($base, $frag);
        $schemas = $merged['components']['schemas'];

        self::assertArrayHasKey('DunningLadder', $schemas);
        self::assertArrayHasKey('IncassoKostenBerekening', $schemas);
        self::assertArrayHasKey('OninbaarAfschrijving', $schemas);

        // Pre-existing schemas survive the merge.
        foreach ($beforeSchemas as $name) {
            self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive merge");
        }

    }//end testFragmentMergesAdditivelyOntoMonolith()

    /**
     * Seed objects target only declared schemas in the shillinq register
     * (REQ-CCD seed-data pattern).
     *
     * @return void
     */
    public function testSeedObjectsTargetDeclaredSchemas(): void
    {
        $frag    = $this->fragment();
        $schemas = $frag['components']['schemas'];
        $objects = $frag['components']['objects'];

        self::assertNotEmpty($objects);
        foreach ($objects as $object) {
            self::assertArrayHasKey('@self', $object);
            self::assertSame('shillinq', $object['@self']['register']);
            $schema = $object['@self']['schema'];
            self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
        }

    }//end testSeedObjectsTargetDeclaredSchemas()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
