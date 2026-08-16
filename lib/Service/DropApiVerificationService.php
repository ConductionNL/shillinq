<?php

/**
 * DROP-API ABB Verification Service (REQ-WMO-005 §automatic publication verification)
 *
 * Pure-logic helper for verifying an ABB's publicatie reference against the
 * DROP (Decentrale Regelgeving Officiële Publicaties) API via the openconnector
 * OC-sources bridge. The actual HTTP call is performed by the caller (the OC
 * port abstraction); this service composes the request payload, parses the
 * response, and writes the verification envelope back onto the ABB.
 *
 * Fail-soft: if the DROP API is unavailable, the verification record gets
 * `success=false, message='DROP API unavailable'`, and the operator is alerted
 * via the audit-log + AlertLog channels — the ABB itself is not blocked.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side-effect-free DROP-API verification helper (REQ-WMO-005 §verification).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-4
 */
class DropApiVerificationService {
	/**
	 * DROP-API base URL (configurable via app config in production).
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://repository.officiele-overheidspublicaties.nl/cgi-bin/search-api/sparql';

	/**
	 * Compose the DROP lookup request payload for an ABB (REQ-WMO-005).
	 *
	 * @param array<string,mixed> $abb The ABB record.
	 *
	 * @return array{ok:bool, error?:string, gemeentebladId?:string, request?:array<string,mixed>}
	 */
	public function composeLookupRequest(array $abb): array {
		$gmblad = trim((string)($abb['publicationMunicipalGazette'] ?? ''));
		if ($gmblad === '') {
			return ['ok' => false, 'error' => 'ABB has no publicatieGemeenteblad reference'];
		}

		return [
			'ok' => true,
			'gemeentebladId' => $gmblad,
			'request' => [
				'method' => 'GET',
				'path' => '/officielepublicaties/zoek',
				'query' => ['identifier' => $gmblad],
				'accept' => 'application/sparql-results+json',
			],
		];

	}//end composeLookupRequest()

	/**
	 * Parse a DROP-API response into a verification envelope.
	 *
	 * @param string|null $rawResponse Raw HTTP response body (JSON) or null on connection failure.
	 * @param int|null $statusCode HTTP status (null on connection failure).
	 *
	 * @return array{verifiedAt:string, success:bool, message:string}
	 */
	public function parseResponse(?string $rawResponse, ?int $statusCode): array {
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);

		if ($rawResponse === null || $statusCode === null) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'DROP API unavailable (no response)'];
		}

		if ($statusCode >= 500) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'DROP API error ' . (string)$statusCode];
		}

		if ($statusCode === 404) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'Gemeenteblad reference not found in DROP'];
		}

		if ($statusCode !== 200) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'DROP API HTTP ' . (string)$statusCode];
		}

		$decoded = json_decode($rawResponse, true);
		if (is_array($decoded) === false) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'DROP API returned invalid JSON'];
		}

		$bindings = (array)($decoded['results']['bindings'] ?? []);
		if ($bindings === []) {
			return ['verifiedAt' => $now, 'success' => false, 'message' => 'No matching publication in DROP'];
		}

		return ['verifiedAt' => $now, 'success' => true, 'message' => 'Verified'];
	}//end parseResponse()

	/**
	 * Apply a verification envelope to the ABB (REQ-WMO-005 §verification).
	 *
	 * @param array<string,mixed> $abb The ABB.
	 * @param array{verifiedAt:string,success:bool,message:string} $verification Verification result.
	 *
	 * @return array<string,mixed> Updated ABB.
	 */
	public function applyVerification(array $abb, array $verification): array {
		$abb['dropVerification'] = $verification;
		return $abb;
	}//end applyVerification()
}//end class
