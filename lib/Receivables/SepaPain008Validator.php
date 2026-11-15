<?php

/**
 * SEPA Direct Debit pain.008 validator
 *
 * Checks a rendered pain.008.001.02 file before it is stored: the creditor's
 * IBAN and creditor identifier, every mandate's debtor IBAN (check digits,
 * which the XSD's pattern does not see) and then the document against the
 * shipped pain.008.001.02 XSD. Each error names what is wrong, a debtor IBAN
 * error the mandate it belongs to.
 *
 * @category Receivables
 * @package  OCA\Shillinq\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */

declare(strict_types=1);

namespace OCA\Shillinq\Receivables;

use DOMDocument;
use LibXMLError;
use OCA\Shillinq\Validation\IbanValidator;

/**
 * The errors that keep a pain.008 file from being stored.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
final class SepaPain008Validator {

	/**
	 * The shape of a SEPA creditor identifier: country, check digits, business code, national id.
	 *
	 * @var string
	 */
	private const CREDITOR_ID_PATTERN = '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/';

	/**
	 * Constructor.
	 *
	 * @param IbanValidator $ibans Checks an IBAN's length and check digits.
	 */
	public function __construct(
		private readonly IbanValidator $ibans = new IbanValidator(),
	) {

	}//end __construct()

	/**
	 * Every reason the file may not be stored; empty when it is valid.
	 *
	 * @param string                     $xml         The rendered pain.008 document.
	 * @param list<array<string, mixed>> $collections The collections it was rendered from, each with `mandate`.
	 * @param array<string, string>      $creditor    name, iban, bic and creditorIdentifier.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function errors(string $xml, array $collections, array $creditor): array {
		$errors = $this->creditorErrors(creditor: $creditor);
		if ($collections === []) {
			$errors[] = 'The batch has no collections.';
		}

		foreach ($collections as $collection) {
			$mandate = ($collection['mandate'] ?? null);
			if (is_array($mandate) === false) {
				$errors[] = sprintf('Collection %s has no mandate.', (string)($collection['endToEndId'] ?? ''));
				continue;
			}

			$iban = (string)($mandate['debtorIban'] ?? '');
			if ($this->ibans->isValid(iban: $iban) === false) {
				$errors[] = sprintf('Mandate %s: the debtor IBAN %s is not valid.', (string)($mandate['mandateReference'] ?? ''), $iban);
			}
		}

		return array_merge($errors, $this->schemaErrors(xml: $xml));

	}//end errors()

	/**
	 * The path of the shipped pain.008.001.02 XSD.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public static function schemaPath(): string {
		return __DIR__ . '/xsd/pain.008.001.02.xsd';

	}//end schemaPath()

	/**
	 * Errors in the creditor's name, IBAN and creditor identifier.
	 *
	 * @param array<string, string> $creditor The creditor.
	 *
	 * @return list<string>
	 */
	private function creditorErrors(array $creditor): array {
		$errors = [];
		if (trim((string)($creditor['name'] ?? '')) === '') {
			$errors[] = 'The administration has no name to collect under.';
		}

		$iban = (string)($creditor['iban'] ?? '');
		if ($this->ibans->isValid(iban: $iban) === false) {
			$errors[] = sprintf('The creditor IBAN "%s" of the administration is not valid.', $iban);
		}

		$identifier = strtoupper((string)preg_replace('/\s+/', '', (string)($creditor['creditorIdentifier'] ?? '')));
		if (preg_match(self::CREDITOR_ID_PATTERN, $identifier) !== 1) {
			$errors[] = sprintf('The creditor identifier "%s" is not a SEPA creditor identifier.', $identifier);
		}

		return $errors;

	}//end creditorErrors()

	/**
	 * The XSD errors of the document, one line each.
	 *
	 * @param string $xml The document.
	 *
	 * @return list<string>
	 */
	private function schemaErrors(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$document = new DOMDocument();
		$valid = false;
		if ($document->loadXML($xml, LIBXML_NONET) === true) {
			$valid = $document->schemaValidate(self::schemaPath());
		}

		$errors = array_map(
			static fn (LibXMLError $error): string => 'XSD line ' . $error->line . ': ' . trim($error->message),
			libxml_get_errors()
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === false && $errors === []) {
			$errors[] = 'The file is not valid pain.008.001.02.';
		}

		return array_values($errors);

	}//end schemaErrors()
}//end class
