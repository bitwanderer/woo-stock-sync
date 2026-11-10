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
                    `${data.data.not_found} SKU rows skipped.`
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
        wcGssData.private_sheet ? createElement('p', { className: 'wc-gss-source' }, 'Source: Private Google Sheet') : createElement('div', { className: 'wc-gss-form-group' },
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
                className: 'description'
            }, 'In Google Sheets, use File > Share > Publish to web and choose "Comma-separated values (.csv)".')
        ),

        createElement('div', { className: 'wc-gss-sync-actions' },
            createElement('button', {
                type: 'button',
                onClick: handleSync,
                className: 'button button-primary',
                disabled: isLoading || (!wcGssData.private_sheet && !sheetUrl.trim())
            }, isLoading ? 'Syncing…' : 'Run Sync Now'),
            lastSyncTime ? createElement('span', { className: 'wc-gss-last-sync' },
                'Last successful sync: ', new Date(lastSyncTime).toLocaleString()
            ) : null
        ),

        message ? createElement('div', {
            className: `wc-gss-message wc-gss-message-${messageType}`,
            role: messageType === 'error' ? 'alert' : 'status'
        }, message) : null
    ];

    return createElement('div', { 'aria-busy': isLoading }, ...children);
}

// Mount it into the DOM
const appRoot = document.getElementById('wc-gss-react-app');
if (appRoot && createRoot) {
    createRoot(appRoot).render(createElement(App));
}
