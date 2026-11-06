import '../css/audit.css';
import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request.js';

function AuditConsole(config) {
    const [filters, setFilters] = useState({ month: config.month });
    const [applied, setApplied] = useState({ month: config.month });
    const [events, setEvents] = useState(null);
    const [auditPage, setAuditPage] = useState(1);
    const [deliveries, setDeliveries] = useState(null);
    const [status, setStatus] = useState('');
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [deliveryBusy, setDeliveryBusy] = useState(false);
    const feedback = useRef(null);
    const pending = useRef(null);
    const deliveryPending = useRef(null);
    useEffect(() => { if (message) feedback.current?.focus(); }, [message]);
    function urlFor(base, values, page = 1) {
        const url = new URL(base, location.origin);
        Object.entries({ ...values, page }).forEach(([key, value]) => { if (value !== '') url.searchParams.set(key, ['from', 'to'].includes(key) ? new Date(value).toISOString() : value); });
        return url;
    }
    function fail(error) { setErrors(error.fields ?? {}); setMessage(error.message); }
    async function load(cursor = null, values = applied, page = 1) {
        if (!config.canRead) return;
        pending.current?.abort();
        const controller = new AbortController(); pending.current = controller;
        setLoading(true); setErrors({}); setMessage(''); setEvents(null);
        try {
            const url = urlFor(config.listUrl, values); url.searchParams.delete('page');
            if (cursor) url.searchParams.set('cursor', cursor);
            setEvents(await pricingRequest(url, { signal: controller.signal })); setAuditPage(page);
        }
        catch (error) { if (error.name !== 'AbortError') fail(error); }
        finally { if (!controller.signal.aborted) setLoading(false); }
    }
    async function loadDeliveries(page = 1) {
        if (!config.canRead) return;
        deliveryPending.current?.abort();
        const controller = new AbortController(); deliveryPending.current = controller;
        setDeliveryBusy(true); setDeliveries(null);
        try { setDeliveries(await pricingRequest(urlFor(config.deliveriesUrl, { status }, page), { signal: controller.signal })); }
        catch (error) { if (error.name !== 'AbortError') fail(error); }
        finally { if (!controller.signal.aborted) setDeliveryBusy(false); }
    }
    useEffect(() => { load(); loadDeliveries(); return () => { pending.current?.abort(); deliveryPending.current?.abort(); }; }, []);
    async function exportMonth() {
        setBusy(true); setErrors({}); setMessage('');
        try {
            const response = await fetch(urlFor(config.exportUrl, filters), { credentials: 'same-origin', headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
            if (!response.ok) {
                const body = await response.json();
                const error = new Error(response.status === 422 ? 'Vérifiez les filtres et sélectionnez un mois pour l’export.'
                    : response.status === 401 || response.status === 403 ? 'Vous ne disposez plus du droit d’exporter le journal.' : 'Le fichier n’a pas pu être préparé. Réessayez.');
                error.fields = body.errors; throw error;
            }
            const objectUrl = URL.createObjectURL(await response.blob());
            const link = document.createElement('a'); link.href = objectUrl; link.download = `audit-${filters.month}.xlsx`;
            document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            setMessage(`Fichier Excel préparé : ${response.headers.get('X-Audit-Export-Count')} événement(s). Vérifiez le téléchargement avant de fermer la page.`);
        } catch (error) { fail(error); }
        finally { setBusy(false); }
    }
    async function retry(delivery) {
        if (!window.confirm(`Programmer une reprise de l’envoi ${delivery.id} ? L’opération métier reste enregistrée.`)) return;
        setBusy(true); setMessage('');
        try {
            const url = config.retryUrl.replace('__KIND__', encodeURIComponent(delivery.kind)).replace('__DELIVERY__', encodeURIComponent(delivery.id));
            const response = await pricingRequest(url, { method: 'POST', values: { expected_attempts: delivery.attempts } });
            setMessage(response.message); await loadDeliveries(deliveries.current_page);
        } catch (error) { fail(error); if (error.status === 409) await loadDeliveries(deliveries.current_page); }
        finally { setBusy(false); }
    }
    function field(key, label, type = 'text') {
        return <label htmlFor={`audit-${key}`}>{label}<input id={`audit-${key}`} type={type} value={filters[key] ?? ''}
            maxLength={type === 'text' ? 255 : undefined} min={type === 'number' ? 1 : undefined}
            onChange={event => setFilters({ ...filters, [key]: event.target.value })}
            aria-invalid={!!errors[key]} aria-describedby={errors[key] ? `audit-${key}-error` : undefined} />
            {errors[key] && <span id={`audit-${key}-error`} className="error">{errors[key].join(' ')}</span>}</label>;
    }
    function pagination(data, action) {
        const meta = data.meta ?? data;
        return <nav aria-label="Pagination"><button disabled={loading || deliveryBusy || busy || meta.current_page <= 1} onClick={() => action(meta.current_page - 1)}>Précédent</button>
            <span> Page {meta.current_page} / {meta.last_page} </span>
            <button disabled={loading || deliveryBusy || busy || meta.current_page >= meta.last_page} onClick={() => action(meta.current_page + 1)}>Suivant</button></nav>;
    }
    return <>
        {message && <p ref={feedback} tabIndex="-1" role="status">{message}</p>}
        <section aria-labelledby="audit-heading">
            <h2 id="audit-heading">Événements</h2>
            <p>Le mois suit le fuseau {config.timezone}. Les horodatages des événements et du fichier Excel sont en UTC.</p>
            <details className="audit-filters" open><summary>Filtres et export Excel</summary>
            <form onSubmit={event => { event.preventDefault(); setApplied({ ...filters }); load(null, filters); }}>
                {field('month', 'Mois', 'month')}{field('from', 'Du (heure de cet appareil)', 'datetime-local')}{field('to', 'Au (heure de cet appareil)', 'datetime-local')}{field('actor_id', 'Identifiant du compte', 'number')}
                {field('action', 'Action exacte')}{field('object_type', 'Type d’objet')}{field('object_id', 'Identifiant de l’objet')}
                <label htmlFor="audit-result">Résultat<select id="audit-result" value={filters.result ?? ''} onChange={event => setFilters({ ...filters, result: event.target.value })}>
                    <option value="">Tous</option><option value="SUCCESS">Réussite</option><option value="REFUSED">Refus</option><option value="FAILED">Échec</option></select></label>
                {config.canRead && <button disabled={loading || busy}>Appliquer les filtres</button>}
                {config.canExport && <button type="button" disabled={busy} onClick={exportMonth}>{busy ? 'Préparation…' : 'Télécharger le mois filtré — Excel'}</button>}
            </form></details>
            {loading && <p role="status">Chargement des événements…</p>}
            {events && <>
                {!events.data.length ? <p>Aucun événement pour ces filtres.</p> : <div className="audit-scroll" tabIndex="0" role="region" aria-label="Tableau des événements">
                    <table><thead><tr><th>Date UTC</th><th>Action et objet</th><th>Acteur</th><th>Résultat et détails</th></tr></thead><tbody>
                        {events.data.map(item => <tr key={item.id}>
                            <td><time dateTime={item.occurred_at}>{item.occurred_at}</time></td>
                            <td>{item.action}<br />{item.object_type} {item.object_id}</td>
                            <td>{item.actor_id ?? (item.actor_kind === 'system' ? 'Système' : 'Visiteur')}<br />{item.role_context_available === false ? 'Historique des rôles indisponible' : item.role_context.join(', ')}</td>
                            <td>{item.result}<details><summary>Détails de l’événement</summary><p>Requête : {item.request_id}</p><p>Raison : {item.reason ?? '—'}</p>
                                <pre>{JSON.stringify(item.changes, null, 2)}</pre></details></td>
                        </tr>)}
                    </tbody></table></div>}
                {!!events.data.length && <ol className="audit-cards">{events.data.map(item => <li key={item.id}>
                    <h3>{item.action}</h3><dl><dt>Date UTC</dt><dd>{item.occurred_at}</dd><dt>Objet</dt><dd>{item.object_type} {item.object_id}</dd>
                    <dt>Acteur</dt><dd>{item.actor_id ?? (item.actor_kind === 'system' ? 'Système' : 'Visiteur')} {item.role_context_available === false ? 'Historique des rôles indisponible' : item.role_context.join(', ')}</dd>
                    <dt>Résultat</dt><dd>{item.result}</dd></dl>
                    <details><summary>Détails de l’événement</summary><p>Requête : {item.request_id}</p><p>Raison : {item.reason ?? '—'}</p><pre>{JSON.stringify(item.changes, null, 2)}</pre></details>
                </li>)}</ol>}
                <nav aria-label="Pagination du journal">
                    <button disabled={loading || busy || !events.meta.previous_cursor} onClick={() => load(events.meta.previous_cursor, applied, Math.max(1, auditPage - 1))}>Précédent</button>
                    <span> Page {auditPage} </span>
                    <button disabled={loading || busy || !events.meta.next_cursor} onClick={() => load(events.meta.next_cursor, applied, auditPage + 1)}>Suivant</button>
                </nav>
            </>}
        </section>
        {config.canRead && <section aria-labelledby="deliveries-heading">
            <h2 id="deliveries-heading">Suivi des notifications</h2>
            <p>Une reprise programme l’envoi du message existant. Les envois terminés ne sont pas relancés.</p>
            <form onSubmit={event => { event.preventDefault(); loadDeliveries(); }}>
                <label htmlFor="delivery-status">État<select id="delivery-status" value={status} onChange={event => setStatus(event.target.value)}>
                    <option value="">Tous</option>{['PENDING', 'RETRY', 'SENT', 'EXPIRED'].map(value => <option key={value}>{value}</option>)}</select></label>
                <button disabled={busy || deliveryBusy}>Actualiser les envois</button>
            </form>
            {deliveryBusy && <p role="status">Chargement des envois…</p>}
            {deliveries && <>
                {!deliveries.data.length ? <p>Aucun envoi pour cet état.</p> : <ul>{deliveries.data.map(item => <li key={`${item.kind}:${item.id}`}>
                    <p>{item.kind} · {item.id} — {item.status}, tentative(s) : {item.attempts}</p>
                    {item.last_error_code && <p>Motif : {item.last_error_code}</p>}
                    {item.next_attempt_at && <p>Prochaine tentative : {item.next_attempt_at} UTC</p>}
                    {item.status === 'RETRY' && config.canRetry && (item.kind !== 'employee' || config.canRetryAccess) && (item.kind !== 'order' || config.canRetryOrders) && <button disabled={busy} onClick={() => retry(item)}>Programmer la reprise</button>}
                </li>)}</ul>}
                {pagination(deliveries, loadDeliveries)}
            </>}
        </section>}
    </>;
}
const root = document.getElementById('audit-console');
if (root) createRoot(root).render(<AuditConsole {...JSON.parse(root.dataset.audit)} />);
