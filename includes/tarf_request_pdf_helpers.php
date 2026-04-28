<?php
/**
 * Helpers for DISAPP PDF export (Dompdf).
 */
if (!function_exists('tarf_disapp_pdf_fetch_binary')) {
    /**
     * @return ?string null on failure
     */
    function tarf_disapp_pdf_fetch_binary(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data !== false && $code >= 200 && $code < 400) {
                return $data;
            }

            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 25],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $data = @file_get_contents($url, false, $ctx);

        return $data !== false ? $data : null;
    }
}

if (!function_exists('tarf_disapp_pdf_strip_anchor_tags')) {
    /**
     * Remove hyperlink annotations (Dompdf otherwise embeds URI actions; Chrome blocks file:// targets when the PDF is opened from disk).
     */
    function tarf_disapp_pdf_strip_anchor_tags(string $html): string
    {
        $prev = '';
        while ($prev !== $html) {
            $prev = $html;
            $html = (string) preg_replace('#<a\b[^>]*>(.*?)</a>#is', '$1', $html);
        }

        return $html;
    }
}

if (!function_exists('tarf_disapp_pdf_inline_images')) {
    /**
     * Embed images as data URIs so the PDF has no external or filesystem-based image references.
     */
    function tarf_disapp_pdf_inline_images(string $html, string $projectRoot): string
    {
        $projectRootReal = realpath($projectRoot);

        return (string) preg_replace_callback(
            '#<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)>#i',
            static function (array $m) use ($projectRoot, $projectRootReal) {
                $url = trim($m[3]);
                if ($url === '' || stripos($url, 'data:') === 0) {
                    return $m[0];
                }
                $binary = null;
                $mime = 'image/png';
                if (preg_match('#^https?://#i', $url)) {
                    $binary = tarf_disapp_pdf_fetch_binary($url);
                    if (($binary === null || $binary === '') && $projectRootReal !== false) {
                        $pathPart = parse_url($url, PHP_URL_PATH);
                        if (is_string($pathPart) && $pathPart !== '') {
                            $bp = function_exists('getBasePath') ? '/' . trim((string) getBasePath(), '/') : '';
                            if ($bp === '/') {
                                $bp = '';
                            }
                            if ($bp !== '' && strpos($pathPart, $bp) === 0) {
                                $rel = ltrim(substr($pathPart, strlen($bp)), '/');
                                $rel = str_replace('/', DIRECTORY_SEPARATOR, $rel);
                                $fsPath = realpath($projectRoot . DIRECTORY_SEPARATOR . $rel);
                                if ($fsPath !== false && strpos($fsPath, $projectRootReal) === 0 && is_file($fsPath)) {
                                    $binary = @file_get_contents($fsPath);
                                    $ext = strtolower((string) pathinfo($fsPath, PATHINFO_EXTENSION));
                                    if ($ext === 'jpg' || $ext === 'jpeg') {
                                        $mime = 'image/jpeg';
                                    } elseif ($ext === 'gif') {
                                        $mime = 'image/gif';
                                    } elseif ($ext === 'png') {
                                        $mime = 'image/png';
                                    } elseif ($ext === 'webp') {
                                        $mime = 'image/webp';
                                    }
                                }
                            }
                        }
                    }
                }
                if ($binary === null || $binary === '') {
                    return $m[0];
                }
                if (class_exists('finfo')) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $detected = $finfo->buffer($binary);
                    if (is_string($detected) && strpos($detected, 'image/') === 0) {
                        $mime = $detected;
                    }
                }
                $q = $m[2];
                $b64 = base64_encode($binary);

                return '<img' . $m[1] . 'src=' . $q . 'data:' . $mime . ';base64,' . $b64 . $q . $m[4] . '>';
            },
            $html
        );
    }
}

if (!function_exists('tarf_disapp_pdf_prepare_document_html')) {
    function tarf_disapp_pdf_prepare_document_html(string $html, string $projectRoot): string
    {
        $html = tarf_disapp_pdf_absolutize_resource_urls($html);
        $html = tarf_disapp_pdf_strip_anchor_tags($html);
        $html = tarf_disapp_pdf_inline_images($html, $projectRoot);

        return $html;
    }
}

if (!function_exists('tarf_disapp_pdf_request_origin')) {
    /**
     * Scheme + host for building absolute URLs during the HTTP request that generates the PDF.
     */
    function tarf_disapp_pdf_request_origin(): string
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '127.0.0.1';
        $isLocal = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '::1'
            || stripos($host, 'localhost') !== false
            || stripos($host, '127.0.0.1') !== false
            || stripos($host, '.local') !== false
            || stripos($host, 'xampp') !== false;
        if ($isLocal && strpos($host, ':443') !== false) {
            $host = str_replace(':443', '', $host);
        }
        $https = !$isLocal && (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . $host;
    }
}

if (!function_exists('tarf_disapp_pdf_absolutize_resource_urls')) {
    /**
     * Dompdf combines chroot with root-relative src/href and emits file:// annotations; Chrome then
     * reports unsafe file-origin loads when the PDF is opened from disk. Rewrite to absolute http(s)
     * URLs so images are fetched over HTTP and annotations are valid remote URIs.
     */
    function tarf_disapp_pdf_absolutize_resource_urls(string $html): string
    {
        $origin = tarf_disapp_pdf_request_origin();

        return (string) preg_replace_callback(
            '#\b(src|href)\s*=\s*(["\'])([^"\']*)\2#i',
            static function (array $m) use ($origin) {
                $url = trim($m[3]);
                if ($url === '') {
                    return $m[0];
                }
                if (stripos($url, 'data:') === 0) {
                    return $m[0];
                }
                if (preg_match('#^(https?:)?//#i', $url)) {
                    return $m[0];
                }
                if (stripos($url, 'mailto:') === 0) {
                    return $m[0];
                }
                $q = $m[2];
                if ($url[0] === '/') {
                    return $m[1] . '=' . $q . $origin . $url . $q;
                }
                $bp = function_exists('getBasePath') ? trim((string) getBasePath(), '/') : '';
                $prefix = $bp !== '' ? $origin . '/' . $bp . '/' : $origin . '/';

                return $m[1] . '=' . $q . $prefix . $url . $q;
            },
            $html
        );
    }
}
