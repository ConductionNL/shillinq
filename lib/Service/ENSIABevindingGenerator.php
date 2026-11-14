<?php

/**
 * ENSIA Bevinding Auto-Generator
 *
 * REQ-ENSIA-005 — on peer-review completion, scan every Evaluatievraag in
 * the cyclus where volwassenheidsScore < normniveau and produce a concept-
 * Bevinding (type=tekortkoming) capturing the question reference, score
 * gap, and an auto-populated beschrijving.
 *
 * Pure function over a vragen array — no persistence. The caller (typically
 * a lifecycle action on the parent cyclus or a controller endpoint) iterates
 * the returned Bevinding shapes and writes them to OR via ObjectService.
 * Operators may subsequently re-classify or suppress individual bevindingen
 * via the Bevinding lifecycle (open → geaccepteerd).
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
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Generates concept Bevinding records from a peer-reviewed ENSIA cyclus
 * (REQ-ENSIA-005).
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class ENSIABevindingGenerator {
	/**
	 * Generate Bevinding records for every Evaluatievraag whose maturity
	 * score is strictly below its VNG normniveau (default 3).
	 *
	 * Only questions that have completed peer-review (peerReviewStatus =
	 * akkoord) are considered — questions still in wijziging-gevraagd are
	 * out of scope because their answer is still expected to change.
	 *
	 * Auto-generated records carry:
	 *   - cyclusId / administrationId from the parent cyclus
	 *   - vraagId pointing at the source Evaluatievraag
	 *   - type=tekortkoming
	 *   - beschrijving auto-populated with vraagCode + score gap
	 *   - status=open
	 *   - no owner / streefDatum (operator fills in on triage)
	 *
	 * @param array<string,mixed> $cyclus The parent ENSIAJaarcyclus
	 *                                    record (provides cyclusId
	 *                                    + administrationId).
	 * @param array<int,array<string,mixed>> $vragen Every Evaluatievraag in
	 *                                               the cyclus.
	 *
	 * @return array<int,array<string,mixed>> Concept Bevinding records ready
	 *                                        for ObjectService::saveObject.
	 *
	 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function generate(array $cyclus, array $vragen): array {
		$findings = [];
		foreach ($vragen as $vraag) {
			if ($this->isShortfall(vraag: $vraag) === true) {
				$findings[] = $this->finding(cyclus: $cyclus, vraag: $vraag);
			}
		}

		return $findings;
	}//end generate()

	/**
	 * Generate Bevinding records from the answers alone, for a cyclus that
	 * moves to peer review (REQ-ENSIA-011): a ja-nee-nvt question answered
	 * `nee`, and a maturity score strictly below its normniveau, each give
	 * one finding whatever the question's peer review status is.
	 *
	 * @param array<string, mixed>       $cyclus The ENSIAJaarcyclus.
	 * @param list<array<string, mixed>> $vragen The cyclus's Evaluatievraag records.
	 *
	 * @return list<array<string, mixed>> Bevinding payloads (status open, type tekortkoming).
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function fromAnswers(array $cyclus, array $vragen): array {
		$findings = [];
		foreach ($vragen as $vraag) {
			if ($this->answeredNo(vraag: $vraag) === true || $this->isBelowNorm(vraag: $vraag) === true) {
				$findings[] = $this->finding(cyclus: $cyclus, vraag: $vraag);
			}
		}

		return $findings;
	}//end fromAnswers()

	/**
	 * Whether a ja-nee-nvt question is answered `nee`.
	 *
	 * @param array<string, mixed> $vraag The Evaluatievraag.
	 *
	 * @return bool
	 */
	private function answeredNo(array $vraag): bool {
		return (string)($vraag['answerType'] ?? '') === 'ja-nee-nvt'
			&& strtolower(trim((string)($vraag['answer'] ?? ''))) === 'nee';
	}//end answeredNo()

	/**
	 * Whether a question's maturity score lies strictly below its normniveau.
	 *
	 * @param array<string, mixed> $vraag The Evaluatievraag.
	 *
	 * @return bool
	 */
	private function isBelowNorm(array $vraag): bool {
		$score = $vraag['maturityScore'] ?? null;
		$normniveau = $vraag['normniveau'] ?? null;

		return is_int($score) === true && is_int($normniveau) === true && $score < $normniveau;
	}//end isBelowNorm()

	/**
	 * The description of a finding for a question answered `nee`.
	 *
	 * @param array<string, mixed> $vraag The Evaluatievraag.
	 *
	 * @return string
	 */
	private function noDescription(array $vraag): string {
		$questionLabel = (string)($vraag['questionText'] ?? '');
		if ($questionLabel === '') {
			$questionLabel = 'evaluatievraag';
		}

		return sprintf('%s — %s: beantwoord met nee.', (string)($vraag['questionCode'] ?? ''), $questionLabel);
	}//end noDescription()

	/**
	 * Whether a peer-reviewed question scores strictly below its normniveau.
	 *
	 * @param array<string,mixed> $vraag One Evaluatievraag.
	 *
	 * @return boolean
	 */
	private function isShortfall(array $vraag): bool {
		$score = $vraag['maturityScore'] ?? null;
		$normniveau = $vraag['normniveau'] ?? null;

		return (string)($vraag['peerReviewStatus'] ?? 'nog-niet-beoordeeld') === 'akkoord'
			&& is_int($score) === true
			&& is_int($normniveau) === true
			&& $score < $normniveau;
	}//end isShortfall()

	/**
	 * The concept Bevinding for a shortfall question.
	 *
	 * @param array<string,mixed> $cyclus The parent ENSIAJaarcyclus.
	 * @param array<string,mixed> $vraag  The shortfall Evaluatievraag.
	 *
	 * @return array<string,mixed>
	 */
	private function finding(array $cyclus, array $vraag): array {
		return [
			'cyclusId' => (string)($cyclus['id'] ?? $cyclus['uuid'] ?? ''),
			'administrationId' => (string)($cyclus['administrationId'] ?? ''),
			'questionId' => (string)($vraag['id'] ?? $vraag['uuid'] ?? ''),
			'type' => 'tekortkoming',
			'description' => $this->description(vraag: $vraag),
			'status' => 'open',
		];
	}//end finding()

	/**
	 * The auto-populated description: question code, label and score gap,
	 * or that the question was answered `nee` when it carries no score.
	 *
	 * @param array<string,mixed> $vraag The shortfall Evaluatievraag.
	 *
	 * @return string
	 */
	private function description(array $vraag): string {
		if (is_int($vraag['maturityScore'] ?? null) === false || is_int($vraag['normniveau'] ?? null) === false) {
			return $this->noDescription(vraag: $vraag);
		}

		$questionLabel = (string)($vraag['questionText'] ?? '');
		if ($questionLabel === '') {
			$questionLabel = 'evaluatievraag';
		}

		return sprintf(
			'%s — %s: volwassenheidsScore %d ligt onder VNG normniveau %d.',
			(string)($vraag['questionCode'] ?? ''),
			$questionLabel,
			$vraag['maturityScore'],
			$vraag['normniveau']
		);
	}//end description()
}//end class
