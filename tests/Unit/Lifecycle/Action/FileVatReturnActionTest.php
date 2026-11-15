<?php

/**
 * Unit tests for FileVatReturnAction: Submit on a VAT return files its XBRL instance.
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
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle\Action;

use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Lifecycle\Action\FileVatReturnAction;
use OCA\Shillinq\Lifecycle\Action\HandToDigipoortAction;
use OCA\Shillinq\Lifecycle\PostingRefusedException;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\Sbr\VatReturnXbrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FileVatReturnAction::class)]
#[UsesClass(PostingRefusedException::class)]
final class FileVatReturnActionTest extends TestCase {

	private const RETURN = ['id' => 'ret-1', 'statusCode' => 'handed-off', 'startDate' => '2026-07-01', 'endDate' => '2026-09-30'];

	/** @var list<string> */
	private array $runs = [];

	private function adapter(bool $dormant): DigipoortSbrAdapterInterface {
		$adapter = $this->createMock(DigipoortSbrAdapterInterface::class);
		$adapter->method('isDormant')->willReturn($dormant);
		$adapter->expects($this->never())->method('submit');
		return $adapter;
	}

	private function transitions(?\Throwable $refuse = null): ObjectTransitionRunnerInterface {
		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->expects($this->never())->method('runAsSystem');
		$transitions->method('run')->willReturnCallback(function (string $objectId, string $action) use ($refuse): void {
			$this->runs[] = $objectId . ':' . $action;
			if ($refuse !== null && $action === 'validate') {
				throw $refuse;
			}
		});
		return $transitions;
	}

	private function builder(array $instance): VatReturnXbrlBuilder {
		$builder = $this->createMock(VatReturnXbrlBuilder::class);
		$builder->expects($this->once())->method('instanceFor')->with(self::RETURN)->willReturn($instance);
		return $builder;
	}

	public function testADraftInstanceIsValidatedAndSubmittedAndLinkedToTheReturn(): void {
		$action = new FileVatReturnAction($this->adapter(false), $this->builder(['id' => 'xbrl-1', 'state' => 'draft']), $this->transitions());

		$result = $action->execute(self::RETURN, ['statusCode' => 'draft'], [], 'file-vat-return');

		$this->assertSame(['xbrl-1:validate', 'xbrl-1:submit'], $this->runs);
		$this->assertSame('xbrl-1', $result['xbrlInstanceId']);
		$this->assertSame('handed-off', $result['statusCode']);
	}

	public function testAValidatedInstanceIsSubmittedAgain(): void {
		(new FileVatReturnAction($this->adapter(false), $this->builder(['id' => 'xbrl-1', 'state' => 'validated']), $this->transitions()))
			->execute(self::RETURN, [], [], 'file-vat-return');

		$this->assertSame(['xbrl-1:submit'], $this->runs);
	}

	public function testWithoutAFilingConnectionTheReturnIsRefusedAndNothingIsBuilt(): void {
		$builder = $this->createMock(VatReturnXbrlBuilder::class);
		$builder->expects($this->never())->method('instanceFor');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(HandToDigipoortAction::NOT_CONFIGURED);

		(new FileVatReturnAction($this->adapter(true), $builder, $this->transitions()))->execute(self::RETURN, [], [], 'file-vat-return');
	}

	public function testAnInstanceThatFailsValidationRefusesTheReturnWithItsReason(): void {
		$refusal = new PostingRefusedException('The required concept bd-i:ValueAddedTaxOwed is missing.');
		$action = new FileVatReturnAction($this->adapter(false), $this->builder(['id' => 'xbrl-1', 'state' => 'draft']), $this->transitions($refusal));

		try {
			$action->execute(self::RETURN, [], [], 'file-vat-return');
			$this->fail('the return was not refused');
		} catch (PostingRefusedException $e) {
			$this->assertSame($refusal, $e);
		}

		$this->assertSame(['xbrl-1:validate'], $this->runs, 'nothing is handed off');
	}

	public function testAnInstanceAlreadyBeingFiledIsNotFiledTwice(): void {
		$action = new FileVatReturnAction($this->adapter(false), $this->builder(['id' => 'xbrl-1', 'state' => 'handed-off']), $this->transitions());

		$this->expectExceptionMessage('This VAT return is already being filed (its XBRL instance is handed-off).');

		$action->execute(self::RETURN, [], [], 'file-vat-return');
	}

	public function testARejectedInstanceMustBeReopenedFirst(): void {
		$action = new FileVatReturnAction($this->adapter(false), $this->builder(['id' => 'xbrl-1', 'state' => 'rejected']), $this->transitions());

		$this->expectExceptionMessage('The XBRL instance of this VAT return is rejected; reopen and correct it first.');

		$action->execute(self::RETURN, [], [], 'file-vat-return');
	}
}
