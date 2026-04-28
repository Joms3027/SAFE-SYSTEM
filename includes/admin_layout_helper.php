<?php
/**
 * Helper function to get base path for assets in admin pages
 */
function get_admin_base_path() {
    $basePath = '';
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        $pathSegments = array_filter(explode('/', $scriptPath));
        if (count($pathSegments) > 0) {
            $basePath = '/' . reset($pathSegments);
        }
    }
    if (empty($basePath) && isset($_SERVER['REQUEST_URI'])) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($requestUri && $requestUri !== '/') {
            $uriSegments = array_filter(explode('/', $requestUri));
            if (count($uriSegments) > 0) {
                $basePath = '/' . reset($uriSegments);
            }
        }
    }
    if (empty($basePath)) {
        $basePath = '/FP';
    }
    return $basePath;
}

/**
 * Output the standard admin page head section
 */
function admin_page_head($title, $description = '') {
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <?php if ($description): ?>
    <meta name="description" content="<?php echo htmlspecialchars($description); ?>">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($title); ?> - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('logo.png', true); ?>">
    <!-- Critical CSS inline - ensures layout works even if external stylesheets fail (404/MIME) -->
    <style>
    :root{--primary:#003366;--bg:#f8fafc;--white:#fff;--text:#0f172a;--border:#e2e8f0}
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
    .header{position:fixed;top:0;left:0;right:0;height:56px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;z-index:1030}
    .sidebar,.office-sidebar{position:fixed;top:0;left:0;width:280px;height:100vh;background:var(--white);border-right:1px solid var(--border);z-index:1028;transform:translateX(-100%);transition:transform .25s}
    .sidebar.show,.office-sidebar.show{transform:translateX(0)}
    @media(min-width:992px){.header{left:280px;width:calc(100% - 280px)}.sidebar,.office-sidebar{transform:translateX(0)}}
    .main-content{margin-top:56px;padding:1rem;min-height:calc(100vh - 56px);background:var(--bg)}
    @media(min-width:992px){.main-content{margin-left:280px;width:calc(100% - 280px);padding:1.5rem}}
    .container-fluid{width:100%;padding:0 1rem}
    .card{background:var(--white);border:1px solid var(--border);border-radius:.5rem;margin-bottom:1rem}
    .card-header{background:#f8fafc;border-bottom:1px solid var(--border);padding:.75rem 1rem;font-weight:600}
    .card-body{padding:1rem}
    .page-header{background:var(--white);border:1px solid var(--border);border-radius:.5rem;padding:1rem;margin-bottom:1rem}
    .page-title{font-size:1.25rem;font-weight:600}
    .form-label{display:block;margin-bottom:.25rem;font-weight:500}
    .form-control{width:100%;padding:.5rem .75rem;border:1px solid var(--border);border-radius:.375rem}
    .btn{padding:.5rem 1rem;border-radius:.375rem;display:inline-block;text-decoration:none;border:1px solid}
    .btn-primary{background:var(--primary);color:var(--white);border-color:var(--primary)}
    .btn-outline-primary{background:transparent;color:var(--primary);border-color:var(--primary)}
    .alert{padding:1rem;border-radius:.5rem;margin-bottom:1rem}
    .alert-success{background:#ecfdf5;color:#065f46}
    .alert-danger{background:#fef2f2;color:#991b1b}
    .alert-warning{background:#fffbeb;color:#92400e}
    .row{display:flex;flex-wrap:wrap;margin:0 -0.5rem}
    .col-md-4,.col-md-6,.col-12{flex:0 0 100%;max-width:100%;padding:0 .5rem}
    @media(min-width:768px){.col-md-4{flex:0 0 33.333%;max-width:33.333%}.col-md-6{flex:0 0 50%;max-width:50%}}
    </style>
    <!-- Preload critical CSS for faster rendering -->
    <link rel="preload" href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" as="style">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/admin-portal.css', true); ?>" rel="stylesheet">
    <?php
}

/**
 * Output the standard admin page scripts
 */
function admin_page_scripts() {
    ?>
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/mobile.js', true); ?>"></script>
    <?php
}

/**
 * Output a standard admin page header
 */
function admin_page_header($title, $subtitle = '', $icon = '', $breadcrumbs = [], $actions = '') {
    ?>
    <div class="page-header">
        <div>
            <?php if (!empty($breadcrumbs)): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <?php if ($index === count($breadcrumbs) - 1): ?>
                            <li class="breadcrumb-item active" aria-current="page">
                                <?php if (isset($crumb['icon'])): ?>
                                    <i class="<?php echo htmlspecialchars($crumb['icon']); ?> me-1"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($crumb['label']); ?>
                            </li>
                        <?php else: ?>
                            <li class="breadcrumb-item">
                                <a href="<?php echo htmlspecialchars($crumb['url']); ?>">
                                    <?php if (isset($crumb['icon'])): ?>
                                        <i class="<?php echo htmlspecialchars($crumb['icon']); ?> me-1"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($crumb['label']); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>
            <div class="page-title">
                <?php if ($icon): ?>
                    <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($title); ?></span>
            </div>
            <?php if ($subtitle): ?>
                <p class="page-subtitle"><?php echo htmlspecialchars($subtitle); ?></p>
            <?php endif; ?>
        </div>
        <?php if ($actions): ?>
            <div class="d-none d-md-flex align-items-center gap-2">
                <?php echo $actions; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

