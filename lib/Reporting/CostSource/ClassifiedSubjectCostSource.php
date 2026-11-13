<?php

/**
 * Classified Subject Cost Source
 *
 * The cost of the hours booked in a period on the objects of each configured
 * cost source, with each object's type and the taakveld its type carries.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\CostSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\CostSource;

use OCA\Shillinq\Service\HrmqCostRateAdapter;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Support\FleetAppId;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, per configured source, one costed row per object with hours in a period.
 *
 * Each object is costed through SubjectCostService over the period's hours
 * only. Shillinq sums the money; the source app only classifies its types.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
 */
class ClassifiedSubjectCostSource {

	/**
	 * The humaniq hour schema, as SubjectCostService reads it.
	 */
	private const HOURS_SCHEMA = 'TimeEntry';

	/**
	 * The app ids the hours can live under, newest first.
	 *
	 * @var array<int, string>
	 */
	private const HOURS_APPS = ['humaniq', 'hrmq'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's ObjectService lazily.
	 * @param IAppManager $appManager Whether the source app and humaniq are installed.
	 * @param SubjectCostService $subjectCosts Costs one object's hours.
	 * @param HrmqCostRateAdapter $rates Knows the register the hours live in.
	 * @param SubjectCostSourceConfiguration $configuration The configured sources.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SubjectCostService $subjectCosts,
		private readonly HrmqCostRateAdapter $rates,
		private readonly SubjectCostSourceConfiguration $configuration,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every configured source with its rows for a period.
	 *
	 * A source whose app, or humaniq, is not installed has no rows and names
	 * the missing app, so a report shows that and no figure.
	 *
	 * @param string $period The period: `2026`, `2026-Q3` or `2026-09`.
	 * @param array<int, string>|null $administrationIds Administrations the caller may read, null for all.
	 *
	 * @return array<int, array<string, mixed>> Per source: source, missingApp, missingKind ('source', 'hours' or null) and rows.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-a-cost-source-is-configuration-and-an-absent-app-is-shown-not-zero-req-iv3-011
	 */
	public function read(string $period, ?array $administrationIds = null): array {
		$sections = [];
		foreach ($this->configuration->sources() as $source) {
			$sections[] = $this->readSource(source: $source, period: $period, administrationIds: $administrationIds);
		}

		return $sections;
	}//end read()

	/**
	 * One source's rows for a period.
	 *
	 * @param array<string, string> $source The source.
	 * @param string $period The period.
	 * @param array<int, string>|null $administrationIds Administrations the caller may read.
	 *
	 * @return array{source: array<string, string>, missingApp: string|null, missingKind: string|null, rows: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-iv3-report-shows-case-costs-per-taakveld-from-the-case-type-classification-req-iv3-010
	 */
	public function readSource(array $source, string $period, ?array $administrationIds = null): array {
		$section = ['source' => $source, 'missingApp' => null, 'missingKind' => null, 'rows' => []];
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: $source['app']) === false) {
			return array_merge($section, ['missingApp' => $source['appName'], 'missingKind' => 'source']);
		}

		if ($this->hoursInstalled() === false) {
			return array_merge($section, ['missingApp' => 'Humaniq', 'missingKind' => 'hours']);
		}

		$range = ReportPeriod::range(period: $period);
		if ($range === null) {
			return $section;
		}

		$types = [];
		foreach ($this->hoursPerObject(source: $source, range: $range) as $objectId => $entries) {
			$type = $this->typeOf(source: $source, objectId: (string)$objectId, types: $types);
			$cost = $this->subjectCosts->costForHours(
				subjectApp: $source['app'],
				subjectId: (string)$objectId,
				timeEntries: $entries,
				administrationIds: $administrationIds,
				period: substr($range[1], 0, 7)
			);
			if ((float)($cost['hours'] ?? 0.0) === 0.0 && ($cost['perPerson'] ?? []) === []) {
				// Nothing of this object's hours is the caller's to see.
				continue;
			}

			$section['rows'][] = [
				'objectId' => (string)$objectId,
				'typeId' => $type['id'],
				'typeTitle' => $type['title'],
				'taskField' => $type['taskField'],
				'hours' => (float)($cost['hours'] ?? 0.0),
				'costCents' => ($cost['costCents'] ?? null),
				'complete' => (($cost['complete'] ?? false) === true),
			];
		}//end foreach

