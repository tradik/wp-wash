<?php
/**
 * Minimal Yoast SEO / RankMath / ACF API stand-ins for WashSyncSeoPluginsTest.
 * Only that isolated-process suite loads this file, so declaring these global
 * names cannot flip class_exists()/function_exists() for any other test.
 * Not a test case itself (no *Test.php suffix), so PHPUnit does not collect it.
 */

namespace {
    if (!defined('WPSEO_VERSION')) {
        define('WPSEO_VERSION', '99.0');
    }

    class WPSEO_Meta {
    }

    class WPSEO_Replace_Vars {
        public function replace($template, $post) {
            return str_replace(['%%title%%', '%%sep%%'], [$post->post_title, '|'], $template);
        }
    }

    class RankMath {
    }

    function rank_math_replace_vars($template, $post) {
        return str_replace('%title%', $post->post_title, $template);
    }

    /** @var array ACF fields returned by get_fields(). */
    $GLOBALS['washTestAcfFields'] = [];

    function get_fields($postId) {
        return $GLOBALS['washTestAcfFields'];
    }
}

namespace RankMath\Paper {
    class Paper {
        /** @var string Title the paper reports ('' = none). */
        public static $title = '';
        /** @var bool Throw from get() to exercise the fall-through. */
        public static $throw = false;

        public static function get() {
            if (self::$throw) {
                throw new \Exception('paper unavailable');
            }
            return new self();
        }

        public function get_title() {
            return self::$title;
        }
    }
}
