<?php

/**
 * Unit tests for HandToDigipoortAction: submit hands the filing to integriq.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Lifecycle\Action\HandToDigipoortAction;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult;
use OCA\Shillinq\Service\Sbr\SbrFilingFileStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HandToDigipoortAction::class)]
#[UsesClass(DigipoortSubmissionResult::class)]
final class HandToDigipoortActionTest extends TestCase {

	private const INSTANCE = [
		'id' => 'xbrl-1',
		'administrationId' => 'adm-1',
		'instanceNumber' => 'OB-BTW-2026-Q3',
		'entryPoint' => 'belastingdienst-ob',
		'filingType' => 'vat-return',
		'instanceXml' => '<xbrli:xbrl/>',
		'instanceHash' => 'hash-1',
		'taxonomyVersion' => 'NT20',
		'rejectionReason' => 'earlier failure',
		'state' => 'handed-off',
	];

	private function location(): RegisterLocationInterface {
		$location = $this->createMock(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		return $location;
	}

	public function testWithIntegriqTheFileIsStoredAndHandedOff(): void {
		$adapter = $this->createMock(DigipoortSbrAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(false);
		$adapter->expects($this->once())->method('submit')->with([
			'objectUri' => 'openregister://shillinq/XbrlInstance/xbrl-1',
			'filingType' => 'vat-return',
			'recipient' => 'belastingdienst',
			'payloadFileUri' => 'nextcloud://files/42',
			'administrationId' => 'adm-1',
			'correlationId' => 'openregister://shillinq/XbrlInstance/xbrl-1',
			'xbrlChecksum' => 'hash-1',
			'taxonomyVersion' => 'NT20',
		])->willReturn(new DigipoortSubmissionResult('PENDING', 'corr-1', false));
		$files = $this->createMock(SbrFilingFileStore::class);
		$files->expects($this->once())->method('store')->with('adm-1', 'OB-BTW-2026-Q3.xbrl', '<xbrli:xbrl/>')->willReturn('nextcloud://files/42');

		$result = (new HandToDigipoortAction($adapter, $files, $this->location()))->execute(self::INSTANCE, [], [], 'hand-to-digipoort');

		$this->assertSame('nextcloud://files/42', $result['payloadFileUri']);
		$this->assertSame('digipoort-sbr', $result['digipoortSourceSlug']);
		$this->assertSame('corr-1', $result['handOffReference']);
		$this->assertSame('', $result['rejectionReason']);
		$this->assertSame('handed-off', $result['state']);
		$this->assertArrayNotHasKey('digipoortReceiptId', $result, 'only the delivery report records the Digipoort reference');
	}

	public function testWithTheLogOnlyAdapterTheTransitionIsRefusedAndNothingIsStored(): void {
		$adapter = $this->createMock(DigipoortSbrAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(true);
		$adapter->expects($this->never())->method('submit');
		$files = $this->createMock(SbrFilingFileStore::class);
		$files->expects($this->never())->method('store');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('SBR filing is not configured');

		(new HandToDigipoortAction($adapter, $files, $this->location()))->execute(self::INSTANCE, [], [], 'hand-to-digipoort');
	}

	public function testAnInstanceWithoutXmlIsNotHandedOff(): void {
		$adapter = $this->createMock(DigipoortSbrAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(false);
		$adapter->expects($this->never())->method('submit');

		$this->expectExceptionMessage('The XBRL instance has no document to file.');

		(new HandToDigipoortAction($adapter, $this->createMock(SbrFilingFileStore::class), $this->location()))
			->execute(['instanceXml' => ' '] + self::INSTANCE, [], [], 'hand-to-digipoort');
	}

	public function testAKvkFilingGoesToTheKvkAndAnUnnumberedOneIsNamedByItsId(): void {
		$adapter = $this->createMock(DigipoortSbrAdapterInterface::class);
		$adapter->method('isDormant')->willReturn(false);
		$adapter->expects($this->once())->method('submit')->with($this->callback(static fn (array $p): bool => $p['recipient'] === 'kvk' && $p['xbrlChecksum'] === hash('sha256', '<x/>')))
			->willReturn(new DigipoortSubmissionResult('PENDING', 'r', false));
		$files = $this->createMock(SbrFilingFileStore::class);
		$files->expects($this->once())->method('store')->with('', 'xbrl-2.xbrl', '<x/>')->willReturn('nextcloud://files/7');

		(new HandToDigipoortAction($adapter, $files, $this->location()))
			->execute(['id' => 'xbrl-2', 'entryPoint' => 'kvk-jaarrekening', 'instanceXml' => '<x/>'], [], [], 'hand-to-digipoort');

		$this->assertSame('belastingdienst', HandToDigipoortAction::recipient('belastingdienst-ob'));
	}
}
