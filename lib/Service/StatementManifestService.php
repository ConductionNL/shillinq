<?php

/**
 * Shillinq Statement Manifest Service
 *
 * Imports the RJ 270 financial-statement presentation manifests shipped under
 * lib/Settings/statements/ into the Nextcloud app-config store. Per ADR-022 /
 * ADR-031 shillinq ships no PHP report builder or statement-storage table — the
 * manifests are declarative presentation metadata consumed by the manifest-bound
 * statement pages.
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
 * @spec openspec/specs/bookkeeping-financial-statements/spec.md (REQ-FS-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Idempotently imports the RJ 270 statement-presentation manifests.
 *
 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
 */
class StatementManifestService
{

    /**
     * Statement-manifest files shipped under lib/Settings/statements/.
     *
     * Keyed by the app-config key used for idempotent persistence so that
     * operator edits survive subsequent repair-step runs (REQ-FS-002).
     *
     * @var array<string,string>
     */
    private const STATEMENT_MANIFESTS = [
        'statement_manifest_balance_sheet' => 'rj270-balance-sheet.json',
        'statement_manifest_pl'            => 'rj270-pl.json',
        'statement_manifest_cash_flow'     => 'rj270-cash-flow.json',
    ];

    /**
     * Construct the service.
     *
     * @param IAppConfig      $appConfig App config store for manifest persistence.
     * @param LoggerInterface $logger    Nextcloud logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Import the RJ 270 statement-presentation manifests idempotently.
     *
     * Operator-edited manifests already present in app config are preserved.
     * Use {@see self::importForced()} to bypass that guard.
     *
     * @return array{success: bool, imported: int, skipped: int, message?: string}
     *
     * @spec openspec/specs/bookkeeping-financial-statements/spec.md
     */
    public function import(): array
    {
        return $this->run(force: false);

    }//end import()

    /**
     * Force re-import of the statement-presentation manifests.
     *
     * Overwrites any operator-edited manifest. Used by forced register upgrades
     * that bump the shillinq_register.json version.
     *
     * @return array{success: bool, imported: int, skipped: int, message?: string}
     *
     * @spec openspec/specs/bookkeeping-financial-statements/spec.md
     */
    public function importForced(): array
    {
        return $this->run(force: true);

    }//end importForced()

    /**
     * Internal implementation for the statement-manifest import.
     *
     * @param bool $force When true, re-import even if a key already exists.
     *
     * @return array{success: bool, imported: int, skipped: int, message?: string}
     */
    private function run(bool $force): array
    {
        $imported = 0;
        $skipped  = 0;

        foreach (self::STATEMENT_MANIFESTS as $configKey => $fileName) {
            $manifest = $this->loadManifest(fileName: $fileName);
            if ($manifest === null) {
                return [
                    'success'  => false,
                    'imported' => $imported,
                    'skipped'  => $skipped,
                    'message'  => 'Failed to load statement manifest: '.$fileName,
                ];
            }

            $existing = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
            if ($existing !== '' && $force === false) {
                // Idempotent: operator-edited manifest already present — preserve it.
                $skipped++;
                continue;
            }

            $this->appConfig->setValueString(
                Application::APP_ID,
                $configKey,
                json_encode($manifest)
            );
            $imported++;
        }//end foreach

        $this->logger->info(
            'Shillinq: statement manifests imported',
            ['imported' => $imported, 'skipped' => $skipped]
        );

        return [
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
        ];

    }//end run()

    /**
     * Load and validate a single statement-presentation manifest file.
     *
     * Returns the decoded manifest array on success, or null on any IO/parse
     * error (logged). Validates the presence of the `_meta` block and a
     * non-empty `sections` array (REQ-FS-002).
     *
     * @param string $fileName File name under lib/Settings/statements/.
     *
     * @return array<string,mixed>|null
     */
    private function loadManifest(string $fileName): ?array
    {
        $path = __DIR__.'/../Settings/statements/'.$fileName;
        if (file_exists($path) === false) {
            $this->logger->error('Shillinq: statement manifest not found at '.$path);
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->logger->error('Shillinq: failed to read statement manifest '.$fileName);
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error(
                'Shillinq: failed to parse statement manifest '.$fileName,
                ['error' => json_last_error_msg()]
            );
            return null;
        }

        if (isset($data['_meta']) === false || empty($data['statement']['sections']) === true) {
            $this->logger->error('Shillinq: statement manifest '.$fileName.' missing _meta or statement.sections');
            return null;
        }

        return $data;

    }//end loadManifest()
}//end class
