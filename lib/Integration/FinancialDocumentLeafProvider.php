<?php

/**
 * Financial Document Leaf Provider
 *
 * The data half of `shillinq-financial-documents`: every quote, invoice,
 * credit note, payment request and payout whose `linkedObjects` hold the host
 * object, in one row shape, newest first. A document the caller may not read
 * is still counted, with its kind, status and payment state only, so a case
 * never reads "nothing owed" while an invoice the handler cannot open is
 * outstanding.
 *
 * The leaf reads. Creating and paying documents are acts with their own
 * grants and arrive with the panel.
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
use OCA\Shillinq\Service\FinancialDocumentFinder;
use OCA\Shillinq\Service\FinancialDocumentRows;
use RuntimeException;

/**
 * Lists the financial documents linked to a host object.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 */
final class FinancialDocumentLeafProvider implements IntegrationProvider {
	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'shillinq-financial-documents';

	/**
	 * Constructor.
	 *
	 * @param FinancialDocumentFinder $finder Reads the documents linked to an object.
	 * @param FinancialDocumentRows $rows Turns a document into a row.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FinancialDocumentFinder $finder,
		private readonly FinancialDocumentRows $rows,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getId(): string {
		return self::LEAF_ID;
	}//end getId()

	/**
	 * The label shown on the leaf.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getLabel(): string {
		return 'Financial documents';
	}//end getLabel()

	/**
	 * The MDI icon name.
	 *
	 * @return string The icon.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getIcon(): string {
		return 'FileDocumentMultipleOutline';
	}//end getIcon()

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string The group.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getGroup(): string {
		return 'Finance';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getRequiredApp(): string {
		return 'shillinq';
	}//end getRequiredApp()

	/**
	 * The documents are shillinq's own records.
	 *
	 * @return string The storage strategy.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever shillinq is installed.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Reading needs no action of its own; what the caller may read is OpenRegister's answer.
	 *
	 * @return string|null The action.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * Every financial document on the host object, newest first.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $filters Optional list filters; unknown keys are ignored.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor}` envelope.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $filters is IntegrationProvider's;
	 * the panel narrows by kind itself.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$items = [];
		foreach ($this->finder->onObject(register: $register, schema: $schema, objectId: $objectId) as $found) {
			$row = $this->rows->row(schema: $found['schema'], document: $found['document']);
			if ($found['readable'] === false) {
				$row = $this->rows->restricted(row: $row);
			}

			$items[] = $row;
		}

		usort($items, static fn (array $left, array $right): int => strcmp((string)$right['issuedOn'], (string)$left['issuedOn']));

		return ['items' => $items, 'total' => count($items), 'nextCursor' => null];
	}//end list()

	/**
	 * One document on the host object that the caller may read.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The document id.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @throws RuntimeException When no readable document with that id stands on this object.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		foreach ($this->list(register: $register, schema: $schema, objectId: $objectId)['items'] as $row) {
			if ($row['id'] === $entityId && $row['restricted'] === false) {
				return $row;
			}
		}

		throw new RuntimeException('404 No financial document with that id is readable behind this object.');
	}//end get()

	/**
	 * Creating a document is an act with its own grant, not a leaf write.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is IntegrationProvider's.
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		throw new RuntimeException('The financial documents leaf reads. A document is created through its own act in shillinq.');
	}//end create()

	/**
	 * A document is changed in shillinq, where its lifecycle and audit trail are.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The document id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is IntegrationProvider's.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new RuntimeException('A financial document is changed in shillinq, where its lifecycle and its audit trail are.');
	}//end update()

	/**
	 * A financial document is credited or voided, never deleted from an object.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The document id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is IntegrationProvider's.
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new RuntimeException('A financial document is credited or voided, never deleted from an object.');
	}//end delete()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => self::LEAF_ID];
	}//end health()
}//end class
