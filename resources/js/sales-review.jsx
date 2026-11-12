import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

function SalesReview({ api, catalogue }) {
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
            {detail.can_manage && ['EN_VERIFICATION', 'ATTENTE_ACCORD'].includes(detail.status) && <QuoteDraftEditor key={'quote-' + detail.id + '-' + detail.version}
                detail={detail} api={api} disabled={busy || uncertain} save={values => mutate('quote-drafts', values)} />}
            <h3>Brouillons de devis conservés</h3>
            {(detail.quote_drafts ?? []).map(draft => <details key={draft.id}><summary>Brouillon v{draft.revision} — {draft.created_at} UTC — Agent {draft.actor_id}</summary>
                <QuoteSnapshot snapshot={draft.snapshot} />
                {detail.can_manage && draft.order_version === detail.version && <>
                    <p>{draft.issuance?.reason}</p>
                    <button disabled={busy || uncertain || !draft.issuance?.ready} onClick={() => mutate('quotes', { draft_id: draft.id,
                        expected_version: detail.version, issuance_token: draft.issuance.token, request_key: crypto.randomUUID() })}>Émettre cette version du devis</button>
                </>}
            </details>)}
            <h3>Devis émis</h3>{(detail.quotes ?? []).map(quote => <article key={quote.id}>
                <h4><a href={quote.document_url}>{quote.number} — Télécharger le PDF conservé</a></h4>
                <p>Version {quote.revision} — validité jusqu’au {quote.valid_until} (UTC).</p>
                {quote.decision && <p>{quote.decision.outcome} — {quote.decision.contact_name} — {quote.decision.contact_method} — {quote.decision.contacted_at} UTC — Agent {quote.decision.actor_id}</p>}
                {detail.can_manage && !quote.decision && detail.status === 'ATTENTE_ACCORD' && quote.order_version === detail.version && <form onSubmit={event => {
                    event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget));
                    values.contacted_at = new Date(values.contacted_at).toISOString();
                    mutate('quotes/' + quote.id + '/decision', { ...values, expected_version: detail.version, request_key: crypto.randomUUID() });
                }}><fieldset disabled={busy || uncertain}><legend>Consigner la décision sur {quote.number}</legend>
                    <label>Décision<select name="outcome"><option value="ACCEPTED">Accord</option><option value="REFUSED">Refus</option></select></label>
                    <label>Moyen de contact<select name="contact_method"><option value="PHONE">Téléphone</option><option value="EMAIL">E-mail</option><option value="IN_PERSON">En personne</option></select></label>
                    <label>Interlocuteur<input name="contact_name" required maxLength="160" /></label>
                    <label>Date du contact<input name="contacted_at" type="datetime-local" required /></label>
                    <label>Note interne<textarea name="note" maxLength="2000" /></label><button>Enregistrer sur cette version</button>
                    <p>L’accord ne confirme pas la commande et ne réserve aucun stock.</p>
                </fieldset></form>}
            </article>)}
            {detail.can_manage && ['EN_VERIFICATION', 'ATTENTE_ACCORD'].includes(detail.status) && <SalesLinesEditor key={detail.id + '-' + detail.version}
                detail={detail} api={api} catalogue={catalogue} disabled={busy || uncertain} save={values => mutate('revisions', values)} />}
            {detail.can_manage && detail.status === 'SOUMISE' && <button disabled={busy} onClick={() => mutate('claim', { expected_version: detail.version })}>Prendre en charge</button>}
            {detail.can_manage && ['EN_VERIFICATION', 'ATTENTE_ACCORD'].includes(detail.status) && <form onSubmit={event => {
                event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget));
                values.happened_at = new Date(values.happened_at).toISOString();
                mutate('calls', { ...values, order_id: detail.id, expected_version: detail.version, request_key: crypto.randomUUID() });
            }}><fieldset disabled={busy || uncertain}><legend>Consigner un appel</legend>
                <label>Résultat<select name="outcome">{['JOINED', 'UNREACHABLE', 'CALLBACK_REQUESTED', 'AGREEMENT', 'REFUSED'].map(value => <option key={value}>{value}</option>)}</select></label>
                <label>Date locale de l’appel<input name="happened_at" type="datetime-local" required /></label>
                <label>Note interne<textarea name="note" maxLength="2000" /></label><button>Enregistrer l’appel</button>
            </fieldset></form>}
            {detail.can_manage && ['EN_VERIFICATION', 'ATTENTE_ACCORD'].includes(detail.status) && <form key={detail.id + '-' + detail.version + '-' + revisionKind} onSubmit={event => {
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

function QuoteSnapshot({ snapshot }) {
    return <section>
        <p>Brouillon interne — validité proposée jusqu’au {snapshot.valid_until} (UTC).</p>
        <p>Contact : {snapshot.contact.name} — {snapshot.contact.email} — {snapshot.contact.phone}</p>
        <p>Livraison : {[snapshot.delivery_address.address, snapshot.delivery_address.city, snapshot.delivery_address.postal_code, snapshot.delivery_address.country].filter(Boolean).join(', ')}</p>
        <p>Facturation : {[snapshot.billing_address.address, snapshot.billing_address.city, snapshot.billing_address.postal_code, snapshot.billing_address.country].filter(Boolean).join(', ')}</p>
        <ul>{snapshot.lines.map(line => <li key={line.id}>{line.sku} — {line.designation} : {line.quantity} × {line.units_per_package}, prix unitaire {line.unit_price} MAD TTC — {line.line_subtotal} MAD TTC</li>)}</ul>
        <p>Sous-total : {snapshot.estimated_subtotal} MAD TTC. Frais et conditions de paiement à confirmer avant émission.</p>
    </section>;
}

function QuoteDraftEditor({ detail, api, disabled, save }) {
    const [validUntil, setValidUntil] = useState('');
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    async function calculate(event) {
        event.preventDefault(); setLoading(true); setError(''); setPreview(null);
        try {
            const response = await fetch(api + '/' + detail.id + '/quote-preview', { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ expected_version: detail.version, valid_until: validUntil }) });
            const payload = await response.json();
            if (!response.ok) throw new Error(response.status === 409 ? 'La demande a changé. Rouvrez son détail.' : payload.message || 'Prévisualisation indisponible.');
            setPreview(payload.data);
        } catch (failure) { setError(failure.message); }
        finally { setLoading(false); }
    }
    return <section aria-label="Brouillon de devis" aria-busy={loading}>
        <h3>Préparer une version du devis</h3>
        <p>Le brouillon reprend les dernières propositions enregistrées ci-dessous et recalcule les tarifs client. Chaque version enregistrée est conservée.</p>
        <form onSubmit={calculate}><fieldset disabled={disabled || loading}>
            <label>Fin de validité proposée (UTC)<input type="date" required value={validUntil} onChange={event => { setValidUntil(event.target.value); setPreview(null); }} /></label>
            <button>Prévisualiser le brouillon</button>
        </fieldset></form>
        {error && <p role="alert">{error}</p>}
        {preview && <><QuoteSnapshot snapshot={preview.snapshot} />
            <button disabled={disabled || loading} onClick={() => save({ expected_version: preview.version, valid_until: preview.snapshot.valid_until,
                preview_token: preview.preview_token, request_key: crypto.randomUUID() })}>Conserver cette version du brouillon</button></>}
    </section>;
}

function SalesLinesEditor({ detail, api, catalogue, disabled, save }) {
    const original = [...(detail.revisions ?? [])].reverse().find(item => item.kind === 'lines')?.after.lines ?? detail.lines;
    const [lines, setLines] = useState(original.map(line => ({ product_id: String(line.product_id), quantity: line.quantity,
        units_per_package: line.units_per_package, label: [line.sku, line.designation].filter(Boolean).join(' — ') })));
    const [reference, setReference] = useState('');
    const [products, setProducts] = useState([]);
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [reason, setReason] = useState('');
    async function request(url, values) {
        const response = await fetch(url, { method: values ? 'POST' : 'GET', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            ...(values ? { body: JSON.stringify(values) } : {}) });
        const payload = await response.json();
        if (!response.ok) throw new Error(response.status === 422 ? Object.values(payload.errors ?? {}).flat().join(' ') || payload.message :
            response.status === 409 ? 'La commande a changé. Rouvrez son détail.' : 'Opération refusée ou indisponible.');
        return payload;
    }
    async function search() {
        setLoading(true); setError('');
        try { setProducts((await request(catalogue + '?' + new URLSearchParams({ reference }))).data); }
        catch (failure) { setError(failure.message); }
        finally { setLoading(false); }
    }
    function change(index, field, value) {
        setPreview(null);
        const selected = field === 'product_id' ? products.find(product => String(product.id) === value) : null;
        setLines(current => current.map((line, position) => position === index ? { ...line, [field]: value,
            ...(field === 'product_id' ? { units_per_package: 1, label: selected ? [selected.sku, selected.designation].filter(Boolean).join(' — ') : '' } : {}) } : line));
    }
    async function calculate(event) {
        event.preventDefault(); setLoading(true); setError(''); setPreview(null);
        const requested = lines.map(line => ({ product_id: Number(line.product_id), quantity: Number(line.quantity), units_per_package: Number(line.units_per_package) }));
        try { setPreview({ ...(await request(api + '/' + detail.id + '/line-preview', { expected_version: detail.version, lines: requested })).data, requested }); }
        catch (failure) { setError(failure.message); }
        finally { setLoading(false); }
    }
    return <section aria-label="Proposition de produits et quantités" aria-busy={loading}>
        <h3>Proposer des produits et quantités</h3>
        <p>Les prix proviennent de la tarification réelle du client. Les substitutions et nouvelles conditions restent à soumettre à son accord.</p>
        <fieldset disabled={disabled || loading}>
            <legend>Rechercher une pièce</legend>
            <label>Référence<input value={reference} onChange={event => setReference(event.target.value)} maxLength="120" /></label>
            <button type="button" onClick={search}>Rechercher dans le catalogue</button>
            <p>{products.length} résultat(s) chargés. Affinez la référence si nécessaire.</p>
        </fieldset>
        <form onSubmit={calculate}><fieldset disabled={disabled || loading}><legend>Lignes proposées</legend>
            {lines.map((line, index) => <fieldset key={index}><legend>Pièce {index + 1}</legend>
                <label>Produit<select value={line.product_id} required onChange={event => change(index, 'product_id', event.target.value)}>
                    <option value="">Choisir une pièce</option>
                    {line.product_id && !products.some(product => String(product.id) === line.product_id) && <option value={line.product_id}>{line.label || 'Pièce sélectionnée'}</option>}
                    {products.map(product => <option key={product.id} value={product.id}>{product.sku} — {product.designation}</option>)}
                </select></label>
                <label>Nombre de conditionnements<input type="number" min="1" max="1000000" step="1" required value={line.quantity} onChange={event => change(index, 'quantity', event.target.value)} /></label>
                <label>Unités par conditionnement<input type="number" min="1" max="2147483647" step="1" required value={line.units_per_package} onChange={event => change(index, 'units_per_package', event.target.value)} /></label>
                <button type="button" onClick={() => { setPreview(null); setLines(current => current.filter((_, position) => position !== index)); }}>Retirer de la proposition</button>
            </fieldset>)}
            <button type="button" disabled={lines.length >= 200} onClick={() => { setPreview(null); setLines(current => [...current, { product_id: '', quantity: 1, units_per_package: 1, label: '' }]); }}>Ajouter une pièce</button>
            <button disabled={lines.length === 0}>Recalculer les prix client</button>
        </fieldset></form>
        {error && <p role="alert">{error}</p>}
        {preview && <form onSubmit={event => {
            event.preventDefault(); save({ kind: 'lines', changes: { lines: preview.requested }, pricing_token: preview.pricing_token,
                expected_version: preview.version, reason, request_key: crypto.randomUUID() });
        }}><fieldset disabled={disabled || loading}><legend>Prévisualisation de la proposition</legend>
            <ul>{preview.lines.map(line => <li key={line.product_id + '-' + line.units_per_package}>{line.sku} — {line.designation} : {line.quantity} × {line.units_per_package}, prix unitaire {line.unit_price} MAD TTC, total {line.line_subtotal}</li>)}</ul>
            <p>Sous-total proposé : {preview.estimated_subtotal} MAD TTC. Frais à confirmer.</p>
            <label>Motif<textarea required maxLength="1000" value={reason} onChange={event => setReason(event.target.value)} /></label>
            <button>Enregistrer la proposition en attente d’accord</button>
        </fieldset></form>}
    </section>;
}
const root = document.getElementById('sales-review');
if (root) createRoot(root).render(<SalesReview api={root.dataset.api} catalogue={root.dataset.catalogue} />);
