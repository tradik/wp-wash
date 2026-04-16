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

        // Method 1: Use Bricks render API if available
        if (class_exists('\Bricks\Frontend') && method_exists('\Bricks\Frontend', 'render_data')) {
            ob_start();
            \Bricks\Frontend::render_data($elements, $postId);
            $html = ob_get_clean();
            error_log("[wash] Bricks: rendered HTML length: " . strlen($html));
        } else {
            // Fallback: try the_content filter (may partially work)
            error_log("[wash] Bricks: Frontend class not available, falling back to the_content");
            $post = get_post($postId);
            if ($post) {
                $html = apply_filters('the_content', $post->post_content);
            }
        }

        // Extract CSS — try external file first, then inline generation
        $css = self::getBricksCss($postId);

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Get Bricks CSS for a post.
     * Tries external CSS file first, then inline generation.
     *
     * @param int $postId WordPress post ID
     * @return string CSS content
     */
    private static function getBricksCss($postId) {
        // Try external CSS file (Bricks Settings → Performance → CSS loading method: External files)
        $uploadDir = wp_upload_dir();
        $cssFile = $uploadDir['basedir'] . '/bricks/css/post-' . $postId . '.min.css';

        if (file_exists($cssFile)) {
            $css = file_get_contents($cssFile);
            error_log("[wash] Bricks: loaded external CSS (" . strlen($css) . " bytes) from {$cssFile}");
            return $css;
        }

        // Try non-minified version
        $cssFileAlt = $uploadDir['basedir'] . '/bricks/css/post-' . $postId . '.css';
        if (file_exists($cssFileAlt)) {
            $css = file_get_contents($cssFileAlt);
            error_log("[wash] Bricks: loaded external CSS (" . strlen($css) . " bytes) from {$cssFileAlt}");
            return $css;
        }

        // Try Bricks Assets API for inline CSS generation
        if (class_exists('\Bricks\Assets') && method_exists('\Bricks\Assets', 'generate_inline_css')) {
            ob_start();
            \Bricks\Assets::generate_inline_css($postId);
            $css = ob_get_clean();
            if (!empty($css)) {
                error_log("[wash] Bricks: generated inline CSS (" . strlen($css) . " bytes)");
                return $css;
            }
        }

        // Try global + page CSS from Bricks settings
        $globalCss = get_option('bricks_global_css', '');
        $pageCss = get_post_meta($postId, '_bricks_page_css', true);
        $combinedCss = trim($globalCss . "\n" . $pageCss);

        if (!empty($combinedCss)) {
            error_log("[wash] Bricks: using global+page CSS (" . strlen($combinedCss) . " bytes)");
            return $combinedCss;
        }

        error_log("[wash] Bricks: no CSS found for post {$postId}");
        return '';
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
