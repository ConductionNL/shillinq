<?php

/**
 * Extraction Draft Committer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The commit step of the extraction consume flow: save a corrected
 * SupplierInvoice or Receipt draft through OpenRegister and feed the booked
 * GL account back to docudesk. The confirm route and the MCP receipt tool
 * both commit through it, so a draft booked by an agent lands exactly as one
 * booked on the review screen.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCA\Shillinq\Kernel\Contracts\GlAccountSuggestionClientInterface;
use Psr\Container\ContainerInterface;

/**
 * Saves a corrected extraction draft and feeds its booking back.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
class ExtractionDraftCommitter {

	/**
	 * The register every extraction draft lives in.
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface                 $container        DI container (OR ObjectService).
	 * @param GlAccountSuggestionClientInterface $suggestionClient Feeds a committed booking back to docudesk.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly GlAccountSuggestionClientInterface $suggestionClient,
	) {
	}//end __construct()

	/**
	 * Save the corrected draft, then feed its booking back to docudesk.
	 *
	 * @param string               $schema The draft's schema (SupplierInvoice|Receipt).
	 * @param array<string, mixed> $draft  The corrected draft.
	 *
	 * @return array<string, mixed> The saved record.
	 *
	 * @throws \Throwable Whatever the save throws; nothing is fed back then.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	public function commit(string $schema, array $draft): array {
		$record = $this->save(schema: $schema, draft: $draft);
		$this->feedBack(record: $record);

		return $record;
	}//end commit()

	/**
	 * Save a draft through OpenRegister's ObjectService.
	 *
	 * @param string               $schema The draft's schema.
	 * @param array<string, mixed> $draft  The draft to save.
	 *
	 * @return array<string, mixed> The saved record, or the draft itself when the save answers no array (as the confirm route always answered).
	 *
	 * @throws \Throwable Whatever the save throws.
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function save(string $schema, array $draft): array {
		$saved = $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->setRegister(self::REGISTER_SLUG)
			->setSchema($schema)
			->saveObject($draft);

		if (is_array($saved) === true) {
			return $saved;
		}

		return $draft;
	}//end save()

	/**
	 * Post the committed GL-account booking back to docudesk as a correction
	 * when the record carries a known `docudeskExtractionId` and a non-empty
	 * `glAccount` (REQ-GAC-005). No-op, not an error, when either is absent
	 * (REQ-GAC-006).
	 *
	 * @param array<string, mixed> $record The just-saved record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function feedBack(array $record): void {
		$extractionId = (string)($record['docudeskExtractionId'] ?? '');
		$accountCode = trim((string)($record['glAccount'] ?? ''));
		if ($extractionId === '' || $accountCode === '') {
			return;
		}

		$accountLabel = $record['glAccountLabel'] ?? null;
		if (is_string($accountLabel) === false) {
			$accountLabel = null;
		}

		$this->suggestionClient->postCorrection(extractionId: $extractionId, accountCode: $accountCode, accountLabel: $accountLabel);
	}//end feedBack()
}//end class
