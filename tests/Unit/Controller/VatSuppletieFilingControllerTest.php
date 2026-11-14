<?php

/**
 * Unit tests for VatSuppletieFilingController.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatSuppletieFilingController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use OCA\Shillinq\Service\Tax\SuppletieFilingUnavailableException;
use OCA\Shillinq\Service\Tax\VatSuppletieFilingService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Download and file a supplementary return: who may, and what each answers.
 *
 * The filing service is real; the correction lookup and the Digipoort port are doubled.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VatSuppletieFilingController::class)]
#[UsesClass(VatSuppletieFilingService::class)]
#[UsesClass(SuppletieFilingUnavailableException::class)]
#[UsesClass(DigipoortSubmissionResult::class)]
final class VatSuppletieFilingControllerTest extends TestCase {

	/**
	 * The current user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The correction lookup.
	 *
	 * @var VatSuppletieDetectionService&MockObject
	 */
	private VatSuppletieDetectionService&MockObject $suppletie;

	/**
	 * The Digipoort port.
	 *
	 * @var DigipoortSbrAdapterInterface&MockObject
	 */
	private DigipoortSbrAdapterInterface&MockObject $digipoort;

	/**
	 * Set up a logged-in member of adm-1 and the corrections they may and may not see.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->user = $this->createMock(IUser::class);
		$this->digipoort = $this->createMock(DigipoortSbrAdapterInterface::class);
		$this->suppletie = $this->createMock(VatSuppletieDetectionService::class);
		$this->suppletie->method('findCorrection')->willReturnCallback(
			static function (string $vatCorrectionId): ?array {
				$prepared = [
					'administrationId' => 'adm-1',
					'originalVatReturnId' => 'q2-2026',
					'state' => 'draft',
					'categoryDeltas' => [['returnBox' => '5b', 'type' => 'input', 'taxRate' => 21.0, 'deltaTaxableAmount' => 8571.43, 'deltaVATAmount' => 1800.0]],
					'correctionAmount' => -1800.0,
					'thresholdExceeded' => true,
					'preparedAt' => '2026-11-02T10:00:00+00:00',
				];

				return match ($vatCorrectionId) {
					'corr-q2' => $prepared,
					'corr-detected' => array_merge($prepared, ['preparedAt' => null]),
					'corr-other' => array_merge($prepared, ['administrationId' => 'adm-2']),
					default => null,
				};
			}
		);
	}//end setUp()

	/**
	 * The controller for the current user.
	 *
	 * @return VatSuppletieFilingController
	 */
	private function controller(): VatSuppletieFilingController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$filing = new VatSuppletieFilingService(
			new InMemoryObjectServiceStub(['VatCorrection' => []]),
			$this->digipoort,
			$appConfig,
			$this->createStub(LoggerInterface::class)
		);

		return new VatSuppletieFilingController(
			request: $this->createMock(IRequest::class),
			suppletie: $this->suppletie,
			filing: $filing,
			session: $session,
			context: $context,
			logger: $this->createStub(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The prepared return downloads as a JSON file with its per-box differences.
	 *
	 * @return void
	 */
	public function testThePreparedReturnDownloads(): void {
		$response = $this->controller()->download(correctionId: 'corr-q2');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringContainsString('suppletie-corr-q2.json', (string)$response->getHeaders()['Content-Disposition']);
		$document = json_decode($response->render(), true);
		self::assertSame('q2-2026', $document['originalReturnId']);
		self::assertSame('5b', $document['boxes'][0]['returnBox']);
		self::assertSame(1800, (int)$document['boxes'][0]['deltaVATAmount']);
	}//end testThePreparedReturnDownloads()

	/**
	 * A correction not prepared yet answers 409, not a file.
	 *
	 * @return void
	 */
	public function testAnUnpreparedCorrectionDoesNotDownload(): void {
		$response = $this->controller()->download(correctionId: 'corr-detected');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => 'Prepare the supplementary return before downloading or filing it.'], $response->getData());
	}//end testAnUnpreparedCorrectionDoesNotDownload()

	/**
	 * Another administration's correction answers 404 like an unknown one, and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsCorrectionIsNotFound(): void {
		$this->digipoort->expects(self::never())->method('submit');
		$controller = $this->controller();

		self::assertSame(Http::STATUS_NOT_FOUND, $controller->download(correctionId: 'corr-other')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->file(correctionId: 'corr-other')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->file(correctionId: 'corr-unknown')->getStatus());
	}//end testAnotherAdministrationsCorrectionIsNotFound()

	/**
	 * Without a user both answer 401; a malformed id answers 400.
	 *
	 * @return void
	 */
	public function testAnonymousAndMalformedRequestsAreRefused(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->file(correctionId: 'not an id')->getStatus());

		$this->user = null;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->download(correctionId: 'corr-q2')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->file(correctionId: 'corr-q2')->getStatus());
	}//end testAnonymousAndMalformedRequestsAreRefused()

	/**
	 * While Digipoort is not connected, File answers 409 with where to download the return.
	 *
	 * @return void
	 */
	public function testWithoutAConnectionFileAnswersTheDownload(): void {
		$this->digipoort->method('isDormant')->willReturn(true);

		$response = $this->controller()->file(correctionId: 'corr-q2');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('/apps/shillinq/api/vat-corrections/corr-q2/supplementary-return', $response->getData()['download']);
	}//end testWithoutAConnectionFileAnswersTheDownload()

	/**
	 * With a connection File answers the submitted correction.
	 *
	 * @return void
	 */
	public function testWithAConnectionFileSubmitsTheCorrection(): void {
		$this->digipoort->method('isDormant')->willReturn(false);
		$this->digipoort->expects(self::once())->method('submit')->willReturn(
			new DigipoortSubmissionResult(deliveryStatus: 'DELIVERED', reference: 'dp-77', dormant: false)
		);

		$response = $this->controller()->file(correctionId: 'corr-q2');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('submitted', $response->getData()['data']['state']);
		self::assertSame('dp-77', $response->getData()['data']['digipoortMessageId']);
	}//end testWithAConnectionFileSubmitsTheCorrection()
}//end class
