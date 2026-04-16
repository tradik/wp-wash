<?php
/**
 * Page builder detection and content rendering.
 * Detects active page builders (Bricks, Elementor, Divi, etc.)
 * and renders their content to HTML+CSS for Shopify sync.
 */
class WashPageBuilders {

    /**
     * Detect which page builder was used for a specific post.
     *
     * @param int $postId WordPress post ID
     * @return string|null Builder identifier or null if standard content
     */
    public static function detect($postId) {
        // Bricks Builder — stores content in _bricks_page_content_2
        $bricksData = get_post_meta($postId, '_bricks_page_content_2', true);
        if (!empty($bricksData) && is_array($bricksData)) {
            return 'bricks';
        }

        // Elementor — stores content in _elementor_data
        $elementorData = get_post_meta($postId, '_elementor_data', true);
        if (!empty($elementorData)) {
            return 'elementor';
        }

        // Divi — stores version in _et_builder_version
        $diviVersion = get_post_meta($postId, '_et_builder_version', true);
        if (!empty($diviVersion)) {
            return 'divi';
        }

        return null;
    }

    /**
     * Render page builder content to HTML.
     *
     * @param int    $postId  WordPress post ID
     * @param string $builder Builder identifier from detect()
     * @return array{html: string, css: string} Rendered HTML and CSS
     */
    public static function render($postId, $builder) {
        switch ($builder) {
            case 'bricks':
                return self::renderBricks($postId);
            case 'elementor':
                return self::renderElementor($postId);
            case 'divi':
                return self::renderDivi($postId);
            default:
                return ['html' => '', 'css' => ''];
        }
    }

