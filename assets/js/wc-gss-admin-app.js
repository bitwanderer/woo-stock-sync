const { useState, createRoot, createElement } = wp.element;

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

        if (!wcGssData.private_sheet && !sheetUrl.trim().startsWith('https://')) {
            setMessage('Enter an HTTPS Google Sheets CSV export URL.');
            setMessageType('error');
            setIsLoading(false);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'wc_gss_sync_data');
        formData.append('nonce', wcGssData.sync_nonce);
        if (!wcGssData.private_sheet) formData.append('sheet_url', sheetUrl.trim());

        try {
            const response = await fetch(wcGssData.ajax_url, {
                method: 'POST',
                body: formData,
            });

            const data = await response.json();

            if (data.success) {
                setMessage(
                    `Sync successful! Updated ${data.data.updated} products. ` +
                    `${data.data.not_found} SKUs did not match a product.`
                );
                setMessageType('success');
                setLastSyncTime(data.data.last_sync_time);
            } else {
                setMessage(`Sync failed: ${data.data?.message || 'Unknown error.'}`);
                setMessageType('error');
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
        wcGssData.private_sheet ? createElement('p', null, 'Using the private Google Sheet configured on this server.') : createElement('div', { className: 'wc-gss-form-group' },
            createElement('label', {
                htmlFor: 'wc-gss-sheet-url',
                className: 'wc-gss-label'
            }, 'Google Sheet CSV URL:'),

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
            }, 'In Google Sheets, use File > Share > Publish to web and choose "Comma-separated values (.csv)".')
        ),

        createElement('button', {
            onClick: handleSync,
            className: 'wc-gss-button',
            disabled: isLoading || (!wcGssData.private_sheet && !sheetUrl)
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
