<?php

/**
 * Assignment Rate Refresher
 *
 * Keeps `ProjectAssignment.recognisedRate` showing the rate the assignment
 * bills at today (sales-billable-rate-per-hour D6, REQ-BRPH-004). The rate is
 * resolved by {@see BillableRateService} for the assignment's person, project
 * and billing role on today's date, without writing a `RateRecord`: it is a
 * display snapshot, and an invoice line never reads it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Writes an assignment's current rate.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
 */
class AssignmentRateRefresher {
	/**
	 * The assignment schema.
	 *
	 * @var string
	 */
	public const ASSIGNMENT_SCHEMA = 'ProjectAssignment';

	/**
	 * Rows read per lookup.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param BillableRateService    $rates            The rate resolver.
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param ITimeFactory           $timeFactory      The clock, for today's date.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BillableRateService $rates,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Write the rate one assignment resolves to today, when it changed.
	 *
	 * An assignment that resolves to no rate keeps the rate it shows; the
	 * reason is returned for the caller to log.
	 *
	 * @param array<string, mixed> $assignment The assignment, with its `id`.
	 *
	 * @return array<string, mixed> The resolver's answer, with `written` true when the rate was saved.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
	 */
	public function refresh(array $assignment): array {
		$id = (string)($assignment['id'] ?? '');
		$answer = $this->rates->preview(
			hour: [
				'personId' => (string)($assignment['personId'] ?? ''),
				'projectId' => (string)($assignment['projectId'] ?? ''),
				'date' => $this->timeFactory->getDateTime()->format('Y-m-d'),
				'hours' => 1,
				'administrationId' => (string)($assignment['administrationId'] ?? ''),
			]
		);
		$answer['written'] = false;
		if ($id === '' || ($answer['status'] ?? '') !== BillableRateService::STATUS_RATED) {
			return $answer;
		}

		$rate = (float)$answer['rate'];
		$shown = ($assignment['recognisedRate'] ?? null);
		if (is_numeric($shown) === true && (float)$shown === $rate) {
			return $answer;
		}

		$this->objectService->patchObject(
			objectId: $id,
			data: ['recognisedRate' => $rate],
			register: $this->registerLocation->getRegisterSlug(),
			schema: self::ASSIGNMENT_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
		$answer['written'] = true;

		return $answer;
	}//end refresh()

	/**
	 * Refresh every assignment priced by the rate card a version belongs to.
	 *
	 * @param array<string, mixed> $version The `RateCardVersion` that became active.
	 *
	 * @return int The number of assignments whose rate was written.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-2.3
	 */
	public function refreshForVersion(array $version): int {
		$administrationId = (string)($version['administrationId'] ?? '');
		$templateKey = (string)($version['templateId'] ?? '');
		if ($administrationId === '' || $templateKey === '') {
			return 0;
		}

		$cardKeys = [$templateKey];
		foreach ($this->rows(schema: 'RateCardTemplate', administrationId: $administrationId) as $template) {
			$keys = array_filter([(string)($template['id'] ?? ''), (string)($template['templateId'] ?? '')], static fn (string $key): bool => $key !== '');
			if (in_array($templateKey, $keys, true) === true) {
				$cardKeys = array_values(array_unique(array_merge($cardKeys, $keys)));
			}
		}

		$written = 0;
		foreach ($this->rows(schema: self::ASSIGNMENT_SCHEMA, administrationId: $administrationId) as $assignment) {
			if (in_array((string)($assignment['rateCardId'] ?? ''), $cardKeys, true) === true
				&& $this->refresh(assignment: $assignment)['written'] === true
			) {
				$written++;
			}
		}

		return $written;
	}//end refreshForVersion()

	/**
	 * The rows of a schema in one administration, as records with their id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $schema, string $administrationId): array {
		$rows = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema)->findAll(
			['filters' => ['administrationId' => $administrationId], 'limit' => self::READ_LIMIT]
		);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['administrationId'] ?? '') === $administrationId) {
				$records[] = $record;
			}
		}

		return $records;
	}//end rows()
}//end class