    /**
     * Render Bricks Builder content.
     *
     * Uses Bricks Frontend::render_data() for HTML and extracts CSS
     * from generated files or inline generation.
     *
     * @param int $postId WordPress post ID
     * @return array{html: string, css: string}
     */
    private static function renderBricks($postId) {
        $html = '';
        $css = '';

        $elements = get_post_meta($postId, '_bricks_page_content_2', true);
        if (empty($elements) || !is_array($elements)) {
            error_log("[wash] Bricks: no content found for post {$postId}");
            return ['html' => '', 'css' => ''];
        }

        error_log("[wash] Bricks: rendering " . count($elements) . " elements for post {$postId}");

        // Method 1 (preferred): Fetch from frontend — resolves dynamic data AND captures inline CSS
        $frontendData = self::fetchBricksFromFrontend($postId);
        $html = $frontendData['html'];
        $css = $frontendData['css'];

        if (!empty($html)) {
            error_log("[wash] Bricks: frontend fetch succeeded, HTML: " . strlen($html) . " CSS: " . strlen($css));
        } else {
            // Method 2: render_data + capture CSS from Bricks internals
            error_log("[wash] Bricks: frontend fetch empty, falling back to render_data");
            if (class_exists('\Bricks\Frontend') && method_exists('\Bricks\Frontend', 'render_data')) {
                global $post;
                $originalPost = $post;
                $post = get_post($postId);
                setup_postdata($post);

                $returned = \Bricks\Frontend::render_data($elements, $postId);
                if (is_string($returned) && !empty($returned)) {
                    $html = $returned;
                } else {
                    ob_start();
                    \Bricks\Frontend::render_data($elements, $postId);
                    $html = ob_get_clean();
                }

                // Capture inline CSS that Bricks generated during render
                if (class_exists('\Bricks\Assets')) {
                    ob_start();
                    // Trigger Bricks CSS output (normally goes to wp_head)
                    if (method_exists('\Bricks\Assets', 'generate_inline_css')) {
                        \Bricks\Assets::generate_inline_css($postId);
                    }
                    $bricksCssOutput = ob_get_clean();
                    if (!empty($bricksCssOutput)) {
                        $css .= $bricksCssOutput . "\n";
                        error_log("[wash] Bricks: captured Assets CSS (" . strlen($bricksCssOutput) . " bytes)");
                    }
                }

                // Try to resolve dynamic data tags
                if (!empty($html) && function_exists('bricks_render_dynamic_data')) {
                    $html = bricks_render_dynamic_data($html, $postId);
                }

                $post = $originalPost;
                if ($originalPost) {
                    setup_postdata($originalPost);
                } else {
                    wp_reset_postdata();
                }

                error_log("[wash] Bricks: render_data HTML: " . strlen($html));
            }

            // Method 3: Last resort
            if (empty($html)) {
                error_log("[wash] Bricks: all methods failed, falling back to the_content");
                $post = get_post($postId);
                if ($post) {
                    $html = apply_filters('the_content', $post->post_content);
                }
            }
        }

        // Always supplement with filesystem CSS (theme base, child theme, global settings)
        $fsCss = self::getBricksCss($postId);
        if (!empty($fsCss)) {
            $css = $fsCss . "\n" . $css;
            error_log("[wash] Bricks: added filesystem CSS (" . strlen($fsCss) . " bytes)");
        }

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Fetch Bricks-rendered page from frontend URL.
     * Extracts both content HTML and CSS from the full page.
     *
     * @param int $postId WordPress post ID
     * @return array{html: string, css: string}
     */
    private static function fetchBricksFromFrontend($postId) {
        $permalink = get_permalink($postId);
        if (!$permalink) {
            error_log("[wash] Bricks: no permalink for post {$postId}");
            return ['html' => '', 'css' => ''];
        }

        error_log("[wash] Bricks: fetching frontend URL: {$permalink}");

        $requestArgs = [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => ['X-Wash-Internal' => '1'],
        ];

        // Add HTTP Basic Auth if configured (for staging/password-protected sites)
        $httpUser = get_option('wash_http_user', '');
        $httpPass = get_option('wash_http_pass', '');
        if (!empty($httpUser)) {
            $requestArgs['headers']['Authorization'] = 'Basic ' . base64_encode($httpUser . ':' . $httpPass);
        }

        $response = wp_remote_get($permalink, $requestArgs);

        if (is_wp_error($response)) {
            error_log("[wash] Bricks: frontend fetch failed: " . $response->get_error_message());
            return ['html' => '', 'css' => ''];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            error_log("[wash] Bricks: frontend returned HTTP {$statusCode}");
            return ['html' => '', 'css' => ''];
        }

        $body = wp_remote_retrieve_body($response);
        error_log("[wash] Bricks: fetched page HTML length: " . strlen($body));

        // Extract content
        $html = self::extractBricksContent($body);
        error_log("[wash] Bricks: extracted content length: " . strlen($html));

        // Extract CSS from the page (inline <style> tags + linked Bricks CSS)
        $css = self::extractBricksCssFromPage($body, $postId);
        error_log("[wash] Bricks: extracted CSS length: " . strlen($css));

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Extract Bricks CSS from full page HTML.
     * Collects inline <style> tags and fetches linked Bricks CSS files.
     *
     * @param string $pageHtml Full page HTML
     * @param int    $postId   Post ID for filtering relevant CSS
     * @return string Combined CSS
     */
    private static function extractBricksCssFromPage($pageHtml, $postId) {
        $css = '';

        // 1. Extract inline <style> tags (Bricks generates these)
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $pageHtml, $matches)) {
            foreach ($matches[1] as $styleContent) {
                $trimmed = trim($styleContent);
                if (!empty($trimmed)) {
                    $css .= $trimmed . "\n";
                }
            }
            error_log("[wash] Bricks: found " . count($matches[1]) . " inline style tags");
        }

        // 2. Find and fetch linked Bricks CSS files
        if (preg_match_all('/<link[^>]*href=["\']([^"\']*bricks[^"\']*\.css[^"\']*)["\'][^>]*>/i', $pageHtml, $linkMatches)) {
            foreach ($linkMatches[1] as $cssUrl) {
                error_log("[wash] Bricks: fetching CSS file: {$cssUrl}");
                $cssResponse = wp_remote_get($cssUrl, [
                    'timeout'   => 15,
                    'sslverify' => false,
                ]);
                if (!is_wp_error($cssResponse) && wp_remote_retrieve_response_code($cssResponse) === 200) {
                    $cssContent = wp_remote_retrieve_body($cssResponse);
                    $css .= $cssContent . "\n";
                    error_log("[wash] Bricks: fetched CSS file (" . strlen($cssContent) . " bytes)");
                }
            }
        }

        // 3. Also grab post-specific CSS file link if present
        $postCssPattern = '/post-' . $postId . '[\w.-]*\.css/';
        if (preg_match_all('/<link[^>]*href=["\']([^"\']*' . preg_quote("post-{$postId}", '/') . '[^"\']*\.css[^"\']*)["\'][^>]*>/i', $pageHtml, $postCssMatches)) {
            foreach ($postCssMatches[1] as $cssUrl) {
                if (strpos($css, basename($cssUrl)) !== false) continue; // Already fetched
                error_log("[wash] Bricks: fetching post CSS: {$cssUrl}");
                $cssResponse = wp_remote_get($cssUrl, [
                    'timeout'   => 15,
                    'sslverify' => false,
                ]);
                if (!is_wp_error($cssResponse) && wp_remote_retrieve_response_code($cssResponse) === 200) {
                    $css .= wp_remote_retrieve_body($cssResponse) . "\n";
                }
            }
        }

        return trim($css);
    }

