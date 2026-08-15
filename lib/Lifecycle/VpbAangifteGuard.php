<?php

/**
 * Vpb-aangifte Guard
 *
 * ADR-031 exception-path lifecycle guards for the Vpb (vennootschapsbelasting)
 * registers of the bookkeeping-vpb-mkb change (T3). The declarative
 * x-openregister-lifecycle metadata on the VpbAangifte, DefinitieveAanslag and
 * FiscaleEenheid schemas references the methods below for the cross-schema
 * preconditions that the declarative DSL cannot yet express:
 *
 *  - canIndienen():            the linked jaarrekening must be vastgesteld, the
 *                              Belastingplichtige must carry eHerkenning EH3+ and a
 *                              Digipoort-certificaat, and any Innovatiebox claim must
 *                              carry an S&O-verklaring reference, before the aangifte
 *                              may be ingediend (REQ-VPB-002, REQ-VPB-004, REQ-VPB-009).
 *  - canAanslagOntvangen():    the gekoppelde VpbAangifte must be in the ingediend
 *                              state before a DefinitieveAanslag may be marked
 *                              ontvangen (REQ-VPB-010).
 *  - canVoegen():              a FiscaleEenheid voeging requires >=95% bezit,
 *                              gelijke boekjaren and vestiging in NL (REQ-VPB-007).
 *
 * The pure fiscal computations — schijftarief (REQ-VPB-003) and voorvoegingsverlies
 * regime + verjaring (REQ-VPB-006) — live in the sibling
 * OCA\Shillinq\Lifecycle\VpbBerekeningGuard so each class stays focused and below
 * the configured class-complexity threshold.
 *
 * ADR-031 exception reason: cross-schema lookups (jaarrekening vastgesteld-state on
 * a sibling AnnualReport; Innovatiebox S&O-verklaring presence) are not yet
 * expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete
 * this file. No tax-calculation service class is authored (ADR-022/ADR-031): these
 * are thin, fail-closed precondition guards.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
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
 * Lifecycle precondition guards for the Vpb registers.
 *
 * Referenced from the bookkeeping-vpb-mkb register.d fragment schema lifecycle
 * transitions as OCA\Shillinq\Lifecycle\VpbAangifteGuard::<method>. Every guard
 * fails closed: any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 */
class VpbAangifteGuard
{
    /**
     * Valid accountantsverklaring/jaarrekening states that count as vastgesteld.
     *
     * @var array<string>
     */
    private const VASTGESTELDE_STATES = ['determined', 'gedeponeerd'];

    /**
     * Aangifte states from which a DefinitieveAanslag may be marked ontvangen.
     *
     * @var array<string>
     */
    private const AANSLAG_TOEGESTANE_STATES = ['submitted', 'aanslag-ontvangen', 'bezwaar', 'beroep', 'onherroepelijk'];

