<?php

use OCP\Util;

$appId = OCA\Shillinq\AppInfo\Application::APP_ID;
// The webpack build (see webpack.config.js → optimization.splitChunks,
// frontend-bundle-hygiene/ADR-061) emits the `main` entry as THREE files:
// the shared vendor chunk (vue/vue-router/pinia/@vueuse), the shared
// @conduction/nextcloud-vue chunk, and the entry chunk itself. Both shared
// chunks use `chunks: 'initial'` + `enforce: true`, so webpack does NOT
// wire them up via its own runtime (JSONP) chunk loader the way an async
// `import()` chunk would — they must be present in the page BEFORE
// `shillinq-main.js` runs, or its very first `require()` of Vue/nc-vue
// throws and the app never mounts. All three must be loaded here, in
// dependency order (mirrors pipelinq/templates/index.php, the pattern this
// change was modelled on).
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="shillinq-app"></div>
