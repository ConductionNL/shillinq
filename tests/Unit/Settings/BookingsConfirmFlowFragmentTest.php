<?php

/**
 * Unit tests for the bookings-confirm-flow register fragment (ADR-037).
 *
 * Verifies the fragment declares the Appointment and ConfirmationToken schemas
 * with their lifecycle state machines and demo seeds, and that the fragment
 * unions cleanly onto the base register via SettingsService::deepMergeConfig
 * without colliding with existing schemas.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Structural tests for the confirmation-flow register fragment.
 */
final class BookingsConfirmFlowFragmentTest extends TestCase
{
    /**
     * Decoded fragment data.
     *
     * @var array<string,mixed>
     */
    private array $fragment;

    /**
     * Load and decode the fragment file.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__.'/../../../lib/Settings/register.d/bookings-confirm-flow.json';
        self::assertFileExists($path, 'Register fragment must ship in lib/Settings/register.d');
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'Fragment must be valid JSON');
        $this->fragment = $decoded;
    }//end setUp()

    /**
     * The fragment declares both schemas with the required fields (REQ-BCF-002).
     *
     * @return void
     */
    public function testFragmentDeclaresSchemasWithRequiredFields(): void
    {
        $schemas = ($this->fragment['components']['schemas'] ?? []);
        self::assertArrayHasKey('Appointment', $schemas);
        self::assertArrayHasKey('ConfirmationToken', $schemas);

        $token = $schemas['ConfirmationToken'];
        foreach (['appointmentId', 'tokenString', 'expiresAt', 'status'] as $field) {
            self::assertContains($field, $token['required'], 'ConfirmationToken must require '.$field);
            self::assertArrayHasKey($field, $token['properties']);
        }

        $appt = $schemas['Appointment'];
        foreach (['confirmationDeadline', 'confirmedAt', 'confirmationTokenId'] as $field) {
            self::assertArrayHasKey($field, $appt['properties'], 'Appointment must declare '.$field);
        }
    }//end testFragmentDeclaresSchemasWithRequiredFields()

    /**
     * Both schemas declare an x-openregister-lifecycle state machine (REQ-BCF-004).
     *
     * @return void
     */
    public function testSchemasDeclareLifecycleTransitions(): void
    {
        $appt = $this->fragment['components']['schemas']['Appointment']['x-openregister-lifecycle'];
        self::assertSame('status', $appt['field']);
        self::assertSame('pending_confirmation', $appt['initialState']);
        self::assertArrayHasKey('appointment_confirmed', $appt['transitions']);
        self::assertSame('pending_confirmation', $appt['transitions']['appointment_confirmed']['from']);
        self::assertSame('confirmed', $appt['transitions']['appointment_confirmed']['to']);
        self::assertArrayHasKey('appointment_auto_cancelled', $appt['transitions']);

        $token = $this->fragment['components']['schemas']['ConfirmationToken']['x-openregister-lifecycle'];
        self::assertSame('active', $token['initialState']);
        self::assertArrayHasKey('token_redeemed', $token['transitions']);
        self::assertArrayHasKey('token_revoked', $token['transitions']);
    }//end testSchemasDeclareLifecycleTransitions()

    /**
     * The fragment ships demo seed objects bound to the shillinq register.
     *
     * @return void
     */
    public function testFragmentShipsSeedObjects(): void
    {
        $objects = ($this->fragment['objects'] ?? []);
        self::assertGreaterThanOrEqual(3, count($objects));
        foreach ($objects as $object) {
            self::assertSame('shillinq', $object['@self']['register']);
            self::assertContains($object['@self']['schema'], ['Appointment', 'ConfirmationToken']);
        }
    }//end testFragmentShipsSeedObjects()

    /**
     * The fragment unions onto a base register without clobbering existing schemas.
     *
     * @return void
     */
    public function testFragmentUnionsOntoBaseRegister(): void
    {
        $base = [
            'components' => ['schemas' => ['Account' => ['type' => 'object']]],
            'objects'    => [['@self' => ['schema' => 'Account']]],
        ];

        $merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $merge->setAccessible(true);
        $merged = $merge->invoke(null, $base, $this->fragment);

        // Existing schema survives; new schemas are added.
        self::assertArrayHasKey('Account', $merged['components']['schemas']);
        self::assertArrayHasKey('Appointment', $merged['components']['schemas']);
        self::assertArrayHasKey('ConfirmationToken', $merged['components']['schemas']);
        // Objects lists concatenate (base + fragment seeds).
        self::assertGreaterThanOrEqual(4, count($merged['objects']));
    }//end testFragmentUnionsOntoBaseRegister()
}//end class
