<?php

/**
 * Unit tests for ARInvoiceEInvoiceController.
 *
 * Covers the security-endpoint-guards re-verification of send-einvoice's
 * authorization posture: the audit's mechanical scan flagged send() as a
 * candidate because its guard is expressed as `$this->context->canAccess()`
 * rather than the `authorize*`/`require*`/`ensure*` shape the scan looks
 * for. Reading the method body confirmed a genuine, enforcing guard already
 * existed (masked 404 on non-membership, matching
 * AdministrationContextService's own IDOR-safety contract) — this file adds
 * the PHPUnit coverage that was previously missing so the verdict has
 * measured evidence, per REQ-004's both-directions requirement.
 *
 * `EInvoiceService` is final and cannot be doubled, so the tests build the
 * REAL service over an in-memory duck-typed ObjectService (the exact
 * pattern of tests/Unit/Service/EInvoice/EInvoiceServiceTest.php) — the
 * positive-direction test therefore exercises the full controller→service
 * pipeline, not a mock's canned answer.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ARInvoiceEInvoiceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\EInvoice\EInvoiceService;
use OCA\Shillinq\Service\EInvoice\EInvoiceValidationService;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ARInvoiceEInvoiceController's already-enforced membership guard.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 */
final class ARInvoiceEInvoiceControllerTest extends TestCase {

	/**
	 * Rows saved through the duck ObjectService (captured by reference).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * A realistic issued ARInvoice ready to send, owned by adm-1
	 * (mirrors EInvoiceServiceTest::issuedInvoice()).
	 *
	 * @return array<string,mixed>
	 */
	private function issuedInvoice(): array {
		return [
			'id' => 'invoice-obj-1',
			'invoiceNumber' => '2026-0042',
			'customerId' => 'DEB-0001',
			'administrationId' => 'adm-1',
			'invoiceDate' => '2026-06-10',
			'dueDate' => '2026-07-10',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'deliveryStatus' => 'not-sent',
			'sellerName' => 'Shillinq Consultancy B.V.',
			'sellerVatId' => 'NL809876543B01',
			'buyerVatId' => 'NL001234567B01',
			'buyerLegalRegId' => '12340001',
		];

	}//end issuedInvoice()

	/**
	 * Build the controller over the REAL EInvoiceService and an in-memory
	 * ARInvoice store.
	 *
	 * @param string|null $userId The acting uid, or null for anonymous.
	 * @param array<int,string> $accessible Administration ids canAccess() allows.
	 * @param string $administrationId The administrationId request param.
	 *
	 * @return ARInvoiceEInvoiceController
	 */
	private function build(
		?string $userId,
		array $accessible,
		string $administrationId = 'adm-1',
	): ARInvoiceEInvoiceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($administrationId): mixed {
				if ($key === 'administrationId') {
					return $administrationId;
				}

				return $default;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($userId === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		}

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $accessible, true)
		);

		return new ARInvoiceEInvoiceController(
			$request,
			$this->buildRealService(context: $context),
			$session,
			$context,
			new NullLogger(),
		);

	}//end build()

	/**
	 * Build the real (final, non-doublable) EInvoiceService over an
	 * in-memory duck store seeded with one issued adm-1 invoice.
	 *
	 * @param AdministrationContextService $context The membership seam shared with the controller.
	 *
	 * @return EInvoiceService
	 */
	private function buildRealService(AdministrationContextService $context): EInvoiceService {
		$this->saved = [];
		$store = new class(['ARInvoice' => [$this->issuedInvoice()]], $this->saved) {
			/**
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				unset($register);
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
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
			}

			/**
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$vies = $this->createMock(ViesService::class);
		$vies->method('validate')->willReturn(['valid' => true, 'outage' => false]);

		$port = new class() implements PeppolTransmissionPortInterface {
			/**
			 * @inheritDoc
			 */
			public function lookupParticipant(string $administrationId, string $partyId): ?string {
				unset($administrationId, $partyId);
				return '0106:00000000';
			}

			/**
			 * @inheritDoc
			 */
			public function submit(string $participantId, string $documentType, string $payloadFileUri): string {
				unset($documentType, $payloadFileUri);
				if ($participantId === '') {
					throw new RuntimeException('no participant');
				}

				return 'urn:uuid:test-transmission';
			}
		};

		return new EInvoiceService(
			appConfig: $appConfig,
			administrationContext: $context,
			logger: new NullLogger(),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			ublMapper: new ArInvoiceUblMapper(),
			pdfGenerator: new InvoicePdfGenerator(),
			validationService: new EInvoiceValidationService(vies: $vies, peppolPort: $port),
			peppolPort: $port,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildRealService()

	/**
	 * An anonymous caller is rejected with 401 before any access check runs.
	 *
	 * @return void
	 */
	public function testSendRejectsAnonymousCaller(): void {
		$controller = $this->build(userId: null, accessible: []);

		$response = $controller->send('2026-0042');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame([], $this->saved, 'No invoice may be mutated by an anonymous caller');

	}//end testSendRejectsAnonymousCaller()

	/**
	 * NEGATIVE CONTROL: a caller with no membership in the administration
	 * named by the request is masked as a 404 (never 403 — per
	 * AdministrationContextService::canAccess()'s own documented contract,
	 * this endpoint must not become an enumeration oracle for the tenant
	 * list) and the send pipeline never mutates the invoice.
	 *
	 * @return void
	 */
	public function testSendMasksNonMemberAdministrationAs404(): void {
		$controller = $this->build(userId: 'mallory', accessible: ['adm-2']);

		$response = $controller->send('2026-0042');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->saved, 'The invoice must NOT be transmitted or mutated');

	}//end testSendMasksNonMemberAdministrationAs404()

	/**
	 * POSITIVE direction: a member of the invoice's own administration
	 * drives the full send pipeline to a 200 with deliveryStatus queued
	 * (no regression).
	 *
	 * @return void
	 */
	public function testSendSucceedsForAdministrationMember(): void {
		$controller = $this->build(userId: 'alice', accessible: ['adm-1']);

		$response = $controller->send('2026-0042');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('queued', $data['deliveryStatus']);
		$this->assertNotNull($data['transmissionId']);
		$this->assertNotSame([], $this->saved, 'The invoice must be persisted with its new deliveryStatus');

	}//end testSendSucceedsForAdministrationMember()

	/**
	 * A malformed invoice number is rejected before any access check runs.
	 *
	 * @return void
	 */
	public function testSendRejectsInvalidInvoiceNumber(): void {
		$controller = $this->build(userId: 'alice', accessible: ['adm-1']);

		$response = $controller->send('../etc/passwd');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->saved);

	}//end testSendRejectsInvalidInvoiceNumber()

	/**
	 * A missing administrationId is rejected before any access check runs.
	 *
	 * @return void
	 */
	public function testSendRequiresAdministrationId(): void {
		$controller = $this->build(userId: 'alice', accessible: ['adm-1'], administrationId: '');

		$response = $controller->send('2026-0042');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame([], $this->saved);

	}//end testSendRequiresAdministrationId()
}//end class
