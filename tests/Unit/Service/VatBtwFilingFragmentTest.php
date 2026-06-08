<?php

/**
 * Unit tests for the bookkeeping-vat-btw-filing register fragment.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/specs/bookkeeping-vat-btw-filing.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the T3 VAT/BTW filing fragment is valid JSON, declares the three
 * VAT registers with the declared lifecycle + aggregations, seeds objects that
 * resolve to those schemas, and merges additively onto the monolith without
 * colliding with the pre-existing VatReturn (BTW-aangifte) schema (ADR-037).
 */
final class VatBtwFilingFragmentTest extends TestCase
{
    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/bookkeeping-vat-btw-filing.json';

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
     * Load and decode the fragment JSON.
     *
     * @return array<mixed> The decoded fragment.
     */
    private function fragment(): array
    {
        $data = json_decode((string) file_get_contents($this->fragmentPath), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($data);
        return $data;
    }//end fragment()

    /**
     * The fragment file is present and valid JSON with a components.schemas block.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        $data = $this->fragment();
        self::assertArrayHasKey('schemas', $data['components']);
        self::assertArrayHasKey('objects', $data);
    }//end testFragmentIsValidJson()

    /**
     * The fragment declares the three VAT registers from the spec.
     *
     * @return void
     */
    public function testFragmentDeclaresVatRegisters(): void
    {
        $schemas = $this->fragment()['components']['schemas'];
        foreach (['VATReturn', 'VATDeclaration', 'VATLine'] as $name) {
            self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
        }
    }//end testFragmentDeclaresVatRegisters()

    /**
     * VATReturn declares the draft → submitted → verified → filed lifecycle on
     * statusCode with all four transitions (REQ-VAT-005, REQ-VAT-008).
     *
     * @return void
     */
    public function testVatReturnDeclaresLifecycle(): void
    {
        $vatReturn = $this->fragment()['components']['schemas']['VATReturn'];
        self::assertArrayHasKey('x-openregister-lifecycle', $vatReturn);

        $lifecycle = $vatReturn['x-openregister-lifecycle'];
        self::assertSame('statusCode', $lifecycle['field']);
        self::assertSame('draft', $lifecycle['initialState']);

        foreach (['draft', 'submitted', 'verified', 'filed'] as $state) {
            self::assertArrayHasKey($state, $lifecycle['states'], "Missing state $state");
        }

        foreach (['submit', 'verify', 'file', 'rebase'] as $transition) {
            self::assertArrayHasKey($transition, $lifecycle['transitions'], "Missing transition $transition");
        }

        // The statusCode enum mirrors the lifecycle states exactly.
        self::assertSame(
            ['draft', 'submitted', 'verified', 'filed'],
            $vatReturn['properties']['statusCode']['enum']
        );
    }//end testVatReturnDeclaresLifecycle()

    /**
     * VATReturn declares the VAT reconciliation aggregations sourced from
     * VATLine (REQ-VAT-002, REQ-VAT-011) — no PHP service per ADR-031.
     *
     * @return void
     */
    public function testVatReturnDeclaresReconciliationAggregations(): void
    {
        $vatReturn = $this->fragment()['components']['schemas']['VATReturn'];
        self::assertArrayHasKey('x-openregister-aggregations', $vatReturn);

        $aggregations = $vatReturn['x-openregister-aggregations'];
        self::assertArrayHasKey('vatCollectedByRate', $aggregations);
        self::assertArrayHasKey('vatPaidByRate', $aggregations);

        foreach ($aggregations as $aggregation) {
            self::assertSame('VATLine', $aggregation['source']);
            self::assertContains('vatAmount', $aggregation['sum']);
        }
    }//end testVatReturnDeclaresReconciliationAggregations()

    /**
     * Every seed object references one of the three VAT schemas under the
     * shillinq register, with a stable slug.
     *
     * @return void
     */
    public function testSeedObjectsResolveToFragmentSchemas(): void
    {
        $data    = $this->fragment();
        $defined = array_keys($data['components']['schemas']);

        self::assertNotEmpty($data['objects']);
        $slugs = [];
        foreach ($data['objects'] as $object) {
            self::assertArrayHasKey('@self', $object);
            self::assertSame('shillinq', $object['@self']['register']);
            self::assertContains($object['@self']['schema'], $defined, 'Seed references undefined schema');
            self::assertArrayHasKey('slug', $object['@self']);
            $slugs[] = $object['@self']['slug'];
        }

        // Slugs are unique so re-import is idempotent.
        self::assertSame(count($slugs), count(array_unique($slugs)), 'Seed slugs must be unique');
    }//end testSeedObjectsResolveToFragmentSchemas()

    /**
     * Seed VATLine vatAmount equals taxableAmount × taxRate / 100 (the
     * declarative derivation rule from the spec), and reverse-charge lines
     * carry zero VAT with the reverseChargeApplicable flag (REQ-VAT-010).
     *
     * @return void
     */
    public function testSeedVatLinesAreInternallyConsistent(): void
    {
        $objects = $this->fragment()['objects'];
        $lineCount = 0;
        foreach ($objects as $object) {
            if ($object['@self']['schema'] !== 'VATLine') {
                continue;
            }

            $lineCount++;
            if ($object['type'] === 'reverse-charge') {
                self::assertSame(0.0, (float) $object['vatAmount'], 'Reverse-charge VAT is operator-liable (0)');
                self::assertTrue($object['reverseChargeApplicable']);
                continue;
            }

            $expected = round(((float) $object['taxableAmount'] * (float) $object['taxRate']) / 100, 2);
            self::assertSame($expected, (float) $object['vatAmount'], 'vatAmount must equal taxable × rate / 100');
        }

        self::assertGreaterThanOrEqual(5, $lineCount, 'Spec requires multiple seed VAT lines');
    }//end testSeedVatLinesAreInternallyConsistent()

    /**
     * Merging the fragment onto the monolith adds the three VAT registers
     * without dropping any existing schema — including the distinct
     * pre-existing VatReturn (BTW-aangifte) schema (ADR-037 disjoint union).
     *
     * @return void
     */
    public function testFragmentMergesAdditivelyWithoutCollision(): void
    {
        $base = json_decode((string) file_get_contents($this->registerPath), true);
        $frag = $this->fragment();

        $schemaCountBefore = count($base['components']['schemas']);
        $objectCountBefore = count(($base['objects'] ?? []));

        $merged  = $this->merge($base, $frag);
        $schemas = $merged['components']['schemas'];

        self::assertArrayHasKey('VATReturn', $schemas);
        self::assertArrayHasKey('VATDeclaration', $schemas);
        self::assertArrayHasKey('VATLine', $schemas);

        // No existing schema dropped.
        foreach (array_keys($base['components']['schemas']) as $existing) {
            self::assertArrayHasKey($existing, $schemas, "Existing schema $existing must survive merge");
        }

        // The three new schemas are net-additive.
        self::assertSame($schemaCountBefore + 3, count($schemas));

        // Seed objects are concatenated onto the monolith's objects list.
        self::assertSame($objectCountBefore + count($frag['objects']), count($merged['objects']));
    }//end testFragmentMergesAdditivelyWithoutCollision()
}//end class
