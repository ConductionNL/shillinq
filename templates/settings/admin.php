<?php

use OCP\Util;

$appId = OCA\Shillinq\AppInfo\Application::APP_ID;
// Same fix as templates/index.php: the `adminSettings` webpack entry
// (built here as `shillinq-settings.js`) shares the SAME `ncVue`/`vendor`
// splitChunks cacheGroups as `main` (frontend-bundle-hygiene/ADR-061 —
// `minChunks: 2` only extracts modules reachable from BOTH entries), so it
// depends on the same two shared chunk files and never loaded them either.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="shillinq-settings"></div>
