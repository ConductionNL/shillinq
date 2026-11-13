<?php

/**
 * Unit tests for the fail-closed guards of signing and sign-off delegation.
 *
 * Shillinq never signs or approves on its own authority: with filinq absent a
 * signature request throws, and with decidiq absent a sign-off request
 * throws, both before anything is dispatched. The other signing tests
 * declare stand-ins for those apps' events, so these run in a process of
 * their own, where neither is declared.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Signing
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

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Signing\SignatureRequest;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCA\Shillinq\Service\Signing\SignoffRequest;
use OCA\Shillinq\Support\FleetAppId;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signing and sign-off refuse without the app that does the work.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(SigningDelegationService::class)]
#[CoversClass(SignoffDecisionService::class)]
#[UsesClass(SignatureRequest::class)]
#[UsesClass(SignoffRequest::class)]
#[UsesClass(FleetAppId::class)]
#[RunTestsInSeparateProcesses]
final class SigningFailsClosedTest extends TestCase {

	/**
	 * Without filinq a signature request throws, logs, and dispatches nothing.
	 *
	 * @return void
	 */
	public function testASignatureRequestWithoutFilinqThrows(): void {
		self::assertNull(FleetAppId::resolveClass('filinq', 'Event\\DocumentSigningRequestedEvent'), 'filinq is absent in this process');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('SigningDelegationService: filinq not installed — signing cannot be delegated (fail closed)');

		$service = new SigningDelegationService($this->createMock(RegisterLocation::class), $dispatcher, $logger);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('filinq is not installed; document signing request cannot be raised.');
		$service->requestSignature(['id' => 'inv-1', 'signingStatus' => 'requested']);
	}//end testASignatureRequestWithoutFilinqThrows()

	/**
	 * An already signed object stays a no-op even without filinq.
	 *
	 * @return void
	 */
	public function testAnAlreadySignedObjectNeedsNoFilinq(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');
		$service = new SigningDelegationService($this->createMock(RegisterLocation::class), $dispatcher, $this->createMock(LoggerInterface::class));

		$signed = ['id' => 'inv-1', 'signingStatus' => 'signed'];
		self::assertSame($signed, $service->requestSignature($signed));
	}//end testAnAlreadySignedObjectNeedsNoFilinq()

	/**
	 * Without decidiq a sign-off request throws, logs, and dispatches nothing.
	 *
	 * @return void
	 */
	public function testASignoffRequestWithoutDecidiqThrows(): void {
		self::assertNull(FleetAppId::resolveClass('decidiq', 'Event\\DecisionRequestedEvent'), 'decidiq is absent in this process');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects(self::never())->method('dispatchTyped');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('SignoffDecisionService: decidiq not installed — sign-off cannot be delegated (fail closed)');

		$service = new SignoffDecisionService($this->createMock(RegisterLocation::class), $dispatcher, $logger);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('decidiq is not installed; sign-off decision cannot be raised.');
		$service->requestSignoff(['id' => 'report-1', 'decisionOutcome' => 'pending'], 'annualReport');
	}//end testASignoffRequestWithoutDecidiqThrows()
}//end class
