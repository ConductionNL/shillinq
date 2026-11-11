<?php

/**
 * Characterisation tests for the two docudesk HTTP clients.
 *
 * Pins DocudeskExtractionClient::requestExtraction and
 * GlAccountSuggestionClient::requestSuggestion / ::postCorrection branch by
 * branch — the posted options, the full outcome envelopes and the log lines —
 * so sharing the JSON post cannot change what either client sends or answers.
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Extraction;

use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Pins the request options, envelopes and log lines of both clients.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DocudeskClientsCharacterisationTest extends TestCase {

	/**
	 * The options every docudesk POST carries, minus the body.
	 *
	 * @var array<string,mixed>
	 */
	private const OPTIONS = [
		'timeout' => 10,
		'connect_timeout' => 10,
		'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
	];

	/**
	 * The recorded log lines, as [level, message, context].
	 *
	 * @var array<int,array{0: string, 1: string, 2: array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The recorded posts, as [url, options].
	 *
	 * @var array<int,array{0: string, 1: array<string,mixed>}>
	 */
	private array $posted = [];

	/**
	 * A logger that records every line.
	 *
	 * @return AbstractLogger The logger.
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Hold the test that records the lines.
			 *
			 * @param DocudeskClientsCharacterisationTest $test The test.
			 */
			public function __construct(private DocudeskClientsCharacterisationTest $test) {
			}

			/**
			 * Record one line on the test.
			 *
			 * @param mixed              $level   The level.
			 * @param \Stringable|string $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->test->record(level: (string)$level, message: (string)$message, context: $context);
			}
		};
	}//end logger()

	/**
	 * Record one log line.
	 *
	 * @param string              $level   The level.
	 * @param string              $message The message.
	 * @param array<string,mixed> $context The context.
	 *
	 * @return void
	 */
	public function record(string $level, string $message, array $context): void {
		$this->logged[] = [$level, $message, $context];
	}//end record()

	/**
	 * A client service whose client answers the response or throws.
	 *
	 * @param IResponse|\Throwable $answer What the post answers.
	 *
	 * @return IClientService The service.
	 */
	private function clients(IResponse|\Throwable $answer): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($answer): IResponse {
				$this->posted[] = [$url, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return $service;
	}//end clients()

	/**
	 * A response with a status and body.
	 *
	 * @param int    $status The status.
	 * @param string $body   The body.
	 *
	 * @return IResponse The response.
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * A URL generator answering the URL or throwing.
	 *
	 * @param string|null $url The URL, or null to throw.
	 *
	 * @return IURLGenerator The generator.
	 */
	private function urls(?string $url): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		if ($url === null) {
			$urls->method('linkToRouteAbsolute')->willThrowException(new RuntimeException('no route'));
			return $urls;
		}

		$urls->method('linkToRouteAbsolute')->willReturn($url);
		return $urls;
	}//end urls()

	/**
	 * The extraction client.
	 *
	 * @param IResponse|\Throwable $answer What the post answers.
	 * @param string|null          $url    The route URL, or null when it cannot resolve.
	 *
	 * @return DocudeskExtractionClient The client.
	 */
	private function extraction(IResponse|\Throwable $answer, ?string $url = 'https://nc/x'): DocudeskExtractionClient {
		return new DocudeskExtractionClient(clientService: $this->clients($answer), urlGenerator: $this->urls($url), logger: $this->logger());
	}//end extraction()

	/**
	 * The suggestion client.
	 *
	 * @param IResponse|\Throwable $answer What the post answers.
	 * @param string|null          $url    The route URL, or null when it cannot resolve.
	 *
	 * @return GlAccountSuggestionClient The client.
	 */
	private function suggestion(IResponse|\Throwable $answer, ?string $url = 'https://nc/x'): GlAccountSuggestionClient {
		return new GlAccountSuggestionClient(clientService: $this->clients($answer), urlGenerator: $this->urls($url), logger: $this->logger());
	}//end suggestion()

	/**
	 * An accepted extraction posts the body with the shared options and logs the status.
	 *
	 * @return void
	 */
	public function testExtractionPostsTheBodyWithTheSharedOptionsAndLogsTheStatus(): void {
		$result = $this->extraction($this->response(201, '{"uuid":"u-9"}'))->requestExtraction(documentUri: 'docudesk://a/b', docType: 'receipt');

		self::assertSame(['success' => true, 'statusCode' => 201, 'error' => null, 'extractionId' => 'u-9'], $result);
		self::assertSame(
			[['https://nc/x', self::OPTIONS + ['body' => '{"documentUri":"docudesk://a/b","docType":"receipt","callbackEvent":true}']]],
			$this->posted
		);
		self::assertSame(
			[['info', 'DocudeskExtractionClient: extraction requested', ['documentUri' => 'docudesk://a/b', 'docType' => 'receipt', 'status' => 201]]],
			$this->logged
		);
	}//end testExtractionPostsTheBodyWithTheSharedOptionsAndLogsTheStatus()

	/**
	 * A non-2xx answer is not a success but still yields the id it carries.
	 *
	 * @return void
	 */
	public function testANon2xxExtractionAnswerIsNotASuccessButKeepsItsId(): void {
		$result = $this->extraction($this->response(500, '{"id":"e-1"}'))->requestExtraction(documentUri: 'd', docType: 'receipt');

		self::assertSame(['success' => false, 'statusCode' => 500, 'error' => null, 'extractionId' => 'e-1'], $result);
	}//end testANon2xxExtractionAnswerIsNotASuccessButKeepsItsId()

	/**
	 * An unresolvable route is logged and nothing is posted.
	 *
	 * @return void
	 */
	public function testAnUnresolvableExtractionRouteIsLoggedAndPostsNothing(): void {
		$result = $this->extraction($this->response(200, ''), null)->requestExtraction(documentUri: 'd', docType: 'receipt');

		self::assertSame(['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available', 'extractionId' => null], $result);
		self::assertSame([], $this->posted);
		self::assertSame(
			[['warning', 'DocudeskExtractionClient: docudesk route unavailable — docudesk may not be installed', ['exception' => 'no route']]],
			$this->logged
		);
	}//end testAnUnresolvableExtractionRouteIsLoggedAndPostsNothing()

	/**
	 * A transport failure carrying a response reports that response's status.
	 *
	 * @return void
	 */
	public function testAFailedExtractionCarriesTheStatusOfItsResponse(): void {
		$failure = new class('refused') extends RuntimeException {
			/**
			 * The response the failure carries.
			 *
			 * @return IResponse The response.
			 */
			public function getResponse(): IResponse {
				return DocudeskClientsCharacterisationTest::$failureResponse;
			}
		};
		self::$failureResponse = $this->response(422, '');

		$result = $this->extraction($failure)->requestExtraction(documentUri: 'd', docType: 'receipt');

		self::assertSame(['success' => false, 'statusCode' => 422, 'error' => 'docudesk request failed', 'extractionId' => null], $result);
		self::assertSame(
			[
				[
					'warning',
					'DocudeskExtractionClient: extraction request failed',
					['documentUri' => 'd', 'docType' => 'receipt', 'status' => 422, 'exception' => 'refused'],
				],
			],
			$this->logged
		);
	}//end testAFailedExtractionCarriesTheStatusOfItsResponse()

	/**
	 * A transport failure without a response reports status 0.
	 *
	 * @return void
	 */
	public function testAFailedExtractionWithoutAResponseHasStatusZero(): void {
		$failure = new class('gone') extends RuntimeException {
			/**
			 * The failure carries no response.
			 *
			 * @return null
			 */
			public function getResponse(): null {
				return null;
			}
		};

		self::assertSame(0, $this->extraction($failure)->requestExtraction(documentUri: 'd', docType: 'receipt')['statusCode']);
		self::assertSame(0, $this->extraction(new RuntimeException('x'))->requestExtraction(documentUri: 'd', docType: 'receipt')['statusCode']);
	}//end testAFailedExtractionWithoutAResponseHasStatusZero()

	/**
	 * The response a failure in the status test carries.
	 *
	 * @var IResponse|null
	 */
	public static ?IResponse $failureResponse = null;

	/**
	 * A suggestion posts the candidates and answers the decoded body.
	 *
	 * @return void
	 */
	public function testASuggestionPostsTheCandidatesAndAnswersTheDecodedBody(): void {
		$result = $this->suggestion($this->response(200, '{"source":"history"}'))
			->requestSuggestion(extractionId: 'ext-1', candidateAccounts: [['code' => '4300', 'label' => 'K/O']]);

		self::assertSame(['success' => true, 'statusCode' => 200, 'error' => null, 'suggestion' => ['source' => 'history']], $result);
		self::assertSame(
			[['https://nc/x', self::OPTIONS + ['body' => '{"candidateAccounts":[{"code":"4300","label":"K/O"}],"sourceApp":"shillinq"}']]],
			$this->posted
		);
		self::assertSame([], $this->logged);
	}//end testASuggestionPostsTheCandidatesAndAnswersTheDecodedBody()

	/**
	 * A non-2xx or non-JSON suggestion answer is a failure with its status.
	 *
	 * @return void
	 */
	public function testAnUnexpectedSuggestionAnswerIsAFailureWithItsStatus(): void {
		$expected = ['success' => false, 'statusCode' => 503, 'error' => 'docudesk returned an unexpected response', 'suggestion' => null];
		self::assertSame($expected, $this->suggestion($this->response(503, '{"a":1}'))->requestSuggestion(extractionId: 'e', candidateAccounts: []));

		$expected['statusCode'] = 200;
		self::assertSame($expected, $this->suggestion($this->response(200, 'not json'))->requestSuggestion(extractionId: 'e', candidateAccounts: []));
	}//end testAnUnexpectedSuggestionAnswerIsAFailureWithItsStatus()

	/**
	 * Route and transport failures are logged and degrade.
	 *
	 * @return void
	 */
	public function testSuggestionFailuresAreLoggedAndDegrade(): void {
		$noRoute = $this->suggestion($this->response(200, '{}'), null)->requestSuggestion(extractionId: 'e', candidateAccounts: []);
		$failed  = $this->suggestion(new RuntimeException('refused'))->requestSuggestion(extractionId: 'e', candidateAccounts: []);

		self::assertSame(['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available', 'suggestion' => null], $noRoute);
		self::assertSame(['success' => false, 'statusCode' => 0, 'error' => 'docudesk suggestion request failed', 'suggestion' => null], $failed);
		self::assertSame(
			[
				['info', 'GlAccountSuggestionClient: docudesk suggest-account route unavailable — docudesk may not be installed', ['exception' => 'no route']],
				['info', 'GlAccountSuggestionClient: suggestion request failed — degrading gracefully', ['extractionId' => 'e', 'exception' => 'refused']],
			],
			$this->logged
		);
	}//end testSuggestionFailuresAreLoggedAndDegrade()

	/**
	 * A correction without a label posts only the code and logs the status.
	 *
	 * @return void
	 */
	public function testACorrectionWithoutALabelPostsTheCodeAndLogsTheStatus(): void {
		$result = $this->suggestion($this->response(409, ''))->postCorrection(extractionId: 'e-2', accountCode: '4900', accountLabel: null);

		self::assertSame(['success' => false, 'statusCode' => 409, 'error' => null], $result);
		self::assertSame([['https://nc/x', self::OPTIONS + ['body' => '{"fields":{"glAccountCode":"4900"}}']]], $this->posted);
		self::assertSame(
			[['info', 'GlAccountSuggestionClient: booking correction posted', ['extractionId' => 'e-2', 'accountCode' => '4900', 'status' => 409]]],
			$this->logged
		);
	}//end testACorrectionWithoutALabelPostsTheCodeAndLogsTheStatus()

	/**
	 * Correction failures are logged and never throw; a 2xx is a success.
	 *
	 * @return void
	 */
	public function testCorrectionFailuresAreLoggedAndNeverThrow(): void {
		$noRoute = $this->suggestion($this->response(200, ''), null)->postCorrection(extractionId: 'e', accountCode: '1', accountLabel: 'L');
		$failed  = $this->suggestion(new RuntimeException('timeout'))->postCorrection(extractionId: 'e', accountCode: '1', accountLabel: 'L');
		$posted  = $this->suggestion($this->response(204, ''))->postCorrection(extractionId: 'e', accountCode: '1', accountLabel: 'L');

		self::assertSame(['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available'], $noRoute);
		self::assertSame(['success' => false, 'statusCode' => 0, 'error' => 'docudesk correction request failed'], $failed);
		self::assertSame(['success' => true, 'statusCode' => 204, 'error' => null], $posted);
		self::assertSame(
			[
				['info', 'GlAccountSuggestionClient: docudesk corrections route unavailable — booking history not fed', ['exception' => 'no route']],
				[
					'warning',
					'GlAccountSuggestionClient: booking correction failed — local booking unaffected',
					['extractionId' => 'e', 'accountCode' => '1', 'exception' => 'timeout'],
				],
				['info', 'GlAccountSuggestionClient: booking correction posted', ['extractionId' => 'e', 'accountCode' => '1', 'status' => 204]],
			],
			$this->logged
		);
		self::assertSame('{"fields":{"glAccountCode":"1","glAccountLabel":"L"}}', $this->posted[1][1]['body']);
	}//end testCorrectionFailuresAreLoggedAndNeverThrow()
}//end class
