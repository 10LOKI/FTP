import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

async function apiRequest(url, values) {
    const response = await fetch(url, {
        method: values ? 'POST' : 'GET', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        ...(values ? { body: JSON.stringify(values) } : {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(response.status === 409 ? 'Le panier, les prix ou la demande ont changé. Actualisez et vérifiez avant un nouvel envoi.'
            : response.status === 401 || response.status === 419 ? 'Votre session a expiré. Reconnectez-vous puis consultez votre historique.'
            : response.status === 403 ? 'Votre compte ne permet plus cet accès.'
            : response.status === 404 ? 'Cette demande est introuvable.'
            : response.status === 422 ? Object.values(payload.errors ?? {}).flat().join(' ') || payload.message || 'Vérifiez les informations saisies.'
            : 'Le serveur est momentanément indisponible. Réessayez la même demande pour vérifier son résultat.');
        error.status = response.status;
        throw error;
    }
    if (!payload.data) throw new Error('Réponse illisible. Consultez votre historique avant de recommencer.');
    return payload;
}

function OrderDetails({ order }) {
    return <article aria-label={`Commande ${order.number}`}>
        <h2>{order.number}</h2><p>Statut : {order.status} — {order.submitted_at}</p>
        <p role="status">{order.confirmation}</p>
        <ul>{order.lines.map(line => <li key={line.id}>{line.sku} — {line.designation} (réf. {line.original_reference}) : {line.quantity} × {line.units_per_package} unité(s), sous-total {line.line_subtotal}</li>)}</ul>
        <p>Sous-total estimé enregistré : {order.totals.catalogue_subtotal} {order.commercial_terms?.currency}. Frais et conditions commerciales à confirmer.</p>
        <p>Contact : {order.contact.name}, {order.contact.email}, {order.contact.phone}</p>
        {['delivery_address', 'billing_address'].map(key => <p key={key}>
            {key === 'delivery_address' ? 'Livraison' : 'Facturation'} : {order[key].address}, {order[key].postal_code} {order[key].city}, {order[key].country}
        </p>)}
        {order.customer_note && <p>Votre note : {order.customer_note}</p>}
    </article>;
}

export function Checkout({ cart, api, history, reload }) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [order, setOrder] = useState(null);
    const pending = useRef(null);
    const feedback = useRef(null);
    const submitting = useRef(false);
    useEffect(() => { if (message || order) feedback.current?.focus(); }, [message, order]);

    async function send(values) {
        if (submitting.current) return;
        submitting.current = true;
        pending.current = values;
        setBusy(true); setMessage('');
        try {
            const result = await apiRequest(api, values);
            setOrder(result.data); setUncertain(false); pending.current = null;
            await reload();
        } catch (error) {
            const mayHaveSucceeded = !error.status || error.status >= 500;
            setUncertain(mayHaveSucceeded);
            if (!mayHaveSucceeded) pending.current = null;
            setMessage(error instanceof TypeError ? 'Connexion interrompue. Réessayez exactement la même demande ou consultez votre historique.' : error.message);
        } finally { submitting.current = false; setBusy(false); }
    }

    function submit(event) {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const address = prefix => Object.fromEntries(['address', 'city', 'postal_code', 'country'].map(key => [key, form.get(`${prefix}.${key}`)]));
        send({ idempotency_key: crypto.randomUUID(), expected_version: cart.version, pricing_token: cart.pricing_token,
            contact: Object.fromEntries(['name', 'email', 'phone'].map(key => [key, form.get(`contact.${key}`)])),
            delivery_address: address('delivery'), billing_address: address('billing'), customer_note: form.get('customer_note') || null });
    }

    return <section aria-label="Soumettre une demande" aria-busy={busy}>
        {(message || order) && <div ref={feedback} tabIndex="-1">{message && <p role="alert">{message}</p>}{order && <OrderDetails order={order} />}</div>}
        {uncertain && <button type="button" disabled={busy} onClick={() => send(pending.current)}>Vérifier / réessayer la même demande</button>}
        {!order && cart.lines.length > 0 && <>
            <h2>Demande de commande</h2>
            <p>La soumission ne réserve aucun stock et ne confirme pas la vente. Notre équipe vérifiera votre demande.</p>
            {!cart.checkout_ready && <p>Retirez les articles indisponibles ou sans prix avant de soumettre.</p>}
            <form onSubmit={submit}>
                <fieldset disabled={busy || uncertain || !cart.checkout_ready}>
                    <legend>Vos coordonnées</legend>
                    {['name', 'email', 'phone'].map((key, index) => <label key={key}>{['Nom du contact', 'E-mail', 'Téléphone'][index]}
                        <input name={`contact.${key}`} type={key === 'email' ? 'email' : key === 'phone' ? 'tel' : 'text'} required maxLength={key === 'phone' ? 40 : key === 'email' ? 254 : 160} />
                    </label>)}
                    {['delivery', 'billing'].map(prefix => <fieldset key={prefix}>
                        <legend>{prefix === 'delivery' ? 'Adresse de livraison' : 'Adresse de facturation'}</legend>
                        {['address', 'city', 'postal_code', 'country'].map((key, index) => <label key={key}>{['Adresse', 'Ville', 'Code postal', 'Pays'][index]}
                            <input name={`${prefix}.${key}`} required={key !== 'postal_code'} maxLength={key === 'address' ? 500 : key === 'postal_code' ? 30 : 120} />
                        </label>)}
                    </fieldset>)}
                    <label>Note pour notre équipe<textarea name="customer_note" maxLength="2000" /></label>
                    <button>Soumettre ma demande</button>
                </fieldset>
            </form>
        </>}
        <a href={history}>Consulter mes commandes</a>
    </section>;
}

function OrderHistory({ api }) {
    const [result, setResult] = useState(null);
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const feedback = useRef(null);
    useEffect(() => { if (message || selected) feedback.current?.focus(); }, [message, selected]);
    async function load(page = 1) {
        setBusy(true); setMessage(''); setSelected(null);
        try { setResult(await apiRequest(`${api}?page=${page}`)); }
        catch (error) { setResult(null); setMessage(error.message); }
        finally { setBusy(false); }
    }
    useEffect(() => { load(); }, []);
    async function detail(id) {
        setBusy(true); setMessage('');
        try { setSelected((await apiRequest(`${api}/${id}`)).data); }
        catch (error) { setSelected(null); setMessage(error.message); }
        finally { setBusy(false); }
    }
    return <section aria-busy={busy}>
        <button type="button" disabled={busy} onClick={() => load()}>Actualiser mes commandes</button>
        <div ref={feedback} tabIndex="-1">{message && <p role="alert">{message}</p>}{selected && <OrderDetails order={selected} />}</div>
        {result && <>
            {result.data.length === 0 && <p>Aucune demande enregistrée.</p>}
            <ul>{result.data.map(order => <li key={order.id}><button disabled={busy} onClick={() => detail(order.id)}>{order.number} — {order.status}</button></li>)}</ul>
            <p>Page {result.meta.current_page} sur {result.meta.last_page}</p>
            <button disabled={busy || result.meta.current_page <= 1} onClick={() => load(result.meta.current_page - 1)}>Précédent</button>
            <button disabled={busy || result.meta.current_page >= result.meta.last_page} onClick={() => load(result.meta.current_page + 1)}>Suivant</button>
        </>}
    </section>;
}

const root = document.getElementById('customer-orders');
if (root) createRoot(root).render(<OrderHistory api={root.dataset.api} />);
