import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request.js';

function DeliveryPreparation({ baseUrl, permissions }) {
    const [ready, setReady] = useState([]), [batches, setBatches] = useState([]), [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false), [feedback, setFeedback] = useState(null);
    const feedbackRef = useRef(null), scanKey = useRef(null);
    const refresh = async () => {
        const [orders, prepared] = await Promise.all([pricingRequest(`${baseUrl}/ready`), pricingRequest(`${baseUrl}/batches`)]);
        setReady(orders.data); setBatches(prepared.data);
        if (selected) setSelected(prepared.data.find(item => item.id === selected.id) ?? selected);
    };
    useEffect(() => { refresh().catch(error => setFeedback({ error: true, message: error.message })); }, []);
    useEffect(() => { if (feedback) feedbackRef.current?.focus(); }, [feedback]);
    const send = async (path, values = {}, key = null) => {
        setBusy(true); setFeedback(null);
        try {
            const response = await pricingRequest(`${baseUrl}/${path}`, { method: 'POST', values, key });
            setFeedback({ message: 'Opération enregistrée.' }); await refresh(); return response.data;
        } catch (error) { setFeedback({ error: true, message: error.message, fields: error.fields }); throw error; }
        finally { setBusy(false); }
    };
    const claim = async item => { const batch = await send('claim', { order_id: item.order_id, warehouse_id: item.warehouse_id }); setSelected(batch); };
    const scan = async event => {
        event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget));
        const fingerprint = JSON.stringify(values); if (scanKey.current?.fingerprint !== fingerprint) scanKey.current = { fingerprint, key: crypto.randomUUID() };
        try { const result = await send(`batches/${selected.id}/scan`, values, scanKey.current.key); scanKey.current = null; setSelected(result.batch); event.currentTarget.reset(); event.currentTarget.elements.code.focus(); } catch (error) { if (error.status === 409) scanKey.current = null; }
    };
    return <div>
        {feedback && <div ref={feedbackRef} tabIndex="-1" role={feedback.error ? 'alert' : 'status'}><p>{feedback.message}</p>{Object.entries(feedback.fields ?? {}).map(([field, messages]) => <p key={field}>{field}: {messages.join(' ')}</p>)}</div>}
        <section><h2>BL non commencés</h2>{ready.length === 0 ? <p>Aucun BL disponible.</p> : ready.map(item => <article key={`${item.order_id}:${item.warehouse_id}`}><strong>{item.order_number}</strong> — dépôt {item.warehouse_code}, {item.remaining_quantity} unité(s) réservée(s) <button disabled={busy} onClick={() => claim(item)}>Prendre ce BL</button></article>)}</section>
        <section><h2>Mes préparations</h2>{batches.map(item => <button key={item.id} disabled={busy} aria-pressed={selected?.id === item.id} onClick={() => setSelected(item)}>{item.number} — {item.status}</button>)}</section>
        {selected && <section><h2>{selected.number}</h2><p>État : {selected.status}{selected.partial ? ' · livraison partielle' : ''}</p>
            <div role="region" aria-label="Lignes du BL" tabIndex="0"><table><thead><tr><th>Produit</th><th>Conditionnement</th><th>Attendu</th><th>Préparé</th><th>Restant</th></tr></thead><tbody>{selected.lines.map(line => <tr key={line.id}><td>{line.sku}<br /><small>{line.designation} · {line.original_reference}</small></td><td>{line.units_per_package} unité(s)</td><td>{line.expected}</td><td>{line.prepared}</td><td>{line.remaining}</td></tr>)}</tbody></table></div>
            {selected.status === 'IN_PREPARATION' && <><form onSubmit={scan}><h3>Scanner ou saisir</h3><label>Mode<select name="mode"><option value="scanner">Douchette</option><option value="barcode">Barcode saisi</option><option value="reference">Référence/SKU</option></select></label><label>Code<input name="code" required maxLength="160" autoComplete="off" /></label><label>Ligne explicite (si ambiguë)<select name="line_id"><option value="">Résolution automatique</option>{selected.lines.filter(line => line.remaining > 0).map(line => <option value={line.id} key={line.id}>{line.sku} — reste {line.remaining}</option>)}</select></label><button disabled={busy}>Contrôler l’article</button></form>
            <button disabled={busy || !selected.lines.some(line => line.prepared > 0)} onClick={async () => setSelected(await send(`batches/${selected.id}/prepare`, { confirm_partial: selected.lines.some(line => line.remaining > 0) }))}>Valider la préparation{selected.lines.some(line => line.remaining > 0) ? ' partielle' : ''}</button></>}
            {selected.status === 'IN_PREPARATION' && permissions.includes('DELIVERY_TRANSFER') && <form onSubmit={async event => { event.preventDefault(); const target = Number(new FormData(event.currentTarget).get('target_user_id')); setSelected(await send(`batches/${selected.id}/transfer`, { target_user_id: target })); }}><h3>Transférer la préparation</h3><label>Identifiant du nouvel employé<input name="target_user_id" type="number" min="1" step="1" required /></label><button disabled={busy}>Transférer et tracer</button></form>}
            {selected.status === 'PREPARED' && permissions.includes('DELIVERY_DEPARTURE_CONFIRM') && <button disabled={busy} onClick={async () => setSelected(await send(`batches/${selected.id}/dispatch`))}>Confirmer le départ réel</button>}
            {selected.document_url && <p><a href={selected.document_url} target="_blank" rel="noreferrer">Ouvrir le BL PDF définitif</a></p>}
        </section>}
    </div>;
}

const element = document.getElementById('delivery-preparation');
if (element) createRoot(element).render(<DeliveryPreparation {...JSON.parse(element.dataset.delivery)} />);
