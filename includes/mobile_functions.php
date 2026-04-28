<?php
/**
 * Mobile Responsive Functions
 * Handles mobile detection, responsive helpers, and mobile-specific functionality
 */

/**
 * Detect if the current request is from a mobile device
 * @return bool True if mobile device, false otherwise
 */
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Common mobile device patterns
    $mobilePatterns = [
        'Android',
        'iPhone',
        'iPad',
        'iPod',
        'BlackBerry',
        'Windows Phone',
        'webOS',
        'Opera Mini',
        'Opera Mobi',
        'Mobile',
        'Tablet'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (stripos($userAgent, $pattern) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Detect if the current request is from a tablet device
 * @return bool True if tablet device, false otherwise
 */
function isTabletDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $tabletPatterns = ['iPad', 'Android.*Tablet', 'Kindle', 'Silk', 'PlayBook'];
    
    foreach ($tabletPatterns as $pattern) {
        if (preg_match('/' . $pattern . '/i', $userAgent)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Detect if the current request is from an iOS device
 * @return bool True if iOS device, false otherwise
 */
function isIOSDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/(iPad|iPhone|iPod)/i', $userAgent);
}

/**
 * Detect if the current request is from an Android device
 * @return bool True if Android device, false otherwise
 */
function isAndroidDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return stripos($userAgent, 'Android') !== false;
}

/**
 * Get device type as string
 * @return string 'mobile', 'tablet', or 'desktop'
 */
function getDeviceType() {
    if (isTabletDevice()) {
        return 'tablet';
    } elseif (isMobileDevice()) {
        return 'mobile';
    }
    return 'desktop';
}

/**
 * Add mobile-specific meta tags to the page
 * Call this in the <head> section
 */
function addMobileMetaTags() {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    echo '<meta name="theme-color" content="#003366">' . "\n";
}

/**
 * Load mobile-specific CSS
 * @param string $deviceType Optional device type ('mobile', 'tablet', 'desktop')
 */
function loadMobileCSS($deviceType = null) {
    if ($deviceType === null) {
        $deviceType = getDeviceType();
    }
    
    if ($deviceType === 'mobile' || $deviceType === 'tablet') {
        echo '<link href="' . asset_url('css/mobile.css') . '" rel="stylesheet">' . "\n";
    }
}

/**
 * Load mobile-specific JavaScript
 */
function loadMobileJS() {
    if (isMobileDevice()) {
        echo '<script src="' . asset_url('js/mobile.js') . '"></script>' . "\n";
    }
}

/**
 * Generate responsive image srcset for different screen sizes
 * @param string $imagePath Base image path
 * @param array $sizes Array of sizes (e.g., [320, 640, 1024])
 * @return string HTML img tag with srcset
 */
function responsiveImage($imagePath, $alt = '', $sizes = [320, 640, 1024, 1920]) {
    $pathInfo = pathinfo($imagePath);
    $srcset = [];
    
    foreach ($sizes as $width) {
        $srcset[] = $imagePath . " {$width}w";
    }
    
    $srcsetAttr = implode(', ', $srcset);
    $sizesAttr = '(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw';
    
    return sprintf(
        '<img src="%s" srcset="%s" sizes="%s" alt="%s" loading="lazy">',
        htmlspecialchars($imagePath),
        htmlspecialchars($srcsetAttr),
        $sizesAttr,
        htmlspecialchars($alt)
    );
}

/**
 * Render mobile-friendly table with responsive cards on small screens
 * @param array $headers Table headers
 * @param array $rows Table rows (array of arrays)
 * @param string $tableClass Additional CSS classes
 */
function renderResponsiveTable($headers, $rows, $tableClass = '') {
    echo '<div class="table-responsive">';
    echo '<table class="table ' . htmlspecialchars($tableClass) . '">';
    
    // Desktop headers
    echo '<thead class="d-none d-md-table-header-group"><tr>';
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr></thead>';
    
    // Body with data-label attributes for mobile
    echo '<tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $index => $cell) {
            $label = $headers[$index] ?? '';
            echo '<td data-label="' . htmlspecialchars($label) . '">' . $cell . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

/**
 * Generate mobile-friendly pagination
 * @param int $currentPage Current page number
 * @param int $totalPages Total number of pages
 * @param string $baseUrl Base URL for pagination links
 * @param array $queryParams Additional query parameters
 */
function renderMobilePagination($currentPage, $totalPages, $baseUrl = '', $queryParams = []) {
    if ($totalPages <= 1) return '';
    
    $isMobile = isMobileDevice();
    $maxLinks = $isMobile ? 3 : 5;
    
    echo '<nav aria-label="Page navigation" class="mt-4">';
    echo '<ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $queryParams['page'] = $currentPage - 1;
        $url = $baseUrl . '?' . http_build_query($queryParams);
        echo '<li class="page-item">';
        echo '<a class="page-link" href="' . htmlspecialchars($url) . '" aria-label="Previous">';
        echo $isMobile ? '&laquo;' : '<span aria-hidden="true">&laquo;</span> Previous';
        echo '</a></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - floor($maxLinks / 2));
    $end = min($totalPages, $start + $maxLinks - 1);
    
    // Adjust start if we're near the end
    if ($end - $start < $maxLinks - 1) {
        $start = max(1, $end - $maxLinks + 1);
    }
    
    // First page
    if ($start > 1) {
        $queryParams['page'] = 1;
        $url = $baseUrl . '?' . http_build_query($queryParams);
        echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    // Page links
    for ($i = $start; $i <= $end; $i++) {
        $queryParams['page'] = $i;
        $url = $baseUrl . '?' . http_build_query($queryParams);
        $active = $i === $currentPage ? ' active' : '';
        echo '<li class="page-item' . $active . '">';
        echo '<a class="page-link" href="' . htmlspecialchars($url) . '">' . $i . '</a>';
        echo '</li>';
    }
    
    // Last page
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $queryParams['page'] = $totalPages;
        $url = $baseUrl . '?' . http_build_query($queryParams);
        echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">' . $totalPages . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $queryParams['page'] = $currentPage + 1;
        $url = $baseUrl . '?' . http_build_query($queryParams);
        echo '<li class="page-item">';
        echo '<a class="page-link" href="' . htmlspecialchars($url) . '" aria-label="Next">';
        echo $isMobile ? '&raquo;' : 'Next <span aria-hidden="true">&raquo;</span>';
        echo '</a></li>';
    }
    
    echo '</ul>';
    echo '</nav>';
}

/**
 * Render mobile-optimized breadcrumbs
 * @param array $breadcrumbs Array of breadcrumb items ['label' => 'Home', 'url' => '/']
 */
function renderMobileBreadcrumbs($breadcrumbs) {
    if (empty($breadcrumbs)) return '';
    
    $isMobile = isMobileDevice();
    
    echo '<nav aria-label="breadcrumb">';
    echo '<ol class="breadcrumb">';
    
    $total = count($breadcrumbs);
    
    // On mobile, only show first and last if there are many items
    if ($isMobile && $total > 3) {
        // Show first
        $first = $breadcrumbs[0];
        echo '<li class="breadcrumb-item">';
        if (!empty($first['url'])) {
            echo '<a href="' . htmlspecialchars($first['url']) . '">' . htmlspecialchars($first['label']) . '</a>';
        } else {
            echo htmlspecialchars($first['label']);
        }
        echo '</li>';
        
        // Show ellipsis
        echo '<li class="breadcrumb-item">...</li>';
        
        // Show last
        $last = $breadcrumbs[$total - 1];
        echo '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($last['label']) . '</li>';
    } else {
        // Show all items
        foreach ($breadcrumbs as $index => $crumb) {
            $isLast = $index === $total - 1;
            echo '<li class="breadcrumb-item' . ($isLast ? ' active' : '') . '"' . ($isLast ? ' aria-current="page"' : '') . '>';
            
            if (!$isLast && !empty($crumb['url'])) {
                echo '<a href="' . htmlspecialchars($crumb['url']) . '">' . htmlspecialchars($crumb['label']) . '</a>';
            } else {
                echo htmlspecialchars($crumb['label']);
            }
            
            echo '</li>';
        }
    }
    
    echo '</ol>';
    echo '</nav>';
}

/**
 * Render mobile-friendly action buttons
 * @param array $actions Array of actions ['label' => 'Edit', 'url' => '#', 'icon' => 'fa-edit', 'class' => 'btn-primary']
 * @param string $size Button size: 'sm', 'md', 'lg'
 */
function renderMobileActionButtons($actions, $size = 'sm') {
    if (empty($actions)) return '';
    
    $isMobile = isMobileDevice();
    $btnSize = $size === 'sm' ? 'btn-sm' : ($size === 'lg' ? 'btn-lg' : '');
    
    echo '<div class="btn-group' . ($isMobile ? ' btn-group-vertical' : '') . '" role="group">';
    
    foreach ($actions as $action) {
        $label = $action['label'] ?? 'Action';
        $url = $action['url'] ?? '#';
        $icon = $action['icon'] ?? '';
        $class = $action['class'] ?? 'btn-secondary';
        $attrs = $action['attrs'] ?? '';
        
        echo '<a href="' . htmlspecialchars($url) . '" class="btn ' . htmlspecialchars($class) . ' ' . $btnSize . '" ' . $attrs . '>';
        
        if (!empty($icon)) {
            echo '<i class="fas ' . htmlspecialchars($icon) . '"></i> ';
        }
        
        // On mobile, always show label; on desktop, may hide based on preference
        echo htmlspecialchars($label);
        
        echo '</a>';
    }
    
    echo '</div>';
}

/**
 * Optimize image for mobile display
 * @param string $imagePath Path to the image
 * @param int $maxWidth Maximum width for mobile
 * @return string Optimized image path or original if optimization fails
 */
function optimizeImageForMobile($imagePath, $maxWidth = 800) {
    // This is a placeholder - implement actual image optimization if needed
    // For now, just return the original path
    return $imagePath;
}

/**
 * Check if the current page should use mobile layout
 * @return bool True if mobile layout should be used
 */
function useMobileLayout() {
    // Check if user has forced desktop mode
    if (isset($_SESSION['force_desktop_mode']) && $_SESSION['force_desktop_mode']) {
        return false;
    }
    
    return isMobileDevice();
}

/**
 * Toggle between mobile and desktop view
 */
function toggleMobileView() {
    if (!isset($_SESSION['force_desktop_mode'])) {
        $_SESSION['force_desktop_mode'] = false;
    }
    
    $_SESSION['force_desktop_mode'] = !$_SESSION['force_desktop_mode'];
}

/**
 * Render mobile view toggle button
 */
function renderMobileViewToggle() {
    $isMobileView = useMobileLayout();
    $label = $isMobileView ? 'Desktop View' : 'Mobile View';
    $icon = $isMobileView ? 'fa-desktop' : 'fa-mobile-alt';
    
    echo '<a href="?toggle_mobile_view=1" class="btn btn-sm btn-outline-secondary">';
    echo '<i class="fas ' . $icon . '"></i> ' . $label;
    echo '</a>';
}

/**
 * Handle mobile view toggle request
 */
function handleMobileViewToggle() {
    if (isset($_GET['toggle_mobile_view'])) {
        toggleMobileView();
        
        // Remove the query parameter and reload
        $url = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $url);
        exit();
    }
}

/**
 * Format text for mobile display (truncate if too long)
 * @param string $text Text to format
 * @param int $maxLength Maximum length for mobile
 * @param bool $forceOnMobile Only truncate on mobile devices
 * @return string Formatted text
 */
function mobileText($text, $maxLength = 50, $forceOnMobile = true) {
    if ($forceOnMobile && !isMobileDevice()) {
        return $text;
    }
    
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    
    return substr($text, 0, $maxLength - 3) . '...';
}

/**
 * Get appropriate number of items per page based on device
 * @param int $desktopCount Items per page on desktop
 * @param int $mobileCount Items per page on mobile (default: half of desktop)
 * @return int Number of items per page
 */
function getItemsPerPage($desktopCount = 20, $mobileCount = null) {
    if ($mobileCount === null) {
        $mobileCount = max(5, floor($desktopCount / 2));
    }
    
    return isMobileDevice() ? $mobileCount : $desktopCount;
}

/**
 * Render mobile-friendly card for data display
 * @param string $title Card title
 * @param array $data Key-value pairs to display
 * @param array $actions Optional action buttons
 */
function renderMobileCard($title, $data, $actions = []) {
    echo '<div class="card mb-3">';
    echo '<div class="card-header">';
    echo '<h5 class="mb-0">' . htmlspecialchars($title) . '</h5>';
    echo '</div>';
    echo '<div class="card-body">';
    
    foreach ($data as $key => $value) {
        echo '<div class="row mb-2">';
        echo '<div class="col-5 fw-bold">' . htmlspecialchars($key) . ':</div>';
        echo '<div class="col-7">' . $value . '</div>';
        echo '</div>';
    }
    
    if (!empty($actions)) {
        echo '<hr>';
        renderMobileActionButtons($actions, 'sm');
    }
    
    echo '</div>';
    echo '</div>';
}

/**
 * Get user's screen orientation preference
 * @return string 'portrait' or 'landscape'
 */
function getScreenOrientation() {
    // This would typically be detected via JavaScript
    // For server-side, we can only make assumptions based on device
    return isTabletDevice() ? 'landscape' : 'portrait';
}

/**
 * Add touch-friendly spacing to elements
 * @param string $content HTML content
 * @return string Content with mobile-friendly spacing
 */
function addTouchSpacing($content) {
    if (!isMobileDevice()) {
        return $content;
    }
    
    // Add extra spacing between interactive elements
    $content = str_replace('btn btn-', 'btn btn- m-1 ', $content);
    return $content;
}

/**
 * Check if user prefers reduced data usage (for mobile)
 * @return bool True if reduced data mode is enabled
 */
function useReducedData() {
    return isset($_SESSION['reduced_data_mode']) && $_SESSION['reduced_data_mode'];
}

/**
 * Enable/disable reduced data mode
 * @param bool $enable Whether to enable reduced data mode
 */
function setReducedDataMode($enable = true) {
    $_SESSION['reduced_data_mode'] = $enable;
}
?>
