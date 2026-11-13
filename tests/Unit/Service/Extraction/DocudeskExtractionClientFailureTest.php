<?php

/**
 * Unit tests for DocudeskExtractionClient's request body, its failure answers
 * and the extraction id it reads from awkward response bodies.
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
use RuntimeException;

/**
 * DocudeskExtractionClient failure-path unit tests.
 *
 * @covers \OCA\Shillinq\Service\Extraction\DocudeskExtractionClient
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocudeskExtractionClientFailureTest extends TestCase {

	/**
	 * The HTTP client double.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The client under test, with the docudesk route resolvable.
	 *
	 * @var DocudeskExtractionClient
	 */
	private DocudeskExtractionClient $subject;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with(DocudeskExtractionClient::ROUTE_NAME)
			->willReturn('https://nc.example/apps/docudesk/api/extraction/financial');
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->subject = new DocudeskExtractionClient(
			clientService: $clientService,
			urlGenerator: $urlGenerator,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * A response with the given status and body.
	 *
	 * @param int $status The status code.
	 * @param mixed $body The body, or a Throwable to throw on read.
	 *
	 * @return IResponse The response double.
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		if ($body instanceof \Throwable) {
			$response->method('getBody')->willThrowException($body);
		} else {
			$response->method('getBody')->willReturn($body);
		}

		return $response;

	}//end response()

	/**
	 * The request posts the document and type as JSON with timeouts and asks for the callback event.
	 *
	 * @return void
	 */
	public function testTheRequestCarriesTheDocumentAsJson(): void {
		$this->client->expects($this->once())
			->method('post')
			->with(
				'https://nc.example/apps/docudesk/api/extraction/financial',
				[
					'timeout' => 10,
					'connect_timeout' => 10,
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'body' => '{"documentUri":"files/inv/1.pdf","docType":"invoice","callbackEvent":true}',
				]
			)
			->willReturn($this->response(202, '{"uuid":"ext-9"}'));

		$result = $this->subject->requestExtraction('files/inv/1.pdf', 'invoice');

		$this->assertSame(
			['success' => true, 'statusCode' => 202, 'error' => null, 'extractionId' => 'ext-9'],
			$result
		);

	}//end testTheRequestCarriesTheDocumentAsJson()

	/**
	 * A non-2xx answer that did not throw is reported as unsuccessful with its status.
	 *
	 * @return void
	 */
	public function testANon2xxAnswerIsNotASuccess(): void {
		$this->client->method('post')->willReturn($this->response(302, ''));

		$result = $this->subject->requestExtraction('files/inv/1.pdf', 'invoice');

		$this->assertSame(
			['success' => false, 'statusCode' => 302, 'error' => null, 'extractionId' => null],
			$result
		);

	}//end testANon2xxAnswerIsNotASuccess()

	/**
	 * A failed request carrying a response reports that response's status, and is logged.
	 *
	 * @return void
	 */
	public function testAFailedRequestReportsTheResponseStatus(): void {
		$failure = new class('server said no', $this->response(503, '')) extends RuntimeException {
			/**
			 * Constructor.
			 *
			 * @param string $message The message.
			 * @param IResponse $response The response the failure carries.
			 */
			public function __construct(string $message, private readonly IResponse $response) {
				parent::__construct($message);
			}

			/**
			 * The response.
			 *
			 * @return IResponse The response.
			 */
			public function getResponse(): IResponse {
				return $this->response;
			}
		};
		$this->client->method('post')->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'DocudeskExtractionClient: extraction request failed',
				['documentUri' => 'files/inv/1.pdf', 'docType' => 'invoice', 'status' => 503, 'exception' => 'server said no']
			);

		$result = $this->subject->requestExtraction('files/inv/1.pdf', 'invoice');

		$this->assertSame(
			['success' => false, 'statusCode' => 503, 'error' => 'docudesk request failed', 'extractionId' => null],
			$result
		);

	}//end testAFailedRequestReportsTheResponseStatus()

	/**
	 * A failure carrying a null response, or none at all, reports status 0.
	 *
	 * @return void
	 */
	public function testAFailureWithoutAResponseReportsStatusZero(): void {
		$failure = new class('timeout') extends RuntimeException {
			/**
			 * No response arrived.
			 *
			 * @return null Nothing.
			 */
			public function getResponse(): ?IResponse {
				return null;
			}
		};
		$this->client->method('post')->willThrowException($failure);

		$this->assertSame(0, $this->subject->requestExtraction('u', 't')['statusCode']);

	}//end testAFailureWithoutAResponseReportsStatusZero()

	/**
	 * A plain transport failure reports status 0 and the generic error.
	 *
	 * @return void
	 */
	public function testAPlainTransportFailureReportsStatusZero(): void {
		$this->client->method('post')->willThrowException(new RuntimeException('connection refused'));

		$this->assertSame(
			['success' => false, 'statusCode' => 0, 'error' => 'docudesk request failed', 'extractionId' => null],
			$this->subject->requestExtraction('u', 't')
		);

	}//end testAPlainTransportFailureReportsStatusZero()

	/**
	 * A body that is not a JSON object, an empty id, or an unreadable body yields no extraction id.
	 *
	 * @return void
	 */
	public function testAwkwardBodiesYieldNoExtractionId(): void {
		$this->client->method('post')->willReturnOnConsecutiveCalls(
			$this->response(201, '"just a string"'),
			$this->response(201, '{"id":""}'),
			$this->response(201, '{"id":42}'),
			$this->response(201, new RuntimeException('stream closed'))
		);

		for ($i = 0; $i < 4; $i++) {
			$result = $this->subject->requestExtraction('u', 't');
			$this->assertTrue($result['success']);
			$this->assertNull($result['extractionId']);
		}

	}//end testAwkwardBodiesYieldNoExtractionId()
}//end class
