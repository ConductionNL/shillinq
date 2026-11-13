<?php

/**
 * FleetAppId — the app-manager half: which id is installed, whether it is
 * enabled for the user, and the app path built from it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Support;

use OCA\Shillinq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Resolution against an app manager that knows some ids, and fails on others.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(FleetAppId::class)]
final class FleetAppIdInstalledAppTest extends TestCase {

	/**
	 * An app manager on which exactly $installed are installed, $enabled are
	 * enabled for the user, and asking about any id in $broken throws.
	 *
	 * @param list<string> $installed Installed ids.
	 * @param list<string> $enabled   Ids enabled for the user.
	 * @param list<string> $broken    Ids the manager cannot answer for.
	 *
	 * @return IAppManager&MockObject
	 */
	private function apps(array $installed, array $enabled = [], array $broken = []): IAppManager&MockObject {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(
			static function (string $id) use ($installed, $broken): bool {
				if (in_array($id, $broken, true) === true) {
					throw new RuntimeException('app manager down for '.$id);
				}

				return in_array($id, $installed, true);
			}
		);
		$apps->method('isEnabledForUser')->willReturnCallback(
			static function (string $id) use ($enabled, $broken): bool {
				if (in_array($id, $broken, true) === true) {
					throw new RuntimeException('app manager down for '.$id);
				}

				return in_array($id, $enabled, true);
			}
		);
		return $apps;
	}//end apps()

	public function testTheNewIdWinsWhenBothAreInstalled(): void {
		$this->assertSame('integriq', FleetAppId::resolve($this->apps(['integriq', 'openconnector']), 'integriq'));
	}//end testTheNewIdWinsWhenBothAreInstalled()

	public function testTheOldIdIsTheFallback(): void {
		$this->assertSame('docudesk', FleetAppId::resolve($this->apps(['docudesk']), 'filinq'));
	}//end testTheOldIdIsTheFallback()

	public function testACandidateTheManagerCannotAnswerForIsSkipped(): void {
		$apps = $this->apps(['doriath'], [], ['keepiq']);

		$this->assertSame('doriath', FleetAppId::resolve($apps, 'keepiq'));
		$this->assertNull(FleetAppId::resolve($this->apps([], [], ['keepiq', 'doriath']), 'keepiq'));
	}//end testACandidateTheManagerCannotAnswerForIsSkipped()

	public function testAnUnlistedAppIsLookedUpUnderItsOwnName(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('isInstalled')->with('openregister')->willReturn(true);

		$this->assertSame('openregister', FleetAppId::resolve($apps, 'openregister'));
	}//end testAnUnlistedAppIsLookedUpUnderItsOwnName()

	public function testNoInstalledCandidateResolvesToNull(): void {
		$apps = $this->apps(['openregister']);

		$this->assertNull(FleetAppId::resolve($apps, 'dossiq'));
		$this->assertFalse(FleetAppId::isInstalled($apps, 'dossiq'));
		$this->assertTrue(FleetAppId::isInstalled($this->apps(['procest']), 'dossiq'));
	}//end testNoInstalledCandidateResolvesToNull()

	public function testTheEnabledCheckRunsAgainstTheInstalledId(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'scholiq');
		$apps->expects($this->once())->method('isEnabledForUser')->with('scholiq')->willReturn(true);

		$this->assertTrue(FleetAppId::isEnabledForUser($apps, 'learniq'));
	}//end testTheEnabledCheckRunsAgainstTheInstalledId()

	public function testAnInstalledButDisabledAppIsNotEnabled(): void {
		$this->assertFalse(FleetAppId::isEnabledForUser($this->apps(['learniq']), 'learniq'));
	}//end testAnInstalledButDisabledAppIsNotEnabled()

	public function testAMissingAppIsNotEnabledAndIsNotAskedAbout(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(false);
		$apps->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse(FleetAppId::isEnabledForUser($apps, 'decidiq'));
	}//end testAMissingAppIsNotEnabledAndIsNotAskedAbout()

	public function testAnEnabledCheckThatThrowsReadsAsDisabled(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('isEnabledForUser')->willThrowException(new RuntimeException('no user session'));

		$this->assertFalse(FleetAppId::isEnabledForUser($apps, 'buildiq'));
	}//end testAnEnabledCheckThatThrowsReadsAsDisabled()

	public function testThePathUsesTheInstalledId(): void {
		$apps = $this->apps(['openconnector']);

		$this->assertSame('/apps/openconnector', FleetAppId::appPath($apps, 'integriq'));
		$this->assertSame('/apps/openconnector/api/sources', FleetAppId::appPath($apps, 'integriq', 'api/sources'));
		$this->assertSame('/apps/openconnector/api/sources', FleetAppId::appPath($apps, 'integriq', '//api/sources'));
	}//end testThePathUsesTheInstalledId()

	public function testAMissingAppHasNoPath(): void {
		$this->assertNull(FleetAppId::appPath($this->apps([]), 'thematiq', 'settings'));
	}//end testAMissingAppHasNoPath()
}//end class
