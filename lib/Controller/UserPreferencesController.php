<?php

/**
 * Shillinq User Preferences Controller
 *
 * Controller for managing per-user preferences.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing per-user Shillinq preferences.
 *
 * @spec openspec/changes/core/tasks.md#task-10
 */
class UserPreferencesController extends Controller
{
    /**
     * Preference keys managed by this controller.
     *
     * @var array<string>
     */
    private const PREF_KEYS = [
        'language',
        'dateFormat',
        'notificationEmail',
        'notificationInApp',
    ];

    /**
     * Constructor for UserPreferencesController.
     *
     * @param IRequest     $request     The request object
     * @param IConfig      $config      The config interface
     * @param IUserSession $userSession The user session
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-10
     */
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get the current user's preferences.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/core/tasks.md#task-10
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse([], 401);
        }

        $userId = $user->getUID();
        $prefs  = [];

        foreach (self::PREF_KEYS as $key) {
            $prefs[$key] = $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: $key,
                default: ''
            );
        }

        // Convert boolean strings back to booleans.
        foreach (['notificationEmail', 'notificationInApp'] as $boolKey) {
            $val = $prefs[$boolKey];
            $prefs[$boolKey] = ($val === '' || $val === 'true' || $val === '1');
        }

        return new JSONResponse($prefs);
    }//end index()

    /**
     * Update the current user's preferences.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/core/tasks.md#task-10
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $userId = $user->getUID();
        $data   = $this->request->getParams();

        foreach (self::PREF_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $value = $data[$key];
                if (is_bool($value) === true) {
                    $value = ($value ? 'true' : 'false');
                }

                $this->config->setUserValue(
                    userId: $userId,
                    appName: Application::APP_ID,
                    key: $key,
                    value: (string) $value
                );
            }
        }

        return new JSONResponse(['success' => true]);
    }//end create()
}//end class
