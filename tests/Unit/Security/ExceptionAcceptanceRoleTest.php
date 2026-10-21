<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ExceptionResolutionService;
use OCA\Shillinq\Service\RoleMembership;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Accepting a three-way-match exception needs the approver or controller role, checked before any write.
 */
final class ExceptionAcceptanceRoleTest extends TestCase {

	private function service(?RoleMembership $roles): ExceptionResolutionService {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('saveObject');
		return new ExceptionResolutionService(
			appConfig: $this->createMock(IAppConfig::class),
			administrationContext: $context,
			userSession: $this->createMock(IUserSession::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			roleMembership: $roles,
		);
	}

	public function testCallerWithoutTheRoleIsRefusedBeforeAnyWrite(): void {
		$roles = $this->createMock(RoleMembership::class);
		$roles->expects($this->once())->method('callerHoldsAny')->with(['approver', 'controller'])->willReturn(false);
		$this->expectExceptionObject(new RuntimeException(ExceptionResolutionService::MESSAGE_NOT_APPROVER));
		$this->service($roles)->acceptWithMotivation('adm', 'match-1', 'price agreed by phone');
	}

	public function testWithoutARoleResolverNobodyMayAccept(): void {
		$this->expectExceptionMessage(ExceptionResolutionService::MESSAGE_NOT_APPROVER);
		$this->service(null)->acceptWithMotivation('adm', 'match-1', 'price agreed by phone');
	}
}
