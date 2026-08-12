<?php

/**
 * Unit tests for AdministrationContextService.
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
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests administratie-aware RBAC resolution and IDOR-safe access checks.
 *
 * Covers REQ-MA-001 (isolation + masked access), REQ-MA-003 (per-administratie
 * roles + switcher target validation).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationContextServiceTest extends TestCase
{

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->appConfig   = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturn('shillinq');

    }//end setUp()

    /**
     * Authenticate a user with the given uid (or anonymous when null).
     *
     * @param string|null $uid The uid to authenticate, or null for anonymous.
     *
     * @return void
     */
    private function authenticateAs(?string $uid): void
    {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticateAs()

    /**
     * Build the service with an ObjectService stub backed by the given records.
     *
     * @param array<int,array<string,mixed>> $memberships     AdministrationMembership records.
     * @param array<int,array<string,mixed>> $administrations Administration records.
     *
     * @return AdministrationContextService
     */
    private function buildService(array $memberships, array $administrations): AdministrationContextService
    {
        $stub = new class($memberships, $administrations) {

            /**
             * Data sets keyed by schema slug.
             *
             * @var array<string,array<int,array<string,mixed>>>
             */
            private array $data;

            /**
             * Active schema.
             *
             * @var string
             */
            private string $schema = '';

            /**
             * Constructor.
             *
             * @param array<int,array<string,mixed>> $memberships     Membership records.
             * @param array<int,array<string,mixed>> $administrations Administration records.
             */
            public function __construct(array $memberships, array $administrations)
            {
                $this->data = [
                    'AdministrationMembership' => $memberships,
                    'Administration'           => $administrations,
                ];
            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
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
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->schema = $schema;
                return $this;
            }//end setSchema()

            /**
             * Return the data set for the active schema with simple equality filters.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params=[]): array
            {
                $rows    = ($this->data[$this->schema] ?? []);
                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $rows;
                }

                return array_values(
                    array_filter(
                        $rows,
                        static function (array $row) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($row[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }

                            return true;
                        }
                    )
                );
            }//end findAll()
        };

        $this->container->method('get')->willReturn($stub);

        return new AdministrationContextService(
            container: $this->container,
            userSession: $this->userSession,
            appConfig: $this->appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end buildService()

    /**
     * Sample administrations: Werk + Beheer.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sampleAdministrations(): array
    {
        return [
            ['id' => 'adm-werk-001', 'administrationCode' => 'WERK-001', 'name' => 'Werk B.V.', 'status' => 'actief'],
            ['id' => 'adm-beheer-001', 'administrationCode' => 'BEHEER-001', 'name' => 'Beheer B.V.', 'status' => 'actief'],
        ];
    }//end sampleAdministrations()

    /**
     * An anonymous user gets an empty context (REQ-MA-001).
     *
     * @return void
     */
    public function testAnonymousGetsEmptyContext(): void
    {
        $this->authenticateAs(null);
        $service = $this->buildService([], $this->sampleAdministrations());

        $context = $service->buildContext();
        self::assertNull($context['userId']);
        self::assertSame([], $context['administrations']);
        self::assertNull($context['activeAdministrationId']);
        self::assertFalse($service->canAccess('adm-werk-001'));

    }//end testAnonymousGetsEmptyContext()

    /**
     * A controller with two memberships sees both administrations with per-administratie roles (REQ-MA-003).
     *
     * @return void
     */
    public function testPerAdministrationRoles(): void
    {
        $this->authenticateAs('controller');
        $memberships = [
            ['userId' => 'controller', 'administrationId' => 'adm-werk-001', 'role' => 'controller', 'mayPostJournalEntries' => true],
            ['userId' => 'controller', 'administrationId' => 'adm-beheer-001', 'role' => 'inkijker', 'mayPostJournalEntries' => false],
            // A membership for a different user must NOT leak in.
            ['userId' => 'someone-else', 'administrationId' => 'adm-werk-001', 'role' => 'eigenaar'],
        ];
        $service = $this->buildService($memberships, $this->sampleAdministrations());

        $context = $service->buildContext();
        self::assertSame('controller', $context['userId']);
        self::assertCount(2, $context['administrations']);
        self::assertSame('adm-werk-001', $context['activeAdministrationId']);

        $byId = [];
        foreach ($context['administrations'] as $administration) {
            $byId[$administration['administrationId']] = $administration;
        }

        self::assertSame('controller', $byId['adm-werk-001']['role']);
        self::assertSame('inkijker', $byId['adm-beheer-001']['role']);
        self::assertTrue($byId['adm-werk-001']['mayPostJournalEntries']);
        self::assertFalse($byId['adm-beheer-001']['mayPostJournalEntries']);

    }//end testPerAdministrationRoles()

    /**
     * Posting rights respect role + flag (REQ-MA-003).
     *
     * @return void
     */
    public function testCanPostJournalEntry(): void
    {
        $this->authenticateAs('controller');
        $memberships = [
            ['userId' => 'controller', 'administrationId' => 'adm-werk-001', 'role' => 'controller', 'mayPostJournalEntries' => true],
            ['userId' => 'controller', 'administrationId' => 'adm-beheer-001', 'role' => 'inkijker', 'mayPostJournalEntries' => false],
        ];
        $service     = $this->buildService($memberships, $this->sampleAdministrations());

        // Controller may post in Werk but only views Beheer (REQ-MA-003 scenario).
        self::assertTrue($service->canPostJournalEntry('adm-werk-001'));
        self::assertFalse($service->canPostJournalEntry('adm-beheer-001'));

    }//end testCanPostJournalEntry()

    /**
     * A user cannot access — and the switcher cannot target — an administration
     * they have no membership for; the result is masked as denial (REQ-MA-001).
     *
     * @return void
     */
    public function testNoMembershipDeniesAccessAndSwitch(): void
    {
        $this->authenticateAs('controller');
        $memberships = [
            ['userId' => 'controller', 'administrationId' => 'adm-werk-001', 'role' => 'controller', 'mayPostJournalEntries' => true],
        ];
        $service     = $this->buildService($memberships, $this->sampleAdministrations());

        self::assertTrue($service->canAccess('adm-werk-001'));
        self::assertFalse($service->canAccess('adm-beheer-001'));

        // Switcher: valid target returns the id; an inaccessible target returns null (masked 404).
        self::assertSame('adm-werk-001', $service->resolveSwitchTarget('adm-werk-001'));
        self::assertNull($service->resolveSwitchTarget('adm-beheer-001'));
        self::assertNull($service->resolveSwitchTarget('adm-does-not-exist'));

    }//end testNoMembershipDeniesAccessAndSwitch()

    /**
     * An expired membership is excluded from the accessible set (REQ-MA-003).
     *
     * @return void
     */
    public function testExpiredMembershipExcluded(): void
    {
        $this->authenticateAs('temp');
        $memberships = [
            [
                'userId'           => 'temp',
                'administrationId' => 'adm-werk-001',
                'role'             => 'accountant_extern',
                'validFrom'        => '2020-01-01',
                'validUntil'       => '2020-12-31',
            ],
        ];
        $service     = $this->buildService($memberships, $this->sampleAdministrations());

        self::assertSame([], $service->accessibleAdministrationIds());
        self::assertFalse($service->canAccess('adm-werk-001'));

    }//end testExpiredMembershipExcluded()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
