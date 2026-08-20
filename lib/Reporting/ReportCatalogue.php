<?php

/**
 * Report catalogue
 *
 * The static registry of every report shillinq can produce under "Reporting &
 * Compliance". Each entry drives a card on the overview page (label, category,
 * description, the formats it offers) and tells ReportGenerationService which
 * generator and formats apply. This is the single source of truth for the report
 * IA — the consolidation target that replaces the reports scattered across the
 * Belastingen / Bookkeeping / PublicSector / Purchasing menus.
 *
 * `kind` is 'data' (rendered natively as XML/CSV/XBRL) or 'document' (assembled by
 * shillinq as structured content and rendered by docudesk into editable ODT
 * ('odf' in docudesk's own vocabulary) + PDF from a `namespace: "shillinq"`
 * template — see openspec/changes/reports-via-docudesk. `ib-aangifte` and
 * `vpb-aangifte` are declared 'document' but currently have no implementing
 * generator (`ReportGenerationService::generate()` returns `no-generator` for
 * them) — out of this catalogue entry's control, tracked separately).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * phpcs:disable Generic.Files.LineLength
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * Static catalogue of report types for the Reporting & Compliance section.
 */
final class ReportCatalogue {

	/**
	 * Report categories, in display order, for grouping the overview cards.
	 */
	public const CATEGORIES = [
		'tax' => 'Belastingaangiften',
		'statements' => 'Jaarrekening & financiële overzichten',
		'ledger' => 'Grootboek & saldi',
		'audit-file' => 'Auditbestanden & e-facturatie',
		'public-sector' => 'Overheidsrapportages',
		'compliance' => 'Compliance & audit trail',
	];

	/**
	 * The report-type registry. Each: id, label, category, kind (data|document),
	 * formats (offered to the user; document reports list editable formats first),
	 * description, and the default template name for document reports (null for data;
	 * shipped in lib/Reporting/templates/, customisable via docudesk).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		return [
			// --- Tax filings ---
			['id' => 'vat-return', 'label' => 'BTW-aangifte', 'category' => 'tax', 'kind' => 'data', 'formats' => ['xml', 'pdf'], 'templateId' => null, 'description' => 'Periodieke btw-aangifte (Digipoort/XBRL).'],
			['id' => 'icp-opgaaf', 'label' => 'ICP-opgaaf', 'category' => 'tax', 'kind' => 'data', 'formats' => ['xml', 'csv'], 'templateId' => null, 'description' => 'Opgaaf intracommunautaire prestaties.'],
			['id' => 'ib-aangifte', 'label' => 'IB-aangifte (winst)', 'category' => 'tax', 'kind' => 'document', 'formats' => ['docx', 'odt', 'pdf'], 'templateId' => 'shillinq-ib-aangifte', 'description' => 'Inkomstenbelasting winstaangifte ZZP.'],
			['id' => 'vpb-aangifte', 'label' => 'Vpb-aangifte', 'category' => 'tax', 'kind' => 'document', 'formats' => ['docx', 'odt', 'pdf'], 'templateId' => 'shillinq-vpb-aangifte', 'description' => 'Vennootschapsbelasting aangifte met fiscale balans.'],

			// --- Statutory statements ---
			['id' => 'annual-accounts', 'label' => 'Jaarrekening', 'category' => 'statements', 'kind' => 'document', 'formats' => ['odt', 'pdf'], 'templateId' => 'shillinq-jaarrekening', 'description' => 'Titel 9 BW2 jaarrekening (balans, W&V, toelichting).'],
			['id' => 'balance-sheet', 'label' => 'Balans', 'category' => 'statements', 'kind' => 'document', 'formats' => ['odt', 'pdf'], 'templateId' => 'shillinq-balans', 'description' => 'Balans per peildatum.'],
			['id' => 'profit-loss', 'label' => 'Winst- en verliesrekening', 'category' => 'statements', 'kind' => 'document', 'formats' => ['odt', 'pdf'], 'templateId' => 'shillinq-winst-verlies', 'description' => 'Resultatenrekening over de periode.'],
			['id' => 'sbr-xbrl', 'label' => 'SBR/XBRL-deponering', 'category' => 'statements', 'kind' => 'data', 'formats' => ['xbrl'], 'templateId' => null, 'description' => 'SBR-jaarrekening in XBRL (KvK/Belastingdienst).'],

			// --- Ledger / balances ---
			['id' => 'trial-balance', 'label' => 'Proef- en saldibalans', 'category' => 'ledger', 'kind' => 'data', 'formats' => ['csv', 'pdf'], 'templateId' => null, 'description' => 'Saldibalans van alle grootboekrekeningen.'],
			['id' => 'general-ledger', 'label' => 'Grootboekkaarten', 'category' => 'ledger', 'kind' => 'data', 'formats' => ['csv'], 'templateId' => null, 'description' => 'Grootboekmutaties per rekening.'],

			// --- Audit files / e-invoicing ---
			['id' => 'saft', 'label' => 'SAF-T auditbestand', 'category' => 'audit-file', 'kind' => 'data', 'formats' => ['xml'], 'templateId' => null, 'description' => 'OECD SAF-T fiscaal auditbestand.'],
			['id' => 'xaf', 'label' => 'XAF auditbestand (Auditfile Financieel)', 'category' => 'audit-file', 'kind' => 'data', 'formats' => ['xml'], 'templateId' => null, 'description' => 'Nederlands Auditfile Financieel (XAF 3.2, Belastingdienst/XBRL Nederland).'],

			// --- Public sector ---
			['id' => 'iv3', 'label' => 'IV3-rapportage', 'category' => 'public-sector', 'kind' => 'data', 'formats' => ['xml', 'csv'], 'templateId' => null, 'description' => 'Informatie voor derden (CBS).'],
			['id' => 'bbv-jaarstukken', 'label' => 'BBV-jaarstukken', 'category' => 'public-sector', 'kind' => 'document', 'formats' => ['odt', 'pdf'], 'templateId' => 'shillinq-bbv-jaarstukken', 'description' => 'BBV programmaverantwoording & jaarstukken.'],

			// --- Compliance / audit trail ---
			['id' => 'rule-audit', 'label' => 'Compliance-auditrapport', 'category' => 'compliance', 'kind' => 'data', 'formats' => ['csv', 'pdf'], 'templateId' => null, 'description' => 'Resultaat van de regelmotor (shillinq:rules:audit): afdwingbare regels, overtredingen, dekking.'],
			['id' => 'audit-trail', 'label' => 'Audit trail', 'category' => 'compliance', 'kind' => 'data', 'formats' => ['csv'], 'templateId' => null, 'description' => 'Onveranderlijke mutatie-audittrail over de periode.'],
			['id' => 'management-letter', 'label' => 'Management letter', 'category' => 'compliance', 'kind' => 'document', 'formats' => ['odt', 'pdf'], 'templateId' => 'shillinq-management-letter', 'description' => 'Management letter / bevindingenrapport.'],
		];

	}//end all()

	/**
	 * Look up a report type by id.
	 *
	 * @param string $id Report-type id.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function byId(string $id): ?array {
		foreach (self::all() as $report) {
			if ($report['id'] === $id) {
				return $report;
			}
		}

		return null;
	}//end byId()
}//end class
