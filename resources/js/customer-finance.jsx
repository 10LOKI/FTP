import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

const root = document.getElementById('customer-finance');
const base = root.dataset.base;
const own = root.dataset.own === '1';
const modes = { cash: 'Espèces', cheque: 'Chèque', postdated_cheque: 'Chèque postdaté' };
const actions = { payment: 'Enregistrer un règlement', collect: 'Constater un encaissement', reverse: 'Corriger un règlement', terms: 'Modifier les conditions futures', credit_apply: 'Affecter un crédit', refund: 'Enregistrer un remboursement réalisé', unblock: 'Débloquer après régularisation' };
const permission = { payment: 'PAYMENT_RECORD', collect: 'PAYMENT_RECORD', reverse: 'PAYMENT_CORRECT', terms: 'CUSTOMER_TERMS_MANAGE', credit_apply: 'PAYMENT_RECORD', refund: 'PAYMENT_RECORD', unblock: 'PAYMENT_UNBLOCK' };

async function api(path, body) {
    const response = await fetch(`${base}${path}`, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || `Erreur ${response.status}`);
        error.certain = response.status >= 400 && response.status < 500;
        throw error;
    }
    return data;
}

function Finance() {
    const [customers, setCustomers] = useState(null);
    const [search, setSearch] = useState('');
    const [customer, setCustomer] = useState('');
    const [state, setState] = useState(null);
    const [action, setAction] = useState('payment');
    const [mode, setMode] = useState('');
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const pending = useRef(null);
    const generation = useRef(0);
    const form = useRef(null);
    const availableActions = Object.keys(actions).filter(key => state?.permissions?.includes(permission[key]));

    async function list(page = 1) {
        setLoading(true); setError('');
        try {
            const result = await api(`/finance/customers?search=${encodeURIComponent(search)}&page=${page}`);
            setCustomers(result);
            if (customer && !result.data.some(row => String(row.id) === String(customer))) { generation.current++; setCustomer(''); setState(null); }
        }
        catch (err) { setError(err.message); }
        finally { setLoading(false); }
    }
    async function load(id = customer) {
        const current = ++generation.current;
        setLoading(true); setError(''); setState(null);
        try {
            const result = await api(own ? '/me/finance' : `/finance/customers/${id}`);
            if (current === generation.current) { setState(result.data); setMode(result.data.terms?.mode || ''); }
        } catch (err) { if (current === generation.current) setError(err.message); }
        finally { if (current === generation.current) setLoading(false); }
    }
    useEffect(() => { if (own) load(); else list(); }, []);
    useEffect(() => { if (customer) load(customer); }, [customer]);
    useEffect(() => { if (availableActions.length && !availableActions.includes(action)) setAction(availableActions[0]); }, [state]);

    async function send() {
        setBusy(true); setError(''); setMessage('');
        try {
            const result = await api(pending.current.path, pending.current.body);
            pending.current = null; setUncertain(false);
            form.current?.reset();
            setMessage(result.data.replayed ? 'Opération déjà enregistrée, sans doublon.' : 'Opération enregistrée.');
            await load();
        } catch (err) {
            if (err.certain) { pending.current = null; setUncertain(false); }
            else setUncertain(true);
            setError(err.message);
        } finally { setBusy(false); }
    }
    function submit(event) {
        event.preventDefault();
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        if (['terms', 'unblock'].includes(action)) fields.version = state.version;
        if (['payment', 'credit_apply'].includes(action)) {
            fields.allocations = [{ receivable_id: fields.receivable_id, amount: fields.amount }];
            delete fields.receivable_id;
        }
        fields.request_key = crypto.randomUUID();
        pending.current = { path: `/finance/customers/${customer}/${action}`, body: fields };
        send();
    }
    const payments = state?.operations?.filter(op => op.action === 'payment' && !state.operations.some(other => other.action === 'reverse' && other.payment_id === op.id)) || [];
    const eligiblePayments = payments.filter(op => action !== 'collect' || (op.mode !== 'cash' && !state.operations.some(other => other.action === 'collect' && other.payment_id === op.id)));
    const due = state?.receivables?.filter(row => row.balance !== '0.00') || [];

    return <>
        <p role="status">{loading ? 'Chargement…' : message}</p>
        {error && <p role="alert" className="error">{error}</p>}
        {uncertain && <article role="alert"><p>La réponse est incertaine. Conservez cette page et reprenez la même demande pour vérifier son enregistrement sans doublon.</p><button disabled={busy} onClick={send}>Reprendre la même opération</button></article>}
        {!own && <fieldset disabled={busy || uncertain}>
            <legend>Rechercher un client</legend>
            <form onSubmit={e => { e.preventDefault(); list(); }}><label>Nom ou code<input value={search} onChange={e => setSearch(e.target.value)} /></label><button disabled={loading}>Rechercher</button></form>
            <label>Client<select value={customer} onChange={e => { setCustomer(e.target.value); setState(null); setMessage(''); }}><option value="">Choisir un client</option>{customers?.data?.map(row => <option key={row.id} value={row.id}>{row.name} · {row.customer_code || row.approval}</option>)}</select></label>
            {customers && <div className="pagination"><button disabled={loading || !customers.prev_page_url} onClick={() => list(customers.current_page - 1)}>Précédent</button> Page {customers.current_page} / {customers.last_page} <button disabled={loading || !customers.next_page_url} onClick={() => list(customers.current_page + 1)}>Suivant</button></div>}
        </fieldset>}
        {(own || customer) && <button disabled={busy || uncertain || loading} onClick={() => load()}>Actualiser les soldes</button>}
        {state && <>
            <h2>{state.customer_name} · {state.customer_code || `Client ${state.customer_id}`}</h2>
            {!state.enabled && <p role="status">Les règlements sont désactivés dans l’attente des règles approuvées.</p>}
            {!state.complete && <p role="alert">Solde incomplet : {state.unresolved_delivery_count} ligne(s) livrée(s) attendent leurs conditions historiques. Le montant affiché ne couvre que les lignes reconnues.</p>}
            <div className="grid">{[['Dette', state.debt], ['Crédit disponible', state.credit], ['Remboursements réalisés', state.refunds], ['Chèques en attente', state.pending_cheques], ['Impayés échus', state.overdue]].map(([label, value]) => <article key={label}><h2>{label}</h2><strong>{value} MAD TTC</strong></article>)}</div>
            <p>Ventes : {!state.enabled ? 'contrôle financier désactivé' : state.blocked ? 'bloquées — régularisation et déblocage administrateur requis' : state.overdue !== '0.00' ? 'bloquées pour impayé' : !state.complete || !state.terms ? 'conditions requises' : 'contrôle financier satisfait'}.</p>
            <p>Mode actif : {modes[state.terms?.mode] || 'à définir'}{state.terms && ` · ${state.terms.label}`}. Les changements futurs conservent les échéances et les documents historiques.</p>
            <h2>Créances par livraison</h2>
            <div className="grid">{state.receivables.map(row => <article key={row.id}><h3>{row.source_number}</h3><p>Montant : {row.amount} MAD TTC · Restant : {row.balance} MAD TTC</p><p>Échéance : {row.due_date} · {modes[row.mode]} · {({ paid: 'Payé', unpaid: 'Non payé', overdue: 'Échu', partially_paid: 'Partiellement payé' })[row.status]}</p>{row.invoices.length === 0 ? <p>Aucune facture liée.</p> : <ul aria-label="Factures liées">{row.invoices.map(invoice => <li key={invoice.id}>{invoice.document_url ? <a href={invoice.document_url}>Facture {invoice.number} — PDF</a> : <span>Facture {invoice.number}</span>} · Émise le {invoice.issued_at} · {invoice.quantity} pièce(s) de cette livraison</li>)}</ul>}{state.reminders?.includes(row.id) && row.balance !== '0.00' && <p>Rappel interne : échéance à suivre</p>}</article>)}</div>
            {!own && availableActions.length > 0 && <form ref={form} onSubmit={submit}>
                <fieldset disabled={busy || uncertain || !state.enabled}>
                    <legend>Nouvelle opération</legend>
                    <label>Action<select value={action} onChange={e => setAction(e.target.value)}>{availableActions.map(key => <option value={key} key={key}>{actions[key]}</option>)}</select></label>
                    {['terms', 'payment'].includes(action) && <label>Mode de paiement<select required name="mode" value={mode} onChange={e => setMode(e.target.value)}><option value="">Choisir explicitement</option>{Object.entries(modes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>}
                    {action === 'payment' && <label>Date réelle du règlement<input type="date" name="payment_date" required/></label>}
                    {action === 'payment' && <label>Note interne<textarea name="note" maxLength={2000}/></label>}
                    {action === 'terms' && <label>Libellé des conditions futures<input name="label" required maxLength={255}/></label>}
                    {['payment', 'credit_apply', 'refund'].includes(action) && <label>Montant MAD TTC<input name="amount" required inputMode="decimal" pattern="[0-9]+\.[0-9]{2}" placeholder="0.00"/></label>}
                    {['payment', 'credit_apply'].includes(action) && <label>Affecter à la créance<select name="receivable_id" required><option value="">Choisir une créance</option>{due.map(row => <option key={row.id} value={row.id}>{row.source_number} · {row.balance} MAD · {row.id.slice(0, 8)}</option>)}</select></label>}
                    {['collect', 'reverse'].includes(action) && <label>Règlement<select name="payment_id" required><option value="">Choisir un règlement</option>{eligiblePayments.map(row => <option key={row.id} value={row.id}>{row.amount} MAD · {modes[row.mode]} · {row.created_at} · {row.reference}</option>)}</select></label>}
                    {['credit_apply', 'refund'].includes(action) && <label>Droit disponible<select name="credit_id" required><option value="">Choisir un crédit</option>{state.credits.filter(row => row.available !== '0.00').map(row => <option key={row.id} value={row.id}>{row.id.slice(0, 8)} · {row.available} MAD</option>)}</select></label>}
                    {(action === 'payment' || ['collect', 'refund'].includes(action)) && <label>Référence de l’opération réalisée<input name="reference" required={action !== 'payment' || mode !== 'cash'} maxLength={255}/></label>}
                    {action === 'payment' && mode !== 'cash' && <label>Échéance du chèque<input type="date" name="maturity_date" required/></label>}
                    {['reverse', 'terms', 'credit_apply', 'refund', 'unblock'].includes(action) && <label>Motif<textarea name="reason" required maxLength={2000}/></label>}
                    <label><input type="checkbox" required/> Je confirme le client, le montant, les références et l’affectation de cette opération.</label>
                    <button>{busy ? 'Enregistrement…' : actions[action]}</button>
                </fieldset>
            </form>}
            {!own && <><h2>Historique financier</h2><div className="grid">{state.operations.map(op => <article key={op.id}><h3>{actions[op.action]}</h3><p>{op.created_at} · {op.amount && `${op.amount} MAD TTC`}</p>{op.payment_date && <p>Date réelle du règlement : {op.payment_date}</p>}{op.reference && <p>Référence : {op.reference}</p>}{op.note && <p>Note interne : {op.note}</p>}{op.reason && <p>Motif : {op.reason}</p>}</article>)}</div></>}
        </>}
    </>;
}
createRoot(root).render(<Finance/>);
