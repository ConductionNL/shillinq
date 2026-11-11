<?php

/**
 * Shillinq Finance Leaf
 *
 * What every read-only finance leaf shillinq serves from its own register
 * answers alike: it sorts under Finance, needs shillinq installed, stores
 * app-locally without an OpenConnector source, is always enabled, needs no
 * permission or credentials of its own to read, and reports itself healthy
 * under its own leaf id.
 *
 * @category Integration
 * @package  OCA\Shillinq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Integration;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;

/**
 * The shared answers of a read-only finance leaf in shillinq's own register.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 */
abstract class ShillinqFinanceLeaf implements IntegrationProvider {

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string The group.
	 */
	public function getGroup(): string {
		return 'Finance';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 */
	public function getRequiredApp(): string {
		return 'shillinq';
	}//end getRequiredApp()

	/**
	 * The records live in shillinq's own register.
	 *
	 * @return string The storage strategy.
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever shillinq is installed.
	 *
	 * @return bool True.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Reading needs no permission of its own; what the caller may read is
	 * OpenRegister's answer.
	 *
	 * @return string|null Null.
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health report.
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => $this->getId()];
	}//end health()
}//end class
