<?php

/**
 * Unit tests for VatReturnChecksController.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\VatReturnChecksController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The checks tab of a VAT return: who may run it, and what it answers.
 *
 * @covers \OCA\Shillinq\Controller\VatReturnChecksController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatReturnChecksControllerTest extends TestCase {

	/**
	 * The current user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The checks.
	 *
	 * @var VatReturnChecks&MockObject
	 */
	private VatReturnChecks&MockObject $checks;

	/**
	 * The VAT return service.
	 *
	 * @var VATReturnService&MockObject
	 */
	private VATReturnService&MockObject $vatReturns;

	/**
	 * Set up a logged-in member of adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->checks = $this->createMock(VatReturnChecks::class);
		$this->checks->method('title')->willReturnCallback(static fn (string $id): string => 'Title of ' . $id);
		$this->vatReturns = $this->createMock(VATReturnService::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * Each check comes back with its name, severity, status and message, and
	 * a failing blocking check marks the return blocked.
	 *
	 * @return void
	 */
	public function testTheChecksOfAReturnAreListedWithTheirStatus(): void {
		$return = ['id' => 'ret-q3', 'administrationId' => 'adm-1', 'startDate' => '2026-07-01', 'endDate' => '2026-09-30'];
		$this->vatReturns->method('findReturn')->with('ret-q3')->willReturn($return);
		$this->checks->expects(self::once())->method('run')->with($return)->willReturn(
			[
				[
					'id' => 'vat-line-has-box',
					'severity' => VatReturnChecks::BLOCKING,
					'passed' => false,
					'message' => 'These transactions have a VAT line without a box: MEM-7',
				],
				['id' => 'previous-return-filed', 'severity' => VatReturnChecks::WARNING, 'passed' => true, 'message' => 'Passed'],
			]
		);

		$response = $this->controller()->index('ret-q3');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'data' => [
					[
						'id' => 'vat-line-has-box',
						'title' => 'Title of vat-line-has-box',
						'severity' => 'blocking',
						'severityLabel' => 'Blocks filing',
						'passed' => false,
						'status' => 'Failed',
						'message' => 'These transactions have a VAT line without a box: MEM-7',
					],
					[
						'id' => 'previous-return-filed',
						'title' => 'Title of previous-return-filed',
						'severity' => 'warning',
						'severityLabel' => 'Warning',
						'passed' => true,
						'status' => 'Passed',
						'message' => 'Passed',
					],
				],
				'blocked' => true,
			],
			$response->getData()
		);
	}//end testTheChecksOfAReturnAreListedWithTheirStatus()

	/**
	 * A failing warning does not block the return.
	 *
	 * @return void
	 */
	public function testAFailingWarningDoesNotBlock(): void {
		$this->vatReturns->method('findReturn')->willReturn(['id' => 'ret-q3', 'administrationId' => 'adm-1']);
		$this->checks->method('run')->willReturn(
			[['id' => 'no-unposted-documents', 'severity' => VatReturnChecks::WARNING, 'passed' => false, 'message' => 'Not posted: VK-8']]
		);

		$data = $this->controller()->index('ret-q3')->getData();

		self::assertFalse($data['blocked']);
		self::assertSame('Failed', $data['data'][0]['status']);
	}//end testAFailingWarningDoesNotBlock()

	/**
	 * A return of another administration answers 404 like a missing one,
	 * and its books are not read.
	 *
	 * @return void
	 */
	public function testAReturnOfAnotherAdministrationIsNotFound(): void {
		$this->vatReturns->method('findReturn')->willReturnMap(
			[
				['ret-other', ['id' => 'ret-other', 'administrationId' => 'adm-2']],
				['ret-gone', null],
			]
		);
		$this->checks->expects(self::never())->method('run');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index('ret-other')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index('ret-gone')->getStatus());
	}//end testAReturnOfAnotherAdministrationIsNotFound()

	/**
	 * Without a user 401, with a malformed id 400, and a return that cannot
	 * be read 500; none of them runs the checks.
	 *
	 * @return void
	 */
	public function testRefusalsBeforeTheChecksRun(): void {
		$this->checks->expects(self::never())->method('run');
		$this->vatReturns->method('findReturn')->willThrowException(new RuntimeException('db down'));

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->index('../etc')->getStatus());
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller()->index('ret-q3')->getStatus());

		$this->user = null;
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->index('ret-q3')->getStatus());
	}//end testRefusalsBeforeTheChecksRun()

	/**
	 * Build the controller for a member of adm-1.
	 *
	 * @return VatReturnChecksController
	 */
	private function controller(): VatReturnChecksController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new VatReturnChecksController(
			request: $this->createMock(IRequest::class),
			checks: $this->checks,
			vatReturns: $this->vatReturns,
			session: $session,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()
}//end class
