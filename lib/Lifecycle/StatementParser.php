<?php

/**
 * Statement Parser
 *
 * Single-purpose lifecycle seam for bank-statement import and reconciliation
 * completeness. Thin PHP seam per ADR-031 §"PHP guards remain a legitimate
 * seam" — CAMT.053 (XML) and MT940 (structured text) parsing primitives are
 * not yet expressible by OR's calculation extension (REQ-BR-003), and the
 * "all lines resolved" reconciliation precondition (REQ-BR-004) needs to
 * aggregate the statement's child lines.
 *
 * No state, no orchestration. `parse()` is a pure content→array transformation;
 * `allLinesResolved()` is a single precondition read.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-003, REQ-BR-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Parses bank statement files and guards reconciliation completeness.
 *
 * Referenced as `OCA\Shillinq\Lifecycle\StatementParser::allLinesResolved`
 * from the BankStatement lifecycle, and invoked as `parse()` on import.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.3
 */
class StatementParser
{
    /**
     * Construct the parser with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container DI container for OR's ObjectService.
     * @param IAppConfig         $appConfig App config for register-slug resolution.
     * @param LoggerInterface    $logger    Nextcloud logger for diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the configured register slug, defaulting to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Parse a bank statement file into a list of normalised statement lines.
     *
     * Supports `camt053` (ISO 20022 XML) and `mt940` (SWIFT structured text).
     * Returns an array of associative arrays keyed by the BankStatementLine
     * fields (valueDate, amount, remittanceInfo, counterpartyName,
     * counterpartyIban, endToEndRef). Pure transformation — callers persist
     * the result through OR's ObjectService.
     *
     * @param string $contents Raw file contents.
     * @param string $format   One of 'camt053', 'mt940'.
     *
     * @return array<int,array<string,mixed>> Parsed statement lines.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.3
     */
    public function parse(string $contents, string $format): array
    {
        return match ($format) {
            'camt053' => $this->parseCamt053(xml: $contents),
            'mt940'   => $this->parseMt940(text: $contents),
            default   => [],
        };

    }//end parse()

    /**
     * Parse CAMT.053 ISO 20022 XML into statement lines.
     *
     * LibXML is XXE-safe by default on PHP 8 + libxml >= 2.9, so external-entity
     * loading need not be toggled. Returns an empty array on malformed XML.
     *
     * @param string $xml CAMT.053 XML contents.
     *
     * @return array<int,array<string,mixed>> Parsed lines.
     */
    private function parseCamt053(string $xml): array
    {
        $lines = [];

        $previous = libxml_use_internal_errors(true);
        $doc      = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            $this->logger->warning('StatementParser: malformed CAMT.053 XML');
            return [];
        }

        // CAMT.053 entries live at Document/BkToCstmrStmt/Stmt/Ntry. Bank
        // namespaces vary, so match purely on local element names via xpath to
        // stay namespace-agnostic (and to avoid member-property access).
        $entries = $doc->xpath('//*[local-name()="Ntry"]');

        foreach (($entries ?? []) as $entry) {
            $amount  = (float) $this->camtValue(entry: $entry, name: 'Amt');
            $isDebit = ($this->camtValue(entry: $entry, name: 'CdtDbtInd') === 'DBIT');
            $signed  = $amount;
            if ($isDebit === true) {
                $signed = -$amount;
            }

            $valueDate = $this->camtDate(entry: $entry, parent: 'ValDt');
            if ($valueDate === '') {
                $valueDate = $this->camtDate(entry: $entry, parent: 'BookgDt');
            }

            $currency = $this->camtAttr(entry: $entry, name: 'Amt', attr: 'Ccy');
            if ($currency === '') {
                $currency = 'EUR';
            }

            $lines[] = [
                'valueDate'        => $valueDate,
                'amount'           => $signed,
                'currency'         => $currency,
                'remittanceInfo'   => $this->camtValue(entry: $entry, name: 'Ustrd'),
                'counterpartyName' => $this->camtValue(entry: $entry, name: 'Nm'),
                'counterpartyIban' => $this->camtValue(entry: $entry, name: 'IBAN'),
                'endToEndRef'      => $this->camtValue(entry: $entry, name: 'EndToEndId'),
            ];
        }//end foreach

