import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { pricingRequest } from './pricing-request.js';

const emptyOffer = { title: '', kind: 'percentage', value: '', starts_at: '', ends_at: '', timezone: 'Africa/Casablanca', state: 'draft' };
const stateLabels = { draft: 'Brouillon', enabled: 'Activée', disabled: 'Désactivée' };

function PromotionManager({ listUrl, productsUrl, settingsUrl, priceUrl, canEditPrice, canEditSettings }) {
    const [offers, setOffers] = useState(null);
    const [products, setProducts] = useState(null);
    const [settings, setSettings] = useState(null);
    const [selected, setSelected] = useState(null);
    const [form, setForm] = useState(emptyOffer);
    const [editing, setEditing] = useState(null);
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [failed, setFailed] = useState(false);
    const [errors, setErrors] = useState({});
    const [scope, setScope] = useState('offer');
    const key = useRef(crypto.randomUUID());
    const feedback = useRef(null);
    const editor = useRef(null);

    useEffect(() => { if (message) feedback.current?.focus(); }, [message]);
    function report(error) {
        setFailed(true);
        setErrors(error.fields ?? {});
        setMessage(error.message || 'Chargement indisponible. Réessayez.');
    }
    async function load(page = 1, state = filter) {
        try {
            const url = new URL(listUrl, location.origin);
            url.searchParams.set('page', page);
            if (state) url.searchParams.set('state', state);
            const [data, configuration] = await Promise.all([pricingRequest(url), pricingRequest(settingsUrl)]);
            setOffers(data);
            setSettings(configuration.data);
            return true;
        } catch (error) { report(error); return false; }
    }
    async function findProducts(search = '', page = 1) {
        try {
            const url = new URL(productsUrl, location.origin);
            url.searchParams.set('search', search);
            url.searchParams.set('page', page);
            setProducts(await pricingRequest(url));
        } catch (error) { report(error); }
    }
    useEffect(() => { load(); findProducts(); }, []);

    async function mutate(url, method, values, errorScope, success, creationKey) {
        setBusy(true); setMessage(''); setErrors({}); setScope(errorScope);
        try {
            await pricingRequest(url, { method, values, key: creationKey });
            if (!await load()) return false;
            setFailed(false); setMessage(success);
            return true;
        } catch (error) {
            report(error);
            if (error.status === 409) {
                setEditing(null); setForm(emptyOffer);
                if (errorScope === 'base') { setSelected(null); await findProducts(); }
                await load();
            }
            return false;
        } finally { setBusy(false); }
    }
    function field(name, label, type = 'text', extra = {}) {
        const fieldErrors = scope === 'offer' ? errors[name] : null;
        return <label htmlFor={`offer-${name}`}>{label}
            <input id={`offer-${name}`} name={name} type={type} value={form[name]} onChange={event => setForm({ ...form, [name]: event.target.value })}
                disabled={busy} aria-invalid={fieldErrors ? true : undefined} aria-describedby={fieldErrors ? `offer-${name}-error` : undefined} {...extra} />
            {fieldErrors && <span className="error" id={`offer-${name}-error`}>{fieldErrors.join(' ')}</span>}
        </label>;
    }
    return <>
        {message && <p ref={feedback} tabIndex="-1" role={failed ? 'alert' : 'status'} className={failed ? 'error' : undefined}>{message}</p>}
        {Object.entries(errors).filter(([name]) => scope !== 'offer' || !['title', 'value', 'starts_at', 'ends_at', 'timezone'].includes(name))
            .map(([name, values]) => <p className="error" key={name}>{values.join(' ')}</p>)}
        <button disabled={busy} onClick={() => { setMessage(''); load(); }}>Actualiser les promotions</button>
        {settings && <section><h2>Base des prix</h2>
            <p>Devise : {settings.currency}. Les montants saisis sont déclarés HT ou TTC ; ce choix ne calcule ni ne convertit la TVA.</p>
            <form key={settings.version} onSubmit={event => {
                event.preventDefault();
                const taxBasis = new FormData(event.currentTarget).get('tax_basis');
                mutate(settingsUrl, 'PUT', { tax_basis: taxBasis || null, expected_version: settings.version }, 'settings', 'Base fiscale enregistrée.');
            }}>
                <label htmlFor="tax-basis">Base fiscale
                    <select id="tax-basis" name="tax_basis" defaultValue={settings.tax_basis ?? ''} disabled={busy || !canEditSettings}>
                        <option value="">Non précisée</option><option value="HT">HT</option><option value="TTC">TTC</option>
                    </select>
                </label>
                {canEditSettings && <button disabled={busy}>Enregistrer la base fiscale</button>}
            </form>
        </section>}
        <section><h2>Choisir un produit</h2>
            <form onSubmit={event => { event.preventDefault(); findProducts(new FormData(event.currentTarget).get('search')); }}>
                <label htmlFor="product-search">SKU, référence originale ou désignation
                    <input id="product-search" name="search" maxLength={120} disabled={busy || editing !== null} />
                </label><button disabled={busy || editing !== null}>Chercher les produits</button>
            </form>
            {products && <>
                <ul>{products.data.map(product => <li key={product.id}>
                    <button disabled={busy || editing !== null} onClick={() => setSelected(product)}>{product.sku} — {product.designation}</button>
                    {' '}Tarif de base : {product.base_price ?? 'Non renseigné'} MAD
                </li>)}</ul>
                {!products.data.length && <p>Aucun produit trouvé.</p>}
                <button disabled={busy || !products.prev_page_url} onClick={() => findProducts(document.getElementById('product-search').value, products.current_page - 1)}>Produits précédents</button>{' '}
                <button disabled={busy || !products.next_page_url} onClick={() => findProducts(document.getElementById('product-search').value, products.current_page + 1)}>Produits suivants</button>
            </>}
        </section>
        {selected && !editing && canEditPrice && <form key={`${selected.id}-${selected.base_price}`} onSubmit={async event => {
            event.preventDefault();
            const value = new FormData(event.currentTarget).get('sale_price');
            if (await mutate(priceUrl.replace('__PRODUCT__', selected.id), 'PATCH', { sale_price: value, expected_sale_price: selected.base_price }, 'base', 'Tarif de base enregistré.')) {
                setSelected({ ...selected, base_price: value });
                findProducts();
            }
        }}>
            <h2>Tarif de base — {selected.sku}</h2>
            <label htmlFor="base-price">Montant en MAD<input id="base-price" name="sale_price" inputMode="decimal" defaultValue={selected.base_price ?? ''} required disabled={busy} /></label>
            <button disabled={busy}>Enregistrer le tarif de base</button>
        </form>}
        <section ref={editor} tabIndex="-1"><h2>{editing ? 'Modifier une promotion' : 'Nouvelle promotion'}</h2>
            {!editing && <button type="button" disabled={busy} onClick={() => { setForm(emptyOffer); setErrors({}); key.current = crypto.randomUUID(); }}>Nouvelle saisie</button>}
            <p>{editing ? editing.product_label : selected ? `${selected.sku} — ${selected.designation}` : 'Choisissez un produit ci-dessus.'}</p>
            <form onSubmit={async event => {
                event.preventDefault();
                if (!editing && !selected) return;
                const values = editing ? { ...form, expected_version: editing.version } : { ...form, product_id: selected.id };
                if (await mutate(editing ? `${listUrl}/${editing.id}` : listUrl, editing ? 'PUT' : 'POST', values, 'offer', 'Promotion enregistrée.', editing ? undefined : key.current)) {
                    setEditing(null); setForm(emptyOffer); key.current = crypto.randomUUID();
                }
            }}>
                {field('title', 'Libellé de l’offre', 'text', { required: true, maxLength: 120 })}
                <label htmlFor="offer-kind">Type d’offre<select id="offer-kind" value={form.kind} disabled={busy} onChange={event => setForm({ ...form, kind: event.target.value })}>
                    <option value="percentage">Remise en %</option><option value="fixed_price">Prix promotionnel en MAD</option>
                </select></label>
                {field('value', form.kind === 'percentage' ? 'Pourcentage de remise' : 'Prix promotionnel en MAD', 'text', { required: true, inputMode: 'decimal' })}
                <p>Saisissez les décimales avec un point. Le tarif final arrondi doit rester inférieur au tarif normal.</p>
                <label htmlFor="offer-timezone">Fuseau horaire
                    <select id="offer-timezone" value={form.timezone} disabled={busy} onChange={event => setForm({ ...form, timezone: event.target.value })}
                        aria-invalid={scope === 'offer' && errors.timezone ? true : undefined}
                        aria-describedby={scope === 'offer' && errors.timezone ? 'offer-timezone-error' : undefined}>
                        <option value="Africa/Casablanca">Africa/Casablanca</option>
                        {form.timezone !== 'Africa/Casablanca' && <option value={form.timezone}>{form.timezone} (brouillon existant)</option>}
                    </select>
                    {scope === 'offer' && errors.timezone && <span className="error" id="offer-timezone-error">{errors.timezone.join(' ')}</span>}
                </label>
                <p>Pour activer, utilisez Africa/Casablanca. Si vous changez le fuseau d’un brouillon, vérifiez les heures locales saisies.</p>
                {field('starts_at', `Début — ${form.timezone}`, 'datetime-local', { required: true, step: 1 })}
                {field('ends_at', `Fin exclue — ${form.timezone}`, 'datetime-local', { required: true, step: 1 })}
                <label htmlFor="offer-state">État<select id="offer-state" value={form.state} disabled={busy} onChange={event => setForm({ ...form, state: event.target.value })}>
                    {Object.entries(stateLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}
                </select></label>
                <button disabled={busy || (!editing && !selected)}>Enregistrer la promotion</button>
                {editing && <button type="button" disabled={busy} onClick={() => { setEditing(null); setForm(emptyOffer); setErrors({}); }}>Annuler la modification</button>}
            </form>
        </section>
        <section><h2>Promotions enregistrées</h2>
            <form onSubmit={event => { event.preventDefault(); load(1, filter); }}>
                <label htmlFor="promotion-filter">État<select id="promotion-filter" value={filter} disabled={busy} onChange={event => setFilter(event.target.value)}>
                    <option value="">Tous</option>{Object.entries(stateLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}
                </select></label><button disabled={busy}>Filtrer les promotions</button>
            </form>
            {!offers && <p role="status">Chargement…</p>}
            {offers && <>
                {!offers.data.length && <p>Aucune promotion enregistrée.</p>}
                <div className="grid">{offers.data.map(offer => <article key={offer.id}>
                    <h3>{offer.title}</h3><p>{offer.product_label}</p>
                    <p>{stateLabels[offer.state]} — {offer.kind === 'percentage' ? `${offer.value} %` : `${offer.value} MAD`}</p>
                    <p>Du {offer.starts_local.replace('T', ' ')} au {offer.ends_local.replace('T', ' ')} ({offer.timezone})</p>
                    <button disabled={busy} onClick={() => {
                        setEditing(offer); setForm({ title: offer.title, kind: offer.kind, value: offer.value, starts_at: offer.starts_local, ends_at: offer.ends_local, timezone: offer.timezone, state: offer.state });
                        setErrors({}); editor.current?.focus();
                    }}>Modifier {offer.title}</button>
                </article>)}</div>
                <p>Page {offers.meta.current_page} sur {offers.meta.last_page}</p>
                <button disabled={busy || !offers.links.prev} onClick={() => load(offers.meta.current_page - 1)}>Précédent</button>{' '}
                <button disabled={busy || !offers.links.next} onClick={() => load(offers.meta.current_page + 1)}>Suivant</button>
            </>}
        </section>
    </>;
}

const root = document.getElementById('promotions-manage');
if (root) createRoot(root).render(<PromotionManager {...JSON.parse(root.dataset.promotions)} />);
