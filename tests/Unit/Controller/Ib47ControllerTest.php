<?php

/**
 * Ib47ControllerTest
 *
 * Who may prepare the IB47 batch, and for which administration.
 *
 * The positive control comes first: a payroll officer with a membership of
 * the administration gets the service's answer. Without it, every refusal
 * below would pass on a controller that refused everyone.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Shillinq\Controller\Ib47Controller;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\Ib47BatchServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The IB47 controller's guards.
 *
 * @covers \OCA\Shillinq\Controller\Ib47Controller
 *
 * @spec openspec/changes/archive/2026-11-24-people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class Ib47ControllerTest extends TestCase {

	/**
	 * The batch service.
	 *
	 * @var Ib47BatchServiceInterface&MockObject
	 */
	private Ib47BatchServiceInterface&MockObject $batches;

	/**
	 * The membership guard.
	 *
	 * @var AdministrationContextServiceInterface&MockObject
	 */
	private AdministrationContextServiceInterface&MockObject $context;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Fresh doubles: a signed-in payroll officer naming adm-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->batches = $this->createMock(originalClassName: Ib47BatchServiceInterface::class);
		$this->context = $this->createMock(originalClassName: AdministrationContextServiceInterface::class);
		$this->groups  = $this->createMock(originalClassName: IGroupManager::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('officer');
		$this->session->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(
			callback: fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
	}//end setUp()

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = ['administration_id' => 'adm-1', 'taxYear' => '2026'];

	/**
	 * A payroll officer with a membership gets the prepared batch.
	 *
	 * @return void
	 */
	public function testAPayrollOfficerPreparesTheBatch(): void {
		$this->officer(admin: false, officer: true);
		$this->context->method('canAccess')->with('adm-1')->willReturn(true);
		$answer = ['taxYear' => 2026, 'administrationId' => 'adm-1', 'prepared' => [['payeeId' => 'p-1']], 'incomplete' => []];
		$this->batches->expects($this->once())->method('prepare')->with('adm-1', 2026)->willReturn($answer);

		$response = $this->controller()->prepare();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: $answer, actual: $response->getData());
	}//end testAPayrollOfficerPreparesTheBatch()

	/**
	 * An instance administrator may prepare it too.
	 *
	 * @return void
	 */
	public function testAnAdministratorPreparesTheBatch(): void {
		$this->officer(admin: true, officer: false);
		$this->context->method('canAccess')->willReturn(true);
		$this->batches->expects($this->once())->method('prepare')->willReturn(['prepared' => [], 'incomplete' => []]);

		self::assertSame(expected: Http::STATUS_OK, actual: $this->controller()->prepare()->getStatus());
	}//end testAnAdministratorPreparesTheBatch()

	/**
	 * A member without the role is refused before anything is read.
	 *
	 * @return void
	 */
	public function testSomeoneWithoutTheRoleIsRefused(): void {
		$this->officer(admin: false, officer: false);
		$this->context->expects($this->never())->method('canAccess');
		$this->batches->expects($this->never())->method('prepare');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller()->prepare()->getStatus());
	}//end testSomeoneWithoutTheRoleIsRefused()

	/**
	 * An administration the officer holds no membership of answers 404.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationIsNotFound(): void {
		$this->officer(admin: false, officer: true);
		$this->params = ['administration_id' => 'adm-2', 'taxYear' => '2026'];
		$this->context->method('canAccess')->with('adm-2')->willReturn(false);
		$this->batches->expects($this->never())->method('prepare');

		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller()->prepare()->getStatus());
	}//end testAnotherAdministrationIsNotFound()

	/**
	 * No administration, or one that is not an id, is a bad request.
	 *
	 * @return void
	 */
	public function testAnUnnamedAdministrationIsABadRequest(): void {
		$this->officer(admin: false, officer: true);
		$this->params = ['administration_id' => '../adm', 'taxYear' => '2026'];
		$this->batches->expects($this->never())->method('prepare');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller()->prepare()->getStatus());
	}//end testAnUnnamedAdministrationIsABadRequest()

	/**
	 * The service's refusal of a year comes back as 400 with its sentence.
	 *
	 * @return void
	 */
	public function testARefusedYearIsABadRequest(): void {
		$this->officer(admin: false, officer: true);
		$this->context->method('canAccess')->willReturn(true);
		$this->batches->method('prepare')->willThrowException(new InvalidArgumentException('An IB47 batch needs a tax year between 2000 and 2100.'));

		$this->params['taxYear'] = '1999';
		$response = $this->controller()->prepare();

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: ['error' => 'An IB47 batch needs a tax year between 2000 and 2100.'], actual: $response->getData());
	}//end testARefusedYearIsABadRequest()

	/**
	 * Without a year or administration the active administration and last year
	 * are prepared, which is what the batch page's button sends.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreLastYearAndTheActiveAdministration(): void {
		$this->officer(admin: false, officer: true);
		$this->params = [];
		$this->context->method('defaultAdministrationIdForUser')->with('officer')->willReturn('adm-7');
		$this->context->method('canAccess')->with('adm-7')->willReturn(true);
		$lastYear = ((int)date(format: 'Y') - 1);
		$this->batches->expects($this->once())->method('prepare')->with('adm-7', $lastYear)->willReturn(['prepared' => [], 'incomplete' => []]);

		self::assertSame(expected: Http::STATUS_OK, actual: $this->controller()->prepare()->getStatus());
	}//end testTheDefaultsAreLastYearAndTheActiveAdministration()

	/**
	 * A year that is not four digits is a bad request.
	 *
	 * @return void
	 */
	public function testAMalformedYearIsABadRequest(): void {
		$this->officer(admin: false, officer: true);
		$this->params['taxYear'] = '26; drop';
		$this->context->method('canAccess')->willReturn(true);
		$this->batches->expects($this->never())->method('prepare');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $this->controller()->prepare()->getStatus());
	}//end testAMalformedYearIsABadRequest()

	/**
	 * Signed out is 401.
	 *
	 * @return void
	 */
	public function testSignedOutIsUnauthorised(): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;
		$this->batches->expects($this->never())->method('prepare');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller()->prepare()->getStatus());
	}//end testSignedOutIsUnauthorised()

	/**
	 * Set the caller's roles.
	 *
	 * @param bool $admin   Instance administrator.
	 * @param bool $officer Member of the payroll officer group.
	 *
	 * @return void
	 */
	private function officer(bool $admin, bool $officer): void {
		$this->groups->method('isAdmin')->with('officer')->willReturn($admin);
		$this->groups->method('isInGroup')->with('officer', Ib47Controller::PAYROLL_OFFICER_GROUP)->willReturn($officer);
	}//end officer()

	/**
	 * The controller over the doubles.
	 *
	 * @return Ib47Controller
	 */
	private function controller(): Ib47Controller {
		return new Ib47Controller(
			request: $this->request,
			batches: $this->batches,
			context: $this->context,
			userSession: $this->session,
			groups: $this->groups
		);
	}//end controller()
}//end class
