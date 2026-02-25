/**
 * Wash Sync Status Block
 * Displays sync status in the editor. Does not render on frontend.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Edit component for Sync Status block.
 */
function Edit() {
    const [syncStatus, setSyncStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const blockProps = useBlockProps({
        className: 'wash-sync-status',
    });

    // Get current post ID
    const postId = useSelect((select) => {
        return select('core/editor').getCurrentPostId();
    }, []);

    // Fetch sync status for current post
    useEffect(() => {
        if (!postId) {
            setLoading(false);
            return;
        }

        setLoading(true);
        setError(null);

        apiFetch({ path: `/wash/v1/sync-status/${postId}` })
            .then((data) => {
                setSyncStatus(data);
                setLoading(false);
            })
            .catch((err) => {
                setError(err.message || __('Failed to load sync status', 'wash'));
                setLoading(false);
            });
    }, [postId]);

    const getStatusIcon = (status) => {
        switch (status) {
            case 'synced':
                return 'yes-alt';
            case 'pending':
                return 'clock';
            case 'error':
                return 'warning';
            case 'queued':
                return 'update';
            default:
                return 'minus';
        }
    };

    const getStatusLabel = (status) => {
        switch (status) {
            case 'synced':
                return __('Synced to Shopify', 'wash');
            case 'pending':
                return __('Pending sync', 'wash');
            case 'error':
                return __('Sync error', 'wash');
            case 'queued':
                return __('In queue', 'wash');
            default:
                return __('Not synced', 'wash');
        }
    };

    const getStatusClass = (status) => {
        switch (status) {
            case 'synced':
                return 'wash-sync-status--success';
            case 'pending':
            case 'queued':
                return 'wash-sync-status--pending';
            case 'error':
                return 'wash-sync-status--error';
            default:
                return 'wash-sync-status--none';
        }
    };

    const formatDate = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    return (
        <div {...blockProps}>
            <div className="wash-sync-status__header">
                <span className="dashicons dashicons-cloud-upload"></span>
                <span className="wash-sync-status__title">
                    {__('Shopify Sync Status', 'wash')}
                </span>
            </div>
            <div className="wash-sync-status__content">
                {loading && (
                    <div className="wash-sync-status__loading">
                        <span className="dashicons dashicons-update spin"></span>
                        {__('Loading...', 'wash')}
                    </div>
                )}
                {error && (
                    <div className="wash-sync-status__error">
                        <span className="dashicons dashicons-warning"></span>
                        {error}
                    </div>
                )}
                {!loading && !error && syncStatus && (
                    <div className={`wash-sync-status__info ${getStatusClass(syncStatus.status)}`}>
                        <div className="wash-sync-status__row">
                            <span className={`dashicons dashicons-${getStatusIcon(syncStatus.status)}`}></span>
                            <span>{getStatusLabel(syncStatus.status)}</span>
                        </div>
                        {syncStatus.shopifyArticleId && (
                            <div className="wash-sync-status__detail">
                                <strong>{__('Shopify ID:', 'wash')}</strong> {syncStatus.shopifyArticleId}
                            </div>
                        )}
                        {syncStatus.syncedAt && (
                            <div className="wash-sync-status__detail">
                                <strong>{__('Last sync:', 'wash')}</strong> {formatDate(syncStatus.syncedAt)}
                            </div>
                        )}
                        {syncStatus.shopifyBlogHandle && (
                            <div className="wash-sync-status__detail">
                                <strong>{__('Blog:', 'wash')}</strong> {syncStatus.shopifyBlogHandle}
                            </div>
                        )}
                        {syncStatus.errorMessage && (
                            <div className="wash-sync-status__error-message">
                                <strong>{__('Error:', 'wash')}</strong> {syncStatus.errorMessage}
                            </div>
                        )}
                    </div>
                )}
                {!loading && !error && !syncStatus && (
                    <div className="wash-sync-status__none">
                        <span className="dashicons dashicons-minus"></span>
                        {__('Not yet synced to Shopify', 'wash')}
                    </div>
                )}
            </div>
            <div className="wash-sync-status__footer">
                <small>{__('This block is editor-only and will not appear on your site.', 'wash')}</small>
            </div>
        </div>
    );
}

/**
 * Save component - returns null as this block doesn't render on frontend.
 */
function Save() {
    return null;
}

// Register the block
registerBlockType('wash/sync-status', {
    edit: Edit,
    save: Save,
});
