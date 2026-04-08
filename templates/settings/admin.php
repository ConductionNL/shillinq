<?php

use OCP\Util;

$appId = OCA\Shillinq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="shillinq-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
