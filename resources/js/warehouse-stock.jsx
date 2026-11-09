import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request.js';
import '../css/warehouse-stock.css';

const labels = { physical: 'Physique vendable', reserved: 'Réservé', available: 'Disponible', control: 'En contrôle', rejected: 'Non réintégrable' };
const kinds = { receipt: 'Réception', adjustment: 'Correction', inspection: 'Contrôle', transfer_out: 'Transfert sortant', transfer_in: 'Transfert entrant', reservation: 'Réservation', release: 'Libération', dispatch: 'Départ BL' };
const operations = [['receive', 'Réception', 'STOCK_RECEIVE'], ['adjust', 'Correction', 'STOCK_ADJUST'], ['inspect', 'Résultat de contrôle', 'STOCK_ADJUST'], ['transfer', 'Transfert', 'STOCK_TRANSFER'], ['configure', 'Emplacement et seuil', 'STOCK_STRUCTURE_MANAGE']];
function Pages({ page, onPage, disabled }) {
    if (!page || page.last_page <= 1) return null;
    return <div aria-label="Pagination"><button type="button" disabled={disabled || page.current_page <= 1} onClick={() => onPage(page.current_page - 1)}>Précédent</button><span>Page {page.current_page} / {page.last_page}</span><button type="button" disabled={disabled || page.current_page >= page.last_page} onClick={() => onPage(page.current_page + 1)}>Suivant</button></div>;
}
function StockConsole({ baseUrl, permissions }) {
    const [warehouses, setWarehouses] = useState(null), [destinationWarehouses, setDestinationWarehouses] = useState(null), [products, setProducts] = useState(null), [locations, setLocations] = useState(null);
    const [warehouseId, setWarehouseId] = useState(''), [productId, setProductId] = useState(''), [destinationId, setDestinationId] = useState('');
    const [balance, setBalance] = useState(null), [destination, setDestination] = useState(null), [history, setHistory] = useState(null), [alerts, setAlerts] = useState(null);
    const [operation, setOperation] = useState(operations.find(([, , permission]) => permissions.includes(permission))?.[0] || '');
    const [query, setQuery] = useState(''), [refresh, setRefresh] = useState(0), [busy, setBusy] = useState(false), [ready, setReady] = useState(false);
    const [feedback, setFeedback] = useState(null);
    const feedbackRef = useRef(null), replay = useRef(null);
    const canStructure = permissions.includes('STOCK_STRUCTURE_MANAGE');
    function url(path, values = {}) {
        const result = new URL(`${baseUrl}/${path}`, location.origin);
        for (const [key, value] of Object.entries(values)) if (value !== '') result.searchParams.set(key, value);
        return result;
    }
    function report(error) { if (error.name !== 'AbortError') setFeedback({ error: true, message: error.message, fields: error.fields }); }
    useEffect(() => { if (feedback) feedbackRef.current?.focus(); }, [feedback]);
    async function loadWarehouses(page = 1) { try { const result = await pricingRequest(url('warehouses', { page })); setWarehouses(result); setDestinationWarehouses(previous => previous ?? result); } catch (e) { report(e); } }
    async function loadDestinationWarehouses(page = 1) { try { setDestinationWarehouses(await pricingRequest(url('warehouses', { page }))); } catch (e) { report(e); } }
    async function findProducts(page = 1) { try { setProducts(await pricingRequest(url('products', { search: query, page }))); } catch (e) { report(e); } }
    async function loadAlerts(page = 1) { try { setAlerts(await pricingRequest(url('alerts', { warehouse_id: warehouseId, page }))); } catch (e) { report(e); } }
    async function loadLocations(page = 1) { try { setLocations(await pricingRequest(url('locations', { warehouse_id: warehouseId, page }))); } catch (e) { report(e); } }
    async function loadHistory(page = 1) { if (balance?.id) try { setHistory(await pricingRequest(url('movements', { balance_id: balance.id, page }))); } catch (e) { report(e); } }
    useEffect(() => { loadWarehouses(); findProducts(); }, []);
    useEffect(() => { loadAlerts(); }, [warehouseId, refresh]);
    useEffect(() => {
        setBalance(null); setDestination(null); setHistory(null); setLocations(null); setReady(false);
        if (!warehouseId) return;
        const abort = new AbortController();
        pricingRequest(url('locations', { warehouse_id: warehouseId }), { signal: abort.signal }).then(setLocations).catch(report);
        if (productId) pricingRequest(url('balances', { warehouse_id: warehouseId, product_id: productId }), { signal: abort.signal }).then(result => {
            const item = result.data[0] ?? { physical: 0, reserved: 0, available: 0, control: 0, rejected: 0, version: 0, threshold_quantity: null, threshold_basis: null, location_id: null };
            setBalance(item); setReady(true);
            if (item.id) pricingRequest(url('movements', { balance_id: item.id }), { signal: abort.signal }).then(setHistory).catch(report);
        }).catch(report);
        return () => abort.abort();
    }, [warehouseId, productId, refresh]);
    useEffect(() => {
        setDestination(null);
        if (!destinationId || !productId || destinationId === warehouseId) return;
        const abort = new AbortController();
        pricingRequest(url('balances', { warehouse_id: destinationId, product_id: productId }), { signal: abort.signal }).then(result => setDestination(result.data[0] ?? { version: 0, physical: 0 })).catch(report);
        return () => abort.abort();
    }, [destinationId, warehouseId, productId, refresh]);
    async function send(action, values, form) {
        const fingerprint = JSON.stringify([action, values]);
        if (replay.current?.fingerprint !== fingerprint) replay.current = { fingerprint, key: crypto.randomUUID() };
        setBusy(true); setFeedback(null);
        try {
            await pricingRequest(url(`actions/${action}`), { method: 'POST', values, key: replay.current.key });
            replay.current = null;
            setFeedback({ message: 'Opération enregistrée. Les quantités et le journal ont été actualisés.' });
            if (action === 'warehouse' || action === 'location') form?.reset();
            await loadWarehouses(warehouses?.current_page ?? 1);
            setRefresh(value => value + 1);
        } catch (e) {
            report(e);
            if (e.status === 409) { replay.current = null; setRefresh(value => value + 1); }
        } finally { setBusy(false); }
    }
    function submitStock(event) {
        event.preventDefault();
        const fields = Object.fromEntries(new FormData(event.currentTarget));
        const values = { warehouse_id: warehouseId, product_id: Number(productId), expected_version: balance.version };
        if (operation === 'configure') Object.assign(values, { location_id: fields.location_id || null, threshold_quantity: fields.threshold === '' ? null : Number(fields.threshold), threshold_basis: fields.threshold === '' ? null : 'available' });
        else {
            Object.assign(values, { quantity: Number(fields.quantity), reason: fields.reason.trim(), source_reference: fields.source_reference.trim() });
            if (operation === 'receive' || operation === 'adjust') values.bucket = fields.bucket;
            if (operation === 'inspect') values.destination = fields.destination;
            if (operation === 'transfer') Object.assign(values, { destination_warehouse_id: destinationId, destination_expected_version: destination.version });
        }
        send(operation, values, event.currentTarget);
    }
    const selectedWarehouse = warehouses?.data.find(item => item.id === warehouseId);
    return <div className="stock-console">
        {feedback && <div ref={feedbackRef} tabIndex={-1} role={feedback.error ? 'alert' : 'status'} className="stock-feedback" data-error={!!feedback.error}><p>{feedback.message}</p>{Object.entries(feedback.fields ?? {}).map(([field, messages]) => <p key={field}>{field} : {messages.join(' ')}</p>)}</div>}
        <section aria-labelledby="stock-selection"><h2 id="stock-selection">Choisir le dépôt et le produit</h2>
            <div className="stock-grid"><label>Dépôt<select value={warehouseId} disabled={busy} onChange={e => setWarehouseId(e.target.value)}><option value="">Choisir un dépôt</option>{warehouses?.data.map(item => <option key={item.id} value={item.id}>{item.code} — {item.name}{!item.active ? ' (désactivé)' : ''}</option>)}</select></label>
            <form onSubmit={e => { e.preventDefault(); findProducts(); }}><label>Référence, SKU ou désignation<input value={query} onChange={e => setQuery(e.target.value)} maxLength={120} /></label><button disabled={busy}>Rechercher les produits</button></form>
            <label>Produit<select value={productId} disabled={busy} onChange={e => setProductId(e.target.value)}><option value="">Choisir un produit</option>{products?.data.map(item => <option key={item.id} value={item.id}>{item.sku} — {item.designation}</option>)}</select></label></div>
            <Pages page={warehouses} onPage={page => { setWarehouseId(''); loadWarehouses(page); }} disabled={busy} /><Pages page={products} onPage={findProducts} disabled={busy} />
            <button type="button" disabled={busy} onClick={() => setRefresh(value => value + 1)}>Actualiser les quantités</button>
            {warehouseId && productId && !ready && <p role="status">Chargement du stock…</p>}
            {balance && <><dl className="stock-counts">{Object.entries(labels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{balance[key]}</dd></div>)}</dl><p>Emplacement : {balance.location?.code ?? 'Non défini'} · Seuil : {balance.threshold_quantity ?? 'Désactivé'}{balance.threshold_basis ? ` sur le ${balance.threshold_basis === 'available' ? 'disponible' : 'physique vendable'}` : ''}</p></>}
        </section>
        {operation && <section aria-labelledby="stock-action"><h2 id="stock-action">Enregistrer une opération</h2><div>{operations.filter(([, , permission]) => permissions.includes(permission)).map(([key, label]) => <button type="button" key={key} aria-pressed={key === operation} disabled={busy} onClick={() => setOperation(key)}>{label}</button>)}</div>
            <form key={`${operation}:${warehouseId}:${productId}:${balance?.version ?? ''}`} onSubmit={submitStock}><fieldset disabled={busy || !ready || !selectedWarehouse?.active}><legend>{operations.find(([key]) => key === operation)?.[1]} — quantités en unités</legend><div className="stock-grid">
                {operation !== 'configure' && <><label>Quantité{operation === 'adjust' ? ' (positive ou négative)' : ''}<input name="quantity" type="number" step="1" min={operation === 'adjust' ? -2147483647 : 1} max={2147483647} required /></label><label>Motif<input name="reason" required maxLength={255} /></label><label>Pièce ou référence source<input name="source_reference" required maxLength={120} /></label></>}
                {(operation === 'receive' || operation === 'adjust') && <label>Compartiment<select name="bucket"><option value="physical">Physique vendable</option><option value="control">En contrôle</option>{operation === 'adjust' && <option value="rejected">Non réintégrable</option>}</select></label>}
                {operation === 'inspect' && <label>Décision<select name="destination"><option value="physical">Accepté : réintégrer au vendable</option><option value="rejected">Refusé : non réintégrable</option></select></label>}
                {operation === 'transfer' && <label>Dépôt de destination<select required value={destinationId} onChange={e => setDestinationId(e.target.value)}><option value="">Choisir un autre dépôt</option>{destinationWarehouses?.data.filter(item => item.id !== warehouseId && item.active).map(item => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</select><small>Les unités réservées restent dans le dépôt source.{destination && ` Physique destination : ${destination.physical}.`}</small></label>}
                {operation === 'configure' && <><label>Emplacement principal<select name="location_id" defaultValue={balance?.location_id ?? ''}><option value="">Non défini</option>{balance?.location_id && !locations?.data.some(item => item.id === balance.location_id) && <option value={balance.location_id}>{balance.location?.code ?? 'Emplacement actuel'}</option>}{locations?.data.map(item => <option key={item.id} value={item.id}>{item.code}</option>)}</select></label><label>Seuil minimum<input name="threshold" type="number" min="0" max={2147483647} step="1" defaultValue={balance?.threshold_quantity ?? ''} /><small>Vide : alertes désactivées pour ce produit et ce dépôt.</small></label><p>Une alerte s’ouvre quand le disponible à la vente est inférieur ou égal au seuil.</p></>}
            </div><button disabled={busy || !ready || (operation === 'transfer' && !destination)}>Enregistrer l’opération</button></fieldset></form>
            {operation === 'transfer' && <Pages page={destinationWarehouses} onPage={page => { setDestinationId(''); loadDestinationWarehouses(page); }} disabled={busy} />}
            {operation === 'configure' && <Pages page={locations} onPage={loadLocations} disabled={busy} />}
            <p>Les alertes sont affichées dans l’application. Aucun email ni réapprovisionnement automatique n’est activé.</p>
        </section>}
        <section aria-labelledby="stock-alerts"><h2 id="stock-alerts">Alertes de réapprovisionnement{selectedWarehouse ? ` — ${selectedWarehouse.code}` : ''}</h2>{!alerts ? <p>Chargement des alertes…</p> : !alerts.data.length ? <p>Aucune alerte active.</p> : alerts.data.map(item => <p className="stock-alert" key={item.id}><strong>{item.warehouse_code} · {item.sku}</strong> — {item.designation} : {item.observed_quantity} unité(s), seuil {item.threshold} ({item.basis === 'available' ? 'disponible' : 'physique vendable'}).</p>)}<Pages page={alerts} onPage={loadAlerts} disabled={busy} /></section>
        <section aria-labelledby="stock-history"><h2 id="stock-history">Journal du produit sélectionné</h2>{history?.data.length ? <><div className="stock-scroll" role="region" aria-label="Mouvements de stock" tabIndex={0}><table><thead><tr><th>Date UTC</th><th>Opération / auteur</th><th>Variation (unités)</th><th>Motif et pièce source</th></tr></thead><tbody>{history.data.map(item => <tr key={item.id}><td>{item.created_at}</td><td>{kinds[item.kind] ?? item.kind}<br /><small>Compte #{item.actor_id}</small></td><td>{Object.entries(labels).filter(([key]) => key !== 'available').map(([key, label]) => item[`${key}_delta`] ? <div key={key}>{label} : {item[`${key}_delta`] > 0 ? '+' : ''}{item[`${key}_delta`]}</div> : null)}</td><td>{item.reason}<br /><small>{item.source_reference}</small></td></tr>)}</tbody></table></div><Pages page={history} onPage={loadHistory} disabled={busy} /></> : <p>Choisissez un produit et un dépôt pour consulter ses mouvements.</p>}</section>
        {canStructure && <section aria-labelledby="stock-structure"><h2 id="stock-structure">Dépôts et emplacements</h2><div className="stock-grid">
            <form onSubmit={e => { e.preventDefault(); const fields = Object.fromEntries(new FormData(e.currentTarget)); send('warehouse', { ...fields, active: true }, e.currentTarget); }}><h3>Créer un dépôt</h3><label>Code unique<input name="code" required maxLength={40} pattern={String.raw`[A-Za-z0-9_.\-]+`} /></label><label>Nom<input name="name" required maxLength={120} /></label><button disabled={busy}>Créer le dépôt</button></form>
            <form onSubmit={e => { e.preventDefault(); send('location', { ...Object.fromEntries(new FormData(e.currentTarget)), warehouse_id: warehouseId }, e.currentTarget); }}><h3>Ajouter un emplacement au dépôt sélectionné</h3><label>Code<input name="code" required maxLength={40} /></label>{[['aisle', 'Allée'], ['shelf', 'Rayon'], ['level', 'Niveau']].map(([key, label]) => <label key={key}>{label}<input name={key} maxLength={60} /></label>)}<button disabled={busy || !selectedWarehouse?.active}>Créer l’emplacement</button></form>
            {selectedWarehouse && <form key={selectedWarehouse.id + selectedWarehouse.version} onSubmit={e => { e.preventDefault(); const fields = Object.fromEntries(new FormData(e.currentTarget)); send('warehouse', { warehouse_id: warehouseId, expected_version: selectedWarehouse.version, code: fields.code, name: fields.name, active: fields.active === 'true' }); }}><h3>Modifier le dépôt sélectionné</h3><label>Code<input name="code" defaultValue={selectedWarehouse.code} required maxLength={40} pattern={String.raw`[A-Za-z0-9_.\-]+`} /></label><label>Nom<input name="name" defaultValue={selectedWarehouse.name} required maxLength={120} /></label><label>État<select name="active" defaultValue={String(selectedWarehouse.active)}><option value="true">Actif</option><option value="false">Désactivé</option></select></label><button disabled={busy}>Enregistrer le dépôt</button></form>}
        </div></section>}
    </div>;
}
const element = document.getElementById('warehouse-stock');
if (element) createRoot(element).render(<StockConsole {...JSON.parse(element.dataset.stock)} />);
