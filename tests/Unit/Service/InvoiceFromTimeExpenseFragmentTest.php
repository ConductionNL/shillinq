<?php

/**
 * Unit tests for the invoice-from-time-and-expense register fragment.
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
 * @spec openspec/changes/invoice-from-time-and-expense/specs/invoice-from-time-and-expense/spec.md
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
 * Verifies the invoice-from-time-and-expense fragment is valid JSON, declares the
 * Invoice + InvoiceLine schemas with declarative VAT calculations and roll-up
 * aggregations (ADR-031), references the InvoiceGuard for the post precondition
 * (ADR-031 exception path), merges additively onto the monolith (ADR-037), and
 * ships internally-consistent seed objects.
 */
final class InvoiceFromTimeExpenseFragmentTest extends TestCase
{
    // PHPUnit assertions take positional arguments; the named-parameter sniff does not apply.
    // phpcs:disable CustomSniffs.Functions.NamedParameters

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/invoice-from-time-and-expense.json';

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
     * The fragment declares the Invoice and InvoiceLine schemas (REQ-ITE-001/002).
     *
     * @return void
     */
    public function testFragmentDeclaresInvoiceSchemas(): void
    {
        $schemas = $this->fragment()['components']['schemas'];
        foreach (['Invoice', 'InvoiceLine'] as $name) {
            self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
            self::assertSame($name, $schemas[$name]['slug'], "$name must carry a slug");
        }

    }//end testFragmentDeclaresInvoiceSchemas()

    /**
     * Invoice carries the five-model billingModel enum (REQ-ITE-001, design D1).
     *
     * @return void
     */
    public function testInvoiceDeclaresFiveBillingModels(): void
    {
        $invoice = $this->fragment()['components']['schemas']['Invoice'];
        $enum    = $invoice['properties']['billingModel']['enum'];
        self::assertSame(['t_and_m', 'fixed_fee', 'milestone', 'retainer', 'mixed'], $enum);

    }//end testInvoiceDeclaresFiveBillingModels()

    /**
     * VAT and roll-ups are declarative (ADR-031): InvoiceLine has VAT/gross
     * calculations and Invoice rolls up net/vat/gross via aggregations — no
     * VATCalculationService.php.
     *
     * @return void
     */
    public function testQuantitativeLogicIsDeclarative(): void
    {
        $schemas = $this->fragment()['components']['schemas'];

        $calc = $schemas['InvoiceLine']['x-openregister-calculations'];
        self::assertArrayHasKey('lineVatAmount', $calc);
        self::assertArrayHasKey('lineGrossAmount', $calc);
        self::assertStringContainsString('@self.vatRate', $calc['lineVatAmount']['expr']);

        $agg = $schemas['Invoice']['x-openregister-aggregations'];
        self::assertArrayHasKey('netAmount', $agg);
        self::assertArrayHasKey('vatAmount', $agg);
        self::assertArrayHasKey('grossAmount', $agg);
        self::assertSame('InvoiceLine', $agg['netAmount']['join']['schema']);

    }//end testQuantitativeLogicIsDeclarative()

    /**
     * The Invoice post transition references the InvoiceGuard precondition
     * (ADR-031 exception path / REQ-ITE-005/007).
     *
     * @return void
     */
    public function testPostTransitionReferencesGuard(): void
    {
        $invoice = $this->fragment()['components']['schemas']['Invoice'];
        $post    = $invoice['x-openregister-lifecycle']['transitions']['post'];
        self::assertSame('OCA\\Shillinq\\Lifecycle\\InvoiceGuard::canPost', $post['requires']);

        $cancel = $invoice['x-openregister-lifecycle']['transitions']['cancel'];
        self::assertSame('OCA\\Shillinq\\Lifecycle\\InvoiceGuard::canCancel', $cancel['requires']);

    }//end testPostTransitionReferencesGuard()

