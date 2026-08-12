<?php

/**
 * Pipelinq Contact DTO.
 *
 * Immutable value object carrying the subset of a pipelinq Contact that
 * shillinq surfaces to the booking detail view: external id, legal name,
 * email, phone, address, and kvk number. Created by
 * {@see PipelinqContactAdapter::getContact()} from the JSON payload
 * returned by `GET /api/v1/contacts/{externalId}`, and serialised to an
 * array for caching (5-minute TTL keyed on the external id).
 *
 * Carries an additional `found` flag so the adapter can return a fallback
 * "not found" shape (404 / malformed JSON) without leaking a `null`
 * through the public API; callers branch on `isFound()`.
 *
 * Security (ADR-005):
 *   - The DTO only ever holds data already returned by pipelinq; no
 *     credentials or transport state are cached on it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Immutable Contact value carried over the customer-bridge.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this constructor signature would ripple to callers;
 *     deferred.
 */
final class PipelinqContact {
	/**
	 * Constructor.
	 *
	 * @param string $externalId The pipelinq `externalId` for this contact.
	 * @param string $legalName Trade / legal name; empty when omitted upstream.
	 * @param string $email Primary email; empty when omitted upstream.
	 * @param string $phone Primary phone; empty when omitted upstream.
	 * @param string $address Postal address; empty when omitted upstream.
	 * @param string $kvkNumber KvK / chamber-of-commerce number; empty when omitted.
	 * @param bool $found Whether this DTO represents a real contact (false → fallback / 404).
	 */
	public function __construct(
		public readonly string $externalId,
		public readonly string $legalName = '',
		public readonly string $email = '',
		public readonly string $phone = '',
		public readonly string $address = '',
		public readonly string $kvkNumber = '',
		public readonly bool $found = true,
	) {

	}//end __construct()

	/**
	 * Build a Contact DTO from a decoded pipelinq JSON payload.
	 *
	 * Missing fields default to the empty string so the caller never sees
	 * `null` on the public properties; the `externalId` argument wins over
	 * any value in the payload to keep the cache key contract stable.
	 *
	 * @param string $externalId The id the caller looked up.
	 * @param array<string, mixed> $payload Decoded JSON body returned by pipelinq.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public static function fromApiPayload(string $externalId, array $payload): self {
		return new self(
			externalId: $externalId,
			legalName: self::stringField(payload: $payload, key: 'legalName'),
			email: self::stringField(payload: $payload, key: 'email'),
			phone: self::stringField(payload: $payload, key: 'phone'),
			address: self::stringField(payload: $payload, key: 'address'),
			kvkNumber: self::stringField(payload: $payload, key: 'kvkNumber'),
			found: true,
		);

	}//end fromApiPayload()

	/**
	 * Build a "not found" fallback DTO for 404 or malformed JSON outcomes.
	 *
	 * @param string $externalId The id the caller looked up.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public static function notFound(string $externalId): self {
		return new self(externalId: $externalId, found: false);
	}//end notFound()

	/**
	 * Whether the DTO carries real upstream data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function isFound(): bool {
		return $this->found;
	}//end isFound()

	/**
	 * Serialise the DTO to a plain array (for caching and API surfaces).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public function toArray(): array {
		return [
			'externalId' => $this->externalId,
			'legalName' => $this->legalName,
			'email' => $this->email,
			'phone' => $this->phone,
			'address' => $this->address,
			'kvkNumber' => $this->kvkNumber,
			'found' => $this->found,
		];

	}//end toArray()

	/**
	 * Re-hydrate a DTO from its `toArray()` shape.
	 *
	 * @param array<string, mixed> $data Previously-serialised contact.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-03-contact-read/tasks.md
	 */
	public static function fromArray(array $data): self {
		return new self(
			externalId: self::stringField(payload: $data, key: 'externalId'),
			legalName: self::stringField(payload: $data, key: 'legalName'),
			email: self::stringField(payload: $data, key: 'email'),
			phone: self::stringField(payload: $data, key: 'phone'),
			address: self::stringField(payload: $data, key: 'address'),
			kvkNumber: self::stringField(payload: $data, key: 'kvkNumber'),
			found: (bool)($data['found'] ?? true),
		);

	}//end fromArray()

	/**
	 * Coerce a payload field to a trimmed string, defaulting to empty.
	 *
	 * @param array<string, mixed> $payload Source payload.
	 * @param string $key Field key.
	 *
	 * @return string
	 */
	private static function stringField(array $payload, string $key): string {
		$value = ($payload[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringField()
}//end class
