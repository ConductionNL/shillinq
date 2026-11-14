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
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentActionsInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentAuthoringInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentFinderInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentLeafProviderInterface;
use OCA\Shillinq\Kernel\Contracts\FinancialDocumentRowsInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentActionAuthorizerInterface;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use RuntimeException;

/**
 * Lists the financial documents linked to a host object.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 */
final class FinancialDocumentLeafProvider implements IntegrationProvider, FinancialDocumentLeafProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param FinancialDocumentFinderInterface $finder Reads the documents linked to an object.
	 * @param FinancialDocumentRowsInterface $rows Turns a document into a row.
	 * @param PaymentActionAuthorizerInterface $authorizer Which acts the caller holds, so the panel offers only those.
	 * @param FinancialDocumentActionsInterface $actions Issue an invoice and request payment of one.
	 * @param FinancialDocumentAuthoringInterface $authoring Start a quote, credit an invoice and raise a payout.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FinancialDocumentFinderInterface $finder,
		private readonly FinancialDocumentRowsInterface $rows,
		private readonly PaymentActionAuthorizerInterface $authorizer,
		private readonly FinancialDocumentActionsInterface $actions,
		private readonly FinancialDocumentAuthoringInterface $authoring,
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
	 * @return array<string, mixed> The `{items, total, nextCursor, acts}` envelope.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002, REQ-FDO-003)
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

		return [
			'items' => $items,
			'total' => count($items),
			'nextCursor' => null,
			'acts' => [
				'issueInvoice' => $this->authorizer->may(FinancialDocumentActionsInterface::ACTION_ISSUE_INVOICE),
				'requestPayment' => $this->authorizer->may(PaymentActionAuthorizerInterface::ACTION_REQUEST),
				'administerPayments' => $this->authorizer->may(PaymentActionAuthorizerInterface::ACTION_ADMINISTER),
				'createQuote' => $this->authorizer->may(FinancialDocumentAuthoringInterface::ACTION_CREATE_QUOTE),
				'creditInvoice' => $this->authorizer->may(FinancialDocumentAuthoringInterface::ACTION_CREDIT_INVOICE),
				'raisePayout' => $this->authorizer->may(FinancialDocumentAuthoringInterface::ACTION_RAISE_PAYOUT),
			],
		];
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
	 * Run one act on the host object for the signed-in caller.
	 *
	 * The payload names the act in `act`: `issueInvoice`, `createQuote` and
	 * `raisePayout` write a new document linked to the host object;
	 * `requestPayment` and `creditInvoice` act on the invoice in `invoiceId`,
	 * which must stand on this object and be readable by the caller. Each act
	 * checks its own action and the administration before it writes.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload `act` plus the act's fields.
	 *
	 * @return array<string, mixed> What the act wrote.
	 *
	 * @throws RuntimeException With the HTTP status first when the act is refused; nothing is written.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		return $this->act(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: '');
	}//end create()

	/**
	 * Run one act on the host object for an app, with nobody signed in.
	 *
	 * The same acts as create(). The app must be granted the act's action in
	 * `paymentActionApps` and be enabled; no app carries an action by default,
	 * and a signed-in administrator does not lend one. The app names the
	 * administration, reads as the system, and is recorded as `app:<appId>`
	 * (a payout records the app as its source app).
	 *
	 * This method is not part of IntegrationProvider, so OpenRegister's HTTP
	 * route never reaches it: the app id comes from PHP in the calling app,
	 * never from a request body.
	 *
	 * @param string $appId The calling app's id, for example `dossiq`.
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload `act` plus the act's fields, with `administrationId`.
	 *
	 * @return array<string, mixed> What the act wrote.
	 *
	 * @throws RuntimeException With the HTTP status first when the app or the act is refused; nothing is written.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-005)
	 */
	public function createAsApp(string $appId, string $register, string $schema, string $objectId, array $payload): array {
		if ($appId === '') {
			throw new RuntimeException('403 An app acts under its own id.');
		}

		return $this->act(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId);
	}//end createAsApp()

	/**
	 * Dispatch one act, as the caller or as an app.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload `act` plus the act's fields.
	 * @param string $appId The acting app, or '' for the signed-in caller.
	 *
	 * @return array<string, mixed> What the act wrote.
	 *
	 * @throws RuntimeException With the HTTP status first when the act is refused.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-005)
	 */
	private function act(string $register, string $schema, string $objectId, array $payload, string $appId): array {
		$act = (string)($payload['act'] ?? '');
		unset($payload['act']);

		try {
			return match ($act) {
				'issueInvoice' => $this->actions->issueInvoice(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId),
				'createQuote' => $this->authoring->createQuote(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId),
				'raisePayout' => $this->authoring->raisePayout(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId),
				'requestPayment' => $this->actions->requestPayment(
					invoiceId: $this->invoiceOnObject(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId),
					appId: $appId,
				),
				'creditInvoice' => $this->authoring->creditInvoice(
					invoiceId: $this->invoiceOnObject(register: $register, schema: $schema, objectId: $objectId, payload: $payload, appId: $appId),
					appId: $appId,
				),
				default => throw new FinancialDocumentActionRefusedException(
					message: 'Name the act: issueInvoice, requestPayment, createQuote, creditInvoice or raisePayout.',
					status: 400,
				),
			};
		} catch (FinancialDocumentActionRefusedException $e) {
			throw new RuntimeException($e->getStatus().' '.$e->getMessage(), 0, $e);
		}
	}//end act()

	/**
	 * The invoice an act names, refused unless it stands on the host object.
	 *
	 * Without this an invoice on any other object could be paid or credited
	 * through this host. A signed-in caller must also be able to read it; an
	 * app reads as the system, and its own grant is checked by the act.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload The act's fields, with `invoiceId`.
	 * @param string $appId The acting app, or '' for the signed-in caller.
	 *
	 * @return string The invoice id.
	 *
	 * @throws FinancialDocumentActionRefusedException When no such invoice stands on this object (404).
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-005)
	 */
	private function invoiceOnObject(string $register, string $schema, string $objectId, array $payload, string $appId): string {
		$invoiceId = trim((string)($payload['invoiceId'] ?? ''));
		if ($invoiceId !== '') {
			foreach ($this->finder->onObject(register: $register, schema: $schema, objectId: $objectId) as $found) {
				if ((string)($found['document']['id'] ?? '') === $invoiceId
					&& $this->rows->kindOf(schema: $found['schema'], document: $found['document']) === 'invoice'
					&& ($appId !== '' || $found['readable'] === true)
				) {
					return $invoiceId;
				}
			}
		}

		throw new FinancialDocumentActionRefusedException(message: 'No invoice with that id stands on this object.', status: 404);
	}//end invoiceOnObject()

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
