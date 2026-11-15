<?php

/**
 * Golden tests for ExtractionRequestController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ExtractionRequestController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Extraction\ChartOfAccountsCandidateService;
use OCA\Shillinq\Service\Extraction\DocudeskExtractionClient;
use OCA\Shillinq\Service\Extraction\ExtractionPrefillService;
use OCA\Shillinq\Service\Extraction\GlAccountSuggestionClient;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the answers, writes, log lines and outbound calls of every branch of
 * ExtractionRequestController before its actions are split into named steps.
 */
final class ExtractionRequestGoldenTest extends TestCase {
	/**
	 * Drafts by schema and id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $drafts = [];

	/**
	 * What saveObject does: 'echo' returns the object, 'scalar' returns true, 'throw' throws.
	 *
	 * @var string
	 */
	public string $saveMode = 'echo';

	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Request params.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Docudesk extraction client mock.
	 *
	 * @var DocudeskExtractionClient&MockObject
	 */
	private DocudeskExtractionClient&MockObject $extraction;

	/**
	 * GL suggestion client mock.
	 *
	 * @var GlAccountSuggestionClient&MockObject
	 */
	private GlAccountSuggestionClient&MockObject $suggestion;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->extraction = $this->createMock(DocudeskExtractionClient::class);
		$this->suggestion = $this->createMock(GlAccountSuggestionClient::class);
	}//end setUp()

	/**
	 * Build the controller over the current fixture state.
	 *
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return ExtractionRequestController
	 */
	private function controller(bool $loggedIn = true): ExtractionRequestController {
		$test = $this;
		$objects = new class($test) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param ExtractionRequestGoldenTest $test The test.
			 */
			public function __construct(
				private $test,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}//end setSchema()

			/**
			 * @param string $id The id.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id): array {
				$this->test->calls[] = 'find:' . $this->schema . ':' . $id;
				if (isset($this->test->drafts[$this->schema][$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return $this->test->drafts[$this->schema][$id];
			}//end find()

			/**
			 * @param array<string,mixed> $object The object.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object): mixed {
				$this->test->calls[] = 'save:' . $this->schema . ':' . json_encode($object);
				if ($this->test->saveMode === 'throw') {
					throw new \RuntimeException('disk full');
				}

				return $this->test->saveMode === 'scalar' ? true : $object + ['saved' => true];
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->calls[] = 'canAccess:' . $administrationId;
				return $administrationId === 'adm-1';
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);

		$params = $this->params;
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		$candidates = $this->createMock(ChartOfAccountsCandidateService::class);
		$candidates->method('activeCandidates')->willReturnCallback(
			function (string $administrationId): array {
				$this->calls[] = 'candidates:' . $administrationId;
				return [['code' => '4000']];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		return new ExtractionRequestController(
			$request,
			$this->extraction,
			$this->suggestion,
			$candidates,
			new ExtractionPrefillService(),
			$context,
			$session,
			$container,
			$logger,
		);
	}//end controller()

	/**
	 * Anonymous callers get 401 from every action.
	 *
	 * @return void
	 */
	public function testAnonymous(): void {
		$controller = $this->controller(loggedIn: false);
		foreach ([$controller->request(), $controller->confirm('d-1', 'Receipt'), $controller->suggestGlAccount('d-1', 'Receipt')] as $response) {
			$this->assertSame([401, ['error' => 'Not logged in']], [$response->getStatus(), $response->getData()]);
		}

		$this->assertSame([], $this->calls);
	}//end testAnonymous()

	/**
	 * request(): 422 on bad input; without a draft id no lookup; with one the masked guard runs first.
	 *
	 * @return void
	 */
	public function testRequestRefusalsAndGuard(): void {
		$this->params = ['documentUri' => ' ', 'docType' => 'receipt'];
		$bad = $this->controller()->request();
		$this->assertSame(
			[422, ['error' => 'documentUri and a valid docType (receipt|supplier-invoice) are required']],
			[$bad->getStatus(), $bad->getData()]
		);

		$this->drafts = ['Receipt' => ['d-2' => ['id' => 'd-2', 'administrationId' => 'adm-2']]];
		$this->params = ['documentUri' => 'u', 'docType' => 'receipt', 'id' => 'd-2'];
		$this->extraction->expects($this->never())->method('requestExtraction');
		$masked = $this->controller()->request();
		$this->assertSame([404, ['error' => 'Draft not found']], [$masked->getStatus(), $masked->getData()]);
		$this->assertSame(['find:Receipt:d-2', 'canAccess:adm-2'], $this->calls);
	}//end testRequestRefusalsAndGuard()

	/**
	 * request(): 503 with the provider error, 202 without capture when no draft or no id, capture with a logged save failure.
	 *
	 * @return void
	 */
	public function testRequestOutcomes(): void {
		$this->extraction->method('requestExtraction')->willReturnOnConsecutiveCalls(
			['success' => false, 'error' => 'down'],
			['success' => false],
			['success' => true, 'extractionId' => 'x-1'],
			['success' => true, 'extractionId' => ''],
			['success' => true, 'extractionId' => 'x-2']
		);
		$this->params = ['documentUri' => ' u ', 'docType' => ' supplier-invoice '];
		$this->assertSame([503, ['error' => 'down', 'accepted' => false]], $this->respond('request'));
		$this->assertSame([503, ['error' => 'docudesk extraction request failed', 'accepted' => false]], $this->respond('request'));
		$this->assertSame([202, ['accepted' => true]], $this->respond('request'));
		$this->assertSame([], $this->calls);

		$this->drafts = ['SupplierInvoice' => ['d-1' => ['id' => 'd-1', 'administrationId' => 'adm-1']]];
		$this->params['id'] = 'd-1';
		$this->assertSame([202, ['accepted' => true]], $this->respond('request'));
		$this->assertSame(['find:SupplierInvoice:d-1', 'canAccess:adm-1'], $this->calls);

		$this->calls = [];
		$this->saveMode = 'throw';
		$this->assertSame([202, ['accepted' => true]], $this->respond('request'));
		$this->assertSame(
			[
				'find:SupplierInvoice:d-1',
				'canAccess:adm-1',
				'find:SupplierInvoice:d-1',
				'save:SupplierInvoice:{"id":"d-1","administrationId":"adm-1","docudeskExtractionId":"x-2"}',
				'warning:ExtractionRequestController: failed to persist docudeskExtractionId{"schema":"SupplierInvoice","id":"d-1","exception":"disk full"}',
			],
			$this->calls
		);
	}//end testRequestOutcomes()

	/**
	 * Call an action and return status and body.
	 *
	 * @param string $action The action.
	 * @param string $id     The draft id.
	 * @param string $schema The schema.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function respond(string $action, string $id = '', string $schema = ''): array {
		$controller = $this->controller();
		$response = match ($action) {
			'request' => $controller->request(),
			'confirm' => $controller->confirm($id, $schema),
			default => $controller->suggestGlAccount($id, $schema),
		};
		return [$response->getStatus(), $response->getData()];
	}//end respond()

	/**
	 * confirm() and suggestGlAccount(): 422 unknown schema, masked 404, 404 unknown draft.
	 *
	 * @return void
	 */
	public function testDraftRefusals(): void {
		$this->drafts = ['Receipt' => ['d-2' => ['id' => 'd-2', 'administrationId' => 'adm-2']]];
		foreach (['confirm', 'suggest'] as $action) {
			$this->assertSame([422, ['error' => 'Unknown or missing schema']], $this->respond($action, 'd-1', 'Invoice'));
			$this->assertSame([404, ['error' => 'Draft not found']], $this->respond($action, '', 'Receipt'));
			$this->assertSame([404, ['error' => 'Draft not found']], $this->respond($action, 'd-2', 'Receipt'));
			$this->assertSame([404, ['error' => 'Draft not found']], $this->respond($action, 'd-9', 'Receipt'));
		}

		$this->assertSame(
			['find:Receipt:d-2', 'canAccess:adm-2', 'find:Receipt:d-9', 'find:Receipt:d-2', 'canAccess:adm-2', 'find:Receipt:d-9'],
			$this->calls
		);
	}//end testDraftRefusals()

	/**
	 * confirm(): strips the protected fields, saves, feeds the booking back, and answers the saved record.
	 *
	 * @return void
	 */
	public function testConfirmSavesAndFeedsBack(): void {
		$this->drafts = ['Receipt' => ['d-1' => ['id' => 'd-1', 'administrationId' => 'adm-1', 'docudeskExtractionId' => 'x-1']]];
		$this->params = [
			'glAccount' => ' 4000 ',
			'glAccountLabel' => 7,
			'administrationId' => 'adm-9',
			'id' => 'evil',
			'humanCorrected' => false,
			'extractionStatus' => 'x',
		];
		$this->suggestion->expects($this->once())->method('postCorrection')->with('x-1', '4000', null);

		[$status, $body] = $this->respond('confirm', 'd-1', 'Receipt');

		$this->assertSame(200, $status);
		$this->assertTrue($body['record']['saved']);
		$this->assertSame('adm-1', $body['record']['administrationId']);
		$this->assertSame('d-1', $body['record']['id']);
		$this->assertStringStartsWith('save:Receipt:', $this->calls[3]);
		$this->assertCount(4, $this->calls);
	}//end testConfirmSavesAndFeedsBack()

	/**
	 * confirm(): a non-array save result answers the updated draft; a failed save is a logged 500 and feeds nothing back.
	 *
	 * @return void
	 */
	public function testConfirmSaveResults(): void {
		$this->drafts = ['Receipt' => ['d-1' => ['id' => 'd-1', 'administrationId' => 'adm-1']]];
		$this->params = ['glAccount' => '4000'];
		$this->suggestion->expects($this->never())->method('postCorrection');

		$this->saveMode = 'scalar';
		[$status, $body] = $this->respond('confirm', 'd-1', 'Receipt');
		$this->assertSame(200, $status);
		$this->assertArrayNotHasKey('saved', $body['record']);
		$this->assertSame('4000', $body['record']['glAccount']);

		$this->saveMode = 'throw';
		$this->assertSame([500, ['error' => 'Failed to save correction']], $this->respond('confirm', 'd-1', 'Receipt'));
		$this->assertSame('error:ExtractionRequestController.confirm failed to persist: disk full[]', end($this->calls));
	}//end testConfirmSaveResults()

	/**
	 * suggestGlAccount(): every degraded reason, the suggestion defaults, and a logged cache failure.
	 *
	 * @return void
	 */
	public function testSuggestionOutcomes(): void {
		$this->drafts = ['Receipt' => [
			'd-0' => ['id' => 'd-0', 'administrationId' => 'adm-1', 'docudeskExtractionId' => ' '],
			'd-1' => ['id' => 'd-1', 'administrationId' => 'adm-1', 'docudeskExtractionId' => ' x-1 '],
		],
		];
		$this->suggestion->expects($this->exactly(4))->method('requestSuggestion')
			->with('x-1', [['code' => '4000']])
			->willReturnOnConsecutiveCalls(
				['success' => false, 'suggestion' => []],
				['success' => true, 'suggestion' => 'nope'],
				['success' => true, 'suggestion' => ['suggestedAccounts' => ['not-an-array']]],
				['success' => true, 'suggestion' => ['suggestedAccounts' => [['code' => 4000, 'confidence' => '0.5']]]]
			);

		$this->assertSame([200, ['suggestion' => null, 'reason' => 'extraction-id-unknown']], $this->respond('suggest', 'd-0', 'Receipt'));
		$this->assertSame([200, ['suggestion' => null, 'reason' => 'provider-unavailable']], $this->respond('suggest', 'd-1', 'Receipt'));
		$this->assertSame([200, ['suggestion' => null, 'reason' => 'provider-unavailable']], $this->respond('suggest', 'd-1', 'Receipt'));
		$this->assertSame([200, ['suggestion' => null, 'reason' => 'no-suggestion']], $this->respond('suggest', 'd-1', 'Receipt'));

		$this->calls = [];
		$this->saveMode = 'throw';
		$expected = ['code' => '4000', 'label' => null, 'confidence' => 0.5, 'rationale' => '', 'source' => 'none'];
		$this->assertSame([200, ['suggestion' => $expected]], $this->respond('suggest', 'd-1', 'Receipt'));
		$this->assertSame(
			[
				'find:Receipt:d-1',
				'canAccess:adm-1',
				'find:Receipt:d-1',
				'candidates:adm-1',
				'save:Receipt:' . json_encode(['id' => 'd-1', 'administrationId' => 'adm-1', 'docudeskExtractionId' => ' x-1 ', 'suggestedGlAccount' => $expected]),
				'info:ExtractionRequestController: failed to cache suggestedGlAccount (non-blocking){"schema":"Receipt","exception":"disk full"}',
			],
			$this->calls
		);
	}//end testSuggestionOutcomes()
}//end class
