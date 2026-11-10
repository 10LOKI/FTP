import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

function SalesReview({ api }) {
    const [orders, setOrders] = useState(null);
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [filters, setFilters] = useState({});
    const pendingOperation = useRef(null);
    const [revisionKind, setRevisionKind] = useState('delivery_address');
    const [uncertain, setUncertain] = useState(false);
    async function mutate(kind, values) {
        if (busy) return;
        const orderId = values.order_id ?? detail.id;
        pendingOperation.current = { kind, values: { ...values, order_id: orderId } };
        setBusy(true); setError('');
        try {
            const response = await fetch(api + '/' + orderId + '/' + kind, { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(values) });
            if (!response.ok) {
                if (response.status >= 500) throw new Error('Résultat incertain. Réessayez la même opération.');
                pendingOperation.current = null; setUncertain(false);
                setError(response.status === 409 ? 'État ou version modifiés. Rouvrez la commande avant de continuer.' : 'Action refusée. Vérifiez vos droits et les informations saisies.');
                return;
            }
            pendingOperation.current = null; setUncertain(false);
            await open(orderId);
        } catch (failure) { setUncertain(true); setError(failure.message); }
        finally { setBusy(false); }
    }
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
            <fieldset disabled={busy || uncertain}><legend>Filtres</legend>
                <label>Numéro de commande<input name="number" maxLength="40" /></label>
                <label>Code client<input name="customer_code" maxLength="100" /></label>
                <label>État<select name="status"><option value="">Tous</option>{['SOUMISE', 'EN_VERIFICATION', 'ATTENTE_ACCORD', 'CONFIRMEE', 'EN_PREPARATION', 'EXPEDIEE', 'LIVREE', 'ANNULEE', 'PARTIELLEMENT_LIVREE'].map(value => <option key={value}>{value}</option>)}</select></label>
                <label>Depuis (UTC)<input name="from" type="date" /></label><label>Jusqu’au (UTC)<input name="to" type="date" /></label>
                <button>Filtrer</button>
            </fieldset>
        </form>
        {error && <p role="alert">{error}</p>}
        {uncertain && pendingOperation.current && <button disabled={busy} onClick={() => mutate(pendingOperation.current.kind, pendingOperation.current.values)}>Réessayer la même opération</button>}
        {orders && <><p>{orders.total} commande(s)</p><ul>{orders.data.map(order => <li key={order.id}>
            <button disabled={busy || uncertain} onClick={() => open(order.id)}>{order.number} — {order.customer.customer_code ?? 'Code non disponible'} — {order.status}</button>
        </li>)}</ul>
            <button disabled={busy || uncertain || orders.current_page <= 1} onClick={() => load(filters, orders.current_page - 1)}>Précédent</button>
            <button disabled={busy || uncertain || orders.current_page >= orders.last_page} onClick={() => load(filters, orders.current_page + 1)}>Suivant</button></>}
        {detail && <article><h2>{detail.number}</h2>
            <p>{detail.customer.customer_code} — {detail.customer.type} — {detail.status} — Version {detail.version}</p>
            <p>Contact : {detail.contact.name} — {detail.contact.phone}</p>
            <p>Mode de paiement : contrat de conditions client en attente d’intégration.</p>
            <ul>{detail.lines.map(line => <li key={line.id}>{line.designation} — {line.quantity} × {line.units_per_package} unité(s), prix historique {line.unit_price}</li>)}</ul>
            <p>Les montants historiques et leur base fiscale restent ceux de la soumission. Aucune conversion ou modification automatique.</p>
            {detail.can_manage && detail.status === 'SOUMISE' && <button disabled={busy} onClick={() => mutate('claim', { expected_version: detail.version })}>Prendre en charge</button>}
            {detail.can_manage && detail.status === 'EN_VERIFICATION' && <form onSubmit={event => {
                event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget));
                values.happened_at = new Date(values.happened_at).toISOString();
                mutate('calls', { ...values, order_id: detail.id, expected_version: detail.version, request_key: crypto.randomUUID() });
            }}><fieldset disabled={busy || uncertain}><legend>Consigner un appel</legend>
                <label>Résultat<select name="outcome">{['JOINED', 'UNREACHABLE', 'CALLBACK_REQUESTED', 'AGREEMENT', 'REFUSED'].map(value => <option key={value}>{value}</option>)}</select></label>
                <label>Date locale de l’appel<input name="happened_at" type="datetime-local" required /></label>
                <label>Note interne<textarea name="note" maxLength="2000" /></label><button>Enregistrer l’appel</button>
            </fieldset></form>}
            {detail.can_manage && detail.status === 'EN_VERIFICATION' && <form key={detail.id + '-' + detail.version + '-' + revisionKind} onSubmit={event => {
                event.preventDefault(); const fields = new FormData(event.currentTarget);
                const changes = Object.fromEntries([...fields.entries()].filter(([key]) => key !== 'reason'));
                mutate('revisions', { kind: revisionKind, changes, reason: fields.get('reason'), expected_version: detail.version, request_key: crypto.randomUUID() });
            }}><fieldset disabled={busy || uncertain}><legend>Proposer une correction — accord à recueillir</legend>
                <label>Élément<select value={revisionKind} onChange={event => setRevisionKind(event.target.value)}>
                    <option value="contact">Contact</option><option value="delivery_address">Livraison</option><option value="billing_address">Facturation</option>
                </select></label>
                {(revisionKind === 'contact' ? ['name', 'email', 'phone'] : ['address', 'city', 'postal_code', 'country']).map(key => {
                    const latest = [...(detail.revisions ?? [])].reverse().find(item => item.kind === revisionKind)?.after ?? detail[revisionKind];
                    return <label key={key}>{({ name: 'Nom', email: 'E-mail', phone: 'Téléphone', address: 'Adresse', city: 'Ville', postal_code: 'Code postal', country: 'Pays' })[key]}
                        <input name={key} type={key === 'email' ? 'email' : 'text'} required={key !== 'postal_code'} defaultValue={latest?.[key] ?? ''} maxLength={({name:160,email:254,phone:40,address:500,city:120,postal_code:30,country:120})[key]} />
                    </label>;
                })}
                <label>Motif<textarea name="reason" required maxLength="1000" /></label><button>Enregistrer la proposition</button>
                <p>Les données originales restent inchangées. Cette proposition ne vaut pas accord client.</p>
            </fieldset></form>}
            <p>Un résultat « accord » consigne seulement le contact : il ne confirme pas une offre ni ne réserve du stock.</p>
            <h3>Journal des appels</h3><ul>{(detail.calls ?? []).map(call => <li key={call.id}>{call.happened_at} UTC — Agent {call.actor_id} — {call.outcome} — {call.note}</li>)}</ul>
            <h3>Propositions de correction</h3>{(detail.revisions ?? []).map(revision => <article key={revision.id}>
                <h4>Version {revision.order_version} — {revision.kind}</h4><p>Agent {revision.actor_id} — {revision.created_at} UTC — {revision.reason}</p>
                <p>Avant : {JSON.stringify(revision.before)}</p><p>Proposition : {JSON.stringify(revision.after)}</p><p>Accord client à recueillir.</p>
            </article>)}
        </article>}
    </section>;
}
const root = document.getElementById('sales-review');
if (root) createRoot(root).render(<SalesReview api={root.dataset.api} />);
