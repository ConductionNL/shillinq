<?php

use OCP\Util;

$appId = OCA\AppTemplate\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="app-template-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
