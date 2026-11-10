<?php

/**
 * AlgemeenBelangBesluit Lifecycle Service (WMO REQ-WMO-005)
 *
 * Pure-logic state-machine helper for the AlgemeenBelangBesluit (ABB) workflow:
 * concept → raadsvoorstel → raadsbesluit → publicatie → acm-notified → bezwaar →
 * geldig → evaluatie-due → herziening / intrekking. Encodes the precondition
 * checks per transition (e.g. publicatie requires gemeenteblad-kenmerk, geldig
 * requires both publicatieDatum and ACM-kenmerk) and the automatic-task
 * generation map (REQ-WMO-005 §c).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Side-effect-free ABB lifecycle state-machine (REQ-WMO-005).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 */
class AbbLifecycleService {
	/**
	 * Valid status transitions (source -> destinations).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const TRANSITIONS = [
		'draft' => ['raadsvoorstel'],
		'raadsvoorstel' => ['councilResolution', 'draft'],
		'councilResolution' => ['publicatie'],
		'publicatie' => ['acm-notified'],
		'acm-notified' => ['bezwaar'],
		'bezwaar' => ['geldig'],
		'geldig' => ['evaluatie-due', 'intrekking', 'herziening'],
		'evaluatie-due' => ['herziening', 'geldig', 'intrekking'],
		'herziening' => ['raadsvoorstel', 'intrekking'],
		'intrekking' => [],
	];

	/**
	 * Automatic task generated on entering a status (REQ-WMO-005 §c). A null
	 * offset means the task is due on the ABB's nextEvaluation.
	 *
	 * @var array<string,array{type:string,subject:string,offset:string|null,assignedTo:string}>
	 */
	private const STATUS_TASKS = [
		'councilResolution' => [
			'type' => 'publish-gemeenteblad',
			'subject' => 'Publish in gemeenteblad: %s',
			'offset' => 'P14D',
			'assignedTo' => 'griffier',
		],
		'publicatie' => [
			'type' => 'notify-acm',
			'subject' => 'Notify ACM: %s',
			'offset' => 'P7D',
			'assignedTo' => 'juridisch-beleidsadviseur',
		],
		'acm-notified' => [
			'type' => 'review-bezwaarschriften',
			'subject' => 'Review bezwaarschriften (6 weeks): %s',
			'offset' => 'P42D',
			'assignedTo' => 'juridisch-beleidsadviseur',
		],
		'evaluatie-due' => [
			'type' => 'evaluate-abb',
			'subject' => 'Evaluate ABB: %s',
			'offset' => null,
			'assignedTo' => 'juridisch-beleidsadviseur',
		],
	];

	/**
	 * Validate a desired status transition (REQ-WMO-005).
	 *
	 * @param string $fromStatus Current status.
	 * @param string $toStatus Desired status.
	 * @param array<string,mixed> $abb Full ABB record (used for precondition checks).
	 *
	 * @return array{ok:bool, error?:string} Result envelope.
	 */
	public function canTransition(string $fromStatus, string $toStatus, array $abb): array {
		$allowed = self::TRANSITIONS[$fromStatus] ?? [];
		if (in_array($toStatus, $allowed, true) === false) {
			return ['ok' => false, 'error' => sprintf('Transition %s -> %s is not allowed', $fromStatus, $toStatus)];
		}

		$error = $this->preconditionError(toStatus: $toStatus, abb: $abb);
		if ($error !== null) {
			return ['ok' => false, 'error' => $error];
		}

		return ['ok' => true];
	}//end canTransition()

