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
	 * The header holds `entity` (array{scheme:string,identifier:string}, who
	 * reports), `start` and `end` (first and last day of the period, Y-m-d),
	 * `namespaces` (concept prefix to namespace URI) and, optionally,
	 * `schemaRef` (the entry point schema, when known).
	 *
	 * @param array<string,mixed>                                       $header The instance header.
	 * @param list<array{concept:string,value:int|float,context:string}> $facts  Monetary facts in EUR, in writing order.
	 *
	 * @return string The instance XML.
	 *
	 * @throws InvalidArgumentException When a fact's prefix has no namespace.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function write(array $header, array $facts): string {
		self::assertNamespaced(facts: $facts, namespaces: $header['namespaces']);

		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->setIndent(true);
		$writer->setIndentString('  ');
		$writer->startDocument('1.0', 'UTF-8');

		self::openRoot(writer: $writer, header: $header);
		$context = ['entity' => $header['entity'], 'end' => $header['end']];
		self::writeContext(writer: $writer, context: ['id' => self::DURATION, 'start' => $header['start']] + $context);
		self::writeContext(writer: $writer, context: ['id' => self::INSTANT, 'start' => null] + $context);

		$writer->startElementNs('xbrli', 'unit', null);
		$writer->writeAttribute('id', 'EUR');
		$writer->writeElementNs('xbrli', 'measure', null, 'iso4217:EUR');
		$writer->endElement();

		foreach ($facts as $fact) {
			self::writeFact(writer: $writer, fact: $fact);
		}

		$writer->endElement();
		$writer->endDocument();

		return $writer->outputMemory();
	}//end write()

	/**
	 * The draft XbrlInstance record: its identity fields, then the XML, its hash and the draft state.
	 *
	 * @param array<string,string> $identity The record's identity fields, in record order.
	 * @param string               $xml      The instance XML.
	 *
	 * @return array<string,mixed> The instance record.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function record(array $identity, string $xml): array {
		return $identity + [
			'instanceXml'         => $xml,
			'instanceHash'        => hash('sha256', $xml),
			'state'               => 'draft',
			'digipoortSourceSlug' => 'digipoort-sbr',
		];
	}//end record()

	/**
	 * Refuse a fact whose concept prefix has no namespace, the first in writing order.
	 *
	 * @param list<array{concept:string,value:int|float,context:string}> $facts      The facts.
	 * @param array<string,string>                                       $namespaces Concept prefix to namespace URI.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a fact's prefix has no namespace.
	 */
	private static function assertNamespaced(array $facts, array $namespaces): void {
		foreach ($facts as $fact) {
			$prefix = strstr($fact['concept'], ':', true);
			if ($prefix === false || isset($namespaces[$prefix]) === false) {
				throw new InvalidArgumentException(sprintf('The concept %s has no namespace.', $fact['concept']));
			}
		}
	}//end assertNamespaced()

	/**
	 * Open the xbrl root with its namespaces, and the schema reference when there is one.
	 *
	 * @param XMLWriter           $writer The writer.
	 * @param array<string,mixed> $header The instance header (see write()).
	 *
	 * @return void
	 */
	private static function openRoot(XMLWriter $writer, array $header): void {
		$writer->startElementNs('xbrli', 'xbrl', self::XBRLI);
		$writer->writeAttributeNs('xmlns', 'link', null, self::LINK);
		$writer->writeAttributeNs('xmlns', 'xlink', null, self::XLINK);
		$writer->writeAttributeNs('xmlns', 'iso4217', null, self::ISO4217);
		$namespaces = $header['namespaces'];
		ksort($namespaces);
		foreach ($namespaces as $prefix => $uri) {
			$writer->writeAttributeNs('xmlns', (string) $prefix, null, $uri);
		}

		$schemaRef = trim((string) ($header['schemaRef'] ?? ''));
		if ($schemaRef !== '') {
			$writer->startElementNs('link', 'schemaRef', null);
			$writer->writeAttributeNs('xlink', 'type', null, 'simple');
			$writer->writeAttributeNs('xlink', 'href', null, $schemaRef);
			$writer->endElement();
		}
	}//end openRoot()

	/**
	 * Write one monetary fact in whole euros.
	 *
	 * @param XMLWriter                                            $writer The writer.
	 * @param array{concept:string,value:int|float,context:string} $fact   The fact.
	 *
	 * @return void
	 */
	private static function writeFact(XMLWriter $writer, array $fact): void {
		$writer->startElement($fact['concept']);
		$writer->writeAttribute('contextRef', $fact['context']);
		$writer->writeAttribute('unitRef', 'EUR');
		$writer->writeAttribute('decimals', 'INF');
		$writer->text(self::amount(value: $fact['value']));
		$writer->endElement();
	}//end writeFact()

	/**
	 * The concept namespaces: the taxonomy's, else the default.
	 *
	 * @param array<string,mixed>|null $taxonomy The taxonomy record.
	 * @param array<string,string>     $defaults The namespaces when the taxonomy names none.
	 *
	 * @return array<string,string> Prefix to namespace URI.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function namespaces(?array $taxonomy, array $defaults): array {
		$namespaces = $defaults;
		foreach ((array) ($taxonomy['namespaces'] ?? []) as $prefix => $uri) {
			if (is_string($prefix) === true && is_string($uri) === true && trim($uri) !== '') {
				$namespaces[$prefix] = trim($uri);
			}
		}

		return $namespaces;
	}//end namespaces()

	/**
	 * The entry point schema the taxonomy names, or null.
	 *
	 * @param array<string,mixed>|null $taxonomy   The taxonomy record.
	 * @param string                   $entryPoint The entry point.
	 *
	 * @return string|null The schema URL.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public static function schemaRef(?array $taxonomy, string $entryPoint): ?string {
		$ref = (($taxonomy['schemaRefs'] ?? [])[$entryPoint] ?? null);
		if (is_string($ref) === false || trim($ref) === '') {
			return null;
		}

		return trim($ref);
	}//end schemaRef()

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
	 * @param XMLWriter                                                                                 $writer  The writer.
	 * @param array{id:string,entity:array{scheme:string,identifier:string},start:string|null,end:string} $context The context id,
	 *                                                                                                            who reports, the
	 *                                                                                                            first day (null for
	 *                                                                                                            an instant) and the
	 *                                                                                                            last day or instant.
	 *
	 * @return void
	 */
	private static function writeContext(XMLWriter $writer, array $context): void {
		$writer->startElementNs('xbrli', 'context', null);
		$writer->writeAttribute('id', $context['id']);
		$writer->startElementNs('xbrli', 'entity', null);
		$writer->startElementNs('xbrli', 'identifier', null);
		$writer->writeAttribute('scheme', $context['entity']['scheme']);
		$writer->text($context['entity']['identifier']);
		$writer->endElement();
		$writer->endElement();

		$writer->startElementNs('xbrli', 'period', null);
		if ($context['start'] === null) {
			$writer->writeElementNs('xbrli', 'instant', null, $context['end']);
		}

		if ($context['start'] !== null) {
			$writer->writeElementNs('xbrli', 'startDate', null, $context['start']);
			$writer->writeElementNs('xbrli', 'endDate', null, $context['end']);
		}

		$writer->endElement();
		$writer->endElement();
	}//end writeContext()
}//end class
