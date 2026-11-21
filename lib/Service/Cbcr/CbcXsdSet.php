<?php

/**
 * Shillinq country-by-country XSD set
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
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

namespace OCA\Shillinq\Service\Cbcr;

use DOMDocument;
use LibXMLError;

/**
 * Validates a file against an OECD XML schema set kept in the repository.
 *
 * The CbC set lives in `lib/Standards/Xsd/oecd-cbc-v2.0/`, the GloBE
 * information return set (forGir()) in `lib/Standards/Xsd/oecd-gir-2025-01/`
 * (task 2.1 of the change fetches both by hand, each with its SOURCE.md).
 * While the folder holds no main XSD the answer is "not valid" with one error
 * saying so: a file that could not be validated is never stored (design D3,
 * D5), so Generate XML fails closed until the set is there.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
class CbcXsdSet {

	/**
	 * The main schema of the set.
	 */
	public const MAIN_XSD = 'CbcXML_v2.0.xsd';

	/**
	 * The main schema of the GloBE information return set.
	 */
	public const GIR_MAIN_XSD = 'GLOBEXML_v1.0.xsd';

	/**
	 * The folder of the GloBE information return set, under lib/Standards/Xsd.
	 */
	public const GIR_FOLDER = 'oecd-gir-2025-01';

	/**
	 * The folder of the set, absolute.
	 *
	 * @var string
	 */
	private readonly string $folder;

	/**
	 * Constructor.
	 *
	 * @param string|null $folder     The folder holding the set; null for the bundled one.
	 * @param string      $mainXsd    The main schema's file name.
	 * @param string      $folderName The bundled folder's name under lib/Standards/Xsd.
	 * @param string      $version    The schema version a valid file is recorded with.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function __construct(
		?string $folder = null,
		private readonly string $mainXsd = self::MAIN_XSD,
		private readonly string $folderName = 'oecd-cbc-v2.0',
		private readonly string $version = CbcXmlDocument::VERSION,
	) {
		$this->folder = rtrim(($folder ?? dirname(__DIR__, 2) . '/Standards/Xsd/' . $this->folderName), '/');
	}//end __construct()

	/**
	 * The GloBE information return set: GLOBEXML_v1.0.xsd in lib/Standards/Xsd/oecd-gir-2025-01.
	 *
	 * @param string|null $folder The folder holding the set; null for the bundled one.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function forGir(?string $folder = null): self {
		return new self(folder: $folder, mainXsd: self::GIR_MAIN_XSD, folderName: self::GIR_FOLDER, version: GirXmlDocument::VERSION);
	}//end forGir()

	/**
	 * The target namespace the main schema declares.
	 *
	 * @return string The namespace, '' while the set is not bundled or declares none.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function targetNamespace(): string {
		if ($this->isBundled() === false) {
			return '';
		}

		$head = (string)file_get_contents($this->folder . '/' . $this->mainXsd, false, null, 0, 4096);
		if (preg_match('/targetNamespace\s*=\s*"([^"]+)"/', $head, $match) === 1) {
			return $match[1];
		}

		return '';
	}//end targetNamespace()

	/**
	 * Whether the set's main schema is present.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function isBundled(): bool {
		return is_file($this->folder . '/' . $this->mainXsd) === true;
	}//end isBundled()

	/**
	 * The checksum of the set: SHA-256 over the name and SHA-256 of every XSD, by name.
	 *
	 * @return string Hex, '' while the set is not bundled.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function checksum(): string {
		if ($this->isBundled() === false) {
			return '';
		}

		$files = glob($this->folder . '/*.xsd');
		if ($files === false) {
			$files = [];
		}

		sort($files);
		$lines = [];
		foreach ($files as $file) {
			$lines[] = basename($file) . ' ' . (string)hash_file('sha256', $file);
		}

		return hash('sha256', implode("\n", $lines));
	}//end checksum()

	/**
	 * Validate a file against the set.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{valid:bool,errors:array<int,array{line:int,element:string,message:string}>,schemaVersion:string,xsdChecksum:string}
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function validate(string $xml): array {
		if ($this->isBundled() === false) {
			return $this->outcome(
				errors: [
					[
						'line' => 0,
						'element' => '',
						'message' => 'The OECD XML schema set is not in lib/Standards/Xsd/' . $this->folderName . ', so the file cannot be validated.',
					],
				],
				checksum: ''
			);
		}

		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		try {
			$doc    = new DOMDocument();
			$loaded = $doc->loadXML($xml, LIBXML_NONET);
			if ($loaded === true) {
				$doc->schemaValidate($this->folder . '/' . $this->mainXsd, LIBXML_NONET);
			}

			$errors = array_map(static fn (LibXMLError $error): array => self::errorOf(error: $error), libxml_get_errors());
			if ($loaded === false && $errors === []) {
				$errors[] = ['line' => 0, 'element' => '', 'message' => 'The file is not well-formed XML.'];
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		return $this->outcome(errors: $errors, checksum: $this->checksum());
	}//end validate()

	/**
	 * The validation outcome.
	 *
	 * @param array<int,array{line:int,element:string,message:string}> $errors   The errors.
	 * @param string                                                   $checksum The set's checksum.
	 *
	 * @return array{valid:bool,errors:array<int,array{line:int,element:string,message:string}>,schemaVersion:string,xsdChecksum:string}
	 */
	private function outcome(array $errors, string $checksum): array {
		return [
			'valid' => ($errors === []),
			'errors' => $errors,
			'schemaVersion' => $this->version,
			'xsdChecksum' => $checksum,
		];
	}//end outcome()

	/**
	 * One libxml error as line, element and message.
	 *
	 * @param LibXMLError $error The error.
	 *
	 * @return array{line:int,element:string,message:string}
	 */
	private static function errorOf(LibXMLError $error): array {
		$message = trim($error->message);
		$element = '';
		if (preg_match("/Element '([^']+)'/", $message, $match) === 1) {
			$element = (string)preg_replace('/^\{[^}]*\}/', '', $match[1]);
		}

		return ['line' => (int)$error->line, 'element' => $element, 'message' => $message];
	}//end errorOf()
}//end class
