/**
 * Wash Shopify Collection Block
 * Embeds products from a Shopify collection in WordPress content.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls,
} from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    RangeControl,
    Placeholder,
    Spinner,
    Notice,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Edit component for Shopify Collection block.
 */
function Edit({ attributes, setAttributes }) {
    const { collectionId, handle, limit, columns } = attributes;
    const [collections, setCollections] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [collectionData, setCollectionData] = useState(null);

    const blockProps = useBlockProps({
        className: `wash-shopify-collection wash-columns--${columns}`,
    });

    // Fetch available collections from Shopify via WP REST API
    useEffect(() => {
        setLoading(true);
        setError(null);
        apiFetch({ path: '/wash/v1/shopify/collections' })
            .then((data) => {
                if (data.success && data.collections) {
                    setCollections(data.collections);
                } else if (data.collections) {
                    setCollections(data.collections);
                } else {
                    setCollections([]);
                }
                setLoading(false);
            })
            .catch((err) => {
                setError(err.message || __('Failed to load collections', 'wash'));
                setLoading(false);
            });
    }, []);

    // Fetch selected collection data for preview
    useEffect(() => {
        if (handle && collections.length > 0) {
            const selected = collections.find((c) => c.handle === handle);
            setCollectionData(selected || null);
        } else {
            setCollectionData(null);
        }
    }, [handle, collections]);

    const collectionOptions = [
        { label: __('Select a collection...', 'wash'), value: '' },
        ...collections.map((c) => ({
            label: c.title,
            value: c.handle,
        })),
    ];

    const handleCollectionSelect = (newHandle) => {
        const selected = collections.find((c) => c.handle === newHandle);
        setAttributes({
            handle: newHandle,
            collectionId: selected?.id || '',
        });
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Collection Settings', 'wash')}>
                    <SelectControl
                        label={__('Collection', 'wash')}
                        value={handle}
                        options={collectionOptions}
                        onChange={handleCollectionSelect}
                    />
                    <RangeControl
                        label={__('Products to Show', 'wash')}
                        value={limit}
                        onChange={(value) => setAttributes({ limit: value })}
                        min={1}
                        max={24}
                    />
                    <RangeControl
                        label={__('Columns', 'wash')}
                        value={columns}
                        onChange={(value) => setAttributes({ columns: value })}
                        min={2}
                        max={6}
                    />
                </PanelBody>
            </InspectorControls>
            <div {...blockProps}>
                {loading && (
                    <Placeholder
                        icon="grid-view"
                        label={__('Shopify Collection', 'wash')}
                    >
                        <Spinner />
                        <p>{__('Loading collections...', 'wash')}</p>
                    </Placeholder>
                )}
                {error && (
                    <Placeholder
                        icon="warning"
                        label={__('Shopify Collection', 'wash')}
                    >
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                        <p>{__('Make sure Wash is connected to Shopify.', 'wash')}</p>
                    </Placeholder>
                )}
                {!loading && !error && !handle && (
                    <Placeholder
                        icon="grid-view"
                        label={__('Shopify Collection', 'wash')}
                        instructions={__('Select a collection to display products from.', 'wash')}
                    >
                        <SelectControl
                            value={handle}
                            options={collectionOptions}
                            onChange={handleCollectionSelect}
                        />
                    </Placeholder>
                )}
                {!loading && !error && handle && (
                    <div className="wash-collection-preview">
                        <div className="wash-collection-preview__header">
                            <h3 className="wash-collection-preview__title">
                                {collectionData?.title || handle}
                            </h3>
                            <p className="wash-collection-preview__info">
                                {__('Showing', 'wash')} {limit} {__('products in', 'wash')} {columns} {__('columns', 'wash')}
                            </p>
                        </div>
                        <div className="wash-collection-preview__grid" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
                            {Array.from({ length: Math.min(limit, 8) }).map((_, index) => (
                                <div key={index} className="wash-collection-preview__item">
                                    <div className="wash-collection-preview__placeholder">
                                        <span className="dashicons dashicons-format-image"></span>
                                    </div>
                                    <div className="wash-collection-preview__product-title">
                                        {__('Product', 'wash')} {index + 1}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="wash-collection-preview__note">
                            {__('Products will render using your Shopify theme.', 'wash')}
                        </p>
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * Save component for Shopify Collection block.
 * Outputs a placeholder that will be replaced during sync.
 */
function Save({ attributes }) {
    const { handle, limit, columns } = attributes;

    const blockProps = useBlockProps.save({
        className: `wash-shopify-collection wash-columns--${columns}`,
        'data-collection-handle': handle,
        'data-limit': limit,
        'data-columns': columns,
    });

    return (
        <div {...blockProps}>
            <div className="wash-collection-placeholder" data-handle={handle}>
                {/* Content will be replaced with Liquid during sync */}
            </div>
        </div>
    );
}

// Register the block
registerBlockType('wash/shopify-collection', {
    edit: Edit,
    save: Save,
});
