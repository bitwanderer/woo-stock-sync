const { useState, useEffect, createRoot, createElement } = wp.element;

function App() {
    const [sheetUrl, setSheetUrl] = useState(wcGssData.sheet_url);
    const [isLoading, setIsLoading] = useState(false);
    const [message, setMessage] = useState('');
    const [messageType, setMessageType] = useState('');
    const [lastSyncTime, setLastSyncTime] = useState(wcGssData.last_sync_time);

    const handleSheetUrlChange = (e) => {
        setSheetUrl(e.target.value);
    };

    const handleSync = async () => {
        setIsLoading(true);
        setMessage('');
        setMessageType('');

        if (!sheetUrl.startsWith('http://') && !sheetUrl.startsWith('https://')) {
            setMessage('Please enter a valid URL starting with http:// or https://');
            setMessageType('error');
            setIsLoading(false);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'wc_gss_sync_data');
        formData.append('nonce', wcGssData.sync_nonce);
        formData.append('sheet_url', sheetUrl);

        try {
            const response = await fetch(wcGssData.ajax_url, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                setMessage(
                    `Sync successful! Updated ${data.data.updated} products. ` +
                    `${data.data.not_found} products not found/skipped. ` +
                    (data.data.errors.length > 0 ? `Errors: ${data.data.errors.join('; ')}` : '')
                );
                setMessageType('success');
                setLastSyncTime(new Date().toLocaleString());
            } else {
                setMessage(`Sync failed: ${data.data.message || 'Unknown error.'}`);
                setMessageType('error');
                if (data.data.errors && data.data.errors.length > 0) {
                    setMessage(prev => prev + ` Errors: ${data.data.errors.join('; ')}`);
                }
            }
        } catch (error) {
            console.error('Fetch error:', error);
            setMessage(`An unexpected error occurred: ${error.message}`);
            setMessageType('error');
        } finally {
            setIsLoading(false);
        }
    };

    const children = [
        createElement('div', { className: 'wc-gss-form-group' },
            createElement('label', {
                htmlFor: 'wc-gss-sheet-url',
                className: 'wc-gss-label'
            }, 'Google Sheet Public CSV/TSV URL:'),

            createElement('input', {
                type: 'url',
                id: 'wc-gss-sheet-url',
                className: 'wc-gss-input',
                value: sheetUrl,
                onChange: handleSheetUrlChange,
                placeholder: 'https://docs.google.com/spreadsheets/d/.../pub?output=csv',
                disabled: isLoading
            }),

            createElement('p', {
                className: 'wc-gss-info',
                style: { fontSize: '0.85em', color: '#666', marginTop: '5px' }
            }, '*Your Google Sheet must be published to the web as CSV or TSV. Go to File > Share > Publish to web > Choose "Comma-separated values (.csv)" or "Tab-separated values (.tsv)".')
        ),

        createElement('button', {
            onClick: handleSync,
            className: 'wc-gss-button',
            disabled: isLoading || !sheetUrl
        }, isLoading ? 'Syncing...' : 'Run Sync Now'),

        lastSyncTime ? createElement('p', { className: 'wc-gss-last-sync' },
            'Last Sync: ',
            createElement('strong', null, new Date(lastSyncTime).toLocaleString())
        ) : null,

        message ? createElement('div', {
            className: `wc-gss-message ${messageType}`
        }, message) : null
    ];

    return createElement('div', { id: 'wc-gss-admin-root-inner' }, children);
}

// Mount it into the DOM
const appRoot = document.getElementById('wc-gss-react-app');
if (appRoot && createRoot) {
    createRoot(appRoot).render(createElement(App));
}
