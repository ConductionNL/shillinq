<?php

/**
 * Bezwaar/Beroep Termijn Guard
 *
 * ADR-031 exception-path lifecycle guards for the statutory bezwaar/beroep
 * termijnen of the bookkeeping-vpb-mkb change (T3). The VpbAangifte and
 * BezwaarBeroep schema lifecycle transitions reference the methods below for
 * the date-arithmetic preconditions that the declarative DSL cannot yet express:
 *
 *  - canBezwaarMaken():    bezwaar against a DefinitieveAanslag must be lodged
 *                          within 6 weeks of the aanslag dagtekening (REQ-VPB-010,
 *                          Awb art. 6:7).
 *  - canBeroepInstellen(): beroep must be lodged within 6 weeks of the inspecteur
 *                          uitspraak on the bezwaar (REQ-VPB-010, Awb art. 6:7).
 *
 * ADR-031 exception reason: the date arithmetic spans sibling schemas
 * (DefinitieveAanslag.dagtekening, BezwaarBeroep.uitspraakDatum) and compares to
 * the current date — not yet expressible in the declarative lifecycle DSL. When
 * the engine gains those capabilities, replace these references with declarative
 * conditions and delete this file. Both guards fail closed (CWE-863).
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

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Statutory termijn guards for the bezwaar/beroep workflow.
 *
 * Referenced from the bookkeeping-vpb-mkb register.d fragment schema lifecycle
 * transitions as OCA\Shillinq\Lifecycle\BezwaarTermijnGuard::<method>.
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 */
class BezwaarTermijnGuard
{
    /**
     * The statutory termijn length for bezwaar and beroep (Awb art. 6:7).
     *
     * @var string
     */
    private const TERMIJN = '6 weeks';

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
     * Returns true iff bezwaar may still be lodged against the aanslag.
     *
     * REQ-VPB-010: bezwaar is admissible within 6 weeks of the DefinitieveAanslag
     * dagtekening. The aangifte being transitioned carries (or resolves) the
     * gekoppelde DefinitieveAanslag whose dagtekening drives the termijn.
     * Fail-closed: returns false on any exception, a missing aanslag, or a
     * dagtekening that cannot be parsed.
     *
     * @param string                   $aangifteId The VpbAangifte id (call-signature parity).
     * @param array<string,mixed>|null $object     The aangifte being transitioned.
     *
     * @return bool True when the bezwaartermijn has not yet expired.
     *
     * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
     */
    public function canBezwaarMaken(string $aangifteId, ?array $object=null): bool
    {
        try {
            $resolvedId = $aangifteId;
            if ($object !== null && (string) ($object['id'] ?? '') !== '') {
                $resolvedId = (string) $object['id'];
            }

            $aanslag = $this->resolveAanslagForAangifte(aangifteId: $resolvedId);
            if ($aanslag === null) {
                return false;
            }

            return $this->withinTermijn(startDate: (string) ($aanslag['dagtekening'] ?? ''));
        } catch (\Throwable $e) {
            $this->logger->error(
                'BezwaarTermijnGuard: canBezwaarMaken check failed — denying transition (fail-closed)',
                ['aangifteId' => $aangifteId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end canBezwaarMaken()

    /**
     * Returns true iff beroep may still be lodged after the inspecteur uitspraak.
     *
     * REQ-VPB-010: beroep is admissible within 6 weeks of the uitspraakDatum on
     * the bezwaar. Fail-closed on any exception or an unparseable uitspraakDatum.
     *
     * @param string                   $bezwaarId The BezwaarBeroep id (call-signature parity).
     * @param array<string,mixed>|null $object    The bezwaar record being transitioned.
     *
     * @return bool True when the beroepstermijn has not yet expired.
     *
     * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
     */
    public function canBeroepInstellen(string $bezwaarId, ?array $object=null): bool
    {
        try {
            $bezwaar = $object;
            if ($bezwaar === null || isset($bezwaar['uitspraakDatum']) === false) {
                $bezwaar = $this->resolveObject(schema: 'BezwaarBeroep', id: $bezwaarId);
            }

            if ($bezwaar === null) {
                return false;
            }

            $uitspraakDatum = (string) ($bezwaar['uitspraakDatum'] ?? '');
            if ($uitspraakDatum === '') {
                return false;
            }

            return $this->withinTermijn(startDate: $uitspraakDatum);
        } catch (\Throwable $e) {
            $this->logger->error(
                'BezwaarTermijnGuard: canBeroepInstellen check failed — denying transition (fail-closed)',
                ['bezwaarId' => $bezwaarId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end canBeroepInstellen()

    /**
     * Returns true iff today is on or before startDate + the statutory termijn.
     *
     * @param string $startDate The termijn start date (YYYY-MM-DD).
     *
     * @return bool True when within the termijn; false on an empty/invalid date.
     */
    private function withinTermijn(string $startDate): bool
    {
        if ($startDate === '') {
            return false;
        }

        try {
            $start = new DateTimeImmutable(substr($startDate, 0, 10));
        } catch (\Exception $e) {
            return false;
        }

        $deadline = $start->modify('+'.self::TERMIJN);
        $today    = new DateTimeImmutable('today');

        return $today <= $deadline;
    }//end withinTermijn()

    /**
     * Resolve the DefinitieveAanslag linked to a given aangifte.
     *
     * @param string $aangifteId The VpbAangifte id.
     *
     * @return array<string,mixed>|null The aanslag, or null when not found.
     */
    private function resolveAanslagForAangifte(string $aangifteId): ?array
    {
        if ($aangifteId === '') {
            return null;
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->resolveRegister();

        $aanslagen = $objectService
            ->setRegister($register)
            ->setSchema('DefinitieveAanslag')
            ->findAll(['filters' => ['aangifte' => $aangifteId]]);

        foreach ($aanslagen as $aanslag) {
            if (is_array($aanslag) === true) {
                return $aanslag;
            }
        }

        return null;
    }//end resolveAanslagForAangifte()

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
        $register      = $this->resolveRegister();

        $objects = $objectService
            ->setRegister($register)
            ->setSchema($schema)
            ->findAll(['filters' => ['id' => $id]]);

        foreach ($objects as $object) {
            if (is_array($object) === true) {
                return $object;
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
