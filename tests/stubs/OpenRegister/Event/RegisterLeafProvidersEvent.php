<?php

/**
 * Test stub for OpenRegister's RegisterLeafProvidersEvent.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;

class RegisterLeafProvidersEvent extends Event {
	/**
	 * @var array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
	 */
	private array $leaves = [];

	public function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider = null): void {
		$this->leaves[] = ['descriptor' => $descriptor, 'provider' => $provider];
	}//end registerLeaf()

	/**
	 * @return array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
	 */
	public function getLeaves(): array {
		return $this->leaves;
	}//end getLeaves()
}//end class
