<?php

/**
 * Bezwaar/Beroep Termijn Guard
 *
 * ADR-031 exception-path lifecycle guards for the statutory bezwaar/beroep
 * termijnen of the bookkeeping-vpb-mkb change (T3). The VpbAangifte and
 * BezwaarBeroep schema lifecycle transitions reference the methods below for
 * the date-arithmetic preconditions that the declarative DSL cannot yet express:
 *
 *  - canFileObjection():    bezwaar against a DefinitieveAanslag must be lodged
 *                          within 6 weeks of the aanslag dagtekening (REQ-VPB-010,
 *                          Awb art. 6:7).
 *  - canFileAppeal(): beroep must be lodged within 6 weeks of the inspecteur
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
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Statutory termijn guards for the bezwaar/beroep workflow.
 *
 * Referenced from the bookkeeping-vpb-mkb register.d fragment schema lifecycle
 * transitions as OCA\Shillinq\Lifecycle\ObjectionPeriodGuard::<method>.
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 */
class ObjectionPeriodGuard {
	/**
	 * The statutory period for objection and appeal (Awb art. 6:7).
	 *
	 * @var string
	 */
	private const STATUTORY_PERIOD = '6 weeks';

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
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
	 * @param string $taxReturnId The VpbAangifte id (call-signature parity).
	 * @param array<string,mixed>|null $object The tax return being transitioned.
	 *
	 * @return bool True when the objection period has not yet expired.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function canFileObjection(string $taxReturnId, ?array $object = null): bool {
		try {
			$resolvedId = $taxReturnId;
			if ($object !== null && (string)($object['id'] ?? '') !== '') {
				$resolvedId = (string)$object['id'];
			}

			$assessment = $this->resolveAssessmentForTaxReturn(taxReturnId: $resolvedId);
			if ($assessment === null) {
				return false;
			}

			// 'issueDate' is a SCHEMA PROPERTY KEY, not an identifier: it names a
			// column on the DefinitieveAanslag shard table. It moves when that
			// schema is renamed, together with its data migration — not here.
			return $this->withinPeriod(startDate: (string)($assessment['issueDate'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->error(
				'ObjectionPeriodGuard: canFileObjection check failed — denying transition (fail-closed)',
				['taxReturnId' => $taxReturnId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canFileObjection()

	/**
	 * Returns true iff beroep may still be lodged after the inspecteur uitspraak.
	 *
	 * REQ-VPB-010: beroep is admissible within 6 weeks of the uitspraakDatum on
	 * the bezwaar. Fail-closed on any exception or an unparseable uitspraakDatum.
	 *
	 * @param string $objectionId The BezwaarBeroep id (call-signature parity).
	 * @param array<string,mixed>|null $object The objection record being transitioned.
	 *
	 * @return bool True when the appeal period has not yet expired.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function canFileAppeal(string $objectionId, ?array $object = null): bool {
		try {
			// 'BezwaarBeroep' and 'rulingDate' are the SCHEMA NAME and a
			// SCHEMA PROPERTY KEY — the data contract, renamed with their
			// migration, not with this class.
			$objection = $object;
			if ($objection === null || isset($objection['rulingDate']) === false) {
				$objection = $this->resolveObject(schema: 'BezwaarBeroep', id: $objectionId);
			}

			if ($objection === null) {
				return false;
			}

			$rulingDate = (string)($objection['rulingDate'] ?? '');
			if ($rulingDate === '') {
				return false;
			}

			return $this->withinPeriod(startDate: $rulingDate);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ObjectionPeriodGuard: canFileAppeal check failed — denying transition (fail-closed)',
				['objectionId' => $objectionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canFileAppeal()

	/**
	 * Returns true iff today is on or before startDate + the statutory period.
	 *
	 * @param string $startDate The period start date (YYYY-MM-DD).
	 *
	 * @return bool True when within the period; false on an empty/invalid date.
	 */
	private function withinPeriod(string $startDate): bool {
		if ($startDate === '') {
			return false;
		}

		try {
			$start = new DateTimeImmutable(substr($startDate, 0, 10));
		} catch (\Exception $e) {
			return false;
		}

		$deadline = $start->modify('+' . self::STATUTORY_PERIOD);
		$today = new DateTimeImmutable('today');

		return $today <= $deadline;
	}//end withinPeriod()

	/**
	 * Resolve the DefinitieveAanslag linked to a given tax return.
	 *
	 * @param string $taxReturnId The VpbAangifte id.
	 *
	 * @return array<string,mixed>|null The assessment, or null when not found.
	 */
	private function resolveAssessmentForTaxReturn(string $taxReturnId): ?array {
		if ($taxReturnId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		// 'DefinitieveAanslag' and the 'taxReturn' filter key are the data
		// contract — the registered schema title and one of its property
		// columns. Both move with the schema rename and its migration.
		$assessments = $this->objectService
			->setRegister($register)
			->setSchema('DefinitieveAanslag')
			->findAll(['filters' => ['taxReturn' => $taxReturnId]]);

		foreach ($assessments as $assessment) {
			if (is_array($assessment) === true) {
				return $assessment;
			}
		}

		return null;
	}//end resolveAssessmentForTaxReturn()

	/**
	 * Resolve an object of the given schema by id via ObjectService.
	 *
	 * @param string $schema The OpenRegister schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null The object, or null when not found.
	 */
	private function resolveObject(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$objects = $this->objectService
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
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
