<?php

/**
 * Unit tests for the verplichtingen-commitment-accounting delta on the
 * bookkeeping-verplichtingenadministratie register fragment.
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
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-VPL-010's bronReferentie field and REQ-VPL-011's declarative
 * committed-vs-realised aggregation, and that no parallel PHP reporting
 * service duplicates the aggregation's figures (ADR-031).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VerplichtingenCommitmentAccountingFragmentTest extends TestCase
{

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json';

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
     * The fragment is present and valid JSON.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        json_decode((string) file_get_contents($this->fragmentPath), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

    }//end testFragmentIsValidJson()

    /**
     * REQ-VPL-010: Verplichting declares an optional bronReferentie provenance field.
     *
     * @return void
     */
    public function testVerplichtingDeclaresBronReferentie(): void
    {
        $verplichting = $this->fragment()['components']['schemas']['Verplichting'];
        self::assertArrayHasKey('sourceReference', $verplichting['properties']);
        self::assertTrue($verplichting['properties']['sourceReference']['nullable'] ?? false);
        self::assertArrayNotContains('sourceReference', ($verplichting['required'] ?? []));

    }//end testVerplichtingDeclaresBronReferentie()

    /**
     * Custom PHPUnit-style helper: assertArrayNotContains (not a native
     * assertion in the PHPUnit version pinned here).
     *
     * @param mixed        $needle   Value that must not be present.
     * @param array<mixed> $haystack Array to search.
     *
     * @return void
     */
    private static function assertArrayNotContains($needle, array $haystack): void
    {
        self::assertNotContains($needle, $haystack);

    }//end assertArrayNotContains()

    /**
     * REQ-VPL-011: Verplichtingsregel declares the committed-vs-realised
     * per-budget-line aggregation, grouped by the full coderingscombinatie.
     *
     * @return void
     */
    public function testVerplichtingsregelDeclaresCommittedVsRealisedAggregation(): void
    {
        $regel = $this->fragment()['components']['schemas']['Verplichtingsregel'];
        self::assertArrayHasKey('x-openregister-aggregations', $regel);
        self::assertArrayHasKey('committedVsRealisedPerBudgetLine', $regel['x-openregister-aggregations']);

        $agg = $regel['x-openregister-aggregations']['committedVsRealisedPerBudgetLine'];
        self::assertSame('Verplichtingsregel', $agg['source']);
        self::assertSame(
            ['programme', 'costCentre', 'financialYear', 'grootboekrekening'],
            $agg['groupBy']
        );
        self::assertContains('restant_verplicht', $agg['sum']);
        self::assertContains('invoiced_amount', $agg['sum']);
        self::assertSame('Budget', $agg['join']['through']);

    }//end testVerplichtingsregelDeclaresCommittedVsRealisedAggregation()

    /**
     * REQ-VPL-011 / ADR-031: no PHP class in lib/Service or lib/Lifecycle
     * independently computes committed-vs-realised budget-line figures —
     * the aggregation is the sole source (declarative only).
     *
     * @return void
     */
    public function testNoParallelReportingServiceComputesTheSameFigures(): void
    {
        $suspects = [];
        foreach (['lib/Service', 'lib/Lifecycle'] as $dir) {
            $absolute = __DIR__.'/../../../'.$dir;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                if (str_contains($contents, 'committedVsRealisedPerBudgetLine') === true) {
                    $suspects[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $suspects,
            'No PHP service may reference/recompute the committedVsRealisedPerBudgetLine aggregation name — it must stay declarative-only (ADR-031)'
        );

    }//end testNoParallelReportingServiceComputesTheSameFigures()

    /**
     * Fragment merges additively onto the monolith without dropping the
     * existing Verplichting/Verplichtingsregel/Budget schemas.
     *
     * @return void
     */
    public function testFragmentSchemasStillPresent(): void
    {
        $schemas = $this->fragment()['components']['schemas'];
        foreach (['Verplichting', 'Verplichtingsregel', 'Verplichtingsmutatie', 'Mandaat', 'Goedkeuringsstap', 'Budget'] as $name) {
            self::assertArrayHasKey($name, $schemas, "Fragment must still declare $name");
        }

    }//end testFragmentSchemasStillPresent()

    /**
     * Task 6: three auto-materialised-style Verplichting seed objects
     * (inkooporder/raamovereenkomst/subsidiebeschikking), each with at
     * least one Verplichtingsregel and every regel's coderingscombinatie
     * covered by a seeded Budget, so the drilldown is populated on a fresh
     * install.
     *
     * @return void
     */
    public function testSeedDataPopulatesDrilldown(): void
    {
        $objects = $this->fragment()['objects'];

        $verplichtingen = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'Verplichting'));
        $regels         = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'Verplichtingsregel'));
        $budgets        = array_values(array_filter($objects, static fn ($o) => $o['@self']['schema'] === 'Budget'));

        self::assertGreaterThanOrEqual(3, count($verplichtingen));
        self::assertGreaterThanOrEqual(1, count($budgets));

        $soorten = array_map(static fn ($v) => $v['kind'], $verplichtingen);
        foreach (['inkooporder', 'raamovereenkomst', 'subsidiebeschikking'] as $expected) {
            self::assertContains($expected, $soorten, "Seed must include a $expected Verplichting");
        }

        foreach ($verplichtingen as $v) {
            self::assertNotEmpty($v['sourceReference'] ?? '', "Seeded Verplichting {$v['verplichtingsnummer']} must carry a bronReferentie");
            $ownRegels = array_filter($regels, static fn ($r) => $r['commitment'] === $v['verplichtingsnummer']);
            self::assertNotEmpty($ownRegels, "Seeded Verplichting {$v['verplichtingsnummer']} must have at least one Verplichtingsregel");

            foreach ($ownRegels as $regel) {
                $matchingBudget = array_filter(
                    $budgets,
                    static fn ($b) => $b['programmeCode'] === $regel['programme']
                        && $b['financialYear'] === $regel['financialYear']
                        && $b['costCentre'] === $regel['costCentre']
                );
                $slug           = $regel['@self']['slug'];
                self::assertNotEmpty($matchingBudget, "Regel $slug must have a matching seeded Budget");
            }
        }//end foreach

    }//end testSeedDataPopulatesDrilldown()
}//end class
