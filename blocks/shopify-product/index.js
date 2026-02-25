/**
 * Wash Shopify Product Block
 * Embeds a Shopify product in WordPress content.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls,
} from '@wordpress/block-editor';
import {
    PanelBody,
    TextControl,
    SelectControl,
    ToggleControl,
    Placeholder,
    Spinner,
    Notice,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Edit component for Shopify Product block.
 */
function Edit({ attributes, setAttributes }) {
    const { productId, handle, displayType, showPrice, showAddToCart } = attributes;
    const [products, setProducts] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [productData, setProductData] = useState(null);

    const blockProps = useBlockProps({
        className: `wash-shopify-product wash-product--${displayType}`,
    });

    // Fetch available products from Shopify via WP REST API
    useEffect(() => {
        setLoading(true);
        setError(null);
        apiFetch({ path: '/wash/v1/shopify/products' })
            .then((data) => {
                if (data.success && data.products) {
                    setProducts(data.products);
                } else if (data.products) {
                    setProducts(data.products);
                } else {
                    setProducts([]);
                }
                setLoading(false);
            })
            .catch((err) => {
                setError(err.message || __('Failed to load products', 'wash'));
                setLoading(false);
            });
    }, []);

    // Fetch selected product data for preview
    useEffect(() => {
        if (handle && products.length > 0) {
            const selected = products.find((p) => p.handle === handle);
            setProductData(selected || null);
        } else {
            setProductData(null);
        }
    }, [handle, products]);

    const productOptions = [
        { label: __('Select a product...', 'wash'), value: '' },
        ...products.map((p) => ({
            label: p.title,
            value: p.handle,
        })),
    ];

    const handleProductSelect = (newHandle) => {
        const selected = products.find((p) => p.handle === newHandle);
        setAttributes({
            handle: newHandle,
            productId: selected?.id || '',
        });
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Product Settings', 'wash')}>
                    <SelectControl
                        label={__('Product', 'wash')}
                        value={handle}
                        options={productOptions}
                        onChange={handleProductSelect}
                    />
                    <SelectControl
                        label={__('Display Type', 'wash')}
                        value={displayType}
                        options={[
                            { label: __('Card', 'wash'), value: 'card' },
                            { label: __('Minimal', 'wash'), value: 'minimal' },
                            { label: __('Full Width', 'wash'), value: 'full' },
                        ]}
                        onChange={(value) => setAttributes({ displayType: value })}
                    />
                    <ToggleControl
                        label={__('Show Price', 'wash')}
                        checked={showPrice}
                        onChange={(value) => setAttributes({ showPrice: value })}
                    />
                    <ToggleControl
                        label={__('Show Add to Cart', 'wash')}
                        checked={showAddToCart}
                        onChange={(value) => setAttributes({ showAddToCart: value })}
                    />
                </PanelBody>
            </InspectorControls>
            <div {...blockProps}>
                {loading && (
                    <Placeholder
                        icon="cart"
                        label={__('Shopify Product', 'wash')}
                    >
                        <Spinner />
                        <p>{__('Loading products...', 'wash')}</p>
                    </Placeholder>
                )}
                {error && (
                    <Placeholder
                        icon="warning"
                        label={__('Shopify Product', 'wash')}
                    >
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                        <p>{__('Make sure Wash is connected to Shopify.', 'wash')}</p>
                    </Placeholder>
                )}
                {!loading && !error && !handle && (
                    <Placeholder
                        icon="cart"
                        label={__('Shopify Product', 'wash')}
                        instructions={__('Select a product from your Shopify store to embed.', 'wash')}
                    >
                        <SelectControl
                            value={handle}
                            options={productOptions}
                            onChange={handleProductSelect}
                        />
                    </Placeholder>
                )}
                {!loading && !error && handle && (
                    <div className="wash-product-preview">
                        {productData?.featuredImage?.url ? (
                            <img
                                src={productData.featuredImage.url}
                                alt={productData.title}
                                className="wash-product-preview__image"
                            />
                        ) : (
                            <div className="wash-product-preview__placeholder">
                                <span className="dashicons dashicons-format-image"></span>
                            </div>
                        )}
                        <div className="wash-product-preview__content">
                            <h3 className="wash-product-preview__title">
                                {productData?.title || handle}
                            </h3>
                            {showPrice && productData?.priceRange && (
                                <p className="wash-product-preview__price">
                                    {productData.priceRange.minVariantPrice.amount}{' '}
                                    {productData.priceRange.minVariantPrice.currencyCode}
                                </p>
                            )}
                            {showAddToCart && (
                                <button className="wash-product-preview__button" disabled>
                                    {__('Add to Cart', 'wash')}
                                </button>
                            )}
                        </div>
                        <p className="wash-product-preview__note">
                            {__('Product will render using your Shopify theme.', 'wash')}
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * Save component for Shopify Product block.
 * Outputs a placeholder that will be replaced during sync.
 */
function Save({ attributes }) {
    const { handle, displayType, showPrice, showAddToCart } = attributes;

    const blockProps = useBlockProps.save({
        className: `wash-shopify-product wash-product--${displayType}`,
        'data-product-handle': handle,
        'data-show-price': showPrice ? 'true' : 'false',
        'data-show-add-to-cart': showAddToCart ? 'true' : 'false',
    });

    return (
        <div {...blockProps}>
            <div className="wash-product-placeholder" data-handle={handle}>
                {/* Content will be replaced with Liquid during sync */}
            </div>
        </div>
    );
}

// Register the block
registerBlockType('wash/shopify-product', {
    edit: Edit,
    save: Save,
});