        return $lines;

    }//end parseCamt053()

    /**
     * Extract the trimmed text of the first descendant element with the given
     * local name within a CAMT.053 entry. Namespace-agnostic.
     *
     * @param \SimpleXMLElement $entry The Ntry element.
     * @param string            $name  The local element name to find.
     *
     * @return string The element text, or '' when absent.
     */
    private function camtValue(\SimpleXMLElement $entry, string $name): string
    {
        $hits = $entry->xpath('.//*[local-name()="'.$name.'"]');
        if (empty($hits) === true) {
            return '';
        }

        return trim((string) $hits[0]);

    }//end camtValue()

    /**
     * Extract the date text from a CAMT.053 date wrapper (e.g. ValDt/Dt or
     * BookgDt/Dt) within an entry. Namespace-agnostic.
     *
     * @param \SimpleXMLElement $entry  The Ntry element.
     * @param string            $parent The date-wrapper local name (ValDt|BookgDt).
     *
     * @return string The date text, or '' when absent.
     */
    private function camtDate(\SimpleXMLElement $entry, string $parent): string
    {
        $hits = $entry->xpath('.//*[local-name()="'.$parent.'"]/*[local-name()="Dt"]');
        if (empty($hits) === true) {
            return '';
        }

        return trim((string) $hits[0]);

    }//end camtDate()

    /**
     * Extract an attribute of the first descendant element with the given local
     * name within a CAMT.053 entry.
     *
     * @param \SimpleXMLElement $entry The Ntry element.
     * @param string            $name  The local element name to find.
     * @param string            $attr  The attribute name to read.
     *
     * @return string The attribute value, or '' when absent.
     */
    private function camtAttr(\SimpleXMLElement $entry, string $name, string $attr): string
    {
        $hits = $entry->xpath('.//*[local-name()="'.$name.'"]');
        if (empty($hits) === true) {
            return '';
        }

        $attributes = $hits[0]->attributes();
        if ($attributes === null || isset($attributes[$attr]) === false) {
            return '';
        }

        return (string) $attributes[$attr];

    }//end camtAttr()

    /**
     * Parse an MT940 SWIFT statement into statement lines.
     *
     * Recognises :61: (statement line) and :86: (information to account owner)
     * tags. Amounts are normalised to EUR signed numbers.
     *
     * @param string $text MT940 contents.
     *
     * @return array<int,array<string,mixed>> Parsed lines.
     */
    private function parseMt940(string $text): array
    {
        $lines   = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $text) as $row) {
            if (str_starts_with($row, ':61:') === true) {
                if ($current !== null) {
                    $lines[] = $current;
                }

                $current = $this->parseMt940StatementLine(payload: substr($row, 4));
                continue;
            }

            if ($current !== null && str_starts_with($row, ':86:') === true) {
                $current['remittanceInfo'] = trim(substr($row, 4));
            }
        }//end foreach

        if ($current !== null) {
            $lines[] = $current;
        }

        return $lines;

    }//end parseMt940()

    /**
     * Parse a single MT940 :61: statement-line payload.
     *
     * Format (subset): YYMMDD[MMDD]{C|D}amount... — the value date is the
     * leading 6 digits, the credit/debit marker follows the optional entry
     * date, and the amount uses a comma decimal separator.
     *
     * @param string $payload The :61: payload (tag stripped).
     *
     * @return array<string,mixed> Normalised line.
     */
    private function parseMt940StatementLine(string $payload): array
    {
        $valueDate = '';
        if (preg_match('/^(\d{6})/', $payload, $dateMatch) === 1) {
            $year      = substr($dateMatch[1], 0, 2);
            $valueDate = '20'.$year.'-'.substr($dateMatch[1], 2, 2).'-'.substr($dateMatch[1], 4, 2);
        }

        $amount = 0.0;
        $sign   = 1;
        if (preg_match('/(\d{4})?([CD])R?([0-9,]+)/', $payload, $amountMatch) === 1) {
            if ($amountMatch[2] === 'D') {
                $sign = -1;
            }

            $amount = (float) str_replace(',', '.', $amountMatch[3]);
        }

        return [
            'valueDate'        => $valueDate,
            'amount'           => ($sign * $amount),
            'currency'         => 'EUR',
            'remittanceInfo'   => '',
            'counterpartyName' => '',
            'counterpartyIban' => '',
            'endToEndRef'      => '',
        ];

    }//end parseMt940StatementLine()

    /**
     * Precondition for the BankStatement `in-progress → reconciled` transition:
     * no child BankStatementLine may remain in `unmatched` status (REQ-BR-004).
     *
     * @param array<string,mixed> $statement BankStatement object array.
     *
     * @return bool True when every line is matched or routed-to-suspense.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.3
     */
    public function allLinesResolved(array $statement): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $lines         = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('BankStatementLine')
                ->findAll(
                    [
                        'filters' => [
                            'statementId' => (string) ($statement['statementId'] ?? ''),
                            'status'      => 'unmatched',
                        ],
                        'limit'   => 1,
                    ]
                );

            return empty($lines);
        } catch (\Throwable $e) {
            // Fail closed: if completeness cannot be verified, block the
            // reconciled transition rather than asserting a false "complete".
            $this->logger->error(
                'StatementParser: completeness check failed — blocking reconciliation',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end allLinesResolved()
}//end class
