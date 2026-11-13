<?php

/**
 * Unit tests for CashflowRecurringGuard's fail-closed and Contract-lookup edges.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Guard\CashflowRecurringGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Shillinq\Guard\CashflowRecurringGuard
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class CashflowRecurringGuardFailClosedTest extends TestCase {

	/** @var LoggerInterface&MockObject Receives the denials and errors. */
	private LoggerInterface&MockObject $logger;

	/** @var ObjectServiceInterface&MockObject The Contract lookup. */
	private ObjectServiceInterface&MockObject $objects;

	protected function setUp(): void {
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setSchema')->willReturnSelf();
	}

	private function guard(string $register = 'shillinq'): CashflowRecurringGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);
		return new CashflowRecurringGuard(appConfig: $appConfig, logger: $this->logger, objectService: $this->objects);
	}

	/**
	 * A valid monthly item linked to a Contract.
	 *
	 * @return array<string, mixed>
	 */
	private function linked(): array {
		return [
			'recurId' => 'rec-1',
			'standardAmount' => 100,
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
			'validFrom' => '2026-01-01',
			'validTo' => '2026-12-31',
			'contractReference' => 'contract-9',
		];
	}

	public function testAnUnexpectedErrorDeniesTheSaveAndIsLoggedWithTheRecurId(): void {
		$this->logger->expects(self::once())->method('error')->with(
			'CashflowRecurringGuard: validateOnSave failed — denying save (fail-closed)',
			self::callback(fn (array $context): bool => $context['recurId'] === 'rec-err' && $context['exception'] !== '')
		);

		$allowed = $this->guard()->validateOnSave(['recurId' => 'rec-err', 'standardAmount' => 10, 'frequency' => new stdClass()]);

		self::assertFalse($allowed);
	}

	public function testAContractLookupThatThrowsImposesNoBound(): void {
		$this->objects->method('setRegister')->willThrowException(new RuntimeException('register gone'));
		$this->objects->expects(self::never())->method('find');
		$this->logger->expects(self::never())->method('error');

		self::assertTrue($this->guard()->validateOnSave($this->linked()));
	}

	public function testAContractThatDoesNotSerialiseToAnArrayImposesNoBound(): void {
		$contract = $this->createMock(ObjectEntityInterface::class);
		$contract->method('jsonSerialize')->willReturn('contract-9');
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->expects(self::once())->method('find')->with('contract-9')->willReturn($contract);

		self::assertTrue($this->guard()->validateOnSave($this->linked()));
	}

	public function testAnEmptyRegisterSettingFallsBackToTheShillinqRegister(): void {
		$contract = $this->createMock(ObjectEntityInterface::class);
		$contract->method('jsonSerialize')->willReturn(['startDate' => '2026-06-01', 'endDate' => null]);
		$this->objects->expects(self::once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$this->objects->expects(self::once())->method('setSchema')->with('Contract')->willReturnSelf();
		$this->objects->method('find')->willReturn($contract);

		// The item's validFrom 2026-01-01 precedes the Contract's startDate, so the found Contract is applied.
		self::assertFalse($this->guard(register: '')->validateOnSave($this->linked()));
	}
}
