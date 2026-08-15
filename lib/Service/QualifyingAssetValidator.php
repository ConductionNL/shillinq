<?php

/**
 * Qualifying Asset Validator
 *
 * Pure-logic helper that validates a QualifyingAsset's toegangsticket per
 * Wet Vpb art. 12ba (REQ-IBA-001) and derives its status. Three routes:
 *
 *  - S&O-route: requires an RVO S&O-verklaring number in the format
 *    S{jaar}/{6-cijfer}, valid (not expired) against a reference date.
 *  - Octrooi-route: requires an octrooi_nummer (and, for kwekersrecht, a
 *    kwekersrecht_nummer).
 *  - Combinatie-route (type=combinatie): requires BOTH an S&O-verklaring AND
 *    (an octrooi_nummer OR a kwekersrecht_nummer).
 *
 * The validator returns the derived status (valid | invalid_access_ticket |
 * expired) so the caller can stamp it before saving; assets that are not
 * status=valid are excluded from the innovatiebox aggregations. No OpenRegister
 * dependency so the rules are unit-testable in isolation.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free toegangsticket validator for QualifyingAsset (REQ-IBA-001).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-001
 */
class QualifyingAssetValidator {
	/**
	 * RVO S&O-verklaring number format: S{4-digit-year}/{6-digit-sequence}.
	 *
	 * @var string
	 */
	private const SO_VERKLARING_PATTERN = '/^S\d{4}\/\d{6}$/';

	/**
	 * Validate the access ticket and return the derived status (REQ-IBA-001).
	 *
	 * @param array<string,mixed> $asset The QualifyingAsset record.
	 * @param string $referenceDate Date (Y-m-d) to test S&O validity against;
	 *                              defaults to today.
	 *
	 * @return array{status: string, valid: bool, errors: array<int,string>}
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-001
	 */
	public function validateAccessTicket(array $asset, string $referenceDate = ''): array {
		if ($referenceDate === '') {
			$referenceDate = date('Y-m-d');
		}

		$type = (string)($asset['type'] ?? '');
		$ticket = (array)($asset['accessTicket'] ?? []);

		$errors = $this->validateSingleRoute(ticket: $ticket, referenceDate: $referenceDate);
		if ($type === 'combinatie') {
			$errors = $this->validateCombinatie(ticket: $ticket, referenceDate: $referenceDate);
		}

		if ($errors !== []) {
			// Distinguish a purely-expired S&O cert from a malformed/missing one.
			$status = 'invalid_access_ticket';
			if (in_array('so_verklaring_expired', $errors, true) === true) {
				$status = 'expired';
			}

			return [
				'status' => $status,
				'valid' => false,
				'errors' => $errors,
			];
		}

		return [
			'status' => 'valid',
			'valid' => true,
			'errors' => [],
		];

	}//end validateAccessTicket()

	/**
	 * Validate the combinatie-route: S&O-verklaring AND (octrooi OR kwekersrecht).
	 *
	 * @param array<string,mixed> $ticket The toegangsticket sub-object.
	 * @param string $referenceDate Reference date for S&O expiry.
	 *
	 * @return array<int,string> Validation error codes (empty when valid).
	 */
	private function validateCombinatie(array $ticket, string $referenceDate): array {
		$errors = $this->validateSoDeclaration(ticket: $ticket, referenceDate: $referenceDate, required: true);
		$patent = (string)($ticket['patent_number'] ?? '');
		$kweker = (string)($ticket['plantBreedersRight_number'] ?? '');

		if ($patent === '' && $kweker === '') {
			$errors[] = 'combinatie_requires_octrooi_or_kwekersrecht';
		}

		return $errors;
	}//end validateCombinatie()

	/**
	 * Validate a single-route ticket based on its declared soort.
	 *
	 * @param array<string,mixed> $ticket The toegangsticket sub-object.
	 * @param string $referenceDate Reference date for S&O expiry.
	 *
	 * @return array<int,string> Validation error codes (empty when valid).
	 */
	private function validateSingleRoute(array $ticket, string $referenceDate): array {
		$kind = (string)($ticket['kind'] ?? '');

		if ($kind === 'so_declaration') {
			return $this->validateSoDeclaration(ticket: $ticket, referenceDate: $referenceDate, required: true);
		}

		if ($kind === 'octrooi') {
			if ((string)($ticket['patent_number'] ?? '') === '') {
				return ['octrooi_nummer_required'];
			}

			return [];
		}

		if ($kind === 'kwekersrecht') {
			if ((string)($ticket['plantBreedersRight_number'] ?? '') === '') {
				return ['kwekersrecht_nummer_required'];
			}

			return [];
		}

		if ($kind === '') {
			return ['toegangsticket_soort_required'];
		}

		// Weesgeneesmiddel / gebruiksmodel / abc: presence of a soort is enough
		// for the declarative spec; deeper jurisdiction checks are out of scope.
		return [];
	}//end validateSingleRoute()

	/**
	 * Validate an S&O-verklaring number format + expiry (REQ-IBA-001).
	 *
	 * @param array<string,mixed> $ticket The toegangsticket sub-object.
	 * @param string $referenceDate Reference date for expiry.
	 * @param bool $required Whether the S&O number must be present.
	 *
	 * @return array<int,string> Validation error codes (empty when valid).
	 */
	private function validateSoDeclaration(array $ticket, string $referenceDate, bool $required): array {
		$number = (string)($ticket['rnd_declaration_number'] ?? '');

		if ($number === '') {
			if ($required === true) {
				return ['so_verklaring_nummer_required'];
			}

			return [];
		}

		if (preg_match(self::SO_VERKLARING_PATTERN, $number) !== 1) {
			return ['so_verklaring_format_invalid'];
		}

		$tot = (string)(($ticket['so_declaration_period']['tot'] ?? ''));
		if ($tot !== '' && $tot < $referenceDate) {
			return ['so_verklaring_expired'];
		}

		return [];
	}//end validateSoVerklaring()
}//end class
