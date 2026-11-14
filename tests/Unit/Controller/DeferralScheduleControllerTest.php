<?php

/**
 * Unit tests for DeferralScheduleController.
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
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DeferralScheduleController;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Exception\DeferralRefusedException;
use OCA\Shillinq\Service\Deferral\DeferralService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Controller\DeferralScheduleController
 * @uses   \OCA\Shillinq\Kernel\Exception\DeferralRefusedException
 */
final class DeferralScheduleControllerTest extends TestCase {

	/**
	 * The service.
	 *
	 * @var DeferralService&MockObject
	 */
	private DeferralService&MockObject $deferrals;

	/**
	 * The administrations the caller may access.
	 *
	 * @var list<string>
	 */
	private array $accessible = ['adm-consultancy-nl'];

	/**
	 * Whether a user is signed in.
	 *
	 * @var bool
	 */
	private bool $signedIn = true;

	/**
	 * The request body of the licence.
	 *
	 * @var array<string,mixed>
	 */
	private const BODY = [
		'administrationId' => 'adm-consultancy-nl',
		'sourceReference' => 'ap-invoice:INK-2026-0007#line-1',
		'description' => 'Jaarlicentie',
		'kind' => 'prepaid-cost',
		'resultAccount' => '4300',
		'balanceAccount' => '1900',
		'totalCents' => 120000,
		'periodFrom' => '2026-01-01',
		'periodTo' => '2026-12-31',
	];

	/**
	 * Fresh stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->deferrals  = $this->createMock(DeferralService::class);
		$this->accessible = ['adm-consultancy-nl'];
		$this->signedIn   = true;

	}//end setUp()

	/**
	 * The controller over a request body.
	 *
	 * @param array<string,mixed> $body The body.
	 *
	 * @return DeferralScheduleController
	 */
	private function controller(array $body): DeferralScheduleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($body[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->signedIn === true ? $this->createMock(IUser::class) : null);

		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(fn (string $id): bool => in_array($id, $this->accessible, true));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:' . $text);

		return new DeferralScheduleController($request, $this->deferrals, $context, $session, $l10n, $this->createMock(LoggerInterface::class));

	}//end controller()

	public function testCreatePassesTheBodyAndAnswers201WithTheSchedule(): void {
		$this->deferrals->expects($this->once())->method('spread')->with(self::BODY)->willReturn(['id' => 's-1', 'state' => 'active']);

		$response = $this->controller(self::BODY + ['extra' => 'ignored'])->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['data' => ['id' => 's-1', 'state' => 'active']], $response->getData());

	}//end testCreatePassesTheBodyAndAnswers201WithTheSchedule()

	public function testPreviewAnswers200AndCreatesNothing(): void {
		$this->deferrals->expects($this->never())->method('spread');
		$this->deferrals->expects($this->once())->method('preview')->with(self::BODY)->willReturn(['releases' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller(self::BODY)->preview()->getStatus());

	}//end testPreviewAnswers200AndCreatesNothing()

	public function testMissingAndNonScalarFieldsReachTheServiceEmpty(): void {
		$this->deferrals->expects($this->once())->method('preview')
			->with(['kind' => '', 'totalCents' => ''] + self::BODY)
			->willReturn([]);

		$this->controller(['kind' => ['prepaid-cost'], 'totalCents' => null] + self::BODY)->preview();

	}//end testMissingAndNonScalarFieldsReachTheServiceEmpty()

	public function testARefusalIsTranslatedWithItsStatus(): void {
		$this->deferrals->method('spread')->willThrowException(new DeferralRefusedException(message: 'This invoice line is already spread', status: DeferralRefusedException::CONFLICT));

		$response = $this->controller(self::BODY)->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'NL:This invoice line is already spread'], $response->getData());

	}//end testARefusalIsTranslatedWithItsStatus()

	public function testAnUnexpectedFailureIsAGeneric500(): void {
		$this->deferrals->method('spread')->willThrowException(new \RuntimeException('db detail'));

		$response = $this->controller(self::BODY)->create();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'NL:The invoice line could not be spread.'], $response->getData());

	}//end testAnUnexpectedFailureIsAGeneric500()

	public function testAnAnonymousCallerIs401AndNothingIsWritten(): void {
		$this->signedIn = false;
		$this->deferrals->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(self::BODY)->create()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(self::BODY)->preview()->getStatus());

	}//end testAnAnonymousCallerIs401AndNothingIsWritten()

	public function testAForeignOrMissingAdministrationIs404AndNothingIsWritten(): void {
		$this->deferrals->expects($this->never())->method($this->anything());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['administrationId' => 'adm-other'] + self::BODY)->create()->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['administrationId' => ''] + self::BODY)->create()->getStatus());

	}//end testAForeignOrMissingAdministrationIs404AndNothingIsWritten()
}//end class
