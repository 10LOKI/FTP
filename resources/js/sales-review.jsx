import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

function SalesReview({ api }) {
    const [orders, setOrders] = useState(null);
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [filters, setFilters] = useState({});
    async function read(url) {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (!response.ok) throw new Error(response.status === 403 ? 'Accès commercial refusé.' : response.status === 401 ? 'Reconnectez-vous.' : 'Impossible de charger les commandes. Vérifiez les filtres.');
        return data;
    }
    async function load(values = filters, page = 1) {
        setBusy(true); setError(''); setDetail(null);
        try { setOrders(await read(api + '?' + new URLSearchParams({ ...values, page }))); setFilters(values); }
        catch (failure) { setOrders(null); setError(failure.message); }
        finally { setBusy(false); }
    }
    useEffect(() => { load(); }, []);
    async function open(id) {
        setBusy(true); setError(''); setDetail(null);
        try { setDetail((await read(api + '/' + id)).data); }
        catch (failure) { setError(failure.message); }
        finally { setBusy(false); }
    }
    return <section aria-busy={busy}>
        <form onSubmit={event => { event.preventDefault(); load(Object.fromEntries(new FormData(event.currentTarget))); }}>
            <fieldset disabled={busy}><legend>Filtres</legend>
                <label>Numéro de commande<input name="number" maxLength="40" /></label>
                <label>Code client<input name="customer_code" maxLength="100" /></label>
                <label>État<select name="status"><option value="">Tous</option>{['SOUMISE', 'EN_VERIFICATION', 'ATTENTE_ACCORD', 'CONFIRMEE', 'EN_PREPARATION', 'EXPEDIEE', 'LIVREE', 'ANNULEE', 'PARTIELLEMENT_LIVREE'].map(value => <option key={value}>{value}</option>)}</select></label>
                <label>Depuis (UTC)<input name="from" type="date" /></label><label>Jusqu’au (UTC)<input name="to" type="date" /></label>
                <button>Filtrer</button>
            </fieldset>
        </form>
        {error && <p role="alert">{error}</p>}
        {orders && <><p>{orders.total} commande(s)</p><ul>{orders.data.map(order => <li key={order.id}>
            <button disabled={busy} onClick={() => open(order.id)}>{order.number} — {order.customer.customer_code ?? 'Code non disponible'} — {order.status}</button>
        </li>)}</ul>
            <button disabled={busy || orders.current_page <= 1} onClick={() => load(filters, orders.current_page - 1)}>Précédent</button>
            <button disabled={busy || orders.current_page >= orders.last_page} onClick={() => load(filters, orders.current_page + 1)}>Suivant</button></>}
        {detail && <article><h2>{detail.number}</h2>
            <p>{detail.customer.customer_code} — {detail.customer.type} — {detail.status} — Version {detail.version}</p>
            <p>Contact : {detail.contact.name} — {detail.contact.phone}</p>
            <p>Mode de paiement : contrat de conditions client en attente d’intégration.</p>
            <ul>{detail.lines.map(line => <li key={line.id}>{line.designation} — {line.quantity} × {line.units_per_package} unité(s), prix historique {line.unit_price}</li>)}</ul>
            <p>Les montants historiques et leur base fiscale restent ceux de la soumission. Aucune conversion ou modification automatique.</p>
        </article>}
    </section>;
}
const root = document.getElementById('sales-review');
if (root) createRoot(root).render(<SalesReview api={root.dataset.api} />);
