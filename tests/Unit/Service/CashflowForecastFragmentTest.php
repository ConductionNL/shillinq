<?php

/**
 * Unit tests for the zzp-cashflow-13wk register fragment.
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
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-31
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the zzp-cashflow-13wk fragment is valid JSON, declares the eight
 * Cashflow schemas with their lifecycle/aggregations, and merges additively onto
 * the monolith without dropping existing schemas or objects (ADR-037).
 */
final class CashflowForecastFragmentTest extends TestCase
{

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/zzp-cashflow-13wk.json';

    /**
     * Absolute path to the monolith register file.
     *
     * @var string
     */
    private string $registerPath = __DIR__.'/../../../lib/Settings/shillinq_register.json';

    /**
     * The eight schemas the fragment must declare.
     *
     * @var array<int,string>
     */
    private array $expectedSchemas = [
        'CashflowForecastHorizon',
        'CashflowWeek',
        'CashflowARProjection',
        'CashflowAPSchedule',
        'CashflowRecurring',
        'CashflowBufferPolicy',
        'CashflowScenario',
        'CashflowCalibrationReport',
    ];

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
     * Load and decode a JSON file.
     *
     * @param string $path Absolute path.
     *
     * @return array<mixed>
     */
    private function load(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($data);
        return $data;

    }//end load()

    /**
     * The fragment file is present and valid JSON with a components.schemas object.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        $data = $this->load($this->fragmentPath);
        self::assertArrayHasKey('schemas', $data['components']);

    }//end testFragmentIsValidJson()

    /**
     * The fragment declares all eight cashflow schemas.
     *
     * @return void
     */
    public function testFragmentDeclaresAllCashflowSchemas(): void
    {
        $data    = $this->load($this->fragmentPath);
        $schemas = $data['components']['schemas'];

        foreach ($this->expectedSchemas as $name) {
            self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
            self::assertArrayHasKey('required', $schemas[$name]);
            self::assertArrayHasKey('properties', $schemas[$name]);
        }

    }//end testFragmentDeclaresAllCashflowSchemas()

    /**
     * Every required field of every schema is also declared as a property.
     *
     * @return void
     */
    public function testRequiredFieldsAreDeclaredProperties(): void
    {
        $schemas = $this->load($this->fragmentPath)['components']['schemas'];

        foreach ($schemas as $name => $schema) {
            $props = array_keys($schema['properties']);
            foreach ($schema['required'] as $req) {
                self::assertContains($req, $props, "$name.$req must be a declared property");
            }
        }

    }//end testRequiredFieldsAreDeclaredProperties()

    /**
     * The horizon declares its rolling lifecycle (active/rolling/archived) per REQ-CF-000.
     *
     * @return void
     */
    public function testHorizonDeclaresRollingLifecycle(): void
    {
        $schemas   = $this->load($this->fragmentPath)['components']['schemas'];
        $lifecycle = $schemas['CashflowForecastHorizon']['x-openregister-lifecycle'];

        self::assertSame('lifecycleState', $lifecycle['field']);
        foreach (['active', 'rolling', 'archived'] as $state) {
            self::assertArrayHasKey($state, $lifecycle['states'], "Lifecycle must declare $state");
        }

    }//end testHorizonDeclaresRollingLifecycle()

    /**
     * The recurring schema's save precondition is the CashflowRecurringGuard.
     *
     * @return void
     */
    public function testRecurringDeclaresGuardPrecondition(): void
    {
        $schemas   = $this->load($this->fragmentPath)['components']['schemas'];
        $lifecycle = $schemas['CashflowRecurring']['x-openregister-lifecycle'];

        self::assertSame(
            'OCA\\Shillinq\\Guard\\CashflowRecurringGuard::validateOnSave',
            $lifecycle['preconditions']['save'],
            'CashflowRecurring save must be guarded by CashflowRecurringGuard'
        );

    }//end testRecurringDeclaresGuardPrecondition()

    /**
     * The AR projection schema declares the receipts-by-week aggregation per REQ-CF-003.
     *
     * @return void
     */
    public function testArProjectionDeclaresReceiptsAggregation(): void
    {
        $schemas = $this->load($this->fragmentPath)['components']['schemas'];
        $aggs    = $schemas['CashflowARProjection']['x-openregister-aggregations'];

        self::assertArrayHasKey('projectedReceiptsByWeek', $aggs);
        self::assertSame('sum', $aggs['projectedReceiptsByWeek']['operation']);
        self::assertSame('verwachtOntvangstWeek', $aggs['projectedReceiptsByWeek']['groupBy']);

    }//end testArProjectionDeclaresReceiptsAggregation()

    /**
     * Merging the fragment onto the monolith unions schemas additively (ADR-037):
     * existing schemas survive and the cashflow schemas are added.
     *
     * @return void
     */
    public function testFragmentMergesAdditivelyOntoMonolith(): void
    {
        $base     = $this->load($this->registerPath);
        $fragment = $this->load($this->fragmentPath);

        $baseSchemaCount = count($base['components']['schemas']);
        $baseObjectCount = count(($base['objects'] ?? []));

        $merged = $this->merge($base, $fragment);

        // Existing schemas are preserved.
        self::assertArrayHasKey('GLTransaction', $merged['components']['schemas']);
        self::assertArrayHasKey('Account', $merged['components']['schemas']);

        // New schemas are present.
        foreach ($this->expectedSchemas as $name) {
            self::assertArrayHasKey($name, $merged['components']['schemas']);
        }

        // Schema count grew by exactly the number of new schemas (key union).
        self::assertSame(
            ($baseSchemaCount + count($this->expectedSchemas)),
            count($merged['components']['schemas'])
        );

        // Seed objects are concatenated (list union), not replaced.
        self::assertSame(
            ($baseObjectCount + count($fragment['objects'])),
            count($merged['objects'])
        );

    }//end testFragmentMergesAdditivelyOntoMonolith()

    /**
     * Every seed object references a schema declared in the fragment and carries
     * that schema's required fields.
     *
     * @return void
     */
    public function testSeedObjectsAreValid(): void
    {
        $fragment = $this->load($this->fragmentPath);
        $schemas  = $fragment['components']['schemas'];

        foreach ($fragment['objects'] as $object) {
            $schemaName = $object['@self']['schema'];
            self::assertArrayHasKey($schemaName, $schemas, "Seed references unknown schema $schemaName");
            foreach ($schemas[$schemaName]['required'] as $req) {
                self::assertArrayHasKey($req, $object, "Seed for $schemaName missing required $req");
            }
        }

    }//end testSeedObjectsAreValid()

    /**
     * The buffer-policy seed obeys the two-tier alert ratios (REQ-CF-009):
     * vooralarm = buffer x 1.5, ondergrens = buffer x 0.5.
     *
     * @return void
     */
    public function testBufferPolicySeedAlertRatios(): void
    {
        $fragment = $this->load($this->fragmentPath);
        $policy   = null;
        foreach ($fragment['objects'] as $object) {
            if ($object['@self']['schema'] === 'CashflowBufferPolicy') {
                $policy = $object;
                break;
            }
        }

        self::assertNotNull($policy, 'A CashflowBufferPolicy seed must exist');
        self::assertEqualsWithDelta($policy['berekendeBuffer'] * 1.5, $policy['alertVooralarm'], 0.01);
        self::assertEqualsWithDelta($policy['berekendeBuffer'] * 0.5, $policy['alertOndergrens'], 0.01);

    }//end testBufferPolicySeedAlertRatios()
}//end class
