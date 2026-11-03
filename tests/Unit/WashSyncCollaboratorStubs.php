<?php
/**
 * Controllable stand-ins for the classes WashSync collaborates with
 * (page builders, hreflangs, metafields, images, blocks, activator).
 *
 * Loaded ONLY from WashSyncHarness::bootHarness(), which runs inside an isolated
 * PHPUnit process, so these global names never clash with the real classes that
 * other suites autoload from includes/. Each stub exposes public static fields a
 * test sets to steer the branch it wants and records what WashSync asked of it.
 * Not a test case itself (no *Test.php suffix), so PHPUnit does not collect it.
 */

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

class WashPageBuilders {
    /** @var string|null Builder name detect() reports, null for none. */
    public static $detected = null;
    /** @var array{html:string,css:string} What render() returns. */
    public static $rendered = ['html' => '', 'css' => ''];

    public static function detect($postId) {
        return self::$detected;
    }

    public static function render($postId, $builder) {
        return self::$rendered;
    }
}

class WashHreflangs {
    /** @var array|null Translation group returned for every post. */
    public static $group = null;
    /** @var array|\WP_Error Result of syncHreflangs(). */
    public static $syncResult = ['count' => 0];

    public function getTranslationGroupSource($postId) {
        return self::$group;
    }

    public function syncHreflangs($postId, $shopifyId) {
        return self::$syncResult;
    }
}

class WashMetafields {
    /** @var array|\WP_Error Result of syncAllMetafields(). */
    public static $syncResult = ['count' => 0];
    /** @var array Raw ACF meta returned when the ACF plugin is inactive. */
    public static $rawAcf = [];
    /** @var array<string,bool> Enabled metafield keys. */
    public static $enabled = [];

    public function syncAllMetafields($postId, $shopifyId) {
        return self::$syncResult;
    }

    public static function getRawAcfFields($postId) {
        return self::$rawAcf;
    }

    public function getEnabledFieldSet() {
        return self::$enabled;
    }

    public function sanitizeKey($key) {
        return $key;
    }
}

class WashImages {
    /** @var array<int,string> attachment id => stored CDN URL. */
    public static $cdnUrls = [];
    /** @var bool Outcome of syncFile()/syncImage(). */
    public static $syncOk = true;
    /** @var int[] Attachment ids WashSync asked to upload. */
    public static $synced = [];

    public static function getShopifyCdnUrl($attachmentId) {
        return self::$cdnUrls[$attachmentId] ?? '';
    }

    public function syncFile($attachmentId) {
        self::$synced[] = $attachmentId;
        return self::$syncOk;
    }

    public function syncImage($attachmentId) {
        self::$synced[] = $attachmentId;
        return self::$syncOk;
    }
}

class WashBlocks {
    /** @var array Metafields buildBlockMetafields() returns. */
    public static $metafields = [];

    public static function buildBlockMetafields($blocksMeta) {
        return self::$metafields;
    }
}

class WashActivator {
    /** @var int How many times the sync table self-heal ran. */
    public static $created = 0;

    public static function createSyncTable() {
        self::$created++;
    }
}