	/**
	 * The refusal for the per-target precondition, or null when it holds.
	 * Targets without a precondition (intrekking, herziening, ...) pass.
	 *
	 * @param string              $toStatus Desired status.
	 * @param array<string,mixed> $abb      Full ABB record.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function preconditionError(string $toStatus, array $abb): string|null {
		return match ($toStatus) {
			'councilResolution' => $this->councilResolutionError(abb: $abb),
			'publicatie' => $this->publicationError(abb: $abb),
			'acm-notified' => $this->acmNotificationError(abb: $abb),
			'geldig' => $this->validityError(abb: $abb),
			default => null,
		};

	}//end preconditionError()

	/**
	 * A council resolution requires a reference.
	 *
	 * @param array<string,mixed> $abb Full ABB record.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function councilResolutionError(array $abb): string|null {
		if ($this->isBlank(value: $abb['reference'] ?? '') === true) {
			return 'Transition to council resolution requires a reference';
		}

		return null;

	}//end councilResolutionError()

	/**
	 * Publication requires the municipal gazette and the publication date.
	 *
	 * @param array<string,mixed> $abb Full ABB record.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function publicationError(array $abb): string|null {
		if ($this->isBlank(value: $abb['publicationMunicipalGazette'] ?? '') === true) {
			return 'Transition to publicatie requires publicatieGemeenteblad';
		}

		if ($this->isBlank(value: $abb['publicationDate'] ?? '') === true) {
			return 'Transition to publicatie requires publicatieDatum';
		}

		return null;

	}//end publicationError()

	/**
	 * The ACM notification must be submitted and carry its reference.
	 *
	 * @param array<string,mixed> $abb Full ABB record.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function acmNotificationError(array $abb): string|null {
		$acm = (array)($abb['notificationAcm'] ?? []);
		if (((bool)($acm['submitted'] ?? false)) === false) {
			return 'Transition to acm-notified requires kennisgevingAcm.ingediend=true';
		}

		if ($this->isBlank(value: $acm['reference'] ?? '') === true) {
			return 'Transition to acm-notified requires kennisgevingAcm.kenmerk';
		}

		return null;

	}//end acmNotificationError()

	/**
	 * Geldig requires the publication date and a submitted ACM notification
	 * with its reference.
	 *
	 * @param array<string,mixed> $abb Full ABB record.
	 *
	 * @return string|null The refusal message, or null.
	 */
	private function validityError(array $abb): string|null {
		if ($this->isBlank(value: $abb['publicationDate'] ?? '') === true) {
			return 'Geldig requires publicatieDatum';
		}

		$acm = (array)($abb['notificationAcm'] ?? []);
		if (((bool)($acm['submitted'] ?? false)) === false || $this->isBlank(value: $acm['reference'] ?? '') === true) {
			return 'Geldig requires ACM kenmerk';
		}

		return null;

	}//end validityError()

	/**
	 * True when the value is empty once cast to a string and trimmed.
	 *
	 * @param mixed $value The value to test.
	 *
	 * @return bool True when blank.
	 */
	private function isBlank(mixed $value): bool {
		return trim((string)$value) === '';

	}//end isBlank()


	/**
	 * Apply a transition, producing the post-transition ABB plus the task envelope (REQ-WMO-005).
	 *
	 * @param array<string,mixed> $abb Current ABB record.
	 * @param string $toStatus Desired new status.
	 *
	 * @return array{abb:array<string,mixed>, tasks:array<int,array<string,mixed>>} Updated ABB and any auto-generated tasks.
	 *
	 * @throws InvalidArgumentException When the transition is not allowed.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-005
	 */
	public function transition(array $abb, string $toStatus): array {
		$fromStatus = (string)($abb['status'] ?? 'draft');
		$check = $this->canTransition(fromStatus: $fromStatus, toStatus: $toStatus, abb: $abb);
		if ($check['ok'] === false) {
			throw new InvalidArgumentException($check['error']);
		}

		$abb['status'] = $toStatus;
		$abb = $this->withNextEvaluation(abb: $abb);

		return [
			'abb' => $abb,
			'tasks' => $this->generateTasks(abb: $abb, toStatus: $toStatus),
		];

	}//end transition()

	/**
	 * Auto-calculate volgendeEvaluatie when the ABB enters geldig with a
	 * determination date.
	 *
	 * @param array<string,mixed> $abb The ABB record, status already set.
	 *
	 * @return array<string,mixed> The ABB, with nextEvaluation when it applies.
	 */
	private function withNextEvaluation(array $abb): array {
		if ($abb['status'] === 'geldig' && trim((string)($abb['determinationDate'] ?? '')) !== '') {
			$abb['nextEvaluation'] = $this->calculateNextEvaluation(
				determinationDate: (string)$abb['determinationDate'],
				ritme: (string)($abb['evaluationCadence'] ?? 'tweejaarlijks')
			);
		}

		return $abb;
	}//end withNextEvaluation()

