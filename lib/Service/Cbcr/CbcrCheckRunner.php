<?php

/**
 * Shillinq CbC and GIR Check Runner
 *
 * Joins a CbcrReturn or GlobeInformationReturn with the records its file is
 * made from, runs the registered checks through RuleEngine, and gives each
 * check pass or fail with its severity, for the Checks tab, for Generate
 * XML (refused while a mandatory check fails) and for the submit and
 * approve guards. It also gives the fingerprint of those records, which a
 * generated file stores in `lastValidation` so a guard can tell whether the
 * file still matches the records (design D7, REQ-CBC-012).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Standards\Checks\CbcrChecks;
use OCA\Shillinq\Standards\Checks\GirChecks;
use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Runs the CbC and GIR checks over the joined records.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
 */
class CbcrCheckRunner {
	/**
	 * Record keys left out of a fingerprint: they change without the figures changing.
	 *
	 * @var array<int,string>
	 */
	private const VOLATILE = [
		'@self', 'updated', 'created', 'state', 'docRefIds', 'lastValidation', 'cbcrXmlSubmission', 'globeXmlSubmission', 'taxAuthorityReference',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     Names the register.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The CbC checks of a return.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 *
	 * @return array<int,array{id:string,severity:string,passed:bool,source:string,statement:string}>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public function runCbc(array $return): array {
		return self::results(objectType: 'CbcrReturn', ruleIds: array_keys(CbcrChecks::checks()['CbcrReturn']), joined: $this->joinCbc(return: $return));
	}//end runCbc()

	/**
	 * The GIR checks of a return.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 *
	 * @return array<int,array{id:string,severity:string,passed:bool,source:string,statement:string}>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public function runGir(array $return): array {
		return self::results(
			objectType: 'GlobeInformationReturn',
			ruleIds: array_keys(GirChecks::checks()['GlobeInformationReturn']),
			joined: $this->joinGir(return: $return)
		);
	}//end runGir()

	/**
	 * The ids of the mandatory checks that failed.
	 *
	 * @param array<int,array{id:string,severity:string,passed:bool}> $results The check results.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public static function blockingFailures(array $results): array {
		$failed = [];
		foreach ($results as $result) {
			if ($result['passed'] === false && $result['severity'] === 'mandatory') {
				$failed[] = $result['id'];
			}
		}

		return $failed;
	}//end blockingFailures()

	/**
	 * The fingerprint of the records a CbC file is made from: the return's
	 * jurisdiction summaries and the group's entity figures for the year.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 *
	 * @return string SHA-256 hex.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.2
	 */
	public function cbcFingerprint(array $return): string {
		$joined  = $this->joinCbc(return: $return);
		$figures = array_map(static fn (array $entity): mixed => ($entity['figures'] ?? null), $joined['_entities']);

		return self::fingerprint(records: ['return' => $return, 'summaries' => $joined['_summaries'], 'figures' => $figures]);
	}//end cbcFingerprint()

	/**
	 * The fingerprint of the records a GIR file is made from: the group's
	 * safe harbour records and jurisdiction computations for the year.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 *
	 * @return string SHA-256 hex.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.2
	 */
	public function girFingerprint(array $return): string {
		[$upeId, $period] = self::girScope(return: $return);
		$filters = ['ultimateParentEntity' => $upeId, 'period' => $period];

		return self::fingerprint(
			records: [
				'return' => $return,
				'safeHarbours' => $this->all(schema: 'Pillar2SafeHarbour', filters: $filters),
				'computations' => $this->all(schema: 'Pillar2JurisdictionComputation', filters: $filters),
			]
		);
	}//end girFingerprint()

	/**
	 * The CbC return joined with its group's entities (and their figures) and summaries.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 *
	 * @return array<string,mixed>
	 */
	private function joinCbc(array $return): array {
		$reporting = (string)($return['reportingEntity'] ?? '');
		$entity    = $this->one(schema: 'GroupEntityRegistry', id: $reporting);
		$upeId     = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($upeId === '') {
			$upeId = $reporting;
		}

		$year     = self::yearOf(period: (string)($return['period'] ?? ''));
		$entities = [];
		foreach ($this->all(schema: 'GroupEntityRegistry', filters: []) as $member) {
			$id = (string)($member['id'] ?? '');
			$inGroup = ($id === $upeId || (string)($member['ultimateParentEntity'] ?? '') === $upeId);
			if ($inGroup === false || ($member['cbcrIncluded'] ?? true) === false) {
				continue;
			}

			$member['figures'] = ($this->all(schema: 'CbcrEntityFigures', filters: ['entity' => $id, 'fiscalYear' => $year])[0] ?? null);
			$entities[] = $member;
		}

		$summaries = [];
		$listed    = array_values(array_filter(array_map('strval', (array)($return['jurisdictionSummaries'] ?? []))));
		foreach ($this->all(schema: 'CbcrJurisdictionSummary', filters: ['ultimateParentEntity' => $upeId, 'period' => 'FY' . $year]) as $summary) {
			if ($listed === [] || in_array((string)($summary['id'] ?? ''), $listed, true) === true) {
				$summaries[] = $summary;
			}
		}

		return $return + ['_entities' => $entities, '_summaries' => $summaries];
	}//end joinCbc()

	/**
	 * The GIR return joined with its jurisdictions' safe harbour and computation state.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 *
	 * @return array<string,mixed>
	 */
	private function joinGir(array $return): array {
		[$upeId, $period] = self::girScope(return: $return);
		$filters = ['ultimateParentEntity' => $upeId, 'period' => $period];

		$jurisdictions = [];
		foreach ($this->all(schema: 'CbcrJurisdictionSummary', filters: $filters) as $summary) {
			$jurisdictions[(string)($summary['jurisdiction'] ?? '')] = ['safeHarbour' => false, 'computationApproved' => false];
		}

		foreach ($this->all(schema: 'Pillar2SafeHarbour', filters: $filters + ['testResult' => 'pass']) as $test) {
			$jurisdictions[(string)($test['jurisdiction'] ?? '')]['safeHarbour'] = true;
		}

		foreach ($this->all(schema: 'Pillar2JurisdictionComputation', filters: $filters) as $computation) {
			$code = (string)($computation['jurisdiction'] ?? '');
			$jurisdictions[$code] ??= ['safeHarbour' => false, 'computationApproved' => false];
			$jurisdictions[$code]['computationApproved'] = in_array((string)($computation['state'] ?? ''), ['approved', 'locked'], true);
		}

		ksort($jurisdictions);
		return $return + ['_jurisdictions' => $jurisdictions];
	}//end joinGir()

	/**
	 * Every check with its outcome.
	 *
	 * @param string              $objectType The object type the checks are registered for.
	 * @param array<int,string>   $ruleIds    The check ids, in order.
	 * @param array<string,mixed> $joined     The joined object.
	 *
	 * @return array<int,array{id:string,severity:string,passed:bool,source:string,statement:string}>
	 */
	private static function results(string $objectType, array $ruleIds, array $joined): array {
		$failed = [];
		foreach (RuleEngine::evaluate($objectType, $joined, ['jurisdiction' => 'NL']) as $violation) {
			$failed[$violation->ruleId] = true;
		}

		$results = [];
		foreach ($ruleIds as $ruleId) {
			$rule      = RuleEngine::violationFor($ruleId);
			$results[] = [
				'id' => $ruleId,
				'severity' => $rule->severity,
				'passed' => (isset($failed[$ruleId]) === false),
				'source' => $rule->source,
				'statement' => $rule->statement,
			];
		}

		return $results;
	}//end results()

	/**
	 * A stable hash of records, without the keys that change on their own.
	 *
	 * @param array<string,mixed> $records The records.
	 *
	 * @return string
	 */
	private static function fingerprint(array $records): string {
		return hash('sha256', (string)json_encode(self::normalise(value: $records), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
	}//end fingerprint()

	/**
	 * A value with volatile keys removed and keys sorted, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach (self::VOLATILE as $key) {
			unset($value[$key]);
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(static fn (mixed $item): mixed => self::normalise(value: $item), $value);
	}//end normalise()

	/**
	 * The ultimate parent and period of a GIR return.
	 *
	 * @param array<string,mixed> $return The return.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function girScope(array $return): array {
		return [(string)($return['ultimateParent'] ?? ''), 'FY' . self::yearOf(period: (string)($return['period'] ?? ''))];
	}//end girScope()

	/**
	 * The fiscal year in a period such as FY2026 or 2026.
	 *
	 * @param string $period The period.
	 *
	 * @return int
	 */
	private static function yearOf(string $period): int {
		if (preg_match('/(\d{4})/', $period, $match) === 1) {
			return (int)$match[1];
		}

		return 0;
	}//end yearOf()

	/**
	 * One record by id, null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $this->objectService->setRegister($this->register())->setSchema($schema), id: $id);
	}//end one()

	/**
	 * Every row of a schema matching the filters.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all(string $schema, array $filters): array {
		$records = [];
		foreach ($this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters, 'limit' => 10000]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end all()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
