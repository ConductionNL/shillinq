<?php

/**
 * Unit tests for IntegriqSbrHandoffAdapter: a filing handed to integriq.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Digipoort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Digipoort;

use InvalidArgumentException;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use OCA\Shillinq\Service\External\Digipoort\IntegriqSbrHandoffAdapter;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(IntegriqSbrHandoffAdapter::class)]
#[UsesClass(DigipoortSubmissionResult::class)]
final class IntegriqSbrHandoffAdapterTest extends TestCase {

	private const PAYLOAD = [
		'objectUri' => 'openregister://shillinq/XbrlInstance/xbrl-1',
		'filingType' => 'vat-return',
		'recipient' => 'belastingdienst',
		'payloadFileUri' => 'nextcloud://files/42',
		'administrationId' => 'adm-1',
		'correlationId' => 'corr-1',
		'xbrlChecksum' => 'abc',
	];

	public function testItEmitsTheFilingRequestWithTheProposedPayloadAndAnswersPending(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatch')->with(
			'nl.conduction.sbr.filing.requested',
			$this->callback(static fn (GenericEvent $event): bool => $event->getArguments() === [
				'sourceApp' => 'shillinq',
				'objectType' => 'xbrl-instance',
				'objectUri' => 'openregister://shillinq/XbrlInstance/xbrl-1',
				'filingType' => 'vat-return',
				'recipient' => 'belastingdienst',
				'payloadFileUri' => 'nextcloud://files/42',
				'administrationId' => 'adm-1',
				'sourceSlug' => 'digipoort-sbr',
			])
		);

		$result = (new IntegriqSbrHandoffAdapter($dispatcher))->submit(self::PAYLOAD);

		$this->assertSame('PENDING', $result->deliveryStatus);
		$this->assertSame('corr-1', $result->reference);
		$this->assertFalse($result->dormant);
		$this->assertSame(['event' => 'nl.conduction.sbr.filing.requested', 'sourceSlug' => 'digipoort-sbr'], $result->extras);
	}

	public function testWithoutACorrelationIdTheObjectUriIsTheReference(): void {
		$payload = self::PAYLOAD;
		unset($payload['correlationId']);

		$this->assertSame(
			'openregister://shillinq/XbrlInstance/xbrl-1',
			(new IntegriqSbrHandoffAdapter($this->createMock(IEventDispatcher::class)))->submit($payload)->reference
		);
	}

	public function testAnIncompleteFilingIsNotEmitted(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatch');
		$payload = self::PAYLOAD;
		$payload['payloadFileUri'] = ' ';

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The SBR filing cannot be handed off without payloadFileUri.');

		(new IntegriqSbrHandoffAdapter($dispatcher))->submit($payload);
	}

	public function testAFailedDispatchReachesTheCaller(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatch')->willThrowException(new RuntimeException('bus down'));

		$this->expectExceptionMessage('bus down');

		(new IntegriqSbrHandoffAdapter($dispatcher))->submit(self::PAYLOAD);
	}

	public function testItIsNeverDormantAndContactsNoHostItself(): void {
		$this->assertFalse((new IntegriqSbrHandoffAdapter($this->createMock(IEventDispatcher::class)))->isDormant());

		$source = (string) file_get_contents(__DIR__ . '/../../../../../lib/Service/External/Digipoort/IntegriqSbrHandoffAdapter.php');
		foreach (['IClientService', 'curl_', 'digipoort.procesinfrastructuur', 'http://', 'https://'] as $forbidden) {
			$this->assertStringNotContainsString($forbidden, preg_replace('/^.*@(license|link).*$/m', '', $source));
		}
	}
}
