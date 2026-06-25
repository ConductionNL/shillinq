<?php

/**
 * Shillinq first-time setup contract (ADR-042).
 *
 * Backs the abstract CnSetupWizard. Shillinq is the canonical "block-on-required"
 * case: the legal region, RGS chart-of-accounts template and the active
 * administration MUST be chosen before the app is usable, after which an admin can
 * seed the chart of accounts + region-specific reference data. Seeding runs here
 * (admin request context, OpenRegister RBAC satisfied) and is rejected (422) while
 * any required choice is unmet — enforcing the C2 "no tenant data without
 * administration" constraint at the server, not only in the UI.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://shillinq.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * First-time setup status + actions for the abstract setup wizard.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class SetupController extends Controller
{
    /**
     * @var int Setup contract version; matches manifest.setup.version.
     */
    private const SETUP_VERSION = 1;

    /**
     * @param string          $appName         The app id.
     * @param IRequest        $request         The request.
     * @param IAppConfig      $appConfig       App-config reader/writer.
     * @param SettingsService $settingsService OR availability + config import + seeders.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly SettingsService $settingsService,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Report per-step setup status for the wizard.
     *
     * @return DataResponse `{ version, completed, steps: { <id>: { done } } }`.
     *
     * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function status(): DataResponse
    {
        $countryDone  = $this->config(key: 'legal_country') !== '';
        $regionDone   = $this->config(key: 'legal_region') !== '';
        $rgsDone      = $this->config(key: 'rgs_template') !== '';
        $adminDone    = $this->config(key: 'administration_id') !== '';
        $seedDone     = $this->config(key: 'setup_seed_done') === '1';
        $requiredDone = ($countryDone === true && $regionDone === true && $rgsDone === true && $adminDone === true);

        if ($requiredDone === true) {
            $this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string) self::SETUP_VERSION);
        }

        return new DataResponse(
            [
                'version'   => self::SETUP_VERSION,
                'completed' => $requiredDone,
                'steps'     => [
                    'country'        => ['done' => $countryDone],
                    'organisation'   => ['done' => $regionDone],
                    'rgs-template'   => ['done' => $rgsDone],
                    'administration' => ['done' => $adminDone],
                    'seed'           => ['done' => $seedDone],
                ],
            ]
        );
    }//end status()

    /**
     * Persist app-config values from a `choice` / `config-fields` step.
     *
     * @return DataResponse `{ success }`.
     *
     * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function saveConfig(): DataResponse
    {
        foreach ($this->request->getParams() as $key => $value) {
            if ($key === '_route') {
                continue;
            }

            $this->appConfig->setValueString(
                Application::APP_ID,
                (string) $key,
                is_scalar($value) ? (string) $value : json_encode($value),
            );
        }

        return new DataResponse(['success' => true]);
    }//end saveConfig()

    /**
     * Run a privileged server-side setup action.
     *
     * @param string $actionId One of `init-administration` | `seed`.
     *
     * @return DataResponse `{ success, message, detail }`.
     *
     * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function runAction(string $actionId): DataResponse
    {
        if ($actionId === 'init-administration') {
            $this->settingsService->loadConfigurationForced();
            $result  = $this->settingsService->seedDefaultAdministration();
            $adminId = ($result['administrationId'] ?? $result['id'] ?? 'ADM-001');
            $this->appConfig->setValueString(Application::APP_ID, 'administration_id', (string) $adminId);
            return new DataResponse(['success' => true, 'message' => 'Default administration created.', 'detail' => $result]);
        }

        if ($actionId === 'seed') {
            $region   = $this->config(key: 'legal_region');
            $template = $this->config(key: 'rgs_template');
            $adminId  = $this->config(key: 'administration_id');
            if ($region === '' || $template === '' || $adminId === '') {
                return new DataResponse(
                    ['success' => false, 'message' => 'Choose region, RGS template and administration before seeding.'],
                    Http::STATUS_UNPROCESSABLE_ENTITY,
                );
            }

            $this->settingsService->seedRgsTemplate(templateVariant: $template, administrationId: $adminId);
            $this->settingsService->seedBtwTariffs();
            $this->settingsService->seedBbvTaakvelden();
            $this->appConfig->setValueString(Application::APP_ID, 'setup_seed_done', '1');
            return new DataResponse(['success' => true, 'message' => 'Chart of accounts and reference data seeded.']);
        }

        return new DataResponse(
            ['success' => false, 'message' => 'Unknown setup action: '.$actionId],
            Http::STATUS_NOT_FOUND,
        );
    }//end runAction()

    /**
     * Read a shillinq app-config string value.
     *
     * @param string $key The config key.
     *
     * @return string The value, or '' when unset.
     */
    private function config(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end config()
}//end class
