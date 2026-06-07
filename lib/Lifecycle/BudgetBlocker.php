<?php

/**
 * Budget Blocker
 *
 * ADR-031 exception-path lifecycle guard for the Verplichting `aangaan` /
 * `goedkeuren` transitions. Budget-blocking is the core verplichtingen-
 * administratie rule: a commitment reduces available budget the moment it is
 * signed, not when an invoice arrives (REQ-VPL-001). The check resolves the
 * matching per-programma / per-boekjaar Budget for each Verplichtingsregel and
 * verifies sufficient vrije_ruimte, unless the signer holds an override-mandate.
 *
 * Referenced from the Verplichting schema's x-openregister-lifecycle transitions
 * in lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json.
 *
 * ADR-031 exception reason: the check joins each regel to its matching Budget by
 * (programma, boekjaar, administrationId), sums committed amounts, and compares
 * against vrije_ruimte with an override-mandate escape — cross-schema lookup plus
 * integer-cent arithmetic the declarative lifecycle DSL cannot yet express.
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Budget-room precondition + pure budget-math helpers for the Verplichting schema
 * (REQ-VPL-001).
 *
 * Fail-closed: any unexpected exception denies the commitment (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 */
class BudgetBlocker
{
    /**
     * Construct the guard with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for register slug resolution.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     * @param MandaatEnforcer    $mandaat   Mandate resolver for override-mandate detection.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly MandaatEnforcer $mandaat,
    ) {
    }//end __construct()

    /**
     * Precondition for the `aangaan` / `goedkeuren` transitions: is there enough
     * budget room for every regel of this commitment (REQ-VPL-001)?
     *
     * A commitment is allowed when, for each regel, the regel amount fits within
     * the matching Budget's vrije_ruimte — OR the signer holds a valid override-
     * mandate (in which case the commitment proceeds and the override reason is
     * expected to be recorded on the verplichting). Each regel is validated against
     * its own programma + boekjaar budget independently (multi-year isolation).
     *
     * Fail-closed: returns false on any exception (CWE-863).
     *
     * @param string                   $verplichtingsnummer The verplichting identifier (lifecycle-engine call parity).
     * @param array<string,mixed>|null $object              The Verplichting object being transitioned.
     *
     * @return bool True when the commitment may be signed.
     *
     * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
     */
    public function canCommit(string $verplichtingsnummer, ?array $object=null): bool
    {
        try {
            $verplichting = ($object ?? $this->findOne(schema: 'Verplichting', filters: ['verplichtingsnummer' => $verplichtingsnummer]));
            if ($verplichting === null) {
                $this->logger->info(
                    'BudgetBlocker: verplichting not found — denying commitment',
                    ['verplichting' => $verplichtingsnummer]
                );
                return false;
            }

            // Override-mandate holders (e.g. CFO) may force-accept a budget-exceeding
            // commitment; the override reason is recorded on the verplichting (REQ-VPL-001).
            if ($this->hasOverrideMandate(verplichting: $verplichting) === true) {
                return true;
            }

            $admin  = (string) ($verplichting['administrationId'] ?? '');
            $regels = $this->resolveRegels(verplichting: $verplichting);

            foreach ($regels as $regel) {
                if ($this->regelFitsBudget(regel: $regel, administrationId: $admin) === false) {
                    $this->logger->info(
                        'BudgetBlocker: insufficient budget — denying commitment',
                        [
                            'verplichting' => $verplichtingsnummer,
                            'programma'    => ($regel['programma'] ?? null),
                            'boekjaar'     => ($regel['boekjaar'] ?? null),
                        ]
                    );
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'BudgetBlocker: canCommit failed — denying commitment (fail-closed)',
                ['verplichting' => $verplichtingsnummer, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end canCommit()

    /**
     * Compute free budget room for a budget record (REQ-VPL-001). Pure function.
     *
     * Free room equals geautoriseerd_bedrag minus gerealiseerd_bedrag minus
     * openstaande_verplichtingen (D9).
     *
     * @param array<string,mixed> $budget The budget record.
     *
     * @return int Free room in minor units (may be negative when overcommitted).
     *
     * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
     */
    public function freeRoom(array $budget): int
    {
        $authorised = (int) ($budget['geautoriseerd_bedrag'] ?? 0);
        $realised   = (int) ($budget['gerealiseerd_bedrag'] ?? 0);
        $committed  = (int) ($budget['openstaande_verplichtingen'] ?? 0);

        return ($authorised - $realised - $committed);

    }//end freeRoom()

    /**
     * Whether an additional committed amount fits within a budget's free room
     * (REQ-VPL-001). Pure function.
     *
     * @param array<string,mixed> $budget The budget record.
     * @param int                 $bedrag The additional committed amount in minor units.
     *
     * @return bool True when bedrag <= free room.
     *
     * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
     */
    public function fits(array $budget, int $bedrag): bool
    {
        return $bedrag <= $this->freeRoom(budget: $budget);

    }//end fits()

    /**
     * Whether the commitment carries a valid override-mandate (REQ-VPL-001).
     *
     * @param array<string,mixed> $verplichting The commitment.
     *
     * @return bool True when an applicable override-mandate exists.
     */
    private function hasOverrideMandate(array $verplichting): bool
    {
        $applicable = $this->mandaat->resolveApplicableMandate(verplichting: $verplichting);

        return $applicable !== null && (bool) ($applicable['is_override'] ?? false) === true;

    }//end hasOverrideMandate()

    /**
     * Verify a single regel fits its matching programma + boekjaar budget.
     *
     * When no matching budget exists the regel cannot be validated against a known
     * ceiling; fail-closed by rejecting (a missing budget is not free budget).
     *
     * @param array<string,mixed> $regel            The verplichtingsregel.
     * @param string              $administrationId The owning administration.
     *
     * @return bool True when the regel amount fits the matching budget's free room.
     */
    private function regelFitsBudget(array $regel, string $administrationId): bool
    {
        $budget = $this->findOne(
            schema: 'Budget',
            filters: [
                'administrationId' => $administrationId,
                'programmaCode'    => (string) ($regel['programma'] ?? ''),
                'boekjaar'         => (int) ($regel['boekjaar'] ?? 0),
            ]
        );

        if ($budget === null) {
            return false;
        }

        return $this->fits(budget: $budget, bedrag: (int) ($regel['bedrag_excl_btw'] ?? 0));

    }//end regelFitsBudget()

    /**
     * Resolve the regels for a commitment. Prefers regels embedded on the object;
     * otherwise queries the Verplichtingsregel register. When neither yields rows,
     * falls back to a single synthetic regel from the verplichting totals so a
     * single-line commitment is still budget-checked.
     *
     * @param array<string,mixed> $verplichting The commitment.
     *
     * @return array<int, array<string,mixed>> The regels to validate.
     */
    private function resolveRegels(array $verplichting): array
    {
        $embedded = ($verplichting['regels'] ?? null);
        if (is_array($embedded) === true && count($embedded) > 0) {
            return array_values($embedded);
        }

        $nummer  = (string) ($verplichting['verplichtingsnummer'] ?? '');
        $queried = [];
        if ($nummer !== '') {
            $queried = $this->findMany(schema: 'Verplichtingsregel', filters: ['verplichting' => $nummer]);
        }

        if (count($queried) > 0) {
            return $queried;
        }

        // Fallback: derive a single regel from the commitment header so a
        // commitment without explicit regels is still validated.
        return [
            [
                'programma'       => (string) ($verplichting['programma'] ?? ''),
                'boekjaar'        => (int) ($verplichting['boekjaar'] ?? 0),
                'bedrag_excl_btw' => (int) ($verplichting['totaalbedrag_excl_btw'] ?? 0),
            ],
        ];

    }//end resolveRegels()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Find a single record by exact-match filters in the configured register.
     *
     * @param string               $schema  Schema name.
     * @param array<string, mixed> $filters Exact-match filters.
     *
     * @return array<string, mixed>|null First matching record, or null.
     */
    private function findOne(string $schema, array $filters): ?array
    {
        $result = $this->findMany(schema: $schema, filters: $filters, limit: 1);
        if (count($result) === 0) {
            return null;
        }

        return reset($result);

    }//end findOne()

    /**
     * Find records by exact-match filters in the configured register.
     *
     * Returns an empty array when the schema is not yet available. Uses the real
     * OpenRegister ObjectService fluent API (ADR-022): setRegister/setSchema/findAll.
     *
     * @param string               $schema  Schema name.
     * @param array<string, mixed> $filters Exact-match filters.
     * @param int                  $limit   Maximum records to return (0 = no explicit limit).
     *
     * @return array<int, array<string, mixed>> Matching records (possibly empty).
     */
    private function findMany(string $schema, array $filters, int $limit=0): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $query         = ['filters' => $filters];
            if ($limit > 0) {
                $query['limit'] = $limit;
            }

            $result = $objectService
                ->setRegister(register: $this->getRegisterSlug())
                ->setSchema(schema: $schema)
                ->findAll($query);

            if (is_array($result) === false) {
                return [];
            }

            return array_values($result);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'BudgetBlocker: schema lookup unavailable — treating as absent',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try

    }//end findMany()
}//end class
