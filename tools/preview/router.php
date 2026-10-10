<?php

/*
 * Lens's pages in a browser, without a router.
 *
 *     LENS_PREVIEW_DB=/tmp/lens.sqlite OPNSENSE_CORE=/path/to/opnsense/core \
 *         php -S 127.0.0.1:8088 tools/preview/router.php
 *
 * then open http://127.0.0.1:8088/ui/lens/dashboard (add ?theme=opnsense-dark).
 *
 * Views are rendered with the only two Volt expressions Lens uses, lang._()
 * and cache_safe(), inside a copy of core's page chrome and core's own theme
 * CSS; API calls reach Lens's real controllers and models through stubs.php.
 * Nothing here is packaged: it lives outside src/ (PROCESS edge case 8).
 */

define('PREVIEW_ROOT', dirname(__DIR__, 2));
define('PREVIEW_LENS', PREVIEW_ROOT . '/net-mgmt/lens/src/opnsense');
define('PREVIEW_FIXTURES', __DIR__ . '/fixtures');
define('PREVIEW_DB', getenv('LENS_PREVIEW_DB') ?: sys_get_temp_dir() . '/lens-preview.sqlite');
define('PREVIEW_CORE', rtrim((string)getenv('OPNSENSE_CORE'), '/') . '/src/opnsense');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$types = [
    'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
    'json' => 'application/json',
];

/* static files: Lens's own first, then core's */
if (preg_match('#^/ui/((?:css|js|themes|assets)/.+)$#', $path, $m)) {
    foreach ([PREVIEW_LENS . '/www/' . $m[1], PREVIEW_CORE . '/www/' . $m[1]] as $file) {
        if (is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
    http_response_code(404);
    return true;
}

if (preg_match('#^/api/lens/([a-z]+)/([a-zA-Z_]+)$#', $path, $m)) {
    require __DIR__ . '/stubs.php';
    spl_autoload_register(function ($class) {
        $parts = explode('\\', $class);
        if ($parts[0] !== 'OPNsense' || ($parts[1] ?? '') !== 'Lens') {
            return;
        }
        $file = ($parts[2] ?? '') === 'Api'
            ? PREVIEW_LENS . '/mvc/app/controllers/OPNsense/Lens/Api/' . $parts[3] . '.php'
            : PREVIEW_LENS . '/mvc/app/models/OPNsense/Lens/' . $parts[2] . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    $class = 'OPNsense\\Lens\\Api\\' . ucfirst($m[1]) . 'Controller';
    /* as core: pause_group is pauseGroupAction */
    $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $m[2])))) . 'Action';
    if (!class_exists($class) || !method_exists($class, $method)) {
        http_response_code(404);
        return true;
    }
    ob_start();
    $controller = new $class();
    $result = $controller->$method();
    $printed = ob_get_clean();
    if (is_array($result)) {
        header('Content-Type: application/json');
        echo json_encode($result);
    } else {
        header('Content-Type: text/plain');
        echo $printed . (is_string($result) ? $result : '');
    }
    return true;
}

if (!preg_match('#^/ui/lens/([a-z]+)$#', $path, $m) || !is_file(PREVIEW_LENS . "/mvc/app/views/OPNsense/Lens/{$m[1]}.volt")) {
    header('Location: /ui/lens/dashboard');
    return true;
}

$page = $m[1];
$theme = preg_replace('/[^a-z-]/', '', $_GET['theme'] ?? 'opnsense');

/* the menu, from Lens's own Menu.xml, so titles and order are the real ones */
$menu = simplexml_load_file(PREVIEW_LENS . '/mvc/app/models/OPNsense/Lens/Menu/Menu.xml');
$title = 'Lens';
$items = [];
foreach (['Reporting', 'Services'] as $root) {
    foreach ($menu->$root->Lens->children() as $item) {
        $url = (string)$item['url'];
        $items[] = [$root, (string)$item['VisibleName'], $url];
        if ($url === "/ui/lens/$page") {
            $title = "$root: Lens: " . (string)$item['VisibleName'];
        }
        /* the hidden children of Devices, as core names them: parent, then child */
        foreach ($item->children() as $child) {
            if ((string)$child['url'] === "/ui/lens/$page*") {
                $title = "$root: Lens: " . (string)$item['VisibleName'] . ': ' . (string)$child['VisibleName'];
            }
        }
    }
}

