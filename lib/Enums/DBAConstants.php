<?php

/**
 * DBA Compliance Marker — fiscal & policy constants.
 *
 * Single source of truth for thresholds used across the DBA monitoring engine.
 * Mutable via administration settings where the spec marks them as indexed or
 * configurable (REQ-DBA-016 — VBAR uurtarief-grens, peil 2024, geindexeerd).
 *
 * @category Enums
 * @package  OCA\Shillinq\Enums
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Enums;

/**
 * Constant pool for the DBA monitoring engine.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DBAConstants {
	/**
	 * VBAR rechtsvermoeden-grens (peil 2024).
	 *
	 * Effectief uurtarief onder deze grens triggert het wettelijk rechtsvermoeden
	 * van werknemerschap zodra VBAR in werking treedt (per kabinetsplanning
	 * 1 januari 2026, REQ-DBA-016). Bedrag in eurocenten.
	 */
	public const VBAR_GRENS_EUR_CENTS = 3300;

	/**
	 * Peiljaar van de VBAR-grens (voor indexatie-rekenkundige).
	 */
	public const VBAR_GRENS_PEILJAAR = 2024;

	/**
	 * Threshold (12-mnd) waarboven een klant als "concentration" geldt (REQ-DBA-005).
	 */
	public const CONCENTRATIE_DREMPEL_HOOG = 0.70;

	/**
	 * Threshold (12-mnd) waarboven een klant als "kritieke concentratie" geldt.
	 */
	public const CONCENTRATIE_DREMPEL_KRITIEK = 0.85;

	/**
	 * Minimumduur (in jaren) voor een "langjarige hoofdrelatie" (REQ-DBA-005).
	 */
	public const LANGJARIG_DREMPEL_JAREN = 2.0;

	/**
	 * Minimum omzetaandeel voor een "langjarige hoofdrelatie" (REQ-DBA-005).
	 */
	public const LANGJARIG_DREMPEL_OMZET = 0.50;

	/**
	 * Maximumbedrag (eurocenten) voor "eenmalige opdracht, verkorte intake" (REQ-DBA-001).
	 */
	public const VERKORT_LAGE_DREMPEL_CENTS = 500000;

	/**
	 * Minimum aantal opeenvolgende maanden voor vaste-maandfactuur-detectie (REQ-DBA-004).
	 */
	public const VASTE_MAANDFACTUUR_MIN_MAANDEN = 6;

	/**
	 * Maximale variatie-coefficient voor "vaste maandfactuur" (REQ-DBA-004).
	 */
	public const VASTE_MAANDFACTUUR_VARIATIE_MAX = 0.04;

	/**
	 * Maximum aantal dagen verschil tussen factuurdata om als "monthly" te tellen.
	 */
	public const VASTE_MAANDFACTUUR_DAG_TOLERANTIE = 2;

	/**
	 * Minimumduur (in maanden) voor "vervangbaarheid theoretisch" flag (REQ-DBA-014).
	 */
	public const VERVANGBAARHEID_THEORETISCH_MIN_MAANDEN = 18;

	/**
	 * Periodieke-herbeoordeling-trigger (in maanden) voor langlopende opdrachten (REQ-DBA-009).
	 */
	public const HERBEOORDELING_TRIGGER_MAANDEN = 12;

	/**
	 * Termijn (in dagen) tussen herbeoordelingsverzoek en HERBEOORDELING_OVERDUE flag.
	 */
	public const HERBEOORDELING_GRACE_DAGEN = 30;

	/**
	 * Retentie-termijn voor evidence-dossier (jaren) per AWR art. 52 (REQ-DBA-018).
	 */
	public const RETENTIE_TERMIJN_JAREN = 7;

	/**
	 * Geldigheidsduur (in dagen) van een WBA-beoordelingsresultaat (REQ-DBA-013).
	 */
	public const WBA_GELDIGHEID_DAGEN = 365;

	/**
	 * Risico-band thresholds (REQ-DBA-003).
	 */
	public const RISICO_BAND_LAAG_MAX = 24;
	public const RISICO_BAND_LAAG_MIDDEN_MAX = 49;
	public const RISICO_BAND_MIDDEN_HOOG_MAX = 74;
	// HOOG = 75..100.

	/**
	 * Compliance modes (REQ-DBA-000).
	 */
	public const COMPLIANCE_MODE_SOFT = 'soft';
	public const COMPLIANCE_MODE_HARD = 'hard';
	public const COMPLIANCE_MODE_INTERMEDIAIR = 'intermediair';

	/**
	 * App-config key prefix for DBA settings.
	 */
	public const CONFIG_PREFIX = 'dba.';

	/**
	 * Map a numeric risk-score (0-100) onto its band label.
	 *
	 * @param int $score The total DBA risk score (0-100).
	 *
	 * @return string One of LAAG / LAAG_MIDDEN / MIDDEN_HOOG / HOOG.
	 */
	public static function bandFromScore(int $score): string {
		if ($score <= self::RISICO_BAND_LAAG_MAX) {
			return 'LOW';
		}

		if ($score <= self::RISICO_BAND_LAAG_MIDDEN_MAX) {
			return 'LOW_MIDDEN';
		}

		if ($score <= self::RISICO_BAND_MIDDEN_HOOG_MAX) {
			return 'MIDDEN_HIGH';
		}

		return 'HIGH';
	}//end bandFromScore()

	/**
	 * Read the per-administration VBAR threshold (eurocenten), falling back to the
	 * peil-2024 constant when no override is configured.
	 *
	 * @param \OCP\IAppConfig $appConfig The Nextcloud app-config.
	 * @param string $appId The shillinq app id.
	 * @param string|null $administration FK; null reads the global default.
	 *
	 * @return int VBAR threshold in eurocenten.
	 */
	public static function vbarGrensCents(\OCP\IAppConfig $appConfig, string $appId, ?string $administration = null): int {
		$key = self::CONFIG_PREFIX . 'vbar_grens_cents';
		if ($administration !== null) {
			$key .= '.' . $administration;
		}

		$value = $appConfig->getValueInt($appId, $key, self::VBAR_GRENS_EUR_CENTS);
		if ($value > 0) {
			return $value;
		}

		return self::VBAR_GRENS_EUR_CENTS;
	}//end vbarGrensCents()

	/**
	 * Read the per-administration compliance mode.
	 *
	 * @param \OCP\IAppConfig $appConfig The Nextcloud app-config.
	 * @param string $appId The shillinq app id.
	 * @param string|null $administration FK; null reads the global default.
	 *
	 * @return string COMPLIANCE_MODE_SOFT / COMPLIANCE_MODE_HARD / COMPLIANCE_MODE_INTERMEDIAIR.
	 */
	public static function complianceMode(\OCP\IAppConfig $appConfig, string $appId, ?string $administration = null): string {
		$key = self::CONFIG_PREFIX . 'compliance_mode';
		if ($administration !== null) {
			$key .= '.' . $administration;
		}

		$value = $appConfig->getValueString($appId, $key, self::COMPLIANCE_MODE_SOFT);
		return match ($value) {
			self::COMPLIANCE_MODE_HARD => self::COMPLIANCE_MODE_HARD,
			self::COMPLIANCE_MODE_INTERMEDIAIR => self::COMPLIANCE_MODE_INTERMEDIAIR,
			default => self::COMPLIANCE_MODE_SOFT,
		};
	}//end complianceMode()
}//end class
