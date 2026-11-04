import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request.js';

function CustomerDiscounts({ listUrl, updateUrl }) {
    const [customers, setCustomers] = useState(null);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [failed, setFailed] = useState(false);
    const [errors, setErrors] = useState({});
    const [editing, setEditing] = useState(null);
    const feedback = useRef(null);
    useEffect(() => { if (message) feedback.current?.focus(); }, [message]);
    async function load(page = 1) {
        const url = new URL(listUrl, location.origin);
        url.searchParams.set('search', search); url.searchParams.set('page', page);
        try { setCustomers(await pricingRequest(url)); return true; }
        catch (error) { setFailed(true); setMessage(error.message); return false; }
    }
    useEffect(() => { load(); }, []);
    async function save(event, customer) {
        event.preventDefault(); setBusy(true); setMessage(''); setErrors({}); setEditing(customer.id);
        try {
            await pricingRequest(updateUrl.replace('__CUSTOMER__', customer.id), {
                method: 'PUT', values: { percentage: new FormData(event.currentTarget).get('percentage'), expected_version: customer.version },
            });
            if (await load(customers.current_page)) { setFailed(false); setMessage('Remise client enregistrée. Les prochains prix seront recalculés côté serveur.'); }
        } catch (error) {
            setFailed(true); setErrors(error.fields ?? {}); setMessage(error.message);
            if (error.status === 409) await load(customers.current_page);
        } finally { setBusy(false); }
    }
    return <>
        {message && <p ref={feedback} tabIndex="-1" role={failed ? 'alert' : 'status'} className={failed ? 'error' : undefined}>{message}</p>}
        <form onSubmit={event => { event.preventDefault(); load(); }}>
            <label htmlFor="discount-search">Code client ou nom<input id="discount-search" value={search} maxLength={120} disabled={busy} onChange={event => setSearch(event.target.value)} /></label>
            <button disabled={busy}>Chercher les clients</button>
        </form>
        <button disabled={busy} onClick={() => load(customers?.current_page ?? 1)}>Actualiser les remises</button>
        {!customers && <p role="status">Chargement…</p>}
        {customers && <>
            {!customers.data.length && <p>Aucun client trouvé.</p>}
            <div className="grid">{customers.data.map(customer => <article key={customer.id}>
                <h2>{customer.name}</h2><p>Code : {customer.customer_code ?? 'Non attribué'} — {customer.approval}</p>
                <form key={`${customer.id}-${customer.version}`} onSubmit={event => save(event, customer)}>
                    <label htmlFor={`discount-${customer.id}`}>Remise en %
                        <input id={`discount-${customer.id}`} name="percentage" defaultValue={customer.percentage} required inputMode="decimal" disabled={busy}
                            aria-invalid={editing === customer.id && errors.percentage ? true : undefined}
                            aria-describedby={editing === customer.id && errors.percentage ? `error-${customer.id}` : undefined} />
                    </label>
                    {editing === customer.id && errors.percentage && <p className="error" id={`error-${customer.id}`}>{errors.percentage.join(' ')}</p>}
                    <button disabled={busy}>Enregistrer la remise de {customer.name}</button>
                </form>
            </article>)}</div>
            <p>Page {customers.current_page} sur {customers.last_page}</p>
            <button disabled={busy || !customers.prev_page_url} onClick={() => load(customers.current_page - 1)}>Précédent</button>{' '}
            <button disabled={busy || !customers.next_page_url} onClick={() => load(customers.current_page + 1)}>Suivant</button>
        </>}
    </>;
}

const root = document.getElementById('customer-discounts');
if (root) createRoot(root).render(<CustomerDiscounts {...JSON.parse(root.dataset.discounts)} />);
