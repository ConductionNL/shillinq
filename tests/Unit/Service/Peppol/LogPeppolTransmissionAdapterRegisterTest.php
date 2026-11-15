<?php

/**
 * Unit tests for the register and row selection of LogPeppolTransmissionAdapter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Peppol;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which register the participant lookup reads, and which row's participant id it takes.
 *
 * @covers \OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LogPeppolTransmissionAdapterRegisterTest extends TestCase {

	/**
	 * Registers passed to setRegister, in order.
	 *
	 * @var array<int,string>
	 */
	private array $registers = [];

	/**
	 * An adapter whose object service answers the given rows for every schema.
	 *
	 * @param string           $configured The configured register value.
	 * @param array<int,mixed> $rows       The rows findAll answers.
	 *
	 * @return LogPeppolTransmissionAdapter
	 */
	private function adapter(string $configured, array $rows): LogPeppolTransmissionAdapter {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use ($objectService): ObjectServiceInterface {
				$this->registers[] = $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($rows);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($configured);

		return new LogPeppolTransmissionAdapter(objectService: $objectService, appConfig: $appConfig, logger: new NullLogger());

	}//end adapter()

	/**
	 * A configured register is the one the lookup reads.
	 *
	 * @return void
	 */
	public function testTheConfiguredRegisterIsRead(): void {
		$this->adapter(configured: 'books', rows: [])->lookupParticipant('adm-1', 'DEB-1');

		self::assertSame(['books', 'books'], $this->registers);

	}//end testTheConfiguredRegisterIsRead()

	/**
	 * An empty configured register falls back to the app's own register.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterFallsBackToShillinq(): void {
		$this->adapter(configured: '', rows: [])->lookupParticipant('adm-1', 'DEB-1');

		self::assertSame(['shillinq', 'shillinq'], $this->registers);

	}//end testAnEmptyRegisterFallsBackToShillinq()

	/**
	 * Non-array rows and blank participant ids are passed over; the first real id is trimmed.
	 *
	 * @return void
	 */
	public function testTheFirstNonBlankParticipantIdIsTaken(): void {
		$result = $this->adapter(
			configured: 'shillinq',
			rows: ['junk', ['peppolParticipantId' => '  '], ['name' => 'no id'], ['peppolParticipantId' => ' 0106:12345678 '], ['peppolParticipantId' => '0106:99']]
		)->lookupParticipant('adm-1', 'DEB-1');

		self::assertSame('0106:12345678', $result);
		self::assertSame(['shillinq'], $this->registers);

	}//end testTheFirstNonBlankParticipantIdIsTaken()

}//end class