    /**
     * Construct the guard with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns true iff the aangifte may transition concept -> ingediend.
     *
     * REQ-VPB-002 / REQ-VPB-004 / REQ-VPB-009: indiening is only permitted when
     *  - the linked jaarrekening (commercieleWinst FK) is vastgesteld,
     *  - the Belastingplichtige has eHerkenning EH3+ and a Digipoort-certificaat,
     *  - every Innovatiebox claim on the aangifte carries an S&O-verklaring.
     *
     * Fail-closed: returns false on any exception or unresolvable dependency.
     *
     * @param string                   $aangifteId The VpbAangifte id (call-signature parity).
     * @param array<string,mixed>|null $object     The aangifte being transitioned.
     *
     * @return bool True when the aangifte may be ingediend.
     *
     * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
     */
    public function canIndienen(string $aangifteId, ?array $object=null): bool
    {
        try {
            $aangifte = $object;
            if ($aangifte === null || isset($aangifte['belastingplichtige']) === false) {
                $aangifte = $this->resolveObject(schema: 'VpbAangifte', id: $aangifteId);
            }

            if ($aangifte === null) {
                return false;
            }

            if ($this->jaarrekeningVastgesteld(aangifte: $aangifte) === false) {
                return false;
            }

            if ($this->belastingplichtigeDigipoortReady(belastingplichtigeId: (string) ($aangifte['belastingplichtige'] ?? '')) === false) {
                return false;
            }

            return $this->innovatieboxClaimsHaveSO(aangifteId: (string) ($aangifte['id'] ?? $aangifteId));
        } catch (\Throwable $e) {
            $this->logger->error(
                'VpbAangifteGuard: canIndienen check failed — denying indienen transition (fail-closed)',
                ['taxReturnId' => $aangifteId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end canIndienen()

    /**
     * Returns true iff a DefinitieveAanslag may be marked ontvangen.
     *
     * REQ-VPB-010: the gekoppelde VpbAangifte must already be in the ingediend
     * (or later) state. Fail-closed on any exception or missing aangifte.
     *
     * @param string                   $aanslagId The DefinitieveAanslag id (call-signature parity).
     * @param array<string,mixed>|null $object    The aanslag being transitioned.
     *
     * @return bool True when the aanslag may be marked ontvangen.
     *
     * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
     */
    public function canAanslagOntvangen(string $aanslagId, ?array $object=null): bool
    {
        try {
            $aanslag = $object;
            if ($aanslag === null || isset($aanslag['taxReturn']) === false) {
                $aanslag = $this->resolveObject(schema: 'DefinitieveAanslag', id: $aanslagId);
            }

            if ($aanslag === null) {
                return false;
            }

            $aangifte = $this->resolveObject(schema: 'VpbAangifte', id: (string) ($aanslag['taxReturn'] ?? ''));
            if ($aangifte === null) {
                return false;
            }

            $status = (string) ($aangifte['status'] ?? '');

            return in_array($status, self::AANSLAG_TOEGESTANE_STATES, true);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VpbAangifteGuard: canAanslagOntvangen check failed — denying transition (fail-closed)',
                ['aanslagId' => $aanslagId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end canAanslagOntvangen()

    /**
     * Returns true iff a FiscaleEenheid voeging satisfies article 15 conditions.
     *
     * REQ-VPB-007: voeging requires bezitPercentage >= 95, gelijke boekjaren and
     * vestiging in NL. Fail-closed on any exception.
     *
     * @param string                   $eenheidId The FiscaleEenheid id (call-signature parity).
     * @param array<string,mixed>|null $object    The eenheid being created/voegen.
     *
     * @return bool True when the voeging is permitted.
     *
     * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
     */
    public function canVoegen(string $eenheidId, ?array $object=null): bool
    {
        try {
            $eenheid = $object;
            if ($eenheid === null || isset($eenheid['bezitPercentage']) === false) {
                $eenheid = $this->resolveObject(schema: 'FiscaleEenheid', id: $eenheidId);
            }

            if ($eenheid === null) {
                return false;
            }

            $bezit           = (float) ($eenheid['bezitPercentage'] ?? 0);
            $gelijkeBoekjaar = ($eenheid['gelijkeBoekjaren'] ?? false) === true;
            $vestigingNl     = ($eenheid['vestigingNederland'] ?? false) === true;

            return $bezit >= 95.0 && $gelijkeBoekjaar === true && $vestigingNl === true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'VpbAangifteGuard: canVoegen check failed — denying voeging (fail-closed)',
                ['eenheidId' => $eenheidId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end canVoegen()

    /**
     * Returns true iff the aangifte's linked jaarrekening is vastgesteld.
     *
     * Looks up the AnnualReport referenced by commercieleWinst and checks its
     * status against the vastgestelde states. A missing FK denies indiening.
     *
     * @param array<string,mixed> $aangifte The aangifte being transitioned.
     *
     * @return bool True when the jaarrekening is vastgesteld.
     */
    private function jaarrekeningVastgesteld(array $aangifte): bool
    {
        $reportId = (string) ($aangifte['commercieleWinst'] ?? '');
        if ($reportId === '') {
            return false;
        }

        $report = $this->resolveObject(schema: 'AnnualReport', id: $reportId);
        if ($report === null) {
            return false;
        }

        $status = (string) ($report['status'] ?? '');

        return in_array($status, self::VASTGESTELDE_STATES, true);
    }//end jaarrekeningVastgesteld()

    /**
     * Returns true iff the Belastingplichtige is Digipoort-ready.
     *
     * Requires eHerkenningsNiveau EH3+ and a non-empty digipoortCertificaat FK.
     *
     * @param string $belastingplichtigeId The Belastingplichtige id.
     *
     * @return bool True when eHerkenning EH3+ and a Digipoort cert are present.
     */
    private function belastingplichtigeDigipoortReady(string $belastingplichtigeId): bool
    {
        if ($belastingplichtigeId === '') {
            return false;
        }

        $belastingplichtige = $this->resolveObject(schema: 'Belastingplichtige', id: $belastingplichtigeId);
        if ($belastingplichtige === null) {
            return false;
        }

        $niveau = (string) ($belastingplichtige['eHerkenningsNiveau'] ?? '');
        $cert   = (string) ($belastingplichtige['digipoortCertificaat'] ?? '');

        return in_array($niveau, ['EH3', 'EH4'], true) && $cert !== '';
    }//end belastingplichtigeDigipoortReady()

    /**
     * Returns true iff every Innovatiebox claim on the aangifte carries an S&O ref.
     *
     * REQ-VPB-004: an innovatiebox claim is invalid without a soVerklaringReferentie.
     * An aangifte with zero innovatiebox claims trivially passes.
     *
     * @param string $aangifteId The VpbAangifte id whose claims to check.
     *
     * @return bool True when all (or no) innovatiebox claims carry an S&O reference.
     */
    private function innovatieboxClaimsHaveSO(string $aangifteId): bool
    {
        if ($aangifteId === '') {
            return false;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $claims = $objectService
            ->setRegister($this->resolveRegister())
            ->setSchema('Innovatiebox')
            ->findAll(['filters' => ['taxReturn' => $aangifteId]]);

        foreach ($claims as $claim) {
            if (is_array($claim) === false) {
                continue;
            }

            if ((string) ($claim['soDeclarationReference'] ?? '') === '') {
                return false;
            }
        }

        return true;
    }//end innovatieboxClaimsHaveSO()

    /**
     * Resolve an object of the given schema by id via ObjectService.
     *
     * @param string $schema The OpenRegister schema slug.
     * @param string $id     The object id.
     *
     * @return array<string,mixed>|null The object, or null when not found.
     */
    private function resolveObject(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

        $records = $objectService
            ->setRegister($this->resolveRegister())
            ->setSchema($schema)
            ->findAll(['filters' => ['id' => $id]]);

        foreach ($records as $record) {
            if (is_array($record) === true) {
                return $record;
            }
        }

        return null;
    }//end resolveObject()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
     *
     * @return string The register slug.
     */
    private function resolveRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end resolveRegister()
}//end class
