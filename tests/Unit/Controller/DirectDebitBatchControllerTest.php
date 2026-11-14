<?php

/**
 * Unit tests for DirectDebitBatchController.
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
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DateTime;
use OCA\Shillinq\Controller\DirectDebitBatchController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Receivables\SepaPain008Generator;
use OCA\Shillinq\Receivables\SepaPain008Validator;
use OCA\Shillinq\Service\Receivables\DirectDebitBatchService;
use OCA\Shillinq\Service\Receivables\DirectDebitFileService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-SDD-011 / REQ-SDD-012: propose and download, each refused outside the
 * caller's administrations.
 *
 * @covers \OCA\Shillinq\Controller\DirectDebitBatchController
 * @uses   \OCA\Shillinq\Service\Receivables\DirectDebitBatchService
 * @uses   \OCA\Shillinq\Service\Receivables\DirectDebitFileService
 * @uses   \OCA\Shillinq\Receivables\SepaPain008Generator
 * @uses   \OCA\Shillinq\Receivables\SepaPain008Validator
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class DirectDebitBatchControllerTest extends TestCase {

	/**
	 * The administrations canAccess() was asked about.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * The records the controller reads.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function records(): array {
		return [
			'DirectDebitBatch' => [
				['id' => 'b-gen', 'messageId' => 'DD-2026-10', 'pain008Xml' => '<Document>x</Document>', 'status' => 'generated', 'administrationId' => 'adm-1'],
				['id' => 'b-draft', 'messageId' => 'DD-2026-11', 'status' => 'draft', 'administrationId' => 'adm-1'],
				['id' => 'b-other', 'messageId' => 'DD-OTHER', 'pain008Xml' => '<Document>y</Document>', 'status' => 'generated', 'administrationId' => 'adm-2'],
			],
			'SepaMandate' => [
				['id' => 'm-1', 'mandateReference' => 'MR-1', 'status' => 'active', 'scheme' => 'CORE', 'type' => 'recurring', 'administrationId' => 'adm-1'],
			],
			'ARInvoice' => [
				[
					'id' => 'inv-1',
					'invoiceNumber' => 'F-2026-118',
					'directDebitMandateId' => 'm-1',
					'paymentMethod' => 'direct_debit',
					'lifecycleState' => 'issued',
					'grossAmount' => 500.0,
					'currency' => 'EUR',
					'administrationId' => 'adm-1',
				],
			],
		];

	}//end records()

	/**
	 * The controller with these request params, a user (or none) and access to adm-1 only.
	 *
	 * @param array<string, string>        $params  The request params.
	 * @param bool                         $user    Whether a user is logged in.
	 * @param DirectDebitBatchService|null $batches The proposal service, else the real one.
	 *
	 * @return DirectDebitBatchController
	 */
	private function controller(array $params=[], bool $user=true, ?DirectDebitBatchService $batches=null): DirectDebitBatchController {
		$store = new InMemoryObjectServiceStub(data: self::records(), findAllRendersEntities: true);
		$location = $this->createConfiguredMock(originalClassName: RegisterLocation::class, configuration: ['getRegisterSlug' => 'shillinq']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId): bool {
				$this->asked[] = $administrationId;
				return $administrationId === 'adm-1';
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-01T09:30:00+00:00'));

		return new DirectDebitBatchController(
			request: $request,
			batches: ($batches ?? new DirectDebitBatchService(objectService: $store, registerLocation: $location, time: $time)),
			files: new DirectDebitFileService(
				objectService: $store,
				registerLocation: $location,
				generator: new SepaPain008Generator(),
				validator: new SepaPain008Validator()
			),
			administrations: $context,
			objectService: $store,
			registerLocation: $location,
			session: $session,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A generated batch of the caller's administration downloads as an XML attachment.
	 *
	 * @return void
	 */
	public function testAGeneratedBatchDownloadsItsFile(): void {
		$response = $this->controller()->file(id: 'b-gen');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('<Document>x</Document>', $response->render());
		$this->assertSame('text/xml', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('DD-2026-10.pain008.xml', $response->getHeaders()['Content-Disposition']);
		$this->assertSame(['adm-1'], $this->asked);

	}//end testAGeneratedBatchDownloadsItsFile()

	/**
	 * Another administration's batch, and a missing one, answer the same 404.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsBatchIsMaskedAsNotFound(): void {
		$other = $this->controller()->file(id: 'b-other');
		$missing = $this->controller()->file(id: 'b-nope');

		$this->assertInstanceOf(JSONResponse::class, $other);
		$this->assertSame(Http::STATUS_NOT_FOUND, $other->getStatus());
		$this->assertSame(['error' => 'Not found'], $other->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'Not found'], $missing->getData());
		$this->assertSame(['adm-2'], $this->asked);

	}//end testAnotherAdministrationsBatchIsMaskedAsNotFound()

	/**
	 * A draft batch has no file yet; without a user nothing is read.
	 *
	 * @return void
	 */
	public function testADraftHasNoFileAndAnonymousIsRefused(): void {
		$draft = $this->controller()->file(id: 'b-draft');
		$this->assertSame(Http::STATUS_CONFLICT, $draft->getStatus());
		$this->assertSame(['error' => 'not-generated'], $draft->getData());

		$this->asked = [];
		$anonymous = $this->controller(user: false)->file(id: 'b-gen');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
		$this->assertSame([], $this->asked);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(params: ['administrationId' => 'adm-1'], user: false)->propose()->getStatus());

	}//end testADraftHasNoFileAndAnonymousIsRefused()

	/**
	 * A proposal opens the draft batch with its collection and total.
	 *
	 * @return void
	 */
	public function testAProposalAnswersTheDraftBatches(): void {
		$response = $this->controller(params: ['administrationId' => 'adm-1', 'collectionDate' => '2026-10-15'])->propose();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data['batches']);
		$this->assertSame('FRST', $data['batches'][0]['sequenceType']);
		$this->assertSame(500.0, $data['batches'][0]['controlSum']);
		$this->assertSame(['inv-1'], array_column($data['batches'][0]['collections'], 'invoiceId'));
		$this->assertSame([], $data['skipped']);

	}//end testAProposalAnswersTheDraftBatches()

	/**
	 * Outside the caller's administrations, with a bad date or with nothing to collect, no batch is made.
	 *
	 * @return void
	 */
	public function testAProposalIsRefusedOrEmptyWhenItCannotCollect(): void {
		$outside = $this->controller(params: ['administrationId' => 'adm-2', 'collectionDate' => '2026-10-15'])->propose();
		$this->assertSame(Http::STATUS_FORBIDDEN, $outside->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(params: ['collectionDate' => '2026-10-15'])->propose()->getStatus());

		foreach (['2026-02-30', '15-10-2026', ''] as $date) {
			$bad = $this->controller(params: ['administrationId' => 'adm-1', 'collectionDate' => $date])->propose();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus(), $date);
			$this->assertSame(['error' => 'invalid-collection-date'], $bad->getData());
		}

		$batches = $this->createMock(DirectDebitBatchService::class);
		$batches->method('propose')->willReturn(['batches' => [], 'skipped' => [['invoiceNumber' => 'F-1', 'reason' => 'no-mandate']]]);
		$empty = $this->controller(params: ['administrationId' => 'adm-1', 'collectionDate' => '2026-10-15'], batches: $batches)->propose();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $empty->getStatus());
		$this->assertSame('nothing-to-collect', $empty->getData()['error']);
		$this->assertSame('no-mandate', $empty->getData()['skipped'][0]['reason']);

		$failing = $this->createMock(DirectDebitBatchService::class);
		$failing->method('propose')->willThrowException(new RuntimeException('store down'));
		$failed = $this->controller(params: ['administrationId' => 'adm-1', 'collectionDate' => '2026-10-15'], batches: $failing)->propose();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'proposal-failed'], $failed->getData());

	}//end testAProposalIsRefusedOrEmptyWhenItCannotCollect()
}//end class
