<?php

/**
 * Unit tests for ExtractionRequestController (REQ-RXC-004 / REQ-RXC-005).
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
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers: anon 401, request() proxy success/failure, docType validation,
 * confirm() unknown-schema rejection, the IDOR guard (masked 404 for a
 * draft outside the caller's administration), 404 for an unknown draft, and
 * a successful correction commit that records humanCorrected server-side.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExtractionRequestControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock DocudeskExtractionClient.
	 *
	 * @var DocudeskExtractionClient&MockObject
	 */
	private DocudeskExtractionClient&MockObject $extractionClient;

	/**
	 * Mock GlAccountSuggestionClient.
	 *
	 * @var GlAccountSuggestionClient&MockObject
	 */
	private GlAccountSuggestionClient&MockObject $suggestionClient;

	/**
	 * Mock ChartOfAccountsCandidateService.
	 *
	 * @var ChartOfAccountsCandidateService&MockObject
	 */
	private ChartOfAccountsCandidateService&MockObject $candidateService;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Rows the ObjectService stub returns from findAll (keyed by schema).
	 *
	 * Public: read/written directly by the anonymous ObjectService stub
	 * class below, which is a distinct class from this TestCase and so
	 * cannot see a private property (mirrors the accessor-method pattern
	 * used elsewhere in this suite, simplified to a public fixture since
	 * this stub is local to a single test file).
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $stubRows = [];

	/**
	 * Captured saveObject calls.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $savedObjects = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->extractionClient = $this->createMock(DocudeskExtractionClient::class);
		$this->suggestionClient = $this->createMock(GlAccountSuggestionClient::class);
		$this->candidateService = $this->createMock(ChartOfAccountsCandidateService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);

		$this->administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => ($administrationId === 'adm-1')
		);

		$this->container->method('get')->willReturn($this->makeObjectServiceStub());

	}//end setUp()

	/**
	 * Build a controller wired to the shared mocks.
	 *
	 * @return ExtractionRequestController
	 */
	private function buildController(): ExtractionRequestController {
		return new ExtractionRequestController(
			request: $this->request,
			extractionClient: $this->extractionClient,
			suggestionClient: $this->suggestionClient,
			candidateService: $this->candidateService,
			prefillService: new ExtractionPrefillService(),
			administrationContext: $this->administrationContext,
			session: $this->session,
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildController()

	/**
	 * Build a fluent ObjectService stub (setRegister/setSchema/findAll/saveObject).
	 *
	 * @return object
	 */
	private function makeObjectServiceStub(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Back-reference to the test case.
			 *
			 * @var ExtractionRequestControllerTest
			 */
			private $test;

			/**
			 * The schema currently selected via setSchema().
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct the stub.
			 *
			 * @param ExtractionRequestControllerTest $test Test case.
			 */
			public function __construct($test) {
				$this->test = $test;
			}//end __construct()

			/**
			 * Fluent register selector (no-op — the stub is single-register).
			 *
			 * @param string $r Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector.
			 *
			 * @param string $s Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}//end setSchema()

			/**
			 * Single-object lookup by uuid.
			 *
			 * ⚠️ THROWS on a miss — it does not return null, so a caller
			 * wanting a fallback must wrap it in its own try/catch. The double
			 * had no find() at all, so the lookup path the controller actually
			 * takes was never exercised here.
			 *
			 * @param string $id Object uuid.
			 *
			 * @return array<string,mixed>
			 *
			 * @throws DoesNotExistException When no object matches.
			 */
			public function find(string $id): array {
				foreach (($this->test->stubRows[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				throw new DoesNotExistException(
					sprintf("Object with identifier '%s' not found in any magic table", $id)
				);
			}//end find()

			/**
			 * Filter the schema's stub rows by the given equality filters.
			 *
			 * ⚠️ `filters` addresses JSON PROPERTIES only — the ObjectEntity's
			 * `id` is its own column, so real OpenRegister matches ZERO rows
			 * for `['filters' => ['id' => …]]` at every value, silently.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->test->stubRows[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if (array_key_exists('id', $filters) === true) {
					return [];
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Capture a persisted object.
			 *
			 * @param array<string,mixed> $object Object to persist.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->test->savedObjects[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end makeObjectServiceStub()

	/**
	 * An anonymous request is rejected with HTTP 401.
	 *
	 * @return void
	 */
	public function testAnonymousRequestReturns401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new ExtractionRequestController(
			request: $this->request,
			extractionClient: $this->extractionClient,
			suggestionClient: $this->suggestionClient,
			candidateService: $this->candidateService,
			prefillService: new ExtractionPrefillService(),
			administrationContext: $this->administrationContext,
			session: $session,
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class),
		);

		$response = $controller->request();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousRequestReturns401()

	/**
	 * REQ-RXC-005: a valid re-request proxies to docudesk and returns 202.
	 *
	 * @return void
	 */
	public function testRequestProxiesToDocudesk(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'documentUri' => 'docudesk://attachments/x/invoice.pdf',
					'docType' => 'supplier-invoice',
					default => $default,
				};
			}
		);
		$this->extractionClient->expects(self::once())
			->method('requestExtraction')
			->with('docudesk://attachments/x/invoice.pdf', 'supplier-invoice')
			->willReturn(['success' => true, 'statusCode' => 202, 'error' => null]);

		$response = $this->buildController()->request();
		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		self::assertTrue($response->getData()['accepted']);

	}//end testRequestProxiesToDocudesk()

	/**
	 * An invalid docType is rejected before ever calling docudesk.
	 *
	 * @return void
	 */
	public function testRequestRejectsInvalidDocType(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'documentUri' => 'docudesk://attachments/x/invoice.pdf',
					'docType' => 'invalid',
					default => $default,
				};
			}
		);
		$this->extractionClient->expects(self::never())->method('requestExtraction');

		$response = $this->buildController()->request();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testRequestRejectsInvalidDocType()

	/**
	 * When docudesk is unreachable the proxy surfaces 503, never a fatal error.
	 *
	 * @return void
	 */
	public function testRequestSurfaces503WhenDocudeskUnavailable(): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'documentUri' => 'docudesk://attachments/x/invoice.pdf',
					'docType' => 'receipt',
					default => $default,
				};
			}
		);
		$this->extractionClient->method('requestExtraction')->willReturn(
			['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available']
		);

		$response = $this->buildController()->request();
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testRequestSurfaces503WhenDocudeskUnavailable()

	/**
	 * REQ-GAC-001: a successful re-request against an EXISTING draft
	 * captures the docudesk financialExtraction id from the synchronous
	 * response and persists it onto that draft.
	 *
	 * @return void
	 */
	public function testRequestCapturesAndPersistsExtractionId(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-1', 'administrationId' => 'adm-1', 'sourceDocumentUri' => 'docudesk://attachments/x/invoice.pdf'],
		];

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null): mixed {
				return match ($key) {
					'documentUri' => 'docudesk://attachments/x/invoice.pdf',
					'docType' => 'supplier-invoice',
					'id' => 'draft-1',
					default => $default,
				};
			}
		);
		$this->extractionClient->method('requestExtraction')->willReturn(
			['success' => true, 'statusCode' => 201, 'error' => null, 'extractionId' => 'ext-123']
		);

		$response = $this->buildController()->request();
		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());

		self::assertCount(1, $this->savedObjects);
		self::assertSame('ext-123', $this->savedObjects[0]['object']['docudeskExtractionId']);

	}//end testRequestCapturesAndPersistsExtractionId()

	/**
	 * Confirm() rejects an unknown/missing schema before touching OR.
	 *
	 * @return void
	 */
	public function testConfirmRejectsUnknownSchema(): void {
		$response = $this->buildController()->confirm(id: 'draft-1', schema: 'NotASchema');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testConfirmRejectsUnknownSchema()

	/**
	 * Confirm() returns a masked 404 for a draft in another administration
	 * (IDOR guard, ADR-005) — never a 403 that would disclose it exists.
	 *
	 * @return void
	 */
	public function testConfirmMasksDraftInOtherAdministration(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-2', 'administrationId' => 'adm-OTHER', 'fieldConfidence' => []],
		];

		$response = $this->buildController()->confirm(id: 'draft-2', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $this->savedObjects);

	}//end testConfirmMasksDraftInOtherAdministration()

	/**
	 * Confirm() returns 404 for an id that does not exist.
	 *
	 * @return void
	 */
	public function testConfirmReturns404ForUnknownDraft(): void {
		$response = $this->buildController()->confirm(id: 'missing', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testConfirmReturns404ForUnknownDraft()

	/**
	 * REQ-RXC-004: a valid correction is recorded (humanCorrected) and persisted.
	 *
	 * @return void
	 */
	public function testConfirmRecordsCorrectionAndPersists(): void {
		$this->stubRows['SupplierInvoice'] = [
			[
				'id' => 'draft-1',
				'administrationId' => 'adm-1',
				'glAccount' => '',
				'invoiceNumber' => 'F-2026-88',
				'fieldConfidence' => ['invoiceNumber' => 0.97, 'glAccount' => 0.55],
				'humanCorrected' => [],
				'extractionStatus' => 'pending-review',
			],
		];

		$this->request->method('getParams')->willReturn(['glAccount' => '4500', 'id' => 'draft-1']);
		// DecodeBody() prefers php://input which is empty under PHPUnit, so
		// it falls back to getParams() — matches SupplierInvoiceImportController's
		// established test pattern.
		$this->suggestionClient->expects(self::never())->method('postCorrection');

		$response = $this->buildController()->confirm(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

		self::assertCount(1, $this->savedObjects);
		$saved = $this->savedObjects[0]['object'];
		self::assertSame('4500', $saved['glAccount']);
		self::assertSame(['glAccount'], $saved['humanCorrected']);
		self::assertSame('confirmed', $saved['extractionStatus']);

	}//end testConfirmRecordsCorrectionAndPersists()

	/**
	 * REQ-GAC-005: booking to a DIFFERENT account than the docudesk
	 * suggestion still posts the operator's chosen code back as a
	 * correction (never the original suggestion) when the draft carries a
	 * known docudeskExtractionId.
	 *
	 * @return void
	 */
	public function testConfirmPostsOverriddenCorrectionWhenExtractionIdKnown(): void {
		$this->stubRows['SupplierInvoice'] = [
			[
				'id' => 'draft-1',
				'administrationId' => 'adm-1',
				'glAccount' => '',
				'docudeskExtractionId' => 'ext-123',
				'suggestedGlAccount' => ['code' => '4300', 'label' => 'Kantoorkosten'],
				'fieldConfidence' => [],
				'humanCorrected' => [],
				'extractionStatus' => 'pending-review',
			],
		];

		$this->request->method('getParams')->willReturn(['glAccount' => '4900', 'id' => 'draft-1']);

		$this->suggestionClient->expects(self::once())
			->method('postCorrection')
			->with('ext-123', '4900', null)
			->willReturn(['success' => true, 'statusCode' => 200, 'error' => null]);

		$response = $this->buildController()->confirm(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testConfirmPostsOverriddenCorrectionWhenExtractionIdKnown()

	/**
	 * REQ-GAC-005: a docudesk-side correction-post failure never blocks or
	 * undoes the already-successful local booking.
	 *
	 * @return void
	 */
	public function testConfirmSucceedsEvenWhenCorrectionPostFails(): void {
		$this->stubRows['SupplierInvoice'] = [
			[
				'id' => 'draft-1',
				'administrationId' => 'adm-1',
				'glAccount' => '',
				'docudeskExtractionId' => 'ext-123',
				'fieldConfidence' => [],
				'humanCorrected' => [],
				'extractionStatus' => 'pending-review',
			],
		];

		$this->request->method('getParams')->willReturn(['glAccount' => '4300', 'id' => 'draft-1']);

		$this->suggestionClient->method('postCorrection')->willReturn(
			['success' => false, 'statusCode' => 0, 'error' => 'docudesk correction request failed']
		);

		$response = $this->buildController()->confirm(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $this->savedObjects);

	}//end testConfirmSucceedsEvenWhenCorrectionPostFails()

	/**
	 * REQ-GAC-006: a draft with no known docudeskExtractionId degrades
	 * gracefully — no error, no suggestion.
	 *
	 * @return void
	 */
	public function testSuggestGlAccountDegradesWhenExtractionIdUnknown(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-1', 'administrationId' => 'adm-1'],
		];

		$this->suggestionClient->expects(self::never())->method('requestSuggestion');

		$response = $this->buildController()->suggestGlAccount(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['suggestion']);
		self::assertSame('extraction-id-unknown', $response->getData()['reason']);

	}//end testSuggestGlAccountDegradesWhenExtractionIdUnknown()

	/**
	 * REQ-GAC-002/003: candidates are resolved from shillinq's own chart of
	 * accounts and a history-backed suggestion is returned.
	 *
	 * @return void
	 */
	public function testSuggestGlAccountReturnsTopRankedSuggestion(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-1', 'administrationId' => 'adm-1', 'docudeskExtractionId' => 'ext-123'],
		];

		$this->candidateService->expects(self::once())
			->method('activeCandidates')
			->with('adm-1')
			->willReturn([['code' => '4300', 'label' => 'Kantoorkosten']]);

		$this->suggestionClient->expects(self::once())
			->method('requestSuggestion')
			->with('ext-123', [['code' => '4300', 'label' => 'Kantoorkosten']])
			->willReturn(
				[
					'success' => true,
					'statusCode' => 200,
					'error' => null,
					'suggestion' => [
						'extractionId' => 'ext-123',
						'suggestedAccounts' => [
							[
								'code' => '4300',
								'label' => 'Kantoorkosten',
								'confidence' => 0.8,
								'rationale' => 'Booked to 4300 in 8 of the last 10 invoices from this supplier',
							],
						],
						'source' => 'history',
					],
				]
			);

		$response = $this->buildController()->suggestGlAccount(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$suggestion = $response->getData()['suggestion'];
		self::assertSame('4300', $suggestion['code']);
		self::assertSame(0.8, $suggestion['confidence']);
		self::assertSame('history', $suggestion['source']);
		// The cached suggestion is persisted onto the draft (design.md Seed Data).
		self::assertCount(1, $this->savedObjects);
		self::assertSame('4300', $this->savedObjects[0]['object']['suggestedGlAccount']['code']);

	}//end testSuggestGlAccountReturnsTopRankedSuggestion()

	/**
	 * REQ-GAC-006: when docudesk is unreachable the proxy degrades
	 * gracefully — no error, no suggestion.
	 *
	 * @return void
	 */
	public function testSuggestGlAccountDegradesWhenProviderUnavailable(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-1', 'administrationId' => 'adm-1', 'docudeskExtractionId' => 'ext-123'],
		];

		$this->candidateService->method('activeCandidates')->willReturn([]);
		$this->suggestionClient->method('requestSuggestion')->willReturn(
			['success' => false, 'statusCode' => 0, 'error' => 'docudesk is not available', 'suggestion' => null]
		);

		$response = $this->buildController()->suggestGlAccount(id: 'draft-1', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertNull($response->getData()['suggestion']);
		self::assertSame('provider-unavailable', $response->getData()['reason']);

	}//end testSuggestGlAccountDegradesWhenProviderUnavailable()

	/**
	 * REQ-GAC-006 / ADR-005: suggestGlAccount() masks a draft in another
	 * administration as 404, never 403 (IDOR guard).
	 *
	 * @return void
	 */
	public function testSuggestGlAccountMasksDraftInOtherAdministration(): void {
		$this->stubRows['SupplierInvoice'] = [
			['id' => 'draft-2', 'administrationId' => 'adm-OTHER', 'docudeskExtractionId' => 'ext-123'],
		];

		$this->suggestionClient->expects(self::never())->method('requestSuggestion');

		$response = $this->buildController()->suggestGlAccount(id: 'draft-2', schema: 'SupplierInvoice');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSuggestGlAccountMasksDraftInOtherAdministration()
}//end class