$view = (string)file_get_contents(PREVIEW_LENS . "/mvc/app/views/OPNsense/Lens/$page.volt");
$view = preg_replace('/\{#.*?#\}/s', '', $view);
$view = preg_replace_callback("/\\{\\{\\s*lang\\._\\('((?:[^'\\\\]|\\\\.)*)'\\)\\s*\\}\\}/", function ($x) {
    return str_replace("\\'", "'", $x[1]);
}, $view);
$view = preg_replace_callback('/\{\{\s*lang\._\("((?:[^"\\\\]|\\\\.)*)"\)\s*\}\}/', function ($x) {
    return $x[1];
}, $view);
$view = preg_replace("/\\{\\{\\s*cache_safe\\('([^']+)'\\)\\s*\\}\\}/", '$1', $view);

$nav = '';
$last = '';
foreach ($items as [$root, $name, $url]) {
    if ($root !== $last) {
        $nav .= '<a class="list-group-item" style="font-weight:600">' . htmlspecialchars("$root: Lens") . '</a>';
        $last = $root;
    }
    $active = $url === "/ui/lens/$page" ? ' active' : '';
    $nav .= '<a class="list-group-item menu-level-3-item' . $active . '" href="' . $url . '?theme=' . $theme . '">'
        . htmlspecialchars($name) . '</a>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
    <title><?= htmlspecialchars($title) ?> | preview</title>
    <link href="/ui/themes/<?= $theme ?>/build/css/main.css" rel="stylesheet">
    <link href="/ui/assets/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="/ui/assets/fontawesome/css/v4-shims.min.css" rel="stylesheet">
    <style>.menu-level-3-item { font-size: 90%; padding-left: 54px !important; }</style>
    <script src="/ui/js/jquery-3.5.1.min.js"></script>
    <script src="/ui/js/opnsense.js"></script>
    <script src="/ui/js/bootstrap.min.js"></script>
</head>
<body>
<header class="page-head">
    <nav class="navbar navbar-default">
        <div class="container-fluid">
            <div class="navbar-header">
                <a class="navbar-brand" href="/ui/lens/dashboard">
                    <img class="brand-logo" src="/ui/themes/<?= $theme ?>/build/images/default-logo.svg" height="30" alt="logo"/>
                </a>
            </div>
            <div class="collapse navbar-collapse">
                <ul class="nav navbar-nav navbar-right">
                    <li><span class="navbar-text">root@router-01.home.arpa &middot; preview</span></li>
                </ul>
            </div>
        </div>
    </nav>
</header>
<main class="page-content col-sm-9 col-sm-push-3 col-lg-10 col-lg-push-2">
    <aside id="navigation" class="page-side col-xs-12 col-sm-3 col-lg-2 hidden-xs">
        <div class="row">
            <nav class="page-side-nav">
                <div id="mainmenu" class="panel" style="border:0px">
                    <div class="panel list-group" style="border:0px"><?= $nav ?></div>
                </div>
            </nav>
        </div>
    </aside>
    <div class="row">
        <header class="page-content-head">
            <div class="container-fluid">
                <ul class="list-inline"><li><h1><?= htmlspecialchars($title) ?></h1></li></ul>
            </div>
        </header>
        <section class="page-content-main">
            <div class="container-fluid">
                <div class="row">
                    <section class="col-xs-12">
                        <div id="messageregion"></div>
<?= $view ?>
                    </section>
                </div>
            </div>
        </section>
    </div>
</main>
</body>
</html>
