<?php

/**
 * Test stub for OpenRegister's IntegrationProvider contract.
 *
 * Mirrors the real interface at
 * openregister/lib/Service/Integration/IntegrationProvider.php so unit tests can
 * load shillinq's leaf providers without OpenRegister installed. Keep the method
 * set in step with the real one: a stub that omits a method lets a provider
 * compile here and fatal in production.
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

interface IntegrationProvider {
	public function getId(): string;

	public function getLabel(): string;

	public function getIcon(): string;

	public function getGroup(): ?string;

	public function getRequiredApp(): ?string;

	public function getStorageStrategy(): string;

	public function getOpenConnectorSource(): ?string;

	public function isEnabled(): bool;

	public function requiresPermission(): ?string;

	/**
	 * @return array<string, mixed>
	 */
	public function authRequirements(): array;

	/**
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int|string, mixed>
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array;

	/**
	 * @return array<string, mixed>
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array;

	/**
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<string, mixed>
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array;

	/**
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<string, mixed>
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

	public function delete(string $register, string $schema, string $objectId, string $entityId): void;

	/**
	 * @return array<string, mixed>
	 */
	public function health(): array;
}//end interface
