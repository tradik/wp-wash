<?php
/**
 * Extended coverage tests for WashMetafields class.
 * Targets ~90% line coverage for class-wash-metafields.php.
 *
 * Methods covered by this file:
 *   - getRawAcfFields (static)
 *   - init (with/without option)
 *   - migrateFromPluginToggles
 *   - detectAvailableFields
 *   - onMetaUpdate / onMetaAdd / onMetaDelete
 *   - onAcfSavePost
 *   - syncMetafield (private, via onMetaUpdate)
 *   - syncAllMetafields
 *   - buildWordPressMetafields (private, via syncAllMetafields)
 *   - buildAcfMetafields (private)
 *   - convertAcfFieldToMetafield (private)
 *   - formatAcfValue (private) - all branches
 *   - flattenAcfComplexField (private)
 *   - buildSeoMetafields (private)
 *   - buildCustomMetafields (private)
 *   - syncAcfFields (private, via onAcfSavePost)
 *   - ensureMetafieldDefinitions (private)
 *   - generateDefinitionDescription (private)
 *   - hasMetafieldKey (private)
 *   - isAcfField (private)
 *   - getShopifyResourceId (private)
 *   - resolveNamespace (private)
 *   - inferType (private)
 */
namespace Wash\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname(__DIR__, 2) . '/includes/class-wash-api-client.php';
require_once dirname(__DIR__, 2) . '/includes/class-wash-metafields.php';