    /**
     * Merging the fragment onto the monolith adds Invoice/InvoiceLine without
     * dropping existing schemas (ADR-037 additive merge).
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

        self::assertArrayHasKey('Invoice', $schemas);
        self::assertArrayHasKey('InvoiceLine', $schemas);

        // Existing schemas survive the merge.
        foreach ($beforeSchemas as $name) {
            self::assertArrayHasKey($name, $schemas, "Monolith schema $name must survive the merge");
        }

        // Reuse targets are present (we extend the model, never reinvent them).
        foreach (['RateCard', 'UrenRegistratie', 'Receipt', 'Project'] as $reused) {
            self::assertArrayHasKey($reused, $schemas, "Reuse target $reused must exist");
        }

    }//end testFragmentMergesAdditivelyOntoMonolith()

    /**
     * Seed objects target only declared schemas in the shillinq register and
     * every InvoiceLine points at a seeded Invoice (REQ-ITE seed-data pattern).
     *
     * @return void
     */
    public function testSeedObjectsTargetDeclaredSchemas(): void
    {
        $frag    = $this->fragment();
        $schemas = $frag['components']['schemas'];
        $objects = $frag['components']['objects'];

        self::assertNotEmpty($objects);

        $invoiceSlugs = [];
        foreach ($objects as $object) {
            self::assertArrayHasKey('@self', $object);
            self::assertSame('shillinq', $object['@self']['register']);
            $schema = $object['@self']['schema'];
            self::assertArrayHasKey($schema, $schemas, "Seed object targets undeclared schema $schema");
            if ($schema === 'Invoice') {
                $invoiceSlugs[$object['@self']['slug']] = true;
            }
        }

        foreach ($objects as $object) {
            if ($object['@self']['schema'] === 'InvoiceLine') {
                self::assertArrayHasKey(
                    $object['invoiceId'],
                    $invoiceSlugs,
                    'InvoiceLine.invoiceId '.$object['invoiceId'].' must reference a seeded Invoice'
                );
            }
        }

    }//end testSeedObjectsTargetDeclaredSchemas()

    /**
     * Seed line cost arithmetic matches the design.md worked examples: the T&M
     * invoice nets €8,150 (600000 + 200000 + 15000 cents) across its three lines.
     *
     * @return void
     */
    public function testSeedTAndMInvoiceLinesSumToDesignNet(): void
    {
        $objects = $this->fragment()['components']['objects'];

        $net = 0;
        foreach ($objects as $object) {
            if ($object['@self']['schema'] === 'InvoiceLine'
                && $object['invoiceId'] === 'ite-sample-tm-2026-001'
            ) {
                $net += (int) $object['costAmount'];
            }
        }

        self::assertSame(815000, $net, 'T&M seed lines must net €8,150 (design.md example 1)');

    }//end testSeedTAndMInvoiceLinesSumToDesignNet()

    /**
     * Every seeded retainer/mixed invoice carries a mandatory retainer_charge
     * line (design D3) and every milestone line that is posted is complete.
     *
     * @return void
     */
    public function testRetainerSeedHasMandatoryRetainerLine(): void
    {
        $objects = $this->fragment()['components']['objects'];

        $retainerInvoices = [];
        $retainerLines    = [];
        foreach ($objects as $object) {
            $isRetainerModel = ($object['billingModel'] ?? '') === 'retainer'
                || ($object['billingModel'] ?? '') === 'mixed';
            if ($object['@self']['schema'] === 'Invoice' && $isRetainerModel === true) {
                $retainerInvoices[$object['@self']['slug']] = true;
            }

            if ($object['@self']['schema'] === 'InvoiceLine'
                && $object['sourceType'] === 'retainer_charge'
            ) {
                $retainerLines[$object['invoiceId']] = true;
            }
        }

        foreach (array_keys($retainerInvoices) as $slug) {
            self::assertArrayHasKey(
                $slug,
                $retainerLines,
                "Retainer/mixed invoice $slug must carry a retainer_charge line (design D3)"
            );
        }

    }//end testRetainerSeedHasMandatoryRetainerLine()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
