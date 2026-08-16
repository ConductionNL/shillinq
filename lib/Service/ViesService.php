<?php

/**
 * VIES Validation Service
 *
 * Tier-3 intra-community supplies (ICP) VAT-ID verification (REQ-ICP-001,
 * REQ-ICP-009). Validates a counterparty VAT-ID against the EU VIES service and
 * persists an immutable `ViesValidation` record carrying the VIES `requestId` as
 * Belastingdienst audit proof (Council Implementing Regulation (EU) 282/2011,
 * Article 18 good-faith defence). On a transient VIES outage the service falls
 * back to a recent (< 30 days) valid `ViesValidation` for the same VAT-ID, marks
 * the result `outage: true`, and leaves the supply flagged for the daily
 * revalidation job (ViesOutageRetryJob); a definitive VIES rejection is recorded
 * with `valid: false`. The VIES network call goes through Nextcloud's
 * IClientService; the SOAP/REST envelope parsing is a pure, side-effect-free
 * transform (parseViesResponse) so it is unit-testable without a live endpoint.
 *
 * Per ADR-031 this is the engine-side fallback for the declarative
 * x-openregister-calculations.viesValidationId shape that will be attached to the
 * accounts-receivable Invoice schema once that register schema ships; until then
 * the calculation is driven imperatively against the `ViesValidation` schema that
 * this change owns.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Validates EU VAT-IDs against VIES and persists immutable evidence records.
 *
 * Reads / writes are scoped to a single administration (REQ-ICP-001): callers pass
 * the administrationId resolved from the authenticated user's context, never a
 * client-supplied trust boundary. The OpenRegister ObjectService enforces the
 * multitenancy / RBAC boundary on every find / save.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class ViesService {
	/**
	 * VIES REST proxy endpoint (per-country, per-number).
	 *
	 * @var string
	 */
	private const VIES_REST_BASE = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

	/**
	 * Maximum age (seconds) of a prior valid record reusable on outage (30 days).
	 *
	 * @var int
	 */
	private const OUTAGE_REUSE_WINDOW = (30 * 24 * 60 * 60);

	/**
	 * Validity window (seconds) granted to a fresh VIES answer (1 day).
	 *
	 * @var int
	 */
	private const VALIDITY_WINDOW = (24 * 60 * 60);

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param LoggerInterface $logger Logger (no special-category data logged).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Validate a VAT-ID against VIES and persist a ViesValidation record (REQ-ICP-001).
	 *
	 * Normalises the VAT-ID, calls the VIES REST proxy, and stores an immutable
	 * ViesValidation. On a transient outage (HTTP 5xx / network error) the most
	 * recent (< 30 days) valid record for the same VAT-ID is reused and the result
	 * is flagged `outage: true` so the daily retry job (REQ-ICP-009) picks it up.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $vatId The buyer VAT-ID to verify (any casing / spacing).
	 * @param string $now ISO-8601 "now" override for deterministic tests.
	 *
	 * @return array{vatId:string,valid:bool,outage:bool,requestId:string,validationTimestamp:string,validUntil:string,name:string,address:string,saved:bool,reusedPrior:bool}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function validate(string $administrationId, string $vatId, string $now = ''): array {
		$canonical = $this->canonicalVatId(vatId: $vatId);
		$nowTs = $now;
		if ($nowTs === '') {
			$nowTs = gmdate('c');
		}

		[$country, $number] = $this->splitVatId(vatId: $canonical);
		if ($country === '' || $number === '') {
			return $this->persist(
				administrationId: $administrationId,
				outcome: [
					'vatId' => $canonical,
					'valid' => false,
					'outage' => false,
					'requestId' => '',
					'name' => '',
					'address' => '',
					'validationTimestamp' => $nowTs,
					'validUntil' => $nowTs,
					'reusedPrior' => false,
				]
			);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				self::VIES_REST_BASE . '/' . rawurlencode($country) . '/vat/' . rawurlencode($number),
				['timeout' => 10, 'connect_timeout' => 5]
			);
			$body = (string)$response->getBody();
			$parsed = $this->parseViesResponse(body: $body, vatId: $canonical, now: $nowTs);
		} catch (\Throwable $e) {
			// Transient outage: reuse a recent valid record if one exists.
			$this->logger->warning(
				'ViesService: VIES outage for VAT-ID validation',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			$parsed = $this->outageOutcome(
				administrationId: $administrationId,
				canonical: $canonical,
				now: $nowTs
			);
		}//end try

		return $this->persist(administrationId: $administrationId, outcome: $parsed);
	}//end validate()

	/**
	 * Parse a VIES REST response body into a normalised validation outcome.
	 *
	 * Side-effect-free transform — unit-testable without a network. Accepts the EU
	 * REST proxy JSON ({ valid, name, address, requestIdentifier, ... }). An
	 * explicit transient-error envelope ({ "errorWrappers": ... "MS_UNAVAILABLE" })
	 * is mapped to an outage outcome.
	 *
	 * @param string $body The raw response body.
	 * @param string $vatId The canonical VAT-ID being validated.
	 * @param string $now ISO-8601 timestamp for the validation.
	 *
	 * @return array{vatId:string,valid:bool,outage:bool,requestId:string,name:string,address:string,validationTimestamp:string,validUntil:string,reusedPrior:bool}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function parseViesResponse(string $body, string $vatId, string $now): array {
		$data = json_decode($body, true);
		if (is_array($data) === false) {
			return $this->outcome(vatId: $vatId, valid: false, outage: true, requestId: '', name: '', address: '', now: $now);
		}

		$errorJson = json_encode($data['errorWrappers'] ?? $data['error'] ?? '');
		if ($errorJson === false) {
			$errorJson = '';
		}

		$errorBlob = strtoupper($errorJson);
		$transient = ['MS_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SERVICE_UNAVAILABLE', 'TIMEOUT', 'GLOBAL_MAX_CONCURRENT_REQ'];
		foreach ($transient as $marker) {
			if (str_contains($errorBlob, $marker) === true) {
				return $this->outcome(vatId: $vatId, valid: false, outage: true, requestId: '', name: '', address: '', now: $now);
			}
		}

		$valid = (bool)($data['valid'] ?? $data['isValid'] ?? false);
		$requestId = (string)($data['requestIdentifier'] ?? $data['requestId'] ?? '');
		$name = (string)($data['name'] ?? '');
		$address = (string)($data['address'] ?? '');

		return $this->outcome(
			vatId: $vatId,
			valid: $valid,
			outage: false,
			requestId: $requestId,
			name: $name,
			address: $address,
			now: $now
		);

	}//end parseViesResponse()

	/**
	 * Canonicalise a VAT-ID: uppercase, strip spaces / dots / dashes (REQ-ICP-001).
	 *
	 * @param string $vatId The raw VAT-ID.
	 *
	 * @return string The canonical VAT-ID (e.g. "DE123456789").
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function canonicalVatId(string $vatId): string {
		$upper = strtoupper(trim($vatId));

		return (string)preg_replace('/[^A-Z0-9]/', '', $upper);
	}//end canonicalVatId()

	/**
	 * Find the most recent valid (and not-expired-at-reuse-window) ViesValidation.
	 *
	 * Used both by the outage fallback (REQ-ICP-009) and by the correction workflow
	 * to re-attach contemporaneous evidence without re-querying VIES (REQ-ICP-008).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $vatId The canonical VAT-ID.
	 *
	 * @return array<string,mixed>|null The newest valid record, or null when none.
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function findRecentValid(string $administrationId, string $vatId): ?array {
		$records = $this->objectService
			->setRegister($this->register())
			->setSchema('ViesValidation')
			->findAll(['filters' => ['administrationId' => $administrationId, 'vatId' => $vatId]]);

		$best = null;
		$bestTs = -1;
		foreach ($records as $record) {
			if ((bool)($record['valid'] ?? false) !== true) {
				continue;
			}

			$stamp = strtotime((string)($record['validationTimestamp'] ?? ''));
			if ($stamp !== false && $stamp > $bestTs) {
				$bestTs = $stamp;
				$best = $record;
			}
		}

		return $best;
	}//end findRecentValid()

	/**
	 * Build the outage outcome, reusing a recent valid record where available.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $canonical Canonical VAT-ID.
	 * @param string $now ISO-8601 timestamp.
	 *
	 * @return array{vatId:string,valid:bool,outage:bool,requestId:string,name:string,address:string,validationTimestamp:string,validUntil:string,reusedPrior:bool}
	 */
	private function outageOutcome(string $administrationId, string $canonical, string $now): array {
		$prior = $this->findRecentValid(administrationId: $administrationId, vatId: $canonical);
		if ($prior !== null) {
			$priorTs = strtotime((string)($prior['validationTimestamp'] ?? ''));
			$nowTs = strtotime($now);
			if ($priorTs !== false && $nowTs !== false && ($nowTs - $priorTs) <= self::OUTAGE_REUSE_WINDOW) {
				$reused = $this->outcome(
					vatId: $canonical,
					valid: true,
					outage: true,
					requestId: (string)($prior['requestId'] ?? ''),
					name: (string)($prior['name'] ?? ''),
					address: (string)($prior['address'] ?? ''),
					now: $now
				);
				// Mark the good-faith reuse of contemporaneous evidence (REQ-ICP-009).
				$reused['reusedPrior'] = true;

				return $reused;
			}
		}

		return $this->outcome(vatId: $canonical, valid: false, outage: true, requestId: '', name: '', address: '', now: $now);
	}//end outageOutcome()

	/**
	 * Assemble a normalised validation outcome with computed validUntil.
	 *
	 * @param string $vatId Canonical VAT-ID.
	 * @param bool $valid Whether the VAT-ID is valid.
	 * @param bool $outage Whether this is an outage result.
	 * @param string $requestId VIES request identifier (audit proof).
	 * @param string $name Disclosed business name.
	 * @param string $address Disclosed business address.
	 * @param string $now ISO-8601 validation timestamp.
	 *
	 * @return array{vatId:string,valid:bool,outage:bool,requestId:string,name:string,address:string,validationTimestamp:string,validUntil:string,reusedPrior:bool}
	 */
	private function outcome(
		string $vatId,
		bool $valid,
		bool $outage,
		string $requestId,
		string $name,
		string $address,
		string $now,
	): array {
		$nowTs = strtotime($now);
		$validUntil = $now;
		if ($nowTs !== false) {
			$validUntil = gmdate('c', ($nowTs + self::VALIDITY_WINDOW));
		}

		return [
			'vatId' => $vatId,
			'valid' => $valid,
			'outage' => $outage,
			'requestId' => $requestId,
			'name' => $name,
			'address' => $address,
			'validationTimestamp' => $now,
			'validUntil' => $validUntil,
			'reusedPrior' => false,
		];

	}//end outcome()

	/**
	 * Persist a ViesValidation record from a validation outcome (immutable evidence).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $outcome The validation outcome (vatId, valid, outage, requestId, name, address, timestamps, reusedPrior).
	 *
	 * @return array<string,mixed> The persisted outcome plus a `saved` flag.
	 */
	private function persist(string $administrationId, array $outcome): array {
		$saved = false;
		$record = [
			'vatId' => $outcome['vatId'],
			'validationTimestamp' => $outcome['validationTimestamp'],
			'validUntil' => $outcome['validUntil'],
			'valid' => $outcome['valid'],
			'name' => $outcome['name'],
			'address' => $outcome['address'],
			'requestId' => $outcome['requestId'],
			'outage' => $outcome['outage'],
			'administrationId' => $administrationId,
		];

		try {
			$this->objectService->saveObject(
				object: $record,
				register: $this->register(),
				schema: 'ViesValidation',
			);
			$saved = true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ViesService: failed to persist ViesValidation record',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
		}

		return [
			'vatId' => $outcome['vatId'],
			'valid' => $outcome['valid'],
			'outage' => $outcome['outage'],
			'requestId' => $outcome['requestId'],
			'validationTimestamp' => $outcome['validationTimestamp'],
			'validUntil' => $outcome['validUntil'],
			'name' => $outcome['name'],
			'address' => $outcome['address'],
			'saved' => $saved,
			'reusedPrior' => $outcome['reusedPrior'],
		];

	}//end persist()

	/**
	 * Split a canonical VAT-ID into a 2-letter country code and the number part.
	 *
	 * @param string $vatId The canonical VAT-ID (e.g. "DE123456789").
	 *
	 * @return array{0:string,1:string} [countryCode, number] or ['',''] when malformed.
	 */
	private function splitVatId(string $vatId): array {
		if (preg_match('/^([A-Z]{2})([A-Z0-9]{2,15})$/', $vatId, $matches) !== 1) {
			return ['', ''];
		}

		return [$matches[1], $matches[2]];
	}//end splitVatId()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
