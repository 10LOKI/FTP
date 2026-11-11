import React, { useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request';

function InvoicePreview({ baseUrl, canIssue }) {
    const [customer, setCustomer] = useState('');
    const [loadedCustomer, setLoadedCustomer] = useState(null);
    const [page, setPage] = useState(null);
    const [selected, setSelected] = useState({});
    const [preview, setPreview] = useState(null);
    const [uncertain, setUncertain] = useState(false);
    const [issued, setIssued] = useState(null);
    const [confirmed, setConfirmed] = useState(false);
    const issuanceRequest = useRef(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const generation = useRef(0);
    const previewHeading = useRef(null);

    function resetIssue() { setUncertain(false); issuanceRequest.current = null; setConfirmed(false); setIssued(null); }
    function clear() {
        resetIssue();
        generation.current++;
        setPage(null); setSelected({}); setPreview(null); setLoadedCustomer(null); setError(''); setBusy(false);
    }
    async function load(event, pageNumber = 1, preserveSelection = false) {
        event?.preventDefault();
        const current = ++generation.current;
        resetIssue();
        setBusy(true); setError(''); setPreview(null);
        if (!preserveSelection) setSelected({});
        setPage(null);
        try {
            const result = await pricingRequest(`${baseUrl}/deliveries?customer_id=${encodeURIComponent(customer)}&page=${pageNumber}`);
            if (current !== generation.current) return;
            setPage(result); setLoadedCustomer(customer);
        } catch (failure) {
            if (current === generation.current) setError(failure.message);
        } finally {
            if (current === generation.current) setBusy(false);
        }
    }
    async function calculate(event) {
        event.preventDefault();
        const current = ++generation.current;
        resetIssue();
        setBusy(true); setError(''); setPreview(null);
        try {
            const result = await pricingRequest(`${baseUrl}/preview`, { method: 'POST', values: {
                customer_id: Number(loadedCustomer),
                lines: Object.values(selected).map(({ line, quantity }) => ({
                    delivery_line_id: line.id, quantity: Number(quantity), availability_token: line.availability_token,
                })),
            } });
            if (current !== generation.current) return;
            setPreview(result.data);
            requestAnimationFrame(() => previewHeading.current?.focus());
        } catch (failure) {
            if (current !== generation.current) return;
            setError(failure.status === 409 ? 'Les quantités ou prix historiques ont changé. Rechargez les BL et vérifiez votre sélection.' : failure.message);
            if (failure.status === 409 || failure.status === 403 || failure.status === 401) {
                setPage(null); setSelected({});
            }
        } finally {
            if (current === generation.current) setBusy(false);
        }
    }
    async function issue() {
        if (!preview?.issuance?.ready || !confirmed) return;
        const current = ++generation.current;
        if (!issuanceRequest.current) issuanceRequest.current = {
            customer_id: Number(loadedCustomer), request_key: crypto.randomUUID(), issuance_token: preview.issuance.token,
            lines: Object.values(selected).map(({ line, quantity }) => ({ delivery_line_id: line.id, quantity: Number(quantity), availability_token: line.availability_token })),
        };
        setBusy(true); setError('');
        try {
            const result = await pricingRequest(`${baseUrl}/invoices`, { method: 'POST', values: issuanceRequest.current });
            if (current !== generation.current) return;
            setUncertain(false); setIssued(result.data); setPreview(null); setSelected({}); setPage(null);
        } catch (failure) {
            if (current !== generation.current) return;
            const unknown = !failure.status || failure.status >= 500 && failure.status !== 503;
            setUncertain(unknown);
            setError(unknown ? 'Résultat incertain. Réessayez l’émission ici avec la même demande pour retrouver son résultat avant toute nouvelle sélection.' : failure.status === 409 ? 'La sélection ou les conditions ont changé. Rechargez les BL et recalculez.' : failure.message);
            if ([401, 403, 409].includes(failure.status)) { setPreview(null); setSelected({}); setPage(null); resetIssue(); }
        } finally { if (current === generation.current) setBusy(false); }
    }
    function change(line, quantity) {
        resetIssue();
        setPreview(null);
        setSelected(previous => ({ ...previous, [line.id]: { line, quantity } }));
    }
    return <div aria-busy={busy}>
        <form onSubmit={load}>
            <label htmlFor="billing-customer">Identifiant du compte client
                <input id="billing-customer" type="number" min="1" step="1" required disabled={busy || uncertain} value={customer}
                    onChange={event => { clear(); setCustomer(event.target.value); }} />
            </label>
            <button disabled={busy || uncertain}>Charger les BL</button>
        </form>
        {busy && <p role="status">Chargement…</p>}
        {error && <p className="error" role="alert">{error}</p>}
        {page && <form onSubmit={calculate}>
            <h2>BL du compte {loadedCustomer}</h2>
            <p>Quantités en pièces. Un conditionnement de 10 correspond à 10 pièces. Les montants sont en MAD TTC.</p>
            {page.data.length === 0 && <p role="status">Aucun BL avec départ validé pour ce compte.</p>}
            {page.data.map(batch => <fieldset key={batch.id} disabled={busy || uncertain}>
                <legend>{batch.number} · {batch.order_number}</legend>
                <p>Départ : {batch.dispatched_at} UTC · <a href={`${baseUrl}/deliveries/${batch.id}`} target="_blank" rel="noreferrer">Consulter les données source du BL</a></p>
                {batch.lines.map(line => {
                    const unavailable = line.remaining_quantity === 0 || line.unit_price === null;
                    return <article key={line.id}>
                        <label><span><input style={{ width: 'auto' }} type="checkbox" disabled={unavailable}
                            checked={Boolean(selected[line.id])} onChange={event => {
                                setPreview(null); resetIssue();
                                if (event.target.checked) change(line, line.remaining_quantity);
                                else setSelected(previous => { const next = { ...previous }; delete next[line.id]; return next; });
                            }} /> {line.sku} — {line.designation}</span></label>
                        <p>Livré : {line.delivered_quantity} · Déjà facturé : {line.invoiced_quantity} · Retourné avant facture : {line.returned_before_invoice_quantity} · Restant : {line.remaining_quantity}</p>
                        <p>{line.unit_price === null ? 'Prix historique MAD TTC indisponible : confirmation commerciale nécessaire.' : `${line.unit_price} MAD TTC / pièce`}</p>
                        {selected[line.id] && <label>Quantité à facturer pour {line.sku}
                            <input type="number" min="1" max={line.remaining_quantity} step="1" required
                                value={selected[line.id].quantity} onChange={event => change(line, event.target.value)} />
                        </label>}
                    </article>;
                })}
            </fieldset>)}
            <p role="status">{Object.keys(selected).length} ligne(s) sélectionnée(s)</p>
            <button disabled={busy || uncertain || Object.keys(selected).length === 0}>Calculer l’aperçu</button>
            <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap' }}>
                <button type="button" disabled={busy || uncertain || page.current_page <= 1} onClick={() => load(null, page.current_page - 1, true)}>Précédent</button>
                <span>Page {page.current_page} / {page.last_page}</span>
                <button type="button" disabled={busy || uncertain || page.current_page >= page.last_page} onClick={() => load(null, page.current_page + 1, true)}>Suivant</button>
            </div>
        </form>}
        {issued && <section aria-label="Facture émise" role="status">
            <h2>Facture {issued.number} émise</h2><p>Total : {issued.snapshot.total} MAD TTC</p>
            <a href={issued.document_url}>Télécharger le PDF historique</a>
        </section>}
        {preview && <section aria-labelledby="preview-result">
            <h2 id="preview-result" ref={previewHeading} tabIndex="-1">Aperçu — compte {preview.customer_id}</h2>
            {preview.lines.map(line => <p key={line.id}>{line.delivery_number} · {line.sku} · {line.quantity} × {line.unit_price} = {line.total} MAD TTC</p>)}
            <p><strong>Total : {preview.total} MAD TTC</strong></p>
            <p>Aperçu uniquement. Aucune facture émise, aucun stock ni solde modifié.</p>
            {!preview.issuance?.ready && <p role="status">Émission indisponible : {preview.issuance?.reason}</p>}
            {canIssue && preview.issuance?.ready && <div>
                <label><span><input type="checkbox" style={{ width: 'auto' }} checked={confirmed} disabled={busy || uncertain} onChange={event => setConfirmed(event.target.checked)} /> J’ai vérifié le client, les quantités et le total à émettre.</span></label>
                <button type="button" disabled={busy || !confirmed} onClick={issue}>Émettre la facture</button>
                <p>En cas de réponse réseau incertaine, réessayez ici pour retrouver la même facture.</p>
            </div>}
        </section>}
    </div>;
}
const root = document.getElementById('invoice-preview');
if (root) createRoot(root).render(<InvoicePreview baseUrl={root.dataset.baseUrl} canIssue={root.dataset.canIssue === '1'} />);
