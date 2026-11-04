import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { PriceDisplay } from './price-display.jsx';

function ProductPrice({ initialPrice, endpoint }) {
    const [price, setPrice] = useState(initialPrice);
    const [message, setMessage] = useState('');
    const [failed, setFailed] = useState(false);
    const request = useRef(null);
    async function refresh() {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('previous_fingerprint', price.fingerprint);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (controller.signal.aborted) return;
            setPrice(result.data);
            setFailed(false);
            setMessage(result.changed ? result.message : '');
        } catch {
            if (!controller.signal.aborted) {
                setFailed(true);
                setMessage('Le tarif n’a pas pu être actualisé. Reconnectez-vous si nécessaire, puis réessayez avant de commander.');
            }
        }
    }
    useEffect(() => {
        const remaining = price.valid_until ? Date.parse(price.valid_until) - Date.parse(price.calculated_at) + 150 : 60000;
        const timer = window.setTimeout(refresh, Math.max(1000, Math.min(60000, remaining)));
        const visible = () => { if (document.visibilityState === 'visible') refresh(); };
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', visible);
        return () => {
            window.clearTimeout(timer);
            request.current?.abort();
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [price]);
    return <>
        {message && <p role={failed ? 'alert' : 'status'}>{message}</p>}
        {!failed && <PriceDisplay price={price} />}
        <button type="button" onClick={refresh}>Actualiser le tarif</button>
    </>;
}

for (const root of document.querySelectorAll('.product-price')) {
    createRoot(root).render(<ProductPrice {...JSON.parse(root.dataset.price)} />);
}
