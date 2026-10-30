<?php

/**
 * Financial Document Finder
 *
 * Reads every quote, invoice, credit note, payment request and payout whose
 * `linkedObjects` hold a given object. The read runs twice per schema: once
 * past the caller's rights, so the leaf can count a document the caller may
 * not open, and once as the caller, to know which documents they may read.
 *
 * When the object's id is a UUID, OpenRegister's `_relations` index narrows
 * the read, because a UUID inside `linkedObjects` is indexed as a relation.
 * The match on register, schema and id below stays the authority either way.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Finds the financial documents linked to one object.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
 */
final class FinancialDocumentFinder {
	/**
	 * The schemas that hold financial documents, in the order they are read.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['Quote', 'ARInvoice', 'PaymentRequest', 'InboundPayable'];

	/**
	 * Rows per page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * A ceiling on pages, so a store that ignores `offset` cannot loop forever.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 50;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every document linked to the object, with its schema and whether the caller may read it.
	 *
	 * @param string $register The object's register.
	 * @param string $schema The object's schema.
	 * @param string $objectId The object's id.
	 *
	 * @return array<int, array{schema: string, document: array<string, mixed>, readable: bool}> The documents.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-002)
	 */
	public function onObject(string $register, string $schema, string $objectId): array {
		$key = implode('|', [$register, $schema, $objectId]);
		$filters = [];
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId) === 1) {
			$filters['_relations_contains'] = $objectId;
		}

		$found = [];
		foreach (self::SCHEMAS as $documentSchema) {
			$readable = array_column($this->linked(schema: $documentSchema, filters: $filters, key: $key, asSystem: false), 'id');
			foreach ($this->linked(schema: $documentSchema, filters: $filters, key: $key, asSystem: true) as $document) {
				$found[] = [
					'schema' => $documentSchema,
					'document' => $document,
					'readable' => in_array($document['id'], $readable, true),
				];
			}
		}

		return $found;
	}//end onObject()

	/**
	 * The documents of one schema whose list holds the object.
	 *
	 * @param string $schema The document schema.
	 * @param array<string, mixed> $filters The narrowing filters.
	 * @param string $key The object as `register|schema|id`.
	 * @param bool $asSystem True to read past the caller's rights.
	 *
	 * @return array<int, array<string, mixed>> The documents, each with its `id`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whose rights a read runs under
	 * is data the caller decides, not a mode that changes what the method does.
	 */
	private function linked(string $schema, array $filters, string $key, bool $asSystem): array {
		$scoped = $this->objectService->setRegister($this->registerSlug())->setSchema($schema);

		$kept = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $scoped->findAll(
				['filters' => $filters, 'limit' => self::PAGE, 'offset' => ($page * self::PAGE)],
				_rbac: ($asSystem === false),
				_multitenancy: ($asSystem === false),
			);

			foreach ($rows as $row) {
				$document = ObjectIdentifier::recordWithId(candidate: $row);
				if ($document !== null && (string)($document['id'] ?? '') !== '' && $this->links(document: $document, key: $key) === true) {
					$kept[] = $document;
				}
			}

			if (count($rows) < self::PAGE) {
				break;
			}
		}

		return $kept;
	}//end linked()

	/**
	 * Whether a document's list holds the object.
	 *
	 * @param array<string, mixed> $document The document.
	 * @param string $key The object as `register|schema|id`.
	 *
	 * @return bool True when one item names the object.
	 */
	private function links(array $document, string $key): bool {
		foreach ((array)($document['linkedObjects'] ?? []) as $link) {
			if (is_array($link) === true
				&& implode('|', [(string)($link['register'] ?? ''), (string)($link['schema'] ?? ''), (string)($link['id'] ?? '')]) === $key
			) {
				return true;
			}
		}

		return false;
	}//end links()

	/**
	 * The register slug shillinq's documents live in.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
	}//end registerSlug()
}//end class