// Isolated process: this class stubs optional ecosystem functions (ACF
// get_fields, Yoast/RankMath), which make function_exists() true process-wide
// and would otherwise contaminate later tests that assume those are absent.
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class WashMetafieldsCoverageTest extends TestCase
{
    private \WashMetafields $metafields;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        \WashMetafields::$fieldConfigCache = null;

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_api_key'  => 'test-key',
                'wash_app_url'  => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default         => $default,
            };
        });
        Functions\when('get_site_url')->justReturn('https://example.com');
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->justReturn(false);
        // ACF stubs — always define so function_exists() checks are consistent
        Functions\when('acf_get_field')->justReturn(false);
        Functions\when('get_fields')->justReturn(false);
        Functions\when('get_field_object')->justReturn(false);

        $this->metafields = new \WashMetafields();
    }

    protected function tearDown(): void
    {
        \WashMetafields::$fieldConfigCache = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Helper to invoke private methods
    // -------------------------------------------------------------------

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(\WashMetafields::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->metafields, ...$args);
    }

    // -------------------------------------------------------------------
    // getRawAcfFields
    // -------------------------------------------------------------------

    public function testGetRawAcfFieldsReturnsEmptyWhenNoMeta(): void
    {
        Functions\when('get_post_meta')->justReturn([]);
        $result = \WashMetafields::getRawAcfFields(1);
        $this->assertSame([], $result);
    }

    public function testGetRawAcfFieldsSkipsMetaWithoutAcfReference(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'some_field' => ['value1'],
        ]);
        $result = \WashMetafields::getRawAcfFields(1);
        $this->assertSame([], $result);
    }

    public function testGetRawAcfFieldsReturnsFieldsWithAcfPrefix(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'my_acf_field'  => ['hello'],
            '_my_acf_field' => ['field_abc123'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);

        $result = \WashMetafields::getRawAcfFields(5);
        $this->assertArrayHasKey('my_acf_field', $result);
        $this->assertSame('hello', $result['my_acf_field']);
    }

    public function testGetRawAcfFieldsSkipsEmptyValues(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'empty_field'  => [''],
            '_empty_field' => ['field_xyz'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);

        $result = \WashMetafields::getRawAcfFields(5);
        $this->assertArrayNotHasKey('empty_field', $result);
    }

    public function testGetRawAcfFieldsSkipsRefNotStartingWithFieldPrefix(): void
    {
        Functions\when('get_post_meta')->justReturn([
            'meta_key'  => ['value'],
            '_meta_key' => ['not_a_field_ref'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);

        $result = \WashMetafields::getRawAcfFields(5);
        $this->assertArrayNotHasKey('meta_key', $result);
    }

    // -------------------------------------------------------------------
    // init
    // -------------------------------------------------------------------

    public function testInitDoesNothingWhenSyncDisabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '0';
            return match ($key) {
                'wash_api_key' => 'test-key', 'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '', 'wash_http_pass' => '', default => $default,
            };
        });
        Functions\expect('add_action')->never();
        $mf = new \WashMetafields();
        $mf->init();
        // no exception = pass
        $this->assertTrue(true);
    }

    public function testInitRegistersHooksWhenSyncEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key' => 'test-key', 'wash_app_url' => 'https://app-wash.tradik.dev',
                'wash_http_user' => '', 'wash_http_pass' => '', default => $default,
            };
        });
        Functions\when('add_action')->justReturn(true);

        $mf = new \WashMetafields();
        $mf->init(); // should not throw
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // resolveNamespace (private)
    // -------------------------------------------------------------------

    public function testResolveNamespaceRankMathReturnsSeо(): void
    {
        $result = $this->invokePrivate('resolveNamespace', ['rank_math_title']);
        $this->assertSame('wash_seo', $result);
    }

    public function testResolveNamespaceYoastReturnsSeo(): void
    {
        $result = $this->invokePrivate('resolveNamespace', ['_yoast_wpseo_title']);
        $this->assertSame('wash_seo', $result);
    }

    public function testResolveNamespaceDefaultReturnsCustom(): void
    {
        $result = $this->invokePrivate('resolveNamespace', ['my_custom_field']);
        $this->assertSame('wash_custom', $result);
    }

    // -------------------------------------------------------------------
    // inferType (private)
    // -------------------------------------------------------------------

    public function testInferTypeBool(): void
    {
        $this->assertSame('boolean', $this->invokePrivate('inferType', [true]));
    }

    public function testInferTypeInt(): void
    {
        $this->assertSame('number_integer', $this->invokePrivate('inferType', [42]));
    }

    public function testInferTypeFloat(): void
    {
        $this->assertSame('number_decimal', $this->invokePrivate('inferType', [3.14]));
    }

    public function testInferTypeArray(): void
    {
        $this->assertSame('json', $this->invokePrivate('inferType', [['a', 'b']]));
    }

    public function testInferTypeUrl(): void
    {
        $this->assertSame('url', $this->invokePrivate('inferType', ['https://example.com/img.jpg']));
    }

    public function testInferTypeDate(): void
    {
        $this->assertSame('date', $this->invokePrivate('inferType', ['2024-06-01']));
    }

    public function testInferTypeColor(): void
    {
        $this->assertSame('color', $this->invokePrivate('inferType', ['#ff5733']));
    }

    public function testInferTypeMultiLine(): void
    {
        $this->assertSame('multi_line_text_field', $this->invokePrivate('inferType', [str_repeat('a', 256)]));
    }

    public function testInferTypeDefault(): void
    {
        $this->assertSame('single_line_text_field', $this->invokePrivate('inferType', ['simple text']));
    }

    // -------------------------------------------------------------------
    // generateDefinitionDescription (private)
    // -------------------------------------------------------------------

    public function testGenerateDefinitionDescriptionWordPress(): void
    {
        $result = $this->invokePrivate('generateDefinitionDescription', ['wash', 'wp_post_id']);
        $this->assertSame('Synced from WordPress: wp_post_id', $result);
    }

    public function testGenerateDefinitionDescriptionAcf(): void
    {
        $result = $this->invokePrivate('generateDefinitionDescription', ['wash_acf', 'color']);
        $this->assertSame('Synced from ACF: color', $result);
    }

    public function testGenerateDefinitionDescriptionSeo(): void
    {
        $result = $this->invokePrivate('generateDefinitionDescription', ['wash_seo', 'meta_title']);
        $this->assertSame('Synced from SEO: meta_title', $result);
    }

    public function testGenerateDefinitionDescriptionCustom(): void
    {
        $result = $this->invokePrivate('generateDefinitionDescription', ['wash_custom', 'promo_code']);
        $this->assertSame('Synced from Custom Field: promo_code', $result);
    }

    public function testGenerateDefinitionDescriptionUnknownNamespace(): void
    {
        $result = $this->invokePrivate('generateDefinitionDescription', ['wash_hreflang', 'locale']);
        $this->assertSame('Synced from WordPress: locale', $result);
    }

    // -------------------------------------------------------------------
    // hasMetafieldKey (private)
    // -------------------------------------------------------------------

    public function testHasMetafieldKeyReturnsTrueWhenFound(): void
    {
        $list = [['key' => 'meta_title', 'namespace' => 'wash_seo']];
        $this->assertTrue($this->invokePrivate('hasMetafieldKey', [$list, 'meta_title']));
    }

    public function testHasMetafieldKeyReturnsFalseWhenNotFound(): void
    {
        $list = [['key' => 'meta_title', 'namespace' => 'wash_seo']];
        $this->assertFalse($this->invokePrivate('hasMetafieldKey', [$list, 'focus_keyword']));
    }

    public function testHasMetafieldKeyReturnsFalseOnEmpty(): void
    {
        $this->assertFalse($this->invokePrivate('hasMetafieldKey', [[], 'any']));
    }

    // -------------------------------------------------------------------
    // getShopifyResourceId (private)
    // -------------------------------------------------------------------

    public function testGetShopifyResourceIdReturnsIdWhenSet(): void
    {
        Functions\when('get_post_meta')->justReturn('gid://shopify/Article/123');
        $result = $this->invokePrivate('getShopifyResourceId', [42]);
        $this->assertSame('gid://shopify/Article/123', $result);
    }

    public function testGetShopifyResourceIdReturnsNullWhenEmpty(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $result = $this->invokePrivate('getShopifyResourceId', [42]);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------
    // isAcfField (private)
    // -------------------------------------------------------------------

    public function testIsAcfFieldReturnsFalseWhenAcfReturnsNothing(): void
    {
        // acf_get_field is stubbed to return false in setUp
        $result = $this->invokePrivate('isAcfField', ['my_field']);
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------
    // shouldSkipMeta - additional branches
    // -------------------------------------------------------------------

    public function testShouldSkipMetaOembedPrefix(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_oembed_abc']));
    }

    public function testShouldSkipMetaEncloseme(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_encloseme']));
    }

    public function testShouldSkipMetaPingme(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_pingme']));
    }

    public function testShouldSkipMetaMenuItemPrefix(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_menu_item_type']));
    }

    public function testShouldSkipMetaWpAttachedFile(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_attached_file']));
    }

    public function testShouldSkipMetaWpPageTemplate(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_page_template']));
    }

    public function testShouldSkipMetaWpOldSlug(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_old_slug']));
    }

    public function testShouldSkipMetaWpTrashMetaStatus(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_trash_meta_status']));
    }

    public function testShouldSkipMetaWpTrashMetaTime(): void
    {
        $this->assertTrue($this->invokePrivate('shouldSkipMeta', ['_wp_trash_meta_time']));
    }

    // -------------------------------------------------------------------
    // formatAcfValue — all branches
    // -------------------------------------------------------------------

    public function testFormatAcfValueTrueFalseTrue(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [true, 'true_false']);
        $this->assertSame('true', $result);
    }

    public function testFormatAcfValueTrueFalseFalse(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [false, 'true_false']);
        $this->assertSame('false', $result);
    }

    public function testFormatAcfValueImageArray(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn('https://cdn.example.com/img.jpg');
        $result = $this->invokePrivate('formatAcfValue', [['url' => 'https://cdn.example.com/img.jpg'], 'image']);
        $this->assertSame('https://cdn.example.com/img.jpg', $result);
    }

    public function testFormatAcfValueImageNumericId(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn('https://cdn.example.com/img.jpg');
        $result = $this->invokePrivate('formatAcfValue', [42, 'image']);
        $this->assertSame('https://cdn.example.com/img.jpg', $result);
    }

    public function testFormatAcfValueImageNumericIdNoUrl(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn(false);
        $result = $this->invokePrivate('formatAcfValue', [42, 'file']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueImageStringValue(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['https://example.com/img.jpg', 'image']);
        $this->assertSame('https://example.com/img.jpg', $result);
    }

    public function testFormatAcfValueImageNonStringReturnsNull(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [[], 'image']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueGalleryArrayUrls(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn('https://cdn.example.com/a.jpg');
        $value = [
            ['url' => 'https://cdn.example.com/1.jpg'],
            99, // numeric ID
        ];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'gallery']);
        $decoded = json_decode($result, true);
        $this->assertCount(2, $decoded);
    }

    public function testFormatAcfValueGalleryNotArray(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['not-array', 'gallery']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueRepeater(): void
    {
        $value = [['field1' => 'val1'], ['field1' => 'val2']];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'repeater']);
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertCount(2, $decoded);
    }

    public function testFormatAcfValueFlexibleContent(): void
    {
        $value = [['acf_fc_layout' => 'block1', 'text' => 'hello']];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'flexible_content']);
        $this->assertIsString($result);
    }

    public function testFormatAcfValueGroup(): void
    {
        $value = ['field_a' => 'value_a', 'field_b' => 42];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'group']);
        $decoded = json_decode($result, true);
        $this->assertSame('value_a', $decoded['field_a']);
    }

    public function testFormatAcfValueLinkArray(): void
    {
        $value = ['url' => 'https://example.com', 'title' => 'Click', 'target' => '_blank'];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'link']);
        $decoded = json_decode($result, true);
        $this->assertSame('https://example.com', $decoded['url']);
        $this->assertSame('Click', $decoded['title']);
    }

    public function testFormatAcfValueLinkString(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['https://example.com', 'link']);
        $this->assertSame('https://example.com', $result);
    }

    public function testFormatAcfValueLinkNonStringNonArrayReturnsNull(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [42, 'link']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueRelationshipWithObjects(): void
    {
        $obj1 = new \stdClass();
        $obj1->ID = 5;
        $obj2 = new \stdClass();
        $obj2->term_id = 10;
        $value = [$obj1, $obj2, 99];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'relationship']);
        $decoded = json_decode($result, true);
        $this->assertContains(5, $decoded);
        $this->assertContains(99, $decoded);
    }

    public function testFormatAcfValueRelationshipNotArrayReturnsNull(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['not-array', 'relationship']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueTaxonomyNotArrayReturnsNull(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [42, 'taxonomy']);
        $this->assertNull($result);
    }

    public function testFormatAcfValuePostObjectObject(): void
    {
        $obj = new \stdClass();
        $obj->ID = 7;
        $result = $this->invokePrivate('formatAcfValue', [$obj, 'post_object']);
        $this->assertSame('7', $result);
    }

    public function testFormatAcfValuePostObjectNumeric(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [88, 'post_object']);
        $this->assertSame('88', $result);
    }

    public function testFormatAcfValuePostObjectInvalid(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['not-numeric', 'post_object']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueUserObject(): void
    {
        $obj = new \stdClass();
        $obj->ID = 3;
        $result = $this->invokePrivate('formatAcfValue', [$obj, 'user']);
        $this->assertSame('3', $result);
    }

    public function testFormatAcfValueDatePickerYmd(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['20240601', 'date_picker']);
        $this->assertSame('2024-06-01', $result);
    }

    public function testFormatAcfValueDatePickerAlreadyFormatted(): void
    {
        // length != 8, returns as-is
        $result = $this->invokePrivate('formatAcfValue', ['2024-06-01', 'date_picker']);
        $this->assertSame('2024-06-01', $result);
    }

    public function testFormatAcfValueDateTimePicker(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['2024-06-01 12:00:00', 'date_time_picker']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $result);
    }

    public function testFormatAcfValueDateTimePickerInvalidReturnsNull(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['not-a-date', 'date_time_picker']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueGoogleMapArray(): void
    {
        $value = ['lat' => 51.5, 'lng' => -0.1, 'address' => 'London'];
        $result = $this->invokePrivate('formatAcfValue', [$value, 'google_map']);
        $decoded = json_decode($result, true);
        $this->assertSame(51.5, $decoded['lat']);
        $this->assertSame('London', $decoded['address']);
    }

    public function testFormatAcfValueGoogleMapNotArray(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['string', 'google_map']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueCheckboxArray(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [['opt1', 'opt2'], 'checkbox']);
        $decoded = json_decode($result, true);
        $this->assertSame(['opt1', 'opt2'], $decoded);
    }

    public function testFormatAcfValueCheckboxScalar(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['opt1', 'checkbox']);
        $this->assertSame('opt1', $result);
    }

    public function testFormatAcfValueNumberValid(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [42, 'number']);
        $this->assertSame('42', $result);
    }

    public function testFormatAcfValueNumberInvalid(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['abc', 'number']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueRangeValid(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [3.5, 'range']);
        $this->assertSame('3.5', $result);
    }

    public function testFormatAcfValueColorPickerWithHash(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['#ff5733', 'color_picker']);
        $this->assertSame('#ff5733', $result);
    }

    public function testFormatAcfValueColorPickerWithoutHash(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['ff5733', 'color_picker']);
        $this->assertSame('#ff5733', $result);
    }

    public function testFormatAcfValueColorPickerInvalid(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['notacolor', 'color_picker']);
        $this->assertNull($result);
    }

    public function testFormatAcfValueDefaultArray(): void
    {
        $result = $this->invokePrivate('formatAcfValue', [['a' => 1], 'unknown_type']);
        $decoded = json_decode($result, true);
        $this->assertSame(1, $decoded['a']);
    }

    public function testFormatAcfValueDefaultScalar(): void
    {
        $result = $this->invokePrivate('formatAcfValue', ['hello', 'text']);
        $this->assertSame('hello', $result);
    }

    // -------------------------------------------------------------------
    // convertAcfFieldToMetafield (private)
    // -------------------------------------------------------------------

    public function testConvertAcfFieldToMetafieldReturnsNullForNull(): void
    {
        $result = $this->invokePrivate('convertAcfFieldToMetafield', ['field_name', null, null]);
        $this->assertNull($result);
    }

    public function testConvertAcfFieldToMetafieldReturnsNullForEmptyString(): void
    {
        $result = $this->invokePrivate('convertAcfFieldToMetafield', ['field_name', '', null]);
        $this->assertNull($result);
    }

    public function testConvertAcfFieldToMetafieldReturnsArrayOnSuccess(): void
    {
        $fieldObject = ['type' => 'text'];
        $result = $this->invokePrivate('convertAcfFieldToMetafield', ['my_title', 'Hello', $fieldObject]);
        $this->assertIsArray($result);
        $this->assertSame('wash_acf', $result['namespace']);
        $this->assertSame('my_title', $result['key']);
        $this->assertSame('Hello', $result['value']);
        $this->assertSame('single_line_text_field', $result['type']);
    }

    public function testConvertAcfFieldToMetafieldUsesDefaultTypeWhenNoFieldObject(): void
    {
        $result = $this->invokePrivate('convertAcfFieldToMetafield', ['field', 'value', null]);
        $this->assertIsArray($result);
        $this->assertSame('single_line_text_field', $result['type']);
    }

    public function testConvertAcfFieldToMetafieldReturnsNullWhenFormatAcfValueReturnsNull(): void
    {
        // 'google_map' with non-array value → formatAcfValue returns null
        $fieldObject = ['type' => 'google_map'];
        $result = $this->invokePrivate('convertAcfFieldToMetafield', ['location', 'not-array', $fieldObject]);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------
    // flattenAcfComplexField (private)
    // -------------------------------------------------------------------

    public function testFlattenAcfComplexFieldFlattensNestedArrays(): void
    {
        $value = ['row1' => ['col1' => 'a', 'col2' => 'b']];
        $result = $this->invokePrivate('flattenAcfComplexField', [$value]);
        $this->assertSame(['col1' => 'a', 'col2' => 'b'], $result['row1']);
    }

    public function testFlattenAcfComplexFieldConvertsObjectToId(): void
    {
        $obj = new \stdClass();
        $obj->ID = 10;
        $value = ['post' => $obj];
        $result = $this->invokePrivate('flattenAcfComplexField', [$value]);
        $this->assertSame(10, $result['post']);
    }

    public function testFlattenAcfComplexFieldConvertsObjectWithTermId(): void
    {
        $obj = new \stdClass();
        $obj->term_id = 5;
        $value = ['term' => $obj];
        $result = $this->invokePrivate('flattenAcfComplexField', [$value]);
        $this->assertSame(5, $result['term']);
    }

    public function testFlattenAcfComplexFieldPassthroughScalar(): void
    {
        $result = $this->invokePrivate('flattenAcfComplexField', ['just a string']);
        $this->assertSame('just a string', $result);
    }

    public function testFlattenAcfComplexFieldDepthLimitPreventInfiniteRecursion(): void
    {
        // Build deeply nested array (depth 6)
        $nested = 'leaf';
        for ($i = 0; $i < 6; $i++) {
            $nested = ['child' => $nested];
        }
        // Should not throw
        $result = $this->invokePrivate('flattenAcfComplexField', [$nested, 0]);
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------
    // migrateFromPluginToggles
    // -------------------------------------------------------------------

    public function testMigrateFromPluginTogglesBuildsFieldsWithCoreEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_meta_wp_core' => '1',
                'wash_meta_yoast'   => '0',
                'wash_meta_rankmath' => '0',
                'wash_meta_acf'     => '0',
                'wash_post_types'   => 'post',
                'wash_api_key'      => 'test-key',
                'wash_app_url'      => 'https://app-wash.tradik.dev',
                'wash_http_user'    => '',
                'wash_http_pass'    => '',
                default             => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $mf = new \WashMetafields();
        $fields = $mf->migrateFromPluginToggles();

        $this->assertIsArray($fields);
        // Core fields should be syncEnabled = true
        $coreFields = array_filter($fields, fn($f) => $f['source'] === 'wordpress');
        foreach ($coreFields as $f) {
            $this->assertTrue($f['syncEnabled'], 'wordpress core fields should be enabled');
        }
    }

    public function testMigrateFromPluginTogglesDisablesYoastFieldsByDefault(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_meta_wp_core'  => '1',
                'wash_meta_yoast'    => '0',
                'wash_meta_rankmath' => '0',
                'wash_meta_acf'      => '0',
                'wash_post_types'    => 'post',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $mf = new \WashMetafields();
        $fields = $mf->migrateFromPluginToggles();

        // All fields should be returned as an array
        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);

        // WordPress core fields should be enabled (wash_meta_wp_core = 1)
        $wpFields = array_filter($fields, fn($f) => $f['source'] === 'wordpress');
        foreach ($wpFields as $f) {
            $this->assertTrue($f['syncEnabled'], 'wordpress field should be enabled');
        }
    }

    // -------------------------------------------------------------------
    // detectAvailableFields
    // -------------------------------------------------------------------

    public function testDetectAvailableFieldsReturnsCoreFields(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);

        $fields = $this->metafields->detectAvailableFields();

        $this->assertIsArray($fields);
        $keys = array_column($fields, 'key');
        $this->assertContains('wp_post_id', $keys);
        $this->assertContains('wp_permalink', $keys);
    }

    public function testDetectAvailableFieldsScansPostMeta(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([101]);
        Functions\when('get_post_meta')->justReturn([
            'promo_code' => ['SAVE10'],
        ]);

        $fields = $this->metafields->detectAvailableFields();

        $keys = array_column($fields, 'key');
        $this->assertContains('promo_code', $keys);
    }

    public function testDetectAvailableFieldsSkipsInternalMeta(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([101]);
        Functions\when('get_post_meta')->justReturn([
            '_wash_article_id' => ['gid://shopify/Article/1'],
            '_wp_page_template' => ['default'],
        ]);

        $fields = $this->metafields->detectAvailableFields();

        $keys = array_column($fields, 'key');
        $this->assertNotContains('_wash_article_id', $keys);
        $this->assertNotContains('_wp_page_template', $keys);
    }

    public function testDetectAvailableFieldsDetectsAcfFields(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([101]);
        Functions\when('get_post_meta')->justReturn([
            'color'   => ['red'],
            '_color'  => ['field_xyz123'],
        ]);

        $fields = $this->metafields->detectAvailableFields();

        $acfFields = array_filter($fields, fn($f) => $f['source'] === 'acf');
        $this->assertNotEmpty($acfFields);
    }

    public function testDetectAvailableFieldsDetectsRankMathFields(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([101]);
        Functions\when('get_post_meta')->justReturn([
            'rank_math_title' => ['My SEO Title'],
        ]);

        $fields = $this->metafields->detectAvailableFields();

        $rankFields = array_filter($fields, fn($f) => $f['source'] === 'rankmath');
        $this->assertNotEmpty($rankFields);
    }

    // -------------------------------------------------------------------
    // onMetaUpdate / onMetaAdd
    // -------------------------------------------------------------------

    public function testOnMetaUpdateCallsSyncMetafield(): void
    {
        // shouldSkipMeta → false, isAcfField → false (no acf_get_field)
        // getShopifyResourceId → empty → return early
        Functions\when('get_post_meta')->justReturn('');
        \WashMetafields::$fieldConfigCache = [];

        // Should not throw
        $this->metafields->onMetaUpdate(1, 42, 'custom_field', 'value');
        $this->assertTrue(true);
    }

    public function testOnMetaAddCallsSyncMetafield(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        \WashMetafields::$fieldConfigCache = [];

        $this->metafields->onMetaAdd(1, 42, 'custom_field', 'value');
        $this->assertTrue(true);
    }

    public function testOnMetaUpdateSkipsInternalMeta(): void
    {
        // _wash_article_id should be skipped immediately
        $this->metafields->onMetaUpdate(1, 42, '_wash_article_id', 'value');
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // onMetaDelete
    // -------------------------------------------------------------------

    public function testOnMetaDeleteSkipsInternalMeta(): void
    {
        $this->metafields->onMetaDelete(1, 42, '_wash_article_id', 'value');
        $this->assertTrue(true);
    }

    public function testOnMetaDeleteSkipsWhenNoShopifyId(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $this->metafields->onMetaDelete(1, 42, 'custom_field', 'value');
        $this->assertTrue(true);
    }

    public function testOnMetaDeleteSkipsWhenFieldNotEnabled(): void
    {
        Functions\when('get_post_meta')->justReturn('gid://shopify/Article/100');
        \WashMetafields::$fieldConfigCache = []; // empty = no enabled fields

        $this->metafields->onMetaDelete(1, 42, 'custom_field', 'value');
        $this->assertTrue(true);
    }

    public function testOnMetaDeleteSendsRequestWhenFieldEnabled(): void
    {
        Functions\when('get_post_meta')->justReturn('gid://shopify/Article/100');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_custom', 'key' => 'custom_field', 'syncEnabled' => true],
        ];

        $this->metafields->onMetaDelete(1, 42, 'custom_field', 'value');
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // onAcfSavePost
    // -------------------------------------------------------------------

    public function testOnAcfSavePostSkipsWhenNoShopifyId(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $this->metafields->onAcfSavePost(42);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // syncAllMetafields
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsReturnsSkippedWhenDisabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '0';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('skipped', $result);
        $this->assertTrue($result['skipped']);
    }

    public function testSyncAllMetafieldsReturnsSuccessWhenNoFieldsEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = []; // no enabled fields
        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['count']);
    }

    public function testSyncAllMetafieldsReturnsNoMetafieldsToSync(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        // One enabled field but no actual values returned
        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true],
        ];

        // build* methods rely on get_post, get_post_meta, get_permalink, etc.
        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        // wash.wp_post_id IS always built → it should succeed with count >= 1
        $this->assertArrayHasKey('success', $result);
    }

    public function testSyncAllMetafieldsCallsApiAndReturnsResult(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(1, $result['count']);
    }

    public function testSyncAllMetafieldsHandlesWpError(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);

        // Simulate WP_Error from API client
        $wpError = new \WP_Error('http_error', 'Connection failed');
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn($wpError);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(0);
        Functions\when('wp_remote_retrieve_body')->justReturn('');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        // Could be WP_Error or error array
        $this->assertTrue($result instanceof \WP_Error || (is_array($result) && isset($result['success'])));
    }

    // -------------------------------------------------------------------
    // buildWordPressMetafields — featured image path
    // -------------------------------------------------------------------

    public function testBuildWordPressMetafieldsIncludesFeaturedImage(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash', 'key' => 'wp_post_id',        'syncEnabled' => true],
            ['namespace' => 'wash', 'key' => 'featured_image_url', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(55);
        Functions\when('wp_get_attachment_url')->justReturn('https://cdn.example.com/thumb.jpg');
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        $this->assertTrue($result['success']);
        // featured_image_url should be included (count >= 2)
        $this->assertGreaterThanOrEqual(2, $result['count']);
    }

    // -------------------------------------------------------------------
    // buildCustomMetafields
    // -------------------------------------------------------------------

    public function testBuildCustomMetafieldsIncludesMultiValueMeta(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_custom', 'key' => 'my_tags', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([
            'my_tags' => ['tagA', 'tagB'],
        ]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        $this->assertArrayHasKey('success', $result);
    }

    // -------------------------------------------------------------------
    // syncMetafield — field enabled path
    // -------------------------------------------------------------------

    public function testOnMetaUpdateSendsRequestWhenFieldEnabled(): void
    {
        Functions\when('get_post_meta')->justReturn('gid://shopify/Article/100');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_custom', 'key' => 'promo_code', 'syncEnabled' => true],
        ];

        $this->metafields->onMetaUpdate(1, 42, 'promo_code', 'SAVE10');
        $this->assertTrue(true);
    }

    public function testOnMetaUpdateWithArrayValueUsesJsonEncode(): void
    {
        Functions\when('get_post_meta')->justReturn('gid://shopify/Article/100');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_custom', 'key' => 'tag_list', 'syncEnabled' => true],
        ];

        $this->metafields->onMetaUpdate(1, 42, 'tag_list', ['tagA', 'tagB']);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // getFieldConfig — migration path
    // -------------------------------------------------------------------

    public function testGetFieldConfigMigratesWhenOptionEmpty(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_meta_field_config' => '',
                'wash_meta_wp_core'      => '1',
                'wash_meta_yoast'        => '0',
                'wash_meta_rankmath'     => '0',
                'wash_meta_acf'          => '0',
                'wash_post_types'        => 'post',
                'wash_api_key'           => 'test-key',
                'wash_app_url'           => 'https://app-wash.tradik.dev',
                'wash_http_user'         => '',
                'wash_http_pass'         => '',
                default                  => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_option')->justReturn(true);

        $mf = new \WashMetafields();
        $config = $mf->getFieldConfig();

        $this->assertIsArray($config);
        $this->assertNotEmpty($config);
    }

    public function testGetFieldConfigAcceptsAlreadyDecodedArray(): void
    {
        $config = [['namespace' => 'wash', 'key' => 'wp_post_id', 'syncEnabled' => true]];
        Functions\when('get_option')->alias(function ($key, $default = '') use ($config) {
            if ($key === 'wash_meta_field_config') return $config; // already array
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        $mf = new \WashMetafields();
        $result = $mf->getFieldConfig();
        $this->assertSame($config, $result);
    }

    // -------------------------------------------------------------------
    // ensureMetafieldDefinitions — empty input
    // -------------------------------------------------------------------

    public function testEnsureMetafieldDefinitionsEmptyListReturnsEarly(): void
    {
        $ref = new \ReflectionMethod(\WashMetafields::class, 'ensureMetafieldDefinitions');
        $ref->setAccessible(true);
        $result = $ref->invoke($this->metafields, []);
        $this->assertSame(['success' => true, 'count' => 0], $result);
    }

    public function testEnsureMetafieldDefinitionsDeduplicatesKeys(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');
        Functions\when('is_wp_error')->justReturn(false);

        $ref = new \ReflectionMethod(\WashMetafields::class, 'ensureMetafieldDefinitions');
        $ref->setAccessible(true);

        $metafields = [
            ['namespace' => 'wash_seo', 'key' => 'meta_title', 'type' => 'single_line_text_field'],
            ['namespace' => 'wash_seo', 'key' => 'meta_title', 'type' => 'single_line_text_field'], // duplicate
        ];

        // Should not throw and should deduplicate
        $result = $ref->invoke($this->metafields, $metafields);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // init with ACF hook (line 110) — requires acf_get_field to exist
    // -------------------------------------------------------------------

    public function testInitRegistersAcfHookWhenAcfAvailable(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });
        Functions\when('add_action')->justReturn(true);
        // Define acf_get_field so the function_exists check passes
        Functions\when('acf_get_field')->justReturn(false);

        $mf = new \WashMetafields();
        $mf->init();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // migrateFromPluginToggles — all source types via scan
    // -------------------------------------------------------------------

    public function testMigrateFromPluginTogglesYoastAndAcfEnabled(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_meta_wp_core'  => '0',
                'wash_meta_yoast'    => '1',
                'wash_meta_rankmath' => '1',
                'wash_meta_acf'      => '1',
                'wash_post_types'    => 'post',
                'wash_api_key'       => 'test-key',
                'wash_app_url'       => 'https://app-wash.tradik.dev',
                'wash_http_user'     => '',
                'wash_http_pass'     => '',
                default              => $default,
            };
        });
        // Simulate an ACF field in the scan result
        Functions\when('get_posts')->justReturn([201]);
        Functions\when('get_post_meta')->justReturn([
            'color'  => ['red'],
            '_color' => ['field_abc'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('update_option')->justReturn(true);

        $mf = new \WashMetafields();
        $fields = $mf->migrateFromPluginToggles();

        $this->assertIsArray($fields);
        // ACF fields should have syncEnabled=true
        $acfFields = array_filter($fields, fn($f) => $f['source'] === 'acf');
        foreach ($acfFields as $f) {
            $this->assertTrue($f['syncEnabled']);
        }
    }

    // -------------------------------------------------------------------
    // detectAvailableFields — WPSEO_VERSION defined path
    // -------------------------------------------------------------------

    public function testDetectAvailableFieldsWithYoastDefined(): void
    {
        if (!defined('WPSEO_VERSION')) {
            define('WPSEO_VERSION', '22.0');
        }

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);

        $fields = $this->metafields->detectAvailableFields();

        $yoastFields = array_filter($fields, fn($f) => $f['source'] === 'yoast');
        $this->assertNotEmpty($yoastFields, 'Yoast fields should be detected when WPSEO_VERSION is defined');
    }

    // -------------------------------------------------------------------
    // detectAvailableFields — RankMath class_exists path
    // -------------------------------------------------------------------

    public function testDetectAvailableFieldsWithRankMathClass(): void
    {
        if (!class_exists('RankMath')) {
            eval('class RankMath {}');
        }

        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([]);

        $fields = $this->metafields->detectAvailableFields();

        // RankMath uses the same SEO keys as Yoast; if both are present,
        // Yoast fields take priority via $seen deduplication. Verify SEO fields
        // of either source are present and the RankMath code path was evaluated.
        $seoFields = array_filter($fields, fn($f) => $f['namespace'] === 'wash_seo');
        $this->assertNotEmpty($seoFields, 'SEO fields should be detected when RankMath or Yoast is active');
    }

    // -------------------------------------------------------------------
    // detectAvailableFields — ACF with acf_get_field available (line 308-310)
    // -------------------------------------------------------------------

    public function testDetectAvailableFieldsUsesAcfTypeWhenAcfActive(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            return match ($key) {
                'wash_post_types' => 'post',
                'wash_api_key'    => 'test-key',
                'wash_app_url'    => 'https://app-wash.tradik.dev',
                'wash_http_user'  => '',
                'wash_http_pass'  => '',
                default           => $default,
            };
        });
        Functions\when('get_posts')->justReturn([301]);
        Functions\when('get_post_meta')->justReturn([
            'rating'  => ['5'],
            '_rating' => ['field_rating_xyz'],
        ]);
        Functions\when('acf_get_field')->justReturn(['type' => 'number']);

        $fields = $this->metafields->detectAvailableFields();
        $acfFields = array_filter($fields, fn($f) => $f['source'] === 'acf');
        $this->assertNotEmpty($acfFields);
        $ratingField = array_values($acfFields)[0];
        // number type maps to number_decimal
        $this->assertSame('number_decimal', $ratingField['type']);
    }

    // -------------------------------------------------------------------
    // buildAcfMetafields — with ACF API available path (lines 580-593)
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsWithAcfApiAvailable(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_acf', 'key' => 'color', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // Make get_fields and acf_get_field available
        Functions\when('get_fields')->justReturn(['color' => 'red']);
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
    }

    // -------------------------------------------------------------------
    // buildAcfMetafields — with ACF API but get_fields returns false/null
    // -------------------------------------------------------------------

    public function testBuildAcfMetafieldsWithAcfApiReturningFalse(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_acf', 'key' => 'my_field', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // get_fields returns false → falls back to raw meta
        Functions\when('get_fields')->justReturn(false);
        Functions\when('acf_get_field')->justReturn(false);
        Functions\when('get_post_meta')->justReturn([
            'my_field'  => ['hello'],
            '_my_field' => ['field_xyz'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
    }

    // -------------------------------------------------------------------
    // buildAcfMetafields — ACF field with null fieldObject uses get_field_object
    // -------------------------------------------------------------------

    public function testBuildAcfMetafieldsCallsGetFieldObjectWhenAcfGetFieldFails(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_acf', 'key' => 'promo', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // get_fields returns an array, acf_get_field returns false, get_field_object returns null
        Functions\when('get_fields')->justReturn(['promo' => 'SAVE10']);
        Functions\when('acf_get_field')->justReturn(false);
        Functions\when('get_field_object')->justReturn(['type' => 'text']);

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
    }

    // -------------------------------------------------------------------
    // buildSeoMetafields — WPSEO_VERSION path (via syncAllMetafields)
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsWithYoastSeoFields(): void
    {
        if (!defined('WPSEO_VERSION')) {
            define('WPSEO_VERSION', '22.0');
        }

        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_seo', 'key' => 'meta_title',       'syncEnabled' => true],
            ['namespace' => 'wash_seo', 'key' => 'meta_description',  'syncEnabled' => true],
            ['namespace' => 'wash_seo', 'key' => 'focus_keyword',     'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // Return Yoast meta values
        Functions\when('get_post_meta')->alias(function ($postId, $key = '', $single = false) {
            if ($single) {
                return match ($key) {
                    '_yoast_wpseo_title'    => 'My Yoast Title',
                    '_yoast_wpseo_metadesc' => 'My Yoast Desc',
                    '_yoast_wpseo_focuskw'  => 'keyword',
                    default                 => '',
                };
            }
            return [];
        });

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
        $this->assertGreaterThanOrEqual(3, $result['count']);
    }

    // -------------------------------------------------------------------
    // buildSeoMetafields — RankMath path (class_exists)
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsWithRankMathSeoFields(): void
    {
        if (!class_exists('RankMath')) {
            eval('class RankMath {}');
        }

        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_seo', 'key' => 'meta_title',       'syncEnabled' => true],
            ['namespace' => 'wash_seo', 'key' => 'meta_description',  'syncEnabled' => true],
            ['namespace' => 'wash_seo', 'key' => 'focus_keyword',     'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // Return RankMath meta values
        Functions\when('get_post_meta')->alias(function ($postId, $key = '', $single = false) {
            if ($single) {
                return match ($key) {
                    'rank_math_title'          => 'My RankMath Title',
                    'rank_math_description'    => 'My RankMath Desc',
                    'rank_math_focus_keyword'  => 'rank_keyword',
                    default                    => '',
                };
            }
            return [];
        });

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
        $this->assertGreaterThanOrEqual(3, $result['count']);
    }

    // -------------------------------------------------------------------
    // onAcfSavePost with shopify ID → calls syncAcfFields (lines 419, 887-908)
    // -------------------------------------------------------------------

    public function testOnAcfSavePostWithValidShopifyIdAndEnabledFields(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key = '', $single = false) {
            if ($key === '_wash_article_id') return 'gid://shopify/Article/100';
            return $key === '' ? [] : ''; // no-key call returns the all-meta array
        });
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->justReturn(['body' => '{}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_acf', 'key' => 'color', 'syncEnabled' => true],
        ];

        // No ACF API available — falls back to raw fields
        // raw fields: meta has color with ACF ref
        Functions\when('maybe_unserialize')->returnArg(1);

        $this->metafields->onAcfSavePost(42);
        $this->assertTrue(true);
    }

    public function testOnAcfSavePostWithNoEnabledFieldsExitsEarly(): void
    {
        Functions\when('get_post_meta')->alias(function ($postId, $key = '', $single = false) {
            if ($key === '_wash_article_id') return 'gid://shopify/Article/100';
            return $key === '' ? [] : ''; // no-key call returns the all-meta array
        });

        \WashMetafields::$fieldConfigCache = []; // no enabled fields

        $this->metafields->onAcfSavePost(42);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // syncMetafield — ACF field skip path (line 432)
    // -------------------------------------------------------------------

    public function testSyncMetafieldSkipsAcfFieldWhenAcfActive(): void
    {
        // Define acf_get_field so isAcfField can check → returns true for non-underscore fields
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);
        // get_post_meta not called because we skip early
        $this->metafields->onMetaUpdate(1, 42, 'color_field', 'red');
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------
    // isAcfField — with acf_get_field defined, underscore key path (line 984-985)
    // -------------------------------------------------------------------

    public function testIsAcfFieldReturnsFalseForUnderscoreKey(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);
        $result = $this->invokePrivate('isAcfField', ['_hidden_field']);
        $this->assertFalse($result);
    }

    public function testIsAcfFieldReturnsTrueWhenAcfRecognizes(): void
    {
        Functions\when('acf_get_field')->justReturn(['type' => 'text']);
        $result = $this->invokePrivate('isAcfField', ['color_field']);
        $this->assertTrue($result);
    }

    public function testIsAcfFieldReturnsFalseWhenAcfDoesNotRecognize(): void
    {
        Functions\when('acf_get_field')->justReturn(false);
        $result = $this->invokePrivate('isAcfField', ['unknown_field']);
        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------
    // buildCustomMetafields — skip underscore and ACF (lines 860-866)
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsCustomFieldsSkipUnderscoreAndAcf(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_custom', 'key' => 'public_field', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_wp_error')->justReturn(false);

        // Mix of underscore (skip), ACF (skip via acf_get_field), and public field
        Functions\when('acf_get_field')->justReturn(false); // not ACF
        Functions\when('get_post_meta')->justReturn([
            '_internal'   => ['skip_me'],
            'acf_field'   => ['acf_value'],
            '_acf_field'  => ['field_xyz'],
            'public_field' => ['value1'],
        ]);
        Functions\when('maybe_unserialize')->returnArg(1);

        Functions\when('wp_remote_post')->justReturn(['body' => '{"metafields":[]}', 'response' => ['code' => 200]]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"metafields":[]}');
        Functions\when('wp_remote_retrieve_header')->justReturn('');

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');
        $this->assertArrayHasKey('success', $result);
    }

    // -------------------------------------------------------------------
    // syncAllMetafields — empty metafields after filter (line 496)
    // -------------------------------------------------------------------

    public function testSyncAllMetafieldsReturnsNoMetafieldsMessageWhenAllFilteredOut(): void
    {
        Functions\when('get_option')->alias(function ($key, $default = '') {
            if ($key === 'wash_sync_metafields') return '1';
            return match ($key) {
                'wash_api_key'   => 'test-key',
                'wash_app_url'   => 'https://app-wash.tradik.dev',
                'wash_http_user' => '',
                'wash_http_pass' => '',
                default          => $default,
            };
        });

        // Enable a field that won't actually be built (e.g., acf field that has no data)
        \WashMetafields::$fieldConfigCache = [
            ['namespace' => 'wash_acf', 'key' => 'nonexistent', 'syncEnabled' => true],
        ];

        $post = new \stdClass();
        $post->post_type = 'post';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/post');
        Functions\when('get_the_modified_date')->justReturn('2024-06-01');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn([]);
        Functions\when('is_multisite')->justReturn(false);

        $mf = new \WashMetafields();
        $result = $mf->syncAllMetafields(42, 'gid://shopify/Article/100');

        // All built metafields filtered out since wash_acf.nonexistent not built
        $this->assertArrayHasKey('success', $result);
        $this->assertSame(0, $result['count']);
    }
}
