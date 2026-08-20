<?php

/**
 * Unit tests for DocudeskExtractionClient's extractionId capture
 * (gl-account-suggestion-consume, REQ-GAC-001).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gl-account-suggestion-consume/specs/gl-account-suggestion-consume/spec.md#requirement-req-gac-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers: extractionId captured from a successful 201 response body,
 * gracefully absent when the response carries no usable id, and untouched
 * (still null) on any transport/route failure.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocudeskExtractionClientTest extends TestCase {

	/**
	 * Mock IClientService.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Mock IURLGenerator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);

	}//end setUp()

	/**
	 * Build the client under test.
	 *
	 * @return DocudeskExtractionClient
	 */
	private function buildClient(): DocudeskExtractionClient {
		return new DocudeskExtractionClient(
			clientService: $this->clientService,
			urlGenerator: $this->urlGenerator,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildClient()

	/**
	 * REQ-GAC-001: a successful 201 response's `id` is captured as
	 * `extractionId`.
	 *
	 * @return void
	 */
	public function testRequestExtractionCapturesExtractionIdFromResponseBody(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/docudesk/api/extraction/financial');

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(201);
		$response->method('getBody')->willReturn(json_encode(['id' => 'ext-123', 'documentUri' => 'docudesk://x']));

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->requestExtraction(documentUri: 'docudesk://x', docType: 'supplier-invoice');

		self::assertTrue($result['success']);
		self::assertSame('ext-123', $result['extractionId']);

	}//end testRequestExtractionCapturesExtractionIdFromResponseBody()

	/**
	 * A response body with no usable `id` degrades to a null extractionId,
	 * not an error.
	 *
	 * @return void
	 */
	public function testRequestExtractionToleratesMissingId(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/x');

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(202);
		$response->method('getBody')->willReturn('{}');

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$result = $this->buildClient()->requestExtraction(documentUri: 'docudesk://x', docType: 'receipt');

		self::assertTrue($result['success']);
		self::assertNull($result['extractionId']);

	}//end testRequestExtractionToleratesMissingId()

	/**
	 * A route-resolution failure (docudesk not installed) yields a null
	 * extractionId alongside the existing failure contract.
	 *
	 * @return void
	 */
	public function testRequestExtractionExtractionIdNullWhenRouteUnavailable(): void {
		$this->urlGenerator->method('linkToRouteAbsolute')->willThrowException(new \RuntimeException('no route'));

		$result = $this->buildClient()->requestExtraction(documentUri: 'docudesk://x', docType: 'receipt');

		self::assertFalse($result['success']);
		self::assertNull($result['extractionId']);

	}//end testRequestExtractionExtractionIdNullWhenRouteUnavailable()
}//end class
