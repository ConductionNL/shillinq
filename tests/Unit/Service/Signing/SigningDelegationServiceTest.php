<?php

/**
 * Unit tests for SigningDelegationService.
 *
 * Covers: requestSignature idempotency, registry call, field-writing;
 * onSigningCallback field-mapping, idempotency, consequence firing,
 * and unknown-outcome rejection.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use InvalidArgumentException;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SigningDelegationService (REQ-SIGN-001/005/006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SigningDelegationServiceTest extends TestCase
{

    /**
     * Settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settings;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     */
    private SigningDelegationService $svc;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = $this->createMock(SettingsService::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->svc      = new SigningDelegationService(
            settingsService: $this->settings,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Helper: build a fake registry that returns a predictable signingRequestRef.
     *
     * @param string $returnRef The ref the registry will return.
     *
     * @return object
     */
    private function fakeRegistry(string $returnRef): object
    {
        return new class($returnRef) {
            /** @var string */
            private string $ref;

            public function __construct(string $ref)
            {
                $this->ref = $ref;

            }//end __construct()

            /**
             * @param array<string,mixed> $params
             */
            public function call(string $service, string $method, array $params): string
            {
                return $this->ref;

            }//end call()
        };

    }//end fakeRegistry()


    /**
     * requestSignature raises a signing request and writes signingStatus=requested.
     */
    public function testRequestSignatureWritesStatusRequested(): void
    {
        $registry = $this->fakeRegistry('ds-req-001');

        $result = $this->svc->requestSignature(
            financeObject: ['id' => 'acm-1', 'administrationId' => 'adm-1'],
            registry: $registry,
        );

        self::assertSame('ds-req-001', $result['signingRequestRef']);
        self::assertSame('requested', $result['signingStatus']);

    }//end testRequestSignatureWritesStatusRequested()


    /**
     * requestSignature is idempotent when signingStatus==signed.
     */
    public function testRequestSignatureIsIdempotentWhenAlreadySigned(): void
    {
        $registry = $this->fakeRegistry('ds-req-002');

        $obj = ['id' => 'acm-2', 'signingStatus' => 'signed', 'signingRequestRef' => 'existing-ref'];

        $result = $this->svc->requestSignature(
            financeObject: $obj,
            registry: $registry,
        );

        // Should be unchanged — no new registry call expected.
        self::assertSame('existing-ref', $result['signingRequestRef']);
        self::assertSame('signed', $result['signingStatus']);

    }//end testRequestSignatureIsIdempotentWhenAlreadySigned()


    /**
     * requestSignature propagates registry exceptions.
     */
    public function testRequestSignaturePropagatesRegistryException(): void
    {
        $badRegistry = new class {
            /**
             * @param array<string,mixed> $params
             */
            public function call(string $service, string $method, array $params): never
            {
                throw new \RuntimeException('registry offline');

            }//end call()
        };

        $this->expectException(\RuntimeException::class);

        $this->svc->requestSignature(
            financeObject: ['id' => 'acm-3'],
            registry: $badRegistry,
        );

    }//end testRequestSignaturePropagatesRegistryException()


    /**
     * onSigningCallback with outcome=signed writes all fields and fires the consequence.
     */
    public function testOnSigningCallbackSignedWritesFieldsAndFiresConsequence(): void
    {
        $fired  = false;
        $firedWith = null;

        $result = $this->svc->onSigningCallback(
            financeObject: ['id' => 'acm-4', 'signingStatus' => 'requested'],
            outcome: 'signed',
            signingRequestRef: 'ds-req-004',
            signingProvider: 'evidos',
            signingLevel: 'advanced',
            signedDocumentRef: '/NC/files/signed-acm-4.pdf',
            consequenceCallback: function (array $obj) use (&$fired, &$firedWith): void {
                $fired     = true;
                $firedWith = $obj;
            },
        );

        self::assertSame('signed', $result['signingStatus']);
        self::assertSame('ds-req-004', $result['signingRequestRef']);
        self::assertSame('evidos', $result['signingProvider']);
        self::assertSame('advanced', $result['signingLevel']);
        self::assertSame('/NC/files/signed-acm-4.pdf', $result['signedDocumentRef']);
        self::assertTrue($fired);
        self::assertNotNull($firedWith);

    }//end testOnSigningCallbackSignedWritesFieldsAndFiresConsequence()


    /**
     * onSigningCallback with outcome=declined does NOT fire the consequence.
     */
    public function testOnSigningCallbackDeclinedDoesNotFireConsequence(): void
    {
        $fired = false;

        $result = $this->svc->onSigningCallback(
            financeObject: ['id' => 'acm-5', 'signingStatus' => 'requested'],
            outcome: 'declined',
            signingRequestRef: 'ds-req-005',
            consequenceCallback: function (array $obj) use (&$fired): void {
                $fired = true;
            },
        );

        self::assertSame('declined', $result['signingStatus']);
        self::assertFalse($fired);

    }//end testOnSigningCallbackDeclinedDoesNotFireConsequence()


    /**
     * onSigningCallback is idempotent when already in the same terminal state.
     */
    public function testOnSigningCallbackIsIdempotent(): void
    {
        $fired = false;

        $result = $this->svc->onSigningCallback(
            financeObject: ['id' => 'acm-6', 'signingStatus' => 'signed', 'signingRequestRef' => 'ds-req-006'],
            outcome: 'signed',
            signingRequestRef: 'ds-req-006',
            consequenceCallback: function (array $obj) use (&$fired): void {
                $fired = true;
            },
        );

        self::assertSame('signed', $result['signingStatus']);
        self::assertFalse($fired, 'Consequence must not fire on idempotent callback');

    }//end testOnSigningCallbackIsIdempotent()


    /**
     * onSigningCallback rejects unknown outcomes.
     */
    public function testOnSigningCallbackRejectsUnknownOutcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown signing outcome/');

        $this->svc->onSigningCallback(
            financeObject: ['id' => 'acm-7'],
            outcome: 'bogus',
            signingRequestRef: 'ds-req-007',
        );

    }//end testOnSigningCallbackRejectsUnknownOutcome()


    /**
     * onSigningCallback with null consequence callback does not error on 'signed'.
     */
    public function testOnSigningCallbackNullCallbackSignedNoError(): void
    {
        $result = $this->svc->onSigningCallback(
            financeObject: ['id' => 'acm-8', 'signingStatus' => 'requested'],
            outcome: 'signed',
            signingRequestRef: 'ds-req-008',
        );

        self::assertSame('signed', $result['signingStatus']);

    }//end testOnSigningCallbackNullCallbackSignedNoError()


}//end class
