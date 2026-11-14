<?php

/**
 * Unit tests for VsoLockingValidator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VsoLockingValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the VSO year-lock check (REQ-IBA-008, task 4.3).
 *
 * Exercises the pure short-circuit branches (empty administration, non-positive
 * boekjaar) without touching the OpenRegister container. The full positive
 * path (find returns a row with vso_locked true) is exercised by the
 * controller / listener integration tests in the live phpunit.xml profile.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VsoLockingValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var VsoLockingValidator
	 */
	private VsoLockingValidator $val;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->val = new VsoLockingValidator(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Empty administrationId short-circuits to unlocked.
	 *
	 * @return void
	 */
	public function testEmptyAdministrationReturnsUnlocked(): void {
		$this->assertFalse($this->val->isYearLocked('', 2026));

	}//end testEmptyAdministrationReturnsUnlocked()

	/**
	 * Non-positive boekjaar short-circuits to unlocked.
	 *
	 * @return void
	 */
	public function testNonPositiveBoekjaarReturnsUnlocked(): void {
		$this->assertFalse($this->val->isYearLocked('adm-x', 0));
		$this->assertFalse($this->val->isYearLocked('adm-x', -1));

	}//end testNonPositiveBoekjaarReturnsUnlocked()

	/**
	 * A container failure (ObjectService not available) downgrades to
	 * "not locked" + a logged warning — fail-soft per the contract.
	 *
	 * @return void
	 */
	public function testContainerFailureFailsSoftToUnlocked(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not bound'));

		$val = new VsoLockingValidator(
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertFalse($val->isYearLocked('adm-x', 2026));

	}//end testContainerFailureFailsSoftToUnlocked()
	/**
	 * A fake ObjectService returning the given rows and recording the schema.
	 *
	 * @param mixed  $rows    What findAll returns.
	 * @param object &$filters Captures the last filters passed to findAll.
	 *
	 * @return object
	 */
	private function objectService(mixed $rows, ?array &$filters = null): object {
		return new class($rows, $filters) {
			/**
			 * @param mixed      $rows    Rows to return.
			 * @param array|null $filters Capture.
			 */
			public function __construct(private mixed $rows, private ?array &$filters) {
			}
			public function setRegister(string $r): self {
				return $this;
			}
			public function setSchema(string $s): self {
				return $this;
			}
			public function findAll(array $config = []): mixed {
				$this->filters = ($config['filters'] ?? []);
				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * The validator over the given ObjectService and configured register.
	 *
	 * @param object $objectService The OR ObjectService double.
	 * @param string $register      The configured register value.
	 *
	 * @return VsoLockingValidator
	 */
	private function validator(object $objectService, string $register = 'shillinq'): VsoLockingValidator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new VsoLockingValidator($container, $appConfig, $this->createMock(LoggerInterface::class));
	}//end validator()

	/**
	 * A row carrying vso_locked true locks the year, and the lookup is scoped.
	 *
	 * @return void
	 */
	public function testALockedRowLocksTheYear(): void {
		$val = $this->validator($this->objectService([['vso_locked' => true]], $filters));

		$this->assertTrue($val->isYearLocked('adm-x', 2026));
		$this->assertSame(
			['administrationId' => 'adm-x', 'financialYear' => 2026, 'vso_locked' => true],
			$filters
		);
	}//end testALockedRowLocksTheYear()

	/**
	 * No row, a row without the flag, and a non-array row all read as unlocked.
	 *
	 * @return void
	 */
	public function testNoLockingRowReadsAsUnlocked(): void {
		$this->assertFalse($this->validator($this->objectService([]))->isYearLocked('adm-x', 2026));
		$this->assertFalse($this->validator($this->objectService([['vso_locked' => false], 'not-an-array']))->isYearLocked('adm-x', 2026));
	}//end testNoLockingRowReadsAsUnlocked()

	/**
	 * A non-array find result reads as unlocked.
	 *
	 * @return void
	 */
	public function testANonArrayResultReadsAsUnlocked(): void {
		$this->assertFalse($this->validator($this->objectService(null))->isYearLocked('adm-x', 2026));
	}//end testANonArrayResultReadsAsUnlocked()

	/**
	 * An empty configured register still resolves (the default), so the lookup runs.
	 *
	 * @return void
	 */
	public function testEmptyRegisterConfigStillRunsTheLookup(): void {
		$val = $this->validator($this->objectService([['vso_locked' => true]]), register: '');

		$this->assertTrue($val->isYearLocked('adm-x', 2026));
	}//end testEmptyRegisterConfigStillRunsTheLookup()

}//end class
