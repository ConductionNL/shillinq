<?php

/**
 * Unit tests for VatFiscalYearCorrectionController.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatFiscalYearCorrectionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Vat\VatFiscalYearCorrectionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Check fiscal year: who may run it, and what it answers.
 *
 * @covers \OCA\Shillinq\Controller\VatFiscalYearCorrectionController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatFiscalYearCorrectionControllerTest extends TestCase {

	/**
	 * The current user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The fiscal year check.
	 *
	 * @var VatFiscalYearCorrectionService&MockObject
	 */
	private VatFiscalYearCorrectionService&MockObject $corrections;

	/**
	 * Set up a logged-in member of adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->corrections = $this->createMock(VatFiscalYearCorrectionService::class);
		$this->user        = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * A member checks the fiscal year and gets the totals back.
	 *
	 * @return void
	 */
	public function testAMemberChecksTheFiscalYear(): void {
		$fiscalYear = ['id' => 'fy-1', 'administrationId' => 'adm-1', 'startDate' => '2025-07-01', 'endDate' => '2026-06-30'];
		$result     = ['fiscalYearId' => 'fy-1', 'fiscalYear' => '2025/2026', 'returns' => [], 'total' => 0.0, 'thresholdExceeded' => false];
		$this->corrections->method('findFiscalYear')->with('fy-1')->willReturn($fiscalYear);
		$this->corrections->expects(self::once())->method('check')->with($fiscalYear)->willReturn($result);

		$response = $this->controller()->check('fy-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['data' => $result], $response->getData());
	}//end testAMemberChecksTheFiscalYear()

	/**
	 * A fiscal year of another administration answers 404 like a missing
	 * one, and nothing is checked.
	 *
	 * @return void
	 */
	public function testAFiscalYearOfAnotherAdministrationIsNotFound(): void {
		$this->corrections->method('findFiscalYear')->willReturnMap(
			[
				['fy-other', ['id' => 'fy-other', 'administrationId' => 'adm-2']],
				['fy-gone', null],
			]
		);
		$this->corrections->expects(self::never())->method('check');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->check('fy-other')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->check('fy-gone')->getStatus());
	}//end testAFiscalYearOfAnotherAdministrationIsNotFound()

	/**
	 * A calendar fiscal year answers 409 with the reason; any other failure a
	 * logged 500.
	 *
	 * @return void
	 */
	public function testARefusedCheckIsA409AndAFailureA500(): void {
		$this->corrections->method('findFiscalYear')->willReturn(['id' => 'fy-1', 'administrationId' => 'adm-1']);
		$this->corrections->method('check')->willReturnOnConsecutiveCalls(
			self::throwException(new RuntimeException('The fiscal year of this administration is the calendar year.')),
			self::throwException(new \Error('boom'))
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$refused = $this->controller()->check('fy-1');
		self::assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
		self::assertSame(['error' => 'The fiscal year of this administration is the calendar year.'], $refused->getData());

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller($logger)->check('fy-1')->getStatus());
	}//end testARefusedCheckIsA409AndAFailureA500()

	/**
	 * Without a user 401 and with a malformed id 400, before anything is read.
	 *
	 * @return void
	 */
	public function testRefusalsBeforeAnythingIsRead(): void {
		$this->corrections->expects(self::never())->method('findFiscalYear');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->check('../x')->getStatus());
		$this->user = null;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->check('fy-1')->getStatus());
	}//end testRefusalsBeforeAnythingIsRead()

	/**
	 * Build the controller for a member of adm-1.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return VatFiscalYearCorrectionController
	 */
	private function controller(?LoggerInterface $logger = null): VatFiscalYearCorrectionController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		return new VatFiscalYearCorrectionController(
			request: $this->createMock(IRequest::class),
			corrections: $this->corrections,
			session: $session,
			context: $context,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end controller()
}//end class
