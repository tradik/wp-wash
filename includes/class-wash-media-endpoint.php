<?php
/**
 * Signed media REST endpoint.
 *
 * Lets the queue worker pull attachment files via an HMAC-signed request,
 * bypassing third-party hot-link / security blocks on /wp-content/uploads/.
 *
 * Route:  GET wash/v1/media/{id}?site={siteId}
 * Auth:   X-Wash-Key   (matches wash_api_key option)
 *         X-Wash-Timestamp  (unix seconds, within 60s of server time)
 *         X-Wash-Signature  (hex hash_hmac('sha256', "{ts}:{id}:{siteId}", apiKey))
 *
 * Only public attachments are served: post_status=inherit AND
 * (no parent OR parent is published).
 *
 * @package Wash
 */

if (!defined('ABSPATH')) {
    exit;
}

class WashMediaEndpoint {

    /** Anti-replay window for signed requests (seconds). */
    public const TIMESTAMP_WINDOW = 60;

    /** Per-IP request budget per minute. */
    public const RATE_LIMIT_PER_MIN = 100;

    /**
     * Register REST API route.
     */
    public function register() {
        add_action('rest_api_init', [$this, 'registerRoute']);
    }

    /**
     * Register the /media/{id} route.
     */
    public function registerRoute() {
        register_rest_route('wash/v1', '/media/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serveMedia'],
            'permission_callback' => [$this, 'verifySignature'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && (int) $param > 0;
                    },
                ],
                'site' => [
                    'required'          => false,
                    'default'           => 1,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && (int) $param > 0;
                    },
                ],
            ],
        ]);
    }

    /**
     * Permission callback: verify API key, timestamp window, HMAC, rate limit.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function verifySignature($request) {
        if (!$this->checkRateLimit()) {
            return new WP_Error('rate_limited', 'Too many requests', ['status' => 429]);
        }

        $apiKey   = (string) $request->get_header('X-Wash-Key');
        $tsHeader = (string) $request->get_header('X-Wash-Timestamp');
        $sig      = (string) $request->get_header('X-Wash-Signature');

        // Route to the connection that owns the incoming key so its credentials
        // verify the request (an unknown key leaves primary active and fails below).
        $connectionId = WashConnection::getByApiKey($apiKey);
        if ($connectionId !== null) {
            WashContext::setActiveId($connectionId);
        }

        $stored = (string) WashContext::connection()['apiKey'];
        if ($stored === '' || $apiKey === '' || !hash_equals($stored, $apiKey)) {
            return new WP_Error('unauthorized', 'Invalid API key', ['status' => 401]);
        }

        if ($tsHeader === '' || !ctype_digit($tsHeader)) {
            return new WP_Error('bad_timestamp', 'Invalid timestamp', ['status' => 401]);
        }
        $ts = (int) $tsHeader;
        if (abs(time() - $ts) > self::TIMESTAMP_WINDOW) {
            return new WP_Error('stale_timestamp', 'Timestamp outside window', ['status' => 401]);
        }

        $id     = (int) $request->get_param('id');
        $siteId = (int) ($request->get_param('site') ?? 1);
        $expected = hash_hmac('sha256', $tsHeader . ':' . $id . ':' . $siteId, $stored);
        if (!hash_equals($expected, $sig)) {
            return new WP_Error('bad_signature', 'Invalid signature', ['status' => 401]);
        }

        return true;
    }

    /**
     * Stream the attachment file. Skips the WP_REST_Response shell so we can
     * send raw bytes with the correct Content-Type.
     *
     * @param WP_REST_Request $request
     * @return WP_Error|void  WP_Error on failure; void exits on success.
     */
    public function serveMedia($request) {
        $id = (int) $request->get_param('id');
        $siteId = (int) ($request->get_param('site') ?? 1);

        // Multisite: the signed `site` param was verified but never honored, so a
        // subsite attachment id resolved against the network-root blog — serving
        // the wrong file (or 404). Switch to the requesting blog before reading
        // the attachment and restore afterwards (MED-19, #161).
        $switched = is_multisite() && $siteId > 1;
        if ($switched) {
            switch_to_blog($siteId);
        }

        try {
            if (!$this->isAttachmentPublic($id)) {
                return new WP_Error('not_found', 'Attachment not available', ['status' => 404]);
            }

            $path = get_attached_file($id);
            if (!$path || !file_exists($path) || !is_readable($path)) {
                return new WP_Error('not_found', 'File missing on disk', ['status' => 404]);
            }

            $mime = (string) get_post_mime_type($id);
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }

        $this->streamFile($path, $mime, $id);
    }

    /**
     * Send file bytes and exit. Separated so tests can stub it.
     *
     * @param string $path  Absolute file path.
     * @param string $mime  Mime type to send.
     * @param int    $id    Attachment id (for response header).
     */
    protected function streamFile($path, $mime, $id) {
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($path));
            header('X-Wash-Attachment-Id: ' . (string) $id);
            header('Cache-Control: private, max-age=300');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming attachment bytes to the client; readfile() streams without loading the whole file into memory, unlike WP_Filesystem::get_contents().
        readfile($path);
        exit;
    }

    /**
     * Attachment is servable if it's a regular WP attachment (status=inherit)
     * and either has no parent or its parent is published. This prevents
     * leaking files attached to drafts / private posts.
     *
     * @param int $id Attachment ID.
     * @return bool
     */
    protected function isAttachmentPublic($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment' || $post->post_status !== 'inherit') {
            return false;
        }
        if ((int) $post->post_parent === 0) {
            return true;
        }
        $parent = get_post($post->post_parent);
        return $parent && $parent->post_status === 'publish';
    }

    /**
     * Per-IP token bucket using a 60-second transient. Caller still pays
     * the rejection on the next call within the same minute.
     *
     * Note on $_SERVER['REMOTE_ADDR']: used only as a cache-bucketing key
     * for rate-limit attribution, never as an auth signal. Spoofing it via
     * proxy headers degrades the limit's per-client granularity but cannot
     * grant access — that is gated by HMAC signature only.
     *
     * SHA-256 is used over the IP solely to produce a fixed-length, transient-
     * safe cache key with no PII in the storage. Not a security-critical hash.
     *
     * @return bool false when over limit.
     */
    protected function checkRateLimit() {
        $ip = $this->clientIpForRateLimit();
        $key = 'wash_media_rl_' . hash('sha256', $ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_PER_MIN) {
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Return the requesting IP for rate-limit bucketing. Separated so tests
     * can stub it. Falls back to "unknown" when the SAPI doesn't expose one
     * (CLI, certain edge proxies) — that just collapses all such requests
     * into one bucket, which is the safe default.
     *
     * @return string
     */
    protected function clientIpForRateLimit() {
        // NOSONAR: REMOTE_ADDR used only as a rate-limit bucket key, never
        // as an auth or authorization signal. HMAC signature is the auth gate.
        return isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : 'unknown'; // NOSONAR
    }
}