		return $section;
	}//end readSource()

	/**
	 * Whether the app the hours come from is installed.
	 *
	 * @return bool True when installed.
	 */
	private function hoursInstalled(): bool {
		foreach (self::HOURS_APPS as $app) {
			if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: $app) === true) {
				return true;
			}
		}

		return false;
	}//end hoursInstalled()

	/**
	 * The period's hour rows of the source's objects, grouped per object.
	 *
	 * @param array<string, string> $source The source.
	 * @param array{0: string, 1: string} $range The inclusive date range.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Object id => its hour rows.
	 */
	private function hoursPerObject(array $source, array $range): array {
		try {
			$rows = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->setRegister($this->rates->registerSlug())
				->setSchema(self::HOURS_SCHEMA)
				->findAll(['limit' => 10000]);
		} catch (Throwable $e) {
			$this->logger->warning('ClassifiedSubjectCostSource: could not read hours', ['source' => $source['id'], 'exception' => $e->getMessage()]);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$perObject = [];
		foreach ($rows as $row) {
			$row = self::toArray(value: $row);
			if ($row === null || ReportPeriod::contains(date: ($row['date'] ?? null), range: $range) === false) {
				continue;
			}

			$objectId = trim((string)($row['domainObjectRef'] ?? ''));
			$type = trim((string)($row['domainObjectType'] ?? ''));
			if ($objectId === '' || str_starts_with($type, $source['app'] . ':') === false) {
				continue;
			}

			$perObject[$objectId][] = $row;
		}

		ksort($perObject);

		return $perObject;
	}//end hoursPerObject()

	/**
	 * The type of one object: its id, title and taakveld, cached per type.
	 *
	 * An object or type that cannot be read gives an empty type, so its hours
	 * still count and it lands without a taakveld rather than disappearing.
	 *
	 * @param array<string, string> $source The source.
	 * @param string $objectId The object.
	 * @param array<string, array{id: string, title: string, taskField: string}> $types The cache.
	 *
	 * @return array{id: string, title: string, taskField: string}
	 */
	private function typeOf(array $source, string $objectId, array &$types): array {
		$object = $this->find(register: $source['register'], schema: $source['schema'], id: $objectId);
		$typeId = self::idOf(value: ($object[$source['typeProperty']] ?? null));
		if ($typeId === '') {
			return ['id' => '', 'title' => '', 'taskField' => ''];
		}

		if (isset($types[$typeId]) === false) {
			$type = $this->find(register: $source['register'], schema: $source['typeSchema'], id: $typeId);
			$types[$typeId] = [
				'id' => $typeId,
				'title' => trim((string)($type[$source['typeTitleProperty']] ?? '')),
				'taskField' => trim((string)($type[$source['taskFieldProperty']] ?? '')),
			];
		}

		return $types[$typeId];
	}//end typeOf()

	/**
	 * One object as an array, or an empty array when it cannot be read.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed> The object.
	 */
	private function find(string $register, string $schema, string $id): array {
		try {
			$scoped = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->setRegister($register)
				->setSchema($schema);
			// Checks the schema of the answer: find() alone searches every schema.
			$found = ObjectIdentifier::findScoped(scoped: $scoped, id: $id);
		} catch (Throwable $e) {
			$this->logger->info('ClassifiedSubjectCostSource: could not read object', ['schema' => $schema, 'id' => $id, 'exception' => $e->getMessage()]);
			return [];
		}

		return (self::toArray(value: $found) ?? []);
	}//end find()

	/**
	 * The id in a reference: a plain id, or an object carrying `id` or `@self.id`.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id, or ''.
	 */
	private static function idOf(mixed $value): string {
		if (is_string($value) === true || is_int($value) === true) {
			return trim((string)$value);
		}

		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['@self']['id'] ?? '')));
		}

		return '';
	}//end idOf()

	/**
	 * A stored row as an array.
	 *
	 * @param mixed $value The row.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	private static function toArray(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return null;
	}//end toArray()
}//end class
