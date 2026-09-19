<?php

/**
 * Object Payment Request Validator
 *
 * A PaymentRequest used to be invoice-shaped: `invoiceReference` was required
 * and the amount was a calculation over the invoice. Since
 * `case-payment-requests` a request may instead stand on any object in any
 * register, so the required set is conditional on `subjectKind` and cannot be
 * expressed by a flat `required` array in the register fragment. This class is
 * that conditional shape, plus the uniqueness invariant: at most one `pending`
 * request per `(subject, requestType)`, so a case cannot quietly collect two
 * open leges requests that both resolve to a payment link.
 *
 * It refuses by throwing, and every refusal names what is missing or what
 * already exists — a caller that cannot tell which of the two happened cannot
 * fix either.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Validates the conditional shape and the uniqueness of a PaymentRequest.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-001)
 */
final class ObjectPaymentRequestValidator {
	/**
	 * The subject kinds the schema accepts.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_KINDS = ['invoice', 'object'];

	/**
	 * The request types an object request may carry.
	 *
	 * @var array<int, string>
	 */
	public const REQUEST_TYPES = ['leges', 'dwangsom', 'deposit', 'other'];

	/**
	 * The parts a semantic reference (ADR-048) must name.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_PARTS = ['type', 'register', 'schema', 'id'];

	/**
	 * The parts that decide WHICH object a reference points at.
	 *
	 * `type` is deliberately absent. It is the calling app's own word for the
	 * thing (`case`, `zaak`, `permit`) and two callers can spell it differently
	 * for the same object; if it were part of the identity, a second pending
	 * leges request would slip past the uniqueness check by naming the type
	 * differently, which is exactly the duplicate this class exists to refuse.
	 *
	 * @var array<int, string>
	 */
	public const IDENTITY_PARTS = ['register', 'schema', 'id'];

	/**
	 * Refuse a request that does not satisfy its own subject kind, or that
	 * duplicates an open request of the same type on the same subject.
	 *
	 * @param array<string, mixed> $request The request about to be written.
	 * @param array<int, array<string, mixed>> $existing Requests already on the same subject.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the shape or the uniqueness is refused.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-001)
	 */
	public function validate(array $request, array $existing = []): void {
		$subjectKind = (string)($request['subjectKind'] ?? 'invoice');

		if (in_array($subjectKind, self::SUBJECT_KINDS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown subjectKind "%s"; expected one of %s.', $subjectKind, implode(', ', self::SUBJECT_KINDS))
			);
		}

		if ($subjectKind === 'invoice') {
			if ((string)($request['invoiceReference'] ?? '') === '') {
				throw new InvalidArgumentException('A payment request on an invoice needs an invoiceReference.');
			}

			return;
		}

		$this->assertSubject(subject: ($request['subject'] ?? null));

		$requestType = (string)($request['requestType'] ?? '');
		if (in_array($requestType, self::REQUEST_TYPES, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'A payment request on an object needs a requestType; expected one of %s, got "%s".',
					implode(', ', self::REQUEST_TYPES),
					$requestType
				)
			);
		}

		$amount = $request['amount'] ?? null;
		if (is_numeric($amount) === false || (float)$amount <= 0.0) {
			throw new InvalidArgumentException('A payment request on an object needs its own amount above zero.');
		}

		$this->assertNoOpenRequest(
			subject: (array)$request['subject'],
			requestType: $requestType,
			existing: $existing,
			selfId: (string)($request['id'] ?? ''),
		);
	}//end validate()

	/**
	 * The stable key of a semantic reference, used to compare two subjects.
	 *
	 * @param array<string, mixed> $subject The semantic reference.
	 *
	 * @return string The key, over register, schema and id in a fixed order.
	 */
	public function subjectKey(array $subject): string {
		$parts = [];
		foreach (self::IDENTITY_PARTS as $part) {
			$parts[] = (string)($subject[$part] ?? '');
		}

		return implode('|', $parts);
	}//end subjectKey()

	/**
	 * Refuse a subject that does not name all four parts of a semantic reference.
	 *
	 * @param mixed $subject The subject as given.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a part is missing.
	 */
	private function assertSubject(mixed $subject): void {
		if (is_array($subject) === false || $subject === []) {
			throw new InvalidArgumentException('A payment request on an object needs a subject.');
		}

		$missing = [];
		foreach (self::SUBJECT_PARTS as $part) {
			if ((string)($subject[$part] ?? '') === '') {
				$missing[] = $part;
			}
		}

		if ($missing !== []) {
			throw new InvalidArgumentException(
				sprintf('The subject is missing %s; a semantic reference names type, register, schema and id.', implode(', ', $missing))
			);
		}
	}//end assertSubject()

	/**
	 * Refuse a second pending request of the same type on the same subject,
	 * naming the request that already stands.
	 *
	 * @param array<string, mixed> $subject The subject of the new request.
	 * @param string $requestType The type of the new request.
	 * @param array<int, array<string, mixed>> $existing Requests already stored.
	 * @param string $selfId The id of the request being revalidated, if it is an update.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When an open request of the same type exists.
	 */
	private function assertNoOpenRequest(array $subject, string $requestType, array $existing, string $selfId): void {
		$key = $this->subjectKey(subject: $subject);

		foreach ($existing as $candidate) {
			if ((string)($candidate['state'] ?? 'pending') !== 'pending') {
				continue;
			}

			if ((string)($candidate['requestType'] ?? '') !== $requestType) {
				continue;
			}

			if (is_array($candidate['subject'] ?? null) === false
				|| $this->subjectKey(subject: (array)$candidate['subject']) !== $key
			) {
				continue;
			}

			$candidateId = (string)($candidate['id'] ?? '');
			if ($selfId !== '' && $candidateId === $selfId) {
				continue;
			}

			$named = '';
			if ($candidateId !== '') {
				$named = sprintf(' (%s)', $candidateId);
			}

			throw new InvalidArgumentException(
				sprintf(
					'A pending %s request already stands on this object%s; settle or void it before raising another.',
					$requestType,
					$named
				)
			);
		}
	}//end assertNoOpenRequest()
}//end class
