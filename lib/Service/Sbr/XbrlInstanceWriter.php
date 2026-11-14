<?php

/**
 * Shillinq XBRL instance writer
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
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

namespace OCA\Shillinq\Service\Sbr;

use InvalidArgumentException;
use XMLWriter;

/**
 * Writes an XBRL 2.1 instance: one entity, one duration context and one
 * instant context at the end of the period, a EUR unit, and the facts.
 *
 * What a filing reports (the concepts, their namespaces and the entry point
 * schema) is decided by the builder that calls it; this class only writes the
 * document, with `XMLWriter` as the other generators do.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 */
final class XbrlInstanceWriter {

	/**
	 * The XBRL instance namespace.
	 */
	public const XBRLI = 'http://www.xbrl.org/2003/instance';

	/**
	 * The XBRL linkbase namespace.
	 */
	private const LINK = 'http://www.xbrl.org/2003/linkbase';

	/**
	 * The XLink namespace.
	 */
	private const XLINK = 'http://www.w3.org/1999/xlink';

	/**
	 * The ISO 4217 namespace.
	 */
	private const ISO4217 = 'http://www.xbrl.org/2003/iso4217';

	/**
	 * The id of the duration context.
	 */
	public const DURATION = 'd';

	/**
	 * The id of the instant context (the last day of the period).
	 */
	public const INSTANT = 'i';

	/**
	 * Write the instance.
	 *
	 * @param array{scheme:string,identifier:string}                  $entity     Who reports.
	 * @param string                                                  $start      First day of the period (Y-m-d).
	 * @param string                                                  $end        Last day of the period (Y-m-d).
	 * @param array<string,string>                                    $namespaces Concept prefix to namespace URI.
	 * @param list<array{concept:string,value:int|float,context:string}> $facts  Monetary facts in EUR, in writing order.
	 * @param string|null                                             $schemaRef  The entry point schema, when known.
	 *
	 * @return string The instance XML.
	 *
	 * @throws InvalidArgumentException When a fact's prefix has no namespace.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function write(array $entity, string $start, string $end, array $namespaces, array $facts, ?string $schemaRef=null): string {
		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		$writer->startElementNs('xbrli', 'xbrl', self::XBRLI);
		$writer->writeAttributeNs('xmlns', 'link', null, self::LINK);
		$writer->writeAttributeNs('xmlns', 'xlink', null, self::XLINK);
		$writer->writeAttributeNs('xmlns', 'iso4217', null, self::ISO4217);
		ksort($namespaces);
		foreach ($namespaces as $prefix => $uri) {
			$writer->writeAttributeNs('xmlns', (string) $prefix, null, $uri);
		}

		if ($schemaRef !== null && trim($schemaRef) !== '') {
			$writer->startElementNs('link', 'schemaRef', null);
			$writer->writeAttributeNs('xlink', 'type', null, 'simple');
			$writer->writeAttributeNs('xlink', 'href', null, trim($schemaRef));
			$writer->endElement();
		}

		self::writeContext(writer: $writer, id: self::DURATION, entity: $entity, start: $start, end: $end);
		self::writeContext(writer: $writer, id: self::INSTANT, entity: $entity, start: null, end: $end);

		$writer->startElementNs('xbrli', 'unit', null);
		$writer->writeAttribute('id', 'EUR');
		$writer->writeElementNs('xbrli', 'measure', null, 'iso4217:EUR');
		$writer->endElement();

		foreach ($facts as $fact) {
			$prefix = strstr($fact['concept'], ':', true);
			if ($prefix === false || isset($namespaces[$prefix]) === false) {
				throw new InvalidArgumentException(sprintf('The concept %s has no namespace.', $fact['concept']));
			}

			$writer->startElement($fact['concept']);
			$writer->writeAttribute('contextRef', $fact['context']);
			$writer->writeAttribute('unitRef', 'EUR');
			$writer->writeAttribute('decimals', 'INF');
			$writer->text(self::amount(value: $fact['value']));
			$writer->endElement();
		}

		$writer->endElement();
		$writer->endDocument();

		return $writer->outputMemory();
	}//end write()

	/**
	 * A whole-euro amount as XBRL writes it.
	 *
	 * @param int|float $value The amount in euros.
	 *
	 * @return string The amount, rounded to whole euros.
	 */
	private static function amount(int|float $value): string {
		return (string) (int) round((float) $value);
	}//end amount()

	/**
	 * Write one context.
	 *
	 * @param XMLWriter                               $writer The writer.
	 * @param string                                  $id     The context id.
	 * @param array{scheme:string,identifier:string}  $entity Who reports.
	 * @param string|null                             $start  The first day, null for an instant.
	 * @param string                                  $end    The last day, or the instant.
	 *
	 * @return void
	 */
	private static function writeContext(XMLWriter $writer, string $id, array $entity, ?string $start, string $end): void {
		$writer->startElementNs('xbrli', 'context', null);
		$writer->writeAttribute('id', $id);
		$writer->startElementNs('xbrli', 'entity', null);
		$writer->startElementNs('xbrli', 'identifier', null);
		$writer->writeAttribute('scheme', $entity['scheme']);
		$writer->text($entity['identifier']);
		$writer->endElement();
		$writer->endElement();

		$writer->startElementNs('xbrli', 'period', null);
		if ($start === null) {
			$writer->writeElementNs('xbrli', 'instant', null, $end);
		} else {
			$writer->writeElementNs('xbrli', 'startDate', null, $start);
			$writer->writeElementNs('xbrli', 'endDate', null, $end);
		}

		$writer->endElement();
		$writer->endElement();
	}//end writeContext()
}//end class
