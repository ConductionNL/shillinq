<?php

/**
 * Unit tests for VatSuppletieController.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatSuppletieController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Detect drift and prepare a supplementary return: who may, and what each answers.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatSuppletieControllerTest extends TestCase {

	/**
	 * The current user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The detection service.
	 *
	 * @var VatSuppletieDetectionService&MockObject
	 */
	private VatSuppletieDetectionService&MockObject $suppletie;

	/**
	 * The VAT return service.
	 *
	 * @var VATReturnService&MockObject
	 */
	private VATReturnService&MockObject $vatReturns;

	/**
	 * Build the controller for a member of adm-1.
	 *
	 * @return VatSuppletieController
	 */
	private function controller(): VatSuppletieController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		return new VatSuppletieController(
			request: $this->createMock(IRequest::class),
			suppletie: $this->suppletie,
			vatReturns: $this->vatReturns,
			session: $session,
			context: $context,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * Set up a logged-in member of adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->suppletie = $this->createMock(VatSuppletieDetectionService::class);
		$this->vatReturns = $this->createMock(VATReturnService::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * A drifted return answers 201 with the correction written.
	 *
	 * @return void
	 */
	public function testADriftedReturnAnswersTheCorrection(): void {
		$this->vatReturns->method('findReturn')->with('ret-q2')->willReturn(['id' => 'ret-q2', 'administrationId' => 'adm-1', 'statusCode' => 'filed']);
		$correction = ['id' => 'cor-1', 'originalVatReturnId' => 'ret-q2', 'state' => 'draft'];
		$this->suppletie->expects(self::once())->method('detect')->with('ret-q2')->willReturn($correction);

		$response = $this->controller()->detectDrift(returnId: 'ret-q2');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['drift' => true, 'data' => $correction], $response->getData());
	}//end testADriftedReturnAnswersTheCorrection()

	/**
	 * A return that still matches the books answers 200 with no correction.
	 *
	 * @return void
	 */
	public function testAMatchingReturnAnswersNoDrift(): void {
		$this->vatReturns->method('findReturn')->willReturn(['id' => 'ret-q2', 'administrationId' => 'adm-1']);
		$this->suppletie->method('detect')->willReturn(null);

		$response = $this->controller()->detectDrift(returnId: 'ret-q2');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['drift' => false, 'data' => null], $response->getData());
	}//end testAMatchingReturnAnswersNoDrift()

	/**
	 * A return of another administration, or none at all, answers 404 and is never checked.
	 *
	 * @return void
	 */
	public function testAForeignOrMissingReturnIsNotChecked(): void {
		$this->suppletie->expects(self::never())->method('detect');
		$this->vatReturns->method('findReturn')->willReturnMap([
			['ret-other', ['id' => 'ret-other', 'administrationId' => 'adm-2']],
			['ret-none', null],
			['ret-blank', ['id' => 'ret-blank']],
		]);

		foreach (['ret-other', 'ret-none', 'ret-blank'] as $returnId) {
			$response = $this->controller()->detectDrift(returnId: $returnId);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $returnId);
			self::assertSame(['error' => 'VAT return not found'], $response->getData());
		}
	}//end testAForeignOrMissingReturnIsNotChecked()

	/**
	 * A draft return is refused with the service's sentence as a conflict.
	 *
	 * @return void
	 */
	public function testADraftReturnIsAConflict(): void {
		$refusal = 'VATReturn ret-draft is draft; only a filed (submitted or later) return can be checked for drift.';
		$this->vatReturns->method('findReturn')->willReturn(['id' => 'ret-draft', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);
		$this->suppletie->method('detect')->willThrowException(new RuntimeException($refusal));

		$response = $this->controller()->detectDrift(returnId: 'ret-draft');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => $refusal], $response->getData());
	}//end testADraftReturnIsAConflict()

	/**
	 * A detected correction of the caller's administration is prepared.
	 *
	 * @return void
	 */
	public function testADetectedCorrectionIsPrepared(): void {
		$this->suppletie->method('findCorrection')->with('cor-1')->willReturn(['id' => 'cor-1', 'administrationId' => 'adm-1', 'state' => 'draft']);
		$prepared = ['id' => 'cor-1', 'thresholdExceeded' => true, 'adjustmentAmount' => -1800.0];
		$this->suppletie->expects(self::once())->method('prepare')->with('cor-1')->willReturn($prepared);

		$response = $this->controller()->prepare(correctionId: 'cor-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['data' => $prepared], $response->getData());
	}//end testADetectedCorrectionIsPrepared()

	/**
	 * A correction of another administration, or none at all, answers 404 and is never prepared;
	 * one already prepared is a conflict.
	 *
	 * @return void
	 */
	public function testForeignMissingOrPreparedCorrections(): void {
		$this->suppletie->method('findCorrection')->willReturnMap([
			['cor-other', ['id' => 'cor-other', 'administrationId' => 'adm-2']],
			['cor-none', null],
			['cor-done', ['id' => 'cor-done', 'administrationId' => 'adm-1']],
		]);
		$this->suppletie->expects(self::once())->method('prepare')->with('cor-done')
			->willThrowException(new RuntimeException('VatCorrection cor-done is not in the detected sub-state (draft, unprepared).'));

		foreach (['cor-other', 'cor-none'] as $correctionId) {
			$response = $this->controller()->prepare(correctionId: $correctionId);
			self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $correctionId);
			self::assertSame(['error' => 'VAT correction not found'], $response->getData());
		}

		$response = $this->controller()->prepare(correctionId: 'cor-done');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testForeignMissingOrPreparedCorrections()

	/**
	 * Without a user or with a malformed id nothing is looked up.
	 *
	 * @return void
	 */
	public function testNoUserOrAMalformedIdIsRefused(): void {
		$this->vatReturns->expects(self::never())->method('findReturn');
		$this->suppletie->expects(self::never())->method('findCorrection');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->detectDrift(returnId: '../x')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->prepare(correctionId: '')->getStatus());

		$this->user = null;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->detectDrift(returnId: 'ret-q2')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->prepare(correctionId: 'cor-1')->getStatus());
	}//end testNoUserOrAMalformedIdIsRefused()
}//end class
