<?php

/**
 * Tests for the OpenRegister autoload prelude.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use OCA\Shillinq\AppInfo\OpenRegisterAutoloader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The prelude must work on Nextcloud 35, which removed the private
 * `OC_App::registerAutoloading()` it used to call, so it may only use public
 * API and plain PHP — and it must never throw.
 */
class OpenRegisterAutoloaderTest extends TestCase {

	/**
	 * Temporary fake openregister app directory.
	 *
	 * @var string
	 */
	private string $appPath;

	/**
	 * Create a fake openregister app with one class under lib/.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appPath = sys_get_temp_dir().'/shillinq-or-'.bin2hex(random_bytes(4));
		mkdir($this->appPath.'/lib/Fake', 0777, true);
		file_put_contents(
			$this->appPath.'/lib/Fake/Probe.php',
			"<?php\nnamespace OCA\\OpenRegister\\Fake;\nfinal class Probe {}\n"
		);

	}//end setUp()

	/**
	 * Take the loader off the SPL chain and remove the fake app.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		OpenRegisterAutoloader::unregister();
		@unlink($this->appPath.'/lib/Fake/Probe.php');
		@rmdir($this->appPath.'/lib/Fake');
		@rmdir($this->appPath.'/lib');
		@rmdir($this->appPath);
		parent::tearDown();

	}//end tearDown()

	/**
	 * The prelude source must not touch the private OC_App class.
	 *
	 * @return void
	 */
	public function testSourceUsesNoPrivateOcAppApi(): void {
		$source = (string) file_get_contents(__DIR__.'/../../../lib/AppInfo/OpenRegisterAutoloader.php');
		$code   = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$this->assertStringNotContainsString(needle: 'OC_App', haystack: $code);

	}//end testSourceUsesNoPrivateOcAppApi()

	/**
	 * Enabled OpenRegister gets a working, idempotent PSR-4 prefix.
	 *
	 * @return void
	 */
	public function testRegistersPsr4PrefixWhenEnabled(): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('openregister')->willReturn(true);
		$appManager->method('getAppPath')->with('openregister')->willReturn($this->appPath.'/');

		$this->assertTrue(condition: OpenRegisterAutoloader::register(appManager: $appManager));
		$this->assertTrue(condition: OpenRegisterAutoloader::register(appManager: $appManager));
		$this->assertTrue(condition: class_exists('OCA\\OpenRegister\\Fake\\Probe'));

	}//end testRegistersPsr4PrefixWhenEnabled()

	/**
	 * Disabled OpenRegister is the quiet degraded path.
	 *
	 * @return void
	 */
	public function testReturnsFalseWhenDisabled(): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(false);
		$appManager->expects($this->never())->method('getAppPath');

		$this->assertFalse(condition: OpenRegisterAutoloader::register(appManager: $appManager));

	}//end testReturnsFalseWhenDisabled()

	/**
	 * Any failure is swallowed and reported as false.
	 *
	 * @return void
	 */
	public function testNeverThrows(): void {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willThrowException(new \RuntimeException('boom'));

		$this->assertFalse(condition: OpenRegisterAutoloader::register(appManager: $appManager));

	}//end testNeverThrows()

	/**
	 * The loader never answers for classes that are not OpenRegister's.
	 *
	 * @return void
	 */
	public function testClassFileOnlyAnswersForOpenRegister(): void {
		$this->assertSame(
			expected: '/x/lib/Db/Schema.php',
			actual: OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\OpenRegister\\Db\\Schema')
		);
		$this->assertNull(actual: OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\Shillinq\\Foo'));
		$this->assertNull(actual: OpenRegisterAutoloader::classFile(appPath: '/x', class: 'OCA\\OpenRegister\\'));

	}//end testClassFileOnlyAnswersForOpenRegister()
}//end class
