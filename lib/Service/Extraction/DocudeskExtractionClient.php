<?php

/**
 * Docudesk Extraction Client
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change receipt-extraction-consume (REQ-RXC-005) — the ONLY outbound
 * coupling to docudesk: a single HTTP POST to docudesk's canonical
 * `POST /api/extraction/financial` endpoint (owned by docudesk's
 * financial-document-field-extraction spec, REQ-FIN-01) requesting a
 * (re-)extraction with `callbackEvent: true`. shillinq never calls
 * docudesk's internal extraction logic directly (ADR-022) — the resulting
 * `nl.conduction.docudesk.extraction.completed` event flows back through
 * {@see \OCA\Shillinq\Listener\ExtractionCompletedListener}
 * (REQ-RXC-001).
 *
 * Both apps are installed in the same Nextcloud instance, so the request is
 * addressed via `IURLGenerator::linkToRouteAbsolute('docudesk.extraction.financial')`
 * — the standard NC intra-instance route-name convention
 * (`{appId}.{controller}.{method}`, mirroring docudesk's
 * `appinfo/routes.php` entry `['name' => 'extraction#financial', ...]`) —
 * rather than a configured remote endpoint. This throws when docudesk is not
 * installed (its routes are never registered); the client treats that the
 * same as any other transport failure: fail-soft, never fatal to the caller
 * (proposal.md Risk 3 — the PDF path already falls back to the honest 422
 * deferral; the re-request is simply retriable).
 *
 * gl-account-suggestion-consume (REQ-GAC-001) additionally reads the
 * `financialExtraction` object id out of the synchronous 201 response body
 * (verified by reading `FinancialExtractionService::extractFinancial()` on
 * docudesk `origin/development` — it returns the OR-saved object, which
 * includes its `id`, as the response). Neither docudesk event
 * (`extraction.completed`, `gl-account.suggested`) carries this id, so this
 * synchronous response is the ONLY channel available to correlate a later
 * GL-account suggestion request back to the right extraction.
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCA\Shillinq\Kernel\Contracts\DocudeskExtractionClientInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Thin, fail-soft HTTP client for docudesk's financial-extraction request endpoint.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
class DocudeskExtractionClient implements DocudeskExtractionClientInterface {
	/**
	 * Docudesk route name for the financial extraction endpoint (NC
	 * `{appId}.{controller}.{method}` convention for
	 * `['name' => 'extraction#financial', 'url' => 'api/extraction/financial', 'verb' => 'POST']`).
	 *
	 * @var string
	 */
	public const ROUTE_NAME = 'docudesk.extraction.financial';

	/**
	 * Request timeout in seconds — the endpoint itself only needs to accept
	 * the request and dispatch OCR/extraction asynchronously
	 * (`callbackEvent: true`), so a short budget is enough.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator Resolves docudesk's intra-instance route.
	 * @param LoggerInterface $logger Logger; the request never carries credentials to log.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Request a (re-)extraction for a document (REQ-RXC-005).
	 *
	 * POSTs `{documentUri, docType, callbackEvent: true}` to docudesk's
	 * `POST /api/extraction/financial`. Always sets `callbackEvent: true` —
	 * shillinq only consumes the async event path (REQ-RXC-001), never the
	 * synchronous response body.
	 *
	 * Returns an outcome envelope and never throws. `extractionId` is the docudesk
	 * `financialExtraction` object id read from the synchronous response
	 * (REQ-GAC-001), or null when the request failed or the response body carried
	 * no usable id.
	 *
	 * @param string $documentUri The docudesk source document URI.
	 * @param string $docType `receipt` or `supplier-invoice`.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function requestExtraction(string $documentUri, string $docType): array {
		$url = $this->routeUrl();
		if ($url === null) {
			return [
				'success' => false,
				'statusCode' => 0,
				'error' => 'docudesk is not available',
				'extractionId' => null,
			];
		}

		return $this->send(documentUri: $documentUri, docType: $docType, url: $url);

	}//end requestExtraction()

	/**
	 * Send the extraction request; failures come back as an envelope.
	 *
	 * @param string $documentUri The docudesk source document URI.
	 * @param string $docType     `receipt` or `supplier-invoice`.
	 * @param string $url         The docudesk extraction URL.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 */
	private function send(string $documentUri, string $docType, string $url): array {
		$client = $this->clientService->newClient();
		$body = [
			'documentUri' => $documentUri,
			'docType' => $docType,
			'callbackEvent' => true,
		];

		try {
			return $this->post(client: $client, url: $url, body: $body);
		} catch (Throwable $e) {
			return $this->requestFailed(e: $e, body: $body);
		}
	}//end send()

	/**
	 * The absolute docudesk extraction URL, or null (logged) when docudesk has no such route.
	 *
	 * @return string|null
	 */
	private function routeUrl(): string|null {
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute(self::ROUTE_NAME);
		} catch (Throwable $e) {
			$this->logger->warning(
				'DocudeskExtractionClient: docudesk route unavailable — docudesk may not be installed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $url;
	}//end routeUrl()

	/**
	 * POST the extraction request and build the outcome envelope.
	 *
	 * @param IClient $client NC HTTP client.
	 * @param string                   $url    The docudesk extraction URL.
	 * @param array<string,mixed>      $body   Request body: documentUri, docType, callbackEvent.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 *
	 * @throws Throwable When the request or the body encoding fails.
	 */
	private function post(IClient $client, string $url, array $body): array {
		$response = $client->post(
			$url,
			[
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'connect_timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
			]
		);

		return $this->requested(response: $response, body: $body);
	}//end post()

	/**
	 * Log a sent request and build its outcome envelope.
	 *
	 * @param IResponse           $response The docudesk response.
	 * @param array<string,mixed> $body     Request body: documentUri, docType, callbackEvent.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 */
	private function requested(IResponse $response, array $body): array {
		$status = $response->getStatusCode();
		$this->logger->info(
			'DocudeskExtractionClient: extraction requested',
			['documentUri' => $body['documentUri'], 'docType' => $body['docType'], 'status' => $status]
		);

		return [
			'success' => ($status >= 200 && $status < 300),
			'statusCode' => $status,
			'error' => null,
			'extractionId' => $this->extractExtractionId(response: $response),
		];
	}//end requested()

	/**
	 * Log a failed request and build its outcome envelope.
	 *
	 * @param Throwable           $e    The failure.
	 * @param array<string,mixed> $body Request body: documentUri, docType, callbackEvent.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 */
	private function requestFailed(Throwable $e, array $body): array {
		$status = $this->errorStatus(e: $e);

		$this->logger->warning(
			'DocudeskExtractionClient: extraction request failed',
			['documentUri' => $body['documentUri'], 'docType' => $body['docType'], 'status' => $status, 'exception' => $e->getMessage()]
		);

		return [
			'success' => false,
			'statusCode' => $status,
			'error' => 'docudesk request failed',
			'extractionId' => null,
		];
	}//end requestFailed()

	/**
	 * The HTTP status carried by a failed request, or 0 when there is none.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return int
	 */
	private function errorStatus(Throwable $e): int {
		$status = 0;
		if (method_exists($e, 'getResponse') === true) {
			$errorResponse = $e->getResponse();
			if ($errorResponse !== null) {
				$status = $errorResponse->getStatusCode();
			}
		}

		return $status;
	}//end errorStatus()

	/**
	 * Read the `financialExtraction` object id out of the synchronous
	 * response body of `POST /api/extraction/financial` (REQ-GAC-001). The
	 * response is the OR-saved object itself (see class docblock), so its
	 * `id` key is the extraction id; a malformed/unexpected body degrades to
	 * null rather than throwing — the caller treats a missing id exactly
	 * like "docudesk did not tell us" (REQ-GAC-006 graceful degradation).
	 *
	 * @param IResponse $response The HTTP response.
	 *
	 * @return string|null The extraction id, or null when unavailable.
	 */
	private function extractExtractionId(IResponse $response): ?string {
		try {
			$body = (string)$response->getBody();
			if ($body === '') {
				return null;
			}

			$decoded = json_decode($body, true);
			if (is_array($decoded) === false) {
				return null;
			}

			$id = ($decoded['id'] ?? $decoded['uuid'] ?? null);
			if (is_string($id) === true && $id !== '') {
				return $id;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}

	}//end extractExtractionId()
}//end class
