<?php

/**
 * Unit tests for the bookkeeping-bcf-vat-compensation register fragment.
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
 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
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
 * Verifies the BCF fragment is valid JSON, declares the BcfClaim schema with its
 * lifecycle (draft -> submitted -> accepted -> settled) gated by BcfClaimGuard,
 * documents the compensable-VAT aggregation (ADR-037 / ADR-031), re-asserts the
 * BbvAccountMapping BCF flags, merges additively onto the monolith without
 * disturbing existing schemas, and ships seed claims whose breakdown weights are
 * internally consistent (amount × percentage / 100, REQ-BCF-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BcfClaimFragmentTest extends TestCase
{

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json';

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
     * The fragment is present and valid JSON with the expected sections.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        $data = json_decode((string) file_get_contents($this->fragmentPath), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertArrayHasKey('schemas', $data['components']);
        self::assertArrayHasKey('objects', $data['components']);

    }//end testFragmentIsValidJson()

    /**
     * The fragment declares the BcfClaim schema with its required properties (REQ-BCF-001).
     *
     * @return void
     */
    public function testDeclaresBcfClaimSchema(): void
    {
        $schema = $this->fragment()['components']['schemas']['BcfClaim'];
        self::assertSame('BcfClaim', $schema['slug']);

        $expected = [
            'claimQuarter',
            'administrationId',
            'totalCompensableAmount',
            'breakdown',
            'state',
            'submittedOn',
            'acceptedOn',
            'settledOn',
            'attachmentUri',
            'notes',
        ];
        foreach ($expected as $field) {
            self::assertArrayHasKey($field, $schema['properties'], "BcfClaim must declare $field");
        }

        self::assertSame(['draft', 'submitted', 'accepted', 'settled'], $schema['properties']['state']['enum']);

    }//end testDeclaresBcfClaimSchema()

    /**
     * The lifecycle gates draft -> submitted with the BcfClaimGuard (REQ-BCF-003).
     *
     * @return void
     */
    public function testDeclaresLifecycleWithSubmitGuard(): void
    {
        $lifecycle = $this->fragment()['components']['schemas']['BcfClaim']['x-openregister-lifecycle'];
        self::assertSame('state', $lifecycle['field']);
        self::assertSame('draft', $lifecycle['initialState']);

        $submit = $lifecycle['transitions']['submit'];
        self::assertSame('draft', $submit['from']);
        self::assertSame('submitted', $submit['to']);
        self::assertSame('OCA\\Shillinq\\Lifecycle\\BcfClaimGuard::canSubmit', $submit['requires']);

    }//end testDeclaresLifecycleWithSubmitGuard()

    /**
     * The compensable-VAT roll-up is declared as an aggregation (ADR-031, REQ-BCF-002).
     *
     * @return void
     */
    public function testDeclaresAggregation(): void
    {
        $schema = $this->fragment()['components']['schemas']['BcfClaim'];
        self::assertArrayHasKey('x-openregister-aggregations', $schema);
        self::assertArrayHasKey('compensableVatBreakdown', $schema['x-openregister-aggregations']);

    }//end testDeclaresAggregation()

    /**
     * The fragment re-asserts the BbvAccountMapping BCF flags (REQ-BCF-004).
     *
     * @return void
     */
    public function testReAssertsBbvCompensableFlags(): void
    {
        $bbv = $this->fragment()['components']['schemas']['BbvAccountMapping']['properties'];
        self::assertArrayHasKey('bcfCompensable', $bbv);
        self::assertArrayHasKey('compensablePercentage', $bbv);
        self::assertFalse($bbv['bcfCompensable']['default']);
        self::assertSame(100, $bbv['compensablePercentage']['default']);

    }//end testReAssertsBbvCompensableFlags()

    /**
     * Merging the fragment over the full register.d chain adds BcfClaim without
     * dropping existing schemas and keeps BbvAccountMapping's required keys
     * (ADR-037 disjoint union). BbvAccountMapping is owned by the operations
     * fragment, so the realistic merge folds every register.d/*.json in order.
     *
     * @return void
     */
    public function testFragmentMergesAdditivelyOntoMonolith(): void
    {
        $merged = json_decode((string) file_get_contents($this->registerPath), true);

        // Fold every register.d fragment in sorted order, as SettingsService does.
        $fragments = glob(__DIR__.'/../../../lib/Settings/register.d/*.json');
        sort($fragments);
        foreach ($fragments as $fragmentFile) {
            $merged = $this->merge($merged, json_decode((string) file_get_contents($fragmentFile), true));
        }

        $schemas = $merged['components']['schemas'];

        self::assertArrayHasKey('BcfClaim', $schemas);
        // The pre-existing BbvAccountMapping schema survives with its required keys.
        self::assertArrayHasKey('BbvAccountMapping', $schemas);
        self::assertContains('accountNumber', $schemas['BbvAccountMapping']['required']);
        // And it carries the BCF flags after the merge.
        self::assertArrayHasKey('bcfCompensable', $schemas['BbvAccountMapping']['properties']);
        // The trial-balance schema from a sibling fragment also survives.
        self::assertArrayHasKey('TrialBalanceLine', $schemas);

    }//end testFragmentMergesAdditivelyOntoMonolith()

    /**
     * Seed claims target only declared schemas and are internally consistent (REQ-BCF-002).
     *
     * @return void
     */
    public function testSeedClaimsAreConsistent(): void
    {
        $frag    = $this->fragment();
        $schemas = $frag['components']['schemas'];
        $objects = $frag['components']['objects'];

        self::assertNotEmpty($objects);
        foreach ($objects as $object) {
            self::assertArrayHasKey('@self', $object);
            self::assertSame('shillinq', $object['@self']['register']);
            self::assertArrayHasKey($object['@self']['schema'], $schemas);

            // Total equals the sum of breakdown compensableAmount (in cents, REQ-BCF-002).
            $totalCents = (int) round(((float) ($object['totalCompensableAmount'] ?? 0)) * 100);
            $sumCents   = 0;
            foreach (($object['breakdown'] ?? []) as $row) {
                // Each row: compensableAmount = amount × percentage / 100.
                $weighted = (int) round((((float) $row['amount']) * ((int) $row['compensablePercentage'])) / 100 * 100);
                self::assertSame(
                    $weighted,
                    (int) round(((float) $row['compensableAmount']) * 100),
                    'Breakdown row for '.$object['@self']['slug'].' account '.$row['accountNumber'].' must weight correctly'
                );
                $sumCents += (int) round(((float) $row['compensableAmount']) * 100);
            }

            self::assertSame(
                $totalCents,
                $sumCents,
                'Seed '.$object['@self']['slug'].' total must equal the sum of its breakdown'
            );
        }//end foreach

    }//end testSeedClaimsAreConsistent()

    /**
     * Seed claims cover all four lifecycle states across multiple administrations (REQ-BCF-003).
     *
     * @return void
     */
    public function testSeedClaimsCoverLifecycleStates(): void
    {
        $objects = $this->fragment()['components']['objects'];
        $states  = [];
        $admins  = [];
        foreach ($objects as $object) {
            $states[$object['state']]            = true;
            $admins[$object['administrationId']] = true;
        }

        foreach (['draft', 'submitted', 'accepted', 'settled'] as $state) {
            self::assertArrayHasKey($state, $states, "Expect a seed claim in $state state");
        }

        self::assertGreaterThanOrEqual(2, count($admins), 'Expect >= 2 distinct administrations');

    }//end testSeedClaimsCoverLifecycleStates()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
