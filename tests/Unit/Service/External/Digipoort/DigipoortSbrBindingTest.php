<?php

/**
 * Unit tests for DigipoortSbrBinding: which adapter files.
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

use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrBinding;
use OCA\Shillinq\Service\External\Digipoort\IntegriqSbrHandoffAdapter;
use OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(DigipoortSbrBinding::class)]
#[UsesClass(IntegriqSbrHandoffAdapter::class)]
#[UsesClass(LogDigipoortSbrAdapter::class)]
final class DigipoortSbrBindingTest extends TestCase {

	private function container(bool $integriq): ContainerInterface {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('isEnabledForAnyone')->with('integriq')->willReturn($integriq);
		$adapters = [
			IAppManager::class => $apps,
			IntegriqSbrHandoffAdapter::class => new IntegriqSbrHandoffAdapter($this->createMock(IEventDispatcher::class)),
			LogDigipoortSbrAdapter::class => new LogDigipoortSbrAdapter($this->createMock(LoggerInterface::class)),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $adapters[$id]);

		return $container;
	}

	public function testWithIntegriqTheHandOffAnswers(): void {
		$adapter = (new DigipoortSbrBinding())->adapter($this->container(true));

		$this->assertInstanceOf(IntegriqSbrHandoffAdapter::class, $adapter);
		$this->assertFalse($adapter->isDormant());
	}

	public function testWithoutIntegriqTheLogOnlyAdapterAnswers(): void {
		$adapter = (new DigipoortSbrBinding())->adapter($this->container(false));

		$this->assertInstanceOf(LogDigipoortSbrAdapter::class, $adapter);
		$this->assertTrue($adapter->isDormant());
	}
}