    /**
     * Extract the Bricks content area from full page HTML.
     * Looks for #brx-content or main content area.
     *
     * @param string $pageHtml Full page HTML
     * @return string Extracted content HTML
     */
    private static function extractBricksContent($pageHtml) {
        // Try #brx-content first (Bricks main content wrapper)
        if (preg_match('/<div[^>]*id=["\']brx-content["\'][^>]*>(.*?)<\/div>\s*(?:<footer|<div[^>]*id=["\']brx-footer)/s', $pageHtml, $matches)) {
            return trim($matches[1]);
        }

        // Try broader match — everything between header and footer
        if (preg_match('/<div[^>]*id=["\']brx-content["\'][^>]*>(.*)<\/div>/s', $pageHtml, $matches)) {
            // Remove nested closing divs that belong to the wrapper
            $content = $matches[1];
            // Find last occurrence of closing content markers
            $footerPos = strpos($content, '<footer');
            if ($footerPos === false) {
                $footerPos = strpos($content, 'id="brx-footer"');
            }
            if ($footerPos !== false) {
                $content = substr($content, 0, $footerPos);
            }
            return trim($content);
        }

        // Fallback: try <main> tag
        if (preg_match('/<main[^>]*>(.*?)<\/main>/s', $pageHtml, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: try .entry-content
        if (preg_match('/<div[^>]*class=["\'][^"\']*entry-content[^"\']*["\'][^>]*>(.*?)<\/div>/s', $pageHtml, $matches)) {
            return trim($matches[1]);
        }

        error_log("[wash] Bricks: could not extract content from page HTML");
        return '';
    }

    /**
     * Get Bricks CSS for a post.
     * Tries multiple methods: external file, Bricks API, theme files, global settings.
     *
     * @param int $postId WordPress post ID
     * @return string CSS content
     */
    private static function getBricksCss($postId) {
        $css = '';

        // 1. Try external CSS file (Bricks Settings → Performance → CSS loading method: External files)
        $uploadDir = wp_upload_dir();
        $cssFile = $uploadDir['basedir'] . '/bricks/css/post-' . $postId . '.min.css';
        if (file_exists($cssFile)) {
            $css .= file_get_contents($cssFile) . "\n";
            error_log("[wash] Bricks: loaded post CSS file (" . strlen($css) . " bytes)");
        }
        $cssFileAlt = $uploadDir['basedir'] . '/bricks/css/post-' . $postId . '.css';
        if (empty($css) && file_exists($cssFileAlt)) {
            $css .= file_get_contents($cssFileAlt) . "\n";
            error_log("[wash] Bricks: loaded post CSS file alt (" . strlen($css) . " bytes)");
        }

        // 2. Try Bricks Assets API for inline CSS generation
        if (class_exists('\Bricks\Assets')) {
            // Try generate_inline_css (generates CSS for a specific post)
            if (method_exists('\Bricks\Assets', 'generate_inline_css')) {
                global $post;
                $originalPost = $post;
                $post = get_post($postId);
                if ($post) setup_postdata($post);

                ob_start();
                \Bricks\Assets::generate_inline_css($postId);
                $inlineCss = ob_get_clean();

                $post = $originalPost;
                if ($originalPost) setup_postdata($originalPost);
                else wp_reset_postdata();

                if (!empty($inlineCss)) {
                    $css .= $inlineCss . "\n";
                    error_log("[wash] Bricks: Assets::generate_inline_css (" . strlen($inlineCss) . " bytes)");
                }
            }

            // Try generate_css_from_elements (builds CSS from element data)
            if (empty($css) && method_exists('\Bricks\Assets', 'generate_css_from_elements')) {
                $elements = get_post_meta($postId, '_bricks_page_content_2', true);
                if (!empty($elements) && is_array($elements)) {
                    ob_start();
                    \Bricks\Assets::generate_css_from_elements($elements, $postId);
                    $elementCss = ob_get_clean();
                    if (!empty($elementCss)) {
                        $css .= $elementCss . "\n";
                        error_log("[wash] Bricks: Assets::generate_css_from_elements (" . strlen($elementCss) . " bytes)");
                    }
                }
            }
        }

        // 3. Load Bricks theme CSS files directly from filesystem
        $bricksThemeDir = get_template_directory();
        $bricksThemeFiles = [
            $bricksThemeDir . '/assets/css/frontend-layer.min.css',
            $bricksThemeDir . '/assets/css/frontend-layer.css',
        ];
        foreach ($bricksThemeFiles as $themeFile) {
            if (file_exists($themeFile)) {
                $themeCss = file_get_contents($themeFile);
                $css .= $themeCss . "\n";
                error_log("[wash] Bricks: loaded theme CSS (" . strlen($themeCss) . " bytes) from {$themeFile}");
                break;
            }
        }

        // 4. Load child theme CSS
        $childDir = get_stylesheet_directory();
        if ($childDir !== $bricksThemeDir) {
            $childStyleFile = $childDir . '/style.css';
            if (file_exists($childStyleFile)) {
                $childCss = file_get_contents($childStyleFile);
                $css .= $childCss . "\n";
                error_log("[wash] Bricks: loaded child theme CSS (" . strlen($childCss) . " bytes)");
            }
        }

        // 5. Global + page custom CSS from Bricks settings
        $globalCss = get_option('bricks_global_css', '');
        $pageCss = get_post_meta($postId, '_bricks_page_css', true);
        if (!empty($globalCss)) $css .= $globalCss . "\n";
        if (!empty($pageCss)) $css .= $pageCss . "\n";

        error_log("[wash] Bricks: total CSS for post {$postId}: " . strlen($css) . " bytes");
        return trim($css);
    }

    /**
     * Render Elementor content.
     * Placeholder for future implementation.
     *
     * @param int $postId WordPress post ID
     * @return array{html: string, css: string}
     */
    private static function renderElementor($postId) {
        error_log("[wash] Elementor: builder detected for post {$postId} but rendering not yet implemented");

        // Future: \Elementor\Plugin::$instance->frontend->get_builder_content($postId, true)
        $post = get_post($postId);
        $html = $post ? apply_filters('the_content', $post->post_content) : '';

        return ['html' => $html, 'css' => ''];
    }

    /**
     * Render Divi content.
     * Placeholder for future implementation.
     *
     * @param int $postId WordPress post ID
     * @return array{html: string, css: string}
     */
    private static function renderDivi($postId) {
        error_log("[wash] Divi: builder detected for post {$postId} but rendering not yet implemented");

        // Future: et_builder_get_layout_html($postId)
        $post = get_post($postId);
        $html = $post ? apply_filters('the_content', $post->post_content) : '';

        return ['html' => $html, 'css' => ''];
    }

    /**
     * Wrap rendered HTML with inline CSS for self-contained Shopify content.
     *
     * @param string $html Rendered HTML
     * @param string $css  CSS to inline
     * @return string HTML with embedded <style> tag
     */
    public static function wrapWithCss($html, $css) {
        if (empty($css) || empty($html)) {
            return $html;
        }

        // Scope CSS to avoid conflicts with Shopify theme
        $scopedCss = self::scopeCss($css);

        return '<div class="wash-pb-content">'
            . '<style>' . $scopedCss . '</style>'
            . $html
            . '</div>';
    }

    /**
     * Scope CSS selectors to avoid conflicts with Shopify theme.
     * Prefixes selectors with .wash-pb-content.
     *
     * @param string $css Raw CSS
     * @return string Scoped CSS
     */
    private static function scopeCss($css) {
        // Skip @media, @font-face, @keyframes — don't prefix those
        // Simple approach: prefix selectors that don't start with @
        $lines = explode("\n", $css);
        $result = [];
        $inAtRule = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Track @media and other at-rules depth
            if (preg_match('/^@(media|supports|layer)\b/', $trimmed)) {
                $result[] = $line;
                $inAtRule += substr_count($line, '{') - substr_count($line, '}');
                continue;
            }

            // Pass through @font-face, @keyframes etc. as-is
            if (preg_match('/^@/', $trimmed)) {
                $result[] = $line;
                $inAtRule += substr_count($line, '{') - substr_count($line, '}');
                continue;
            }

            // Track brace depth for at-rules
            if ($inAtRule > 0) {
                $inAtRule += substr_count($line, '{') - substr_count($line, '}');
                // Prefix selectors inside @media
                if (strpos($trimmed, '{') !== false && !preg_match('/^[}\s]/', $trimmed)) {
                    $line = preg_replace('/^(\s*)([^{]+)\{/', '$1.wash-pb-content $2{', $line);
                }
                $result[] = $line;
                continue;
            }

            // Prefix top-level selectors
            if (strpos($trimmed, '{') !== false && !empty($trimmed) && $trimmed !== '}') {
                $line = preg_replace('/^(\s*)([^{]+)\{/', '$1.wash-pb-content $2{', $line);
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }
}
