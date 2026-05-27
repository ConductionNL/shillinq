<?php

/**
 * Shillinq PreferencesController.
 *
 * Generic per-user key/value preferences, backed by Nextcloud IConfig
 * user values. Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag) that need to persist a small per-user
 * UI flag cross-device without a bespoke endpoint per feature.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/shillinq
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user preferences controller.
 *
 * @spec openspec/changes/retrofit-2026-05-26-preferences-api/tasks.md
 */
class PreferencesController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest     $request     The request.
     * @param IConfig      $config      The Nextcloud config (user values).
     * @param IUserSession $userSession The user session.
     */
    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Read a per-user preference value.
     *
     * @param string $key The preference key (kebab/alphanumeric).
     *
     * @return JSONResponse `{value: string|null}`.
     *
     * @spec openspec/changes/retrofit-2026-05-26-preferences-api/tasks.md#task-1
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getPreference(string $key): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $keyResult = $this->sanitizeKey(key: $key);
        if ($keyResult['error'] !== null) {
            return new JSONResponse(data: ['message' => $keyResult['error']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $safeKey = $keyResult['key'];

        $value = $this->config->getUserValue(
            userId: $user->getUID(),
            appName: Application::APP_ID,
            key: $safeKey,
            default: ''
        );

        $stored = null;
        if ($value !== '') {
            $stored = $value;
        }

        return new JSONResponse(data: ['key' => $safeKey, 'value' => $stored]);

    }//end getPreference()

    /**
     * Write a per-user preference value. An empty value clears it.
     *
     * @param string $key   The preference key (kebab/alphanumeric).
     * @param string $value The value to store (empty string clears it).
     *
     * @return JSONResponse `{value: string|null}`.
     *
     * @spec openspec/changes/retrofit-2026-05-26-preferences-api/tasks.md#task-2
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setPreference(string $key, string $value=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $keyResult = $this->sanitizeKey(key: $key);
        if ($keyResult['error'] !== null) {
            return new JSONResponse(data: ['message' => $keyResult['error']], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $safeKey = $keyResult['key'];

        if ($value === '') {
            $this->config->deleteUserValue(
                userId: $user->getUID(),
                appName: Application::APP_ID,
                key: $safeKey
            );
            return new JSONResponse(data: ['key' => $safeKey, 'value' => null]);
        }

        $this->config->setUserValue(
            userId: $user->getUID(),
            appName: Application::APP_ID,
            key: $safeKey,
            value: $value
        );

        return new JSONResponse(data: ['key' => $safeKey, 'value' => $value]);

    }//end setPreference()

    /**
     * Restrict keys to a safe charset and enforce a 64-character limit.
     *
     * Keys are restricted to lowercase alphanumeric characters and hyphens so
     * callers cannot reach arbitrary IConfig user values. Keys exceeding 64
     * characters are rejected (400) rather than silently truncated, making the
     * API contract explicit and avoiding hard-to-debug key collisions.
     *
     * @param string $key The raw key.
     *
     * @return array{key: string, error: string|null} `key` is the canonical key;
     *         `error` is non-null when the key is invalid (empty or too long).
     */
    private function sanitizeKey(string $key): array
    {
        $safe = (string) preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));

        if ($safe === '') {
            return ['key' => '', 'error' => 'Invalid key: must contain at least one alphanumeric character or hyphen.'];
        }

        if (strlen($safe) > 64) {
            return [
                'key'   => '',
                'error' => 'Key too long: maximum 64 characters (after stripping non-alphanumeric characters).',
            ];
        }

        return ['key' => $safe, 'error' => null];

    }//end sanitizeKey()
}//end class
