<?php

/**
 * GL Account Suggestion Client
 *
 * Change gl-account-suggestion-consume (REQ-GAC-003 / REQ-GAC-005) — the two
 * outbound HTTP calls to docudesk's already-shipped `ai-gl-account-suggestion`
 * REST endpoints (archived
 * `docudesk/openspec/changes/archive/2026-07-13-ai-gl-account-suggestion/`),
 * consumed exactly as shipped (ADR-022):
 *
 *  - `requestSuggestion()` — `POST /api/extraction/{id}/suggest-account`,
 *    supplying shillinq's own candidate GL accounts (docudesk never learns a
 *    chart of accounts, REQ-GLS-07 on docudesk's side).
 *  - `postCorrection()` — `POST /api/extraction/{id}/corrections`, extending
 *    the already-used `receipt-extraction-consume` corrections endpoint with
 *    the `glAccountCode`/`glAccountLabel` keys docudesk's shipped contract
 *    recognises (REQ-GLS-05 on docudesk's side).
 *
 * Both calls mirror {@see DocudeskExtractionClient}'s intra-instance route
 * resolution (`IURLGenerator::linkToRouteAbsolute`) and fail-soft contract:
 * neither ever throws to the caller — a failure is logged and reported in
 * the returned `success`/`error` shape so the caller can degrade gracefully
 * (REQ-GAC-006). `postCorrection()` in particular MUST NOT block or roll
 * back an already-committed local booking on failure (REQ-GAC-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin, fail-soft HTTP client for docudesk's GL-account suggestion endpoints.
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */
class GlAccountSuggestionClient {
	/**
	 * Docudesk route name for the suggestion endpoint (NC
	 * `{appId}.{controller}.{method}` convention for
	 * `['name' => 'glAccountSuggestion#suggestAccount', ...]`).
	 *
	 * @var string
	 */
	public const SUGGEST_ROUTE_NAME = 'docudesk.glAccountSuggestion.suggestAccount';

	/**
	 * Docudesk route name for the (already-shipped) corrections endpoint.
	 *
	 * @var string
	 */
	public const CORRECTIONS_ROUTE_NAME = 'docudesk.extraction.corrections';

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator Resolves docudesk's intra-instance routes.
	 * @param LoggerInterface $logger Logger; requests never carry credentials to log.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Request a GL-account suggestion for a prior docudesk financial
	 * extraction (REQ-GAC-003).
	 *
	 * Returns an outcome envelope and never throws. `suggestion` is docudesk's
	 * decoded response body, or null on any failure (REQ-GAC-006 graceful
	 * degradation).
	 *
	 * @param string $extractionId The docudesk `financialExtraction`
	 *                             object id.
	 * @param array<int, array<string,string>> $candidateAccounts Candidate accounts (each
	 *                                                            `{code, label}`), supplied by
	 *                                                            shillinq's own chart of accounts
	 *                                                            (REQ-GAC-002).
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, suggestion: array<string,mixed>|null}
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function requestSuggestion(string $extractionId, array $candidateAccounts): array {
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute(self::SUGGEST_ROUTE_NAME, ['id' => $extractionId]);
		} catch (Throwable $e) {
			$this->logger->info(
				'GlAccountSuggestionClient: docudesk suggest-account route unavailable — docudesk may not be installed',
				['exception' => $e->getMessage()]
			);
			return $this->degraded(error: 'docudesk is not available');
		}

		$body = [
			'candidateAccounts' => $candidateAccounts,
			'sourceApp' => 'shillinq',
		];

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'timeout' => self::REQUEST_TIMEOUT_SECONDS,
					'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
				]
			);

			$status = $response->getStatusCode();
			$decoded = json_decode((string)$response->getBody(), true);
			$success = ($status >= 200 && $status < 300 && is_array($decoded) === true);

			$error = 'docudesk returned an unexpected response';
			$suggestion = null;
			if ($success === true) {
				$error = null;
				$suggestion = $decoded;
			}

			return [
				'success' => $success,
				'statusCode' => $status,
				'error' => $error,
				'suggestion' => $suggestion,
			];
		} catch (Throwable $e) {
			$this->logger->info(
				'GlAccountSuggestionClient: suggestion request failed — degrading gracefully',
				['extractionId' => $extractionId, 'exception' => $e->getMessage()]
			);
			return $this->degraded(error: 'docudesk suggestion request failed');
		}//end try

	}//end requestSuggestion()

	/**
	 * Post the operator's committed GL-account booking back to docudesk as a
	 * correction, so the ranker's booking history reflects it (REQ-GAC-005).
	 * Posted whether or not the booked code matches the prior suggestion —
	 * docudesk's own `HistoryRanker` counts frequency over ALL past bookings
	 * (design.md Decision D3).
	 *
	 * @param string $extractionId The docudesk `financialExtraction` object id.
	 * @param string $accountCode The operator's committed GL-account code.
	 * @param string|null $accountLabel Optional account label.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null} Outcome; never throws —
	 *                                                                   best-effort, MUST NOT block or undo the already-committed local booking.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function postCorrection(string $extractionId, string $accountCode, ?string $accountLabel): array {
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute(self::CORRECTIONS_ROUTE_NAME, ['id' => $extractionId]);
		} catch (Throwable $e) {
			$this->logger->info(
				'GlAccountSuggestionClient: docudesk corrections route unavailable — booking history not fed',
				['exception' => $e->getMessage()]
			);
			return ['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available'];
		}

		$body = [
			'fields' => array_filter(
				['glAccountCode' => $accountCode, 'glAccountLabel' => $accountLabel],
				static fn ($value): bool => ($value !== null)
			),
		];

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'timeout' => self::REQUEST_TIMEOUT_SECONDS,
					'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
				]
			);

			$status = $response->getStatusCode();
			$this->logger->info(
				'GlAccountSuggestionClient: booking correction posted',
				['extractionId' => $extractionId, 'accountCode' => $accountCode, 'status' => $status]
			);

			return [
				'success' => ($status >= 200 && $status < 300),
				'statusCode' => $status,
				'error' => null,
			];
		} catch (Throwable $e) {
			// Best-effort (REQ-GAC-005) — logged, never thrown; the caller's
			// already-successful local booking is unaffected.
			$this->logger->warning(
				'GlAccountSuggestionClient: booking correction failed — local booking unaffected',
				['extractionId' => $extractionId, 'accountCode' => $accountCode, 'exception' => $e->getMessage()]
			);
			return ['success' => false, 'statusCode' => 0, 'error' => 'docudesk correction request failed'];
		}//end try

	}//end postCorrection()

	/**
	 * Build the fail-soft "no suggestion available" shape shared by both
	 * `requestSuggestion()` early-return paths.
	 *
	 * @param string $error The error message.
	 *
	 * @return array{success: bool, statusCode: int, error: string, suggestion: null}
	 */
	private function degraded(string $error): array {
		return [
			'success' => false,
			'statusCode' => 0,
			'error' => $error,
			'suggestion' => null,
		];

	}//end degraded()
}//end class
