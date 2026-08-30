<?php

use OCP\Util;

$appId = OCA\Shillinq\AppInfo\Application::APP_ID;
// Same three-file entry point as templates/index.php — webpack's build output:
//
//     Entrypoint adminSettings = shillinq-shared-nc-vue.js
//                                shillinq-shared-vendor.js
//                                shillinq-settings.js
//
// `adminSettings` shares the framework chunks with `main` (that is what
// `minChunks: 2` selects for), so this template has to load them too. See the
// longer note in templates/index.php.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="shillinq-settings"></div>
