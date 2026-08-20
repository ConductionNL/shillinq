<?php

/**
 * Unit tests for DeadlineCalendarSettingsController.
 *
 * Covers REQ-CDC-006: the settings surface reads/writes ONLY the
 * session user's preferences (no IDOR surface), rejects anonymous
 * callers, validates the payload, and triggers an immediate fail-soft
 * re-publication so a disabled category's VEVENTs are removed.
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
 * @spec openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DeadlineCalendarSettingsController;
use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the per-user deadline-calendar settings surface (REQ-CDC-006).
 */
class DeadlineCalendarSettingsControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock calendar service.
	 *
	 * @var ComplianceDeadlineCalendarService&MockObject
	 */
	private ComplianceDeadlineCalendarService&MockObject $calendarService;

	/**
	 * The controller under test.
	 *
	 * @var DeadlineCalendarSettingsController
	 */
	private DeadlineCalendarSettingsController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->calendarService = $this->createMock(ComplianceDeadlineCalendarService::class);
		$logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->controller = new DeadlineCalendarSettingsController(
			request: $this->request,
			userSession: $this->userSession,
			calendarService: $this->calendarService,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Put a session user in place.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function actAs(string $uid): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$user = $this->createMock(IUser::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end actAs()

	/**
	 * Anonymous callers are rejected with 401 on both endpoints.
	 *
	 * @return void
	 */
	public function testAnonymousIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->update()->getStatus());

	}//end testAnonymousIsRejected()

	/**
	 * index() returns the session user's category settings — the user id
	 * is taken from the session ONLY (no request parameter, no IDOR).
	 *
	 * @return void
	 */
	public function testIndexReadsOnlyTheSessionUser(): void {
		$this->actAs(uid: 'alice');

		$seenUserIds = [];
		$this->calendarService->method('isCategoryEnabled')->willReturnCallback(
			function (string $userId, string $category) use (&$seenUserIds): bool {
				$seenUserIds[] = $userId;
				return $category !== 'ar-due';
			}
		);
		$this->calendarService->method('leadTimeDays')->willReturn(7);

		$response = $this->controller->index();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['alice'], array_values(array_unique($seenUserIds)));
		self::assertArrayHasKey('categories', $data);
		self::assertTrue($data['categories']['filing']['enabled']);
		self::assertFalse($data['categories']['ar-due']['enabled']);
		self::assertSame(7, $data['categories']['contract']['leadDays']);

	}//end testIndexReadsOnlyTheSessionUser()

	/**
	 * update() writes only known categories for the session user and
	 * triggers an immediate re-publication (REQ-CDC-006 removal).
	 *
	 * @return void
	 */
	public function testUpdateSavesTogglesAndRepublishes(): void {
		$this->actAs(uid: 'alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key) {
				if ($key === 'categories') {
					return [
						'payment-run' => [
							'enabled' => false,
							'leadDays' => 5,
						],
						'bogus' => ['enabled' => true],
					];
				}

				return null;
			}
		);

		$toggles = [];
		$this->calendarService->method('setCategoryEnabled')->willReturnCallback(
			function (string $userId, string $category, bool $enabled) use (&$toggles): void {
				$toggles[] = [$userId, $category, $enabled];
			}
		);
		$leads = [];
		$this->calendarService->method('setLeadTimeDays')->willReturnCallback(
			function (string $userId, string $category, int $days) use (&$leads): void {
				$leads[] = [$userId, $category, $days];
			}
		);
		$this->calendarService->expects(self::once())
			->method('publishForUser')
			->with(self::identicalTo('alice'))
			->willReturn(
				[
					'status' => 'ok',
					'published' => 0,
					'removed' => 2,
				]
			);
		$this->calendarService->method('isCategoryEnabled')->willReturn(true);
		$this->calendarService->method('leadTimeDays')->willReturn(7);

		$response = $this->controller->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		// Only the known category was written, for the session user.
		self::assertSame([['alice', 'payment-run', false]], $toggles);
		self::assertSame([['alice', 'payment-run', 5]], $leads);
		self::assertSame('ok', $response->getData()['publication']);

	}//end testUpdateSavesTogglesAndRepublishes()

	/**
	 * update() rejects a non-object categories payload with 400.
	 *
	 * @return void
	 */
	public function testUpdateRejectsMalformedPayload(): void {
		$this->actAs(uid: 'alice');
		$this->request->method('getParam')->willReturn('not-an-object');

		$response = $this->controller->update();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testUpdateRejectsMalformedPayload()

	/**
	 * A degraded (calendar-less) publication still saves the toggles —
	 * update() responds 200 with publication 'failed' (fail-soft).
	 *
	 * @return void
	 */
	public function testUpdateFailSoftWhenPublicationDegrades(): void {
		$this->actAs(uid: 'alice');
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key) {
				if ($key === 'categories') {
					return ['filing' => ['enabled' => true]];
				}

				return null;
			}
		);
		$this->calendarService->method('publishForUser')->willReturn(
			[
				'status' => 'failed',
				'published' => 0,
				'removed' => 0,
			]
		);
		$this->calendarService->method('isCategoryEnabled')->willReturn(true);
		$this->calendarService->method('leadTimeDays')->willReturn(10);

		$response = $this->controller->update();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('failed', $response->getData()['publication']);

	}//end testUpdateFailSoftWhenPublicationDegrades()
}//end class
