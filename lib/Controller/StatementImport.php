<?php

/**
 * Statement Import
 *
 * The administration, format, contents and GL account of one bank statement
 * import, normalised from the read payload.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * What one bank statement import is for.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */
final class StatementImport {
	/**
	 * Allowed statement formats accepted by the import endpoint.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_FORMATS = ['camt053', 'mt940', 'csv'];

	/**
	 * The server-resolved administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The lower-cased, trimmed format.
	 *
	 * @var string
	 */
	public readonly string $format;

	/**
	 * The raw file contents.
	 *
	 * @var string
	 */
	public readonly string $contents;

	/**
	 * The trimmed GL account id, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $glAccountId;

	/**
	 * Normalise the read payload.
	 *
	 * @param string              $administrationId The server-resolved administration.
	 * @param array<string,mixed> $payload          The read payload (contents, format, glAccountId).
	 */
	public function __construct(string $administrationId, array $payload) {
		$this->administrationId = $administrationId;
		$this->format = strtolower(trim((string)($payload['format'] ?? '')));
		$this->contents = (string)($payload['contents'] ?? '');
		$this->glAccountId = trim((string)($payload['glAccountId'] ?? ''));

	}//end __construct()

	/**
	 * Whether the format is one of camt053/mt940/csv.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	public function supportedFormat(): bool {
		return in_array($this->format, self::ALLOWED_FORMATS, true);
	}//end supportedFormat()

	/**
	 * Whether the contents are blank.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
	 */
	public function isEmpty(): bool {
		return trim($this->contents) === '';
	}//end isEmpty()
}//end class
