<?php

/**
 * Unit tests for VatPositionController.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\VatPositionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Tax\VatPositionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The position endpoint: who may read it and what it answers.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VatPositionControllerTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * The current user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * The position service.
	 *
	 * @var VatPositionService&MockObject
	 */
	private VatPositionService&MockObject $position;

	/**
	 * The administrations the caller is a member of.
	 *
	 * @var list<string>
	 */
	private array $memberOf = ['adm-1'];

	/**
	 * The caller's active administration, or null.
	 *
	 * @var string|null
	 */
	private ?string $active = null;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the controller.
	 *
	 * @return VatPositionController
	 */
	private function controller(): VatPositionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			fn (string $administrationId): bool => in_array($administrationId, $this->memberOf, true)
		);
		$context->method('buildContext')->willReturnCallback(
			fn (): array => ['userId' => 'u1', 'administrations' => [], 'activeAdministrationId' => $this->active]
		);

		return new VatPositionController(
			request: $request,
			position: $this->position,
			session: $session,
			context: $context,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Set up a logged-in member of adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->position = $this->createMock(VatPositionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * A member reads the position of their administration's period.
	 *
	 * @return void
	 */
	public function testAMemberReadsThePosition(): void {
		$this->params = ['administrationId' => 'adm-1', 'period' => '2026-Q4'];
		$box = ['returnBox' => '1a', 'type' => 'sales', 'taxRate' => 21.0, 'base' => 20000.0, 'vat' => 4200.0];
		$answer = ['period' => '2026-Q4', 'boxes' => [$box], 'payable' => 2700.0];
		$this->position->expects(self::once())->method('positionFor')
			->with('adm-1', '2026-Q4')
			->willReturn($answer);

		$response = $this->controller()->show();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['data' => ['period' => '2026-Q4', 'boxes' => [['id' => '1a-sales-21'] + $box], 'payable' => 2700.0]], $response->getData());
	}//end testAMemberReadsThePosition()

	/**
	 * Asked without an administration or period, the caller's active administration and today's quarter are read.
	 *
	 * @return void
	 */
	public function testWithoutParametersTheActiveAdministrationAndOpenQuarterAreRead(): void {
		$this->params = [];
		$this->active = 'adm-1';
		$this->position->expects(self::once())->method('positionFor')
			->with('adm-1', VatPositionController::openQuarter(today: new \DateTimeImmutable()))
			->willReturn(['boxes' => []]);

		self::assertSame(Http::STATUS_OK, $this->controller()->show()->getStatus());
	}//end testWithoutParametersTheActiveAdministrationAndOpenQuarterAreRead()

	/**
	 * Each month falls in its own quarter.
	 *
	 * @return void
	 */
	public function testTheOpenQuarterOfADay(): void {
		$quarters = [];
		foreach (['01', '03', '04', '06', '07', '09', '10', '12'] as $month) {
			$quarters[] = VatPositionController::openQuarter(today: new \DateTimeImmutable('2026-' . $month . '-15'));
		}

		self::assertSame(['2026-Q1', '2026-Q1', '2026-Q2', '2026-Q2', '2026-Q3', '2026-Q3', '2026-Q4', '2026-Q4'], $quarters);
	}//end testTheOpenQuarterOfADay()

	/**
	 * A caller from another administration is told it does not exist, and nothing is read.
	 *
	 * @return void
	 */
	public function testACallerFromAnotherAdministrationIsRefused(): void {
		$this->params = ['administrationId' => 'adm-2', 'period' => '2026-Q4'];
		$this->position->expects(self::never())->method('positionFor');

		$response = $this->controller()->show();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Administration not found'], $response->getData());
	}//end testACallerFromAnotherAdministrationIsRefused()

	/**
	 * Without a user, or without an administration, nothing is read.
	 *
	 * @return void
	 */
	public function testNoUserOrNoAdministrationIsRefused(): void {
		$this->position->expects(self::never())->method('positionFor');

		$this->user = null;
		$this->params = ['administrationId' => 'adm-1', 'period' => '2026-Q4'];
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->show()->getStatus());

		$this->user = $this->createMock(IUser::class);
		foreach (['', 'adm 1', str_repeat('a', 65)] as $administrationId) {
			$this->params = ['administrationId' => $administrationId, 'period' => '2026-Q4'];
			$response = $this->controller()->show();
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			self::assertSame(['error' => 'administrationId is required'], $response->getData());
		}
	}//end testNoUserOrNoAdministrationIsRefused()

	/**
	 * A malformed period answers 400 with the service's sentence.
	 *
	 * @return void
	 */
	public function testAMalformedPeriodIsABadRequest(): void {
		$this->params = ['administrationId' => 'adm-1', 'period' => '2026-Q9'];
		$refusal = 'The period must read YYYY-Qn, YYYY-MM or YYYY; got "2026-Q9".';
		$this->position->method('positionFor')->willThrowException(new InvalidArgumentException($refusal));

		$response = $this->controller()->show();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => $refusal], $response->getData());
	}//end testAMalformedPeriodIsABadRequest()

	/**
	 * A failure reading the books answers 500 without its details, and is logged.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndNotLeaked(): void {
		$this->params = ['administrationId' => 'adm-1', 'period' => '2026-Q4'];
		$this->position->method('positionFor')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects(self::once())->method('error')
			->with('VatPositionController: failed to read the VAT position', self::callback(
				static fn (array $context): bool => $context['exception'] === 'database gone' && $context['administrationId'] === 'adm-1'
			));

		$response = $this->controller()->show();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Failed to read the VAT position'], $response->getData());
	}//end testAFailureIsLoggedAndNotLeaked()
}//end class
