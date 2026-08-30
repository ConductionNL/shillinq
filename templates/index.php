<?php

use OCP\Util;

$appId = OCA\Shillinq\AppInfo\Application::APP_ID;
// The webpack build (see webpack.config.js -> optimization.splitChunks, added
// by #932) emits this entry point as THREE files, not one. Webpack's own build
// output says so:
//
//     Entrypoint main = shillinq-shared-nc-vue.js
//                       shillinq-shared-vendor.js
//                       shillinq-main.js
//
// All three must be loaded, in dependency order — `shillinq-main.js` references
// modules that now live in the shared chunks. An `initial` chunk is NOT fetched
// by the webpack runtime; the page has to request it, and this app has no
// HtmlWebpackPlugin to inject the tags.
//
// Loading only `-main` is what broke the SPA on `development`: the entry went
// 12,468,786 -> 9,158,113 bytes, the framework never arrived, and ~190 e2e
// tests failed with `locator('main, [role="main"]')` never appearing. Frontend
// Build stayed green throughout — emitting a chunk nobody requests is not a
// build error. pipelinq/templates/index.php carries the same three lines for
// the same reason.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="shillinq-app"></div>