	/**
	 * Generate the automatic tasks for a given status (REQ-WMO-005 §c).
	 *
	 * @param array<string,mixed> $abb The ABB record.
	 * @param string $toStatus The new status.
	 *
	 * @return array<int,array<string,mixed>> Task envelopes.
	 */
	public function generateTasks(array $abb, string $toStatus): array {
		$template = self::STATUS_TASKS[$toStatus] ?? null;
		if ($template === null) {
			return [];
		}

		return [
			[
				'type' => $template['type'],
				'subject' => sprintf($template['subject'], (string)($abb['reference'] ?? 'ABB')),
				'dueDate' => $this->taskDueDate(offset: $template['offset'], abb: $abb),
				'assignedTo' => $template['assignedTo'],
				'abbId' => (string)($abb['id'] ?? $abb['_id'] ?? ''),
			],
		];
	}//end generateTasks()

	/**
	 * Due date of an automatic task: the offset from today, or — for a task
	 * without an offset — the ABB's nextEvaluation, defaulting to today.
	 *
	 * @param string|null         $offset ISO-8601 interval from today, or null.
	 * @param array<string,mixed> $abb    The ABB record.
	 *
	 * @return string The due date (Y-m-d).
	 */
	private function taskDueDate(string|null $offset, array $abb): string {
		$now = new DateTimeImmutable('now');
		if ($offset === null) {
			return (string)($abb['nextEvaluation'] ?? $now->format('Y-m-d'));
		}

		return $now->add(new DateInterval($offset))->format('Y-m-d');
	}//end taskDueDate()

	/**
	 * Calculate volgendeEvaluatie based on vaststellingsdatum + ritme.
	 *
	 * @param string $determinationDate Vaststellingsdatum (ISO date).
	 * @param string $ritme One of jaarlijks / tweejaarlijks / driejaarlijks.
	 *
	 * @return string Next evaluation date (ISO).
	 */
	public function calculateNextEvaluation(string $determinationDate, string $ritme): string {
		try {
			$base = new DateTimeImmutable($determinationDate);
		} catch (\Throwable) {
			return '';
		}

		$years = match ($ritme) {
			'jaarlijks' => 1,
			'tweejaarlijks' => 2,
			'driejaarlijks' => 3,
			default => 2,
		};

		return $base->add(new DateInterval('P' . $years . 'Y'))->format('Y-m-d');
	}//end calculateNextEvaluation()

	/**
	 * Flag dependent CommercialActivity records when an ABB enters herziening / intrekking (REQ-WMO-005 §dependents).
	 *
	 * @param array<int,array<string,mixed>> $activities Linked CommercialActivity records.
	 * @param array<string,mixed> $abb The ABB.
	 *
	 * @return array<int,array{commercialActivityId:string,reason:string}> Flag envelopes.
	 */
	public function flagDependentActivities(array $activities, array $abb): array {
		$status = (string)($abb['status'] ?? '');
		if (in_array($status, ['herziening', 'intrekking'], true) === false) {
			return [];
		}

		$reason = $this->dependentReviewReason(status: $status, reference: (string)($abb['reference'] ?? 'ABB'));

		return $this->activityFlags(activities: $activities, reason: $reason);
	}//end flagDependentActivities()

	/**
	 * One flag per linked activity record, all with the same reason.
	 *
	 * @param array<int,mixed> $activities Linked CommercialActivity records.
	 * @param string           $reason     The review reason.
	 *
	 * @return array<int,array{commercialActivityId:string,reason:string}> Flag envelopes.
	 */
	private function activityFlags(array $activities, string $reason): array {
		$flags = [];
		foreach ($activities as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$flags[] = [
				'commercialActivityId' => (string)($activity['id'] ?? $activity['_id'] ?? ''),
				'reason' => $reason,
			];
		}

		return $flags;
	}//end activityFlags()

	/**
	 * Reason text for flagging a dependent activity of a withdrawn or revised ABB.
	 *
	 * @param string $status    herziening or intrekking.
	 * @param string $reference The ABB reference.
	 *
	 * @return string The reason.
	 */
	private function dependentReviewReason(string $status, string $reference): string {
		if ($status === 'intrekking') {
			return sprintf('Exemption ABB %s ingetrokken; review activity', $reference);
		}

		return sprintf('Exemption ABB %s in herziening; review activity', $reference);
	}//end dependentReviewReason()
}//end class
