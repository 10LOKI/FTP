import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { PriceDisplay } from './price-display.jsx';

const fields = [
    ['reference', 'Référence', 255],
    ['category', 'Catégorie', 255],
    ['part_brand', 'Marque de la pièce', 255],
    ['vehicle_make', 'Constructeur du camion', 100],
    ['vehicle_model', 'Modèle du camion', 100],
];

function Catalogue({ initialProducts, initialFilters, initialErrors, apiUrl, catalogueUrl, productUrl, contactUrl }) {
    const [products, setProducts] = useState(initialProducts);
    const [filters, setFilters] = useState(initialFilters);
    const [errors, setErrors] = useState(initialErrors);
    const [message, setMessage] = useState('');
    const [loading, setLoading] = useState(false);
    const [priceNotice, setPriceNotice] = useState('');
    const request = useRef(null);
    const lastSearch = useRef(window.location.search);
    const errorSummary = useRef(null);
    const resultsHeading = useRef(null);

    useEffect(() => {
        if (message) errorSummary.current?.focus();
    }, [message]);

    async function load(search, updateHistory = true, priceRefresh = false) {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        lastSearch.current = search;
        const parameters = new URLSearchParams(search);
        setFilters(Object.fromEntries(fields.map(([field]) => [field, parameters.get(field) ?? ''])));
        setLoading(true);
        setErrors({});
        setMessage('');

        if (updateHistory) {
            const location = new URL(catalogueUrl, window.location.origin);
            location.search = search;
            window.history.pushState(null, '', location);
        }

        try {
            const endpoint = new URL(apiUrl, window.location.origin);
            endpoint.search = search;
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422) setErrors(data.errors ?? {});
                throw new Error(response.status === 422
                    ? 'Vérifiez les filtres indiqués et relancez la recherche.'
                    : 'Le catalogue est momentanément indisponible. Réessayez.');
            }
            if (!Array.isArray(data.data) || !Number.isInteger(data.current_page)) {
                throw new Error('La réponse du catalogue est indisponible. Réessayez.');
            }
            if (controller.signal.aborted) return;
            if (priceRefresh) {
                const previous = products.data.map(product => [product.id, product.pricing?.fingerprint]);
                const next = data.data.map(product => [product.id, product.pricing?.fingerprint]);
                if (JSON.stringify(previous) !== JSON.stringify(next)) setPriceNotice('Les offres ou les prix ont changé. Vérifiez les tarifs actualisés.');
            }
            setProducts(data);
            if (!priceRefresh) resultsHeading.current?.focus();
        } catch (error) {
            if (!controller.signal.aborted) {
                setMessage(error instanceof TypeError || error instanceof SyntaxError
                    ? 'Impossible de charger le catalogue. Vérifiez votre connexion et réessayez.'
                    : error.message);
            }
        } finally {
            if (!controller.signal.aborted) setLoading(false);
        }
    }

    useEffect(() => {
        const navigate = () => load(window.location.search, false);
        window.addEventListener('popstate', navigate);
        return () => {
            request.current?.abort();
            window.removeEventListener('popstate', navigate);
        };
    }, []);

    useEffect(() => {
        if (!products.refresh_after_ms || message) return;
        const refresh = () => load(lastSearch.current, false, true);
        const timer = window.setTimeout(refresh, products.refresh_after_ms);
        const visible = () => { if (document.visibilityState === 'visible') refresh(); };
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', visible);
        return () => {
            window.clearTimeout(timer);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [products, message]);

    function submit(event) {
        event.preventDefault();
        const parameters = new URLSearchParams();
        for (const [field, value] of new FormData(event.currentTarget)) {
            if (value !== '') parameters.set(field, value);
        }
        const perPage = new URLSearchParams(window.location.search).get('per_page');
        if (perPage && /^\d+$/.test(perPage) && Number(perPage) >= 1 && Number(perPage) <= 100) {
            parameters.set('per_page', perPage);
        }
        load(parameters.toString());
    }

    function pageLink(url, label) {
        if (!url) return null;
        const search = new URL(url, window.location.origin).search;
        const destination = new URL(catalogueUrl, window.location.origin);
        destination.search = search;
        return <a href={destination.href} aria-disabled={loading || undefined} onClick={event => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            if (!loading) load(search);
        }}>{label}</a>;
    }

    return <>
        {priceNotice && <p role="status">{priceNotice}</p>}
        <form className="grid" method="get" action={catalogueUrl} onSubmit={submit}>
            {fields.map(([field, label, maxLength]) => <label key={field} htmlFor={`filter-${field}`}>
                {label}
                <input id={`filter-${field}`} name={field} maxLength={maxLength} value={filters[field] ?? ''}
                    onChange={event => setFilters({ ...filters, [field]: event.target.value })}
                    aria-invalid={errors[field] ? true : undefined}
                    aria-describedby={errors[field] ? `error-${field}` : undefined} />
                {errors[field] && <span className="error" id={`error-${field}`}>{errors[field].join(' ')}</span>}
            </label>)}
            <button disabled={loading}>Appliquer les filtres</button>
        </form>
        {message && <div role="alert" tabIndex="-1" ref={errorSummary}>
            <p className="error">{message}</p>
            <button onClick={() => load(lastSearch.current, false)} disabled={loading}>Réessayer</button>
        </div>}
        {Object.entries(errors).filter(([field]) => !fields.some(([name]) => name === field))
            .map(([field, messages]) => <p className="error" key={field}>{messages.join(' ')}</p>)}
        <section aria-label="Résultats du catalogue" aria-busy={loading}>
            <p role="status">{loading ? 'Chargement du catalogue…' : !message ? `${products.total} résultat(s)` : ''}</p>
            <h2 tabIndex="-1" ref={resultsHeading}>Résultats</h2>
            {!message && !loading && <>
                {products.data.length ? <div className="grid">
                    {products.data.map(product => <article key={product.id}>
                        <h3><a href={productUrl.replace('__PRODUCT__', product.id)}>{product.designation}</a></h3>
                        <p>Référence {product.original_reference}</p>
                        {product.matched_reference && <p>
                            Correspondance {product.matched_reference.type === 'ORIGINALE' ? 'originale' : 'croisée'} :{' '}
                            <strong>{product.matched_reference.reference}</strong>
                            {product.matched_reference.brand && ` · ${product.matched_reference.brand}`}
                        </p>}
                        <p>{[product.part_brand, product.category].filter(Boolean).join(' · ')}</p>
                        {product.pricing ? <PriceDisplay price={product.pricing} /> : product.sale_price != null && <p>Prix de vente {product.sale_price}</p>}
                    </article>)}
                </div> : <p>Aucun produit ne correspond à votre recherche. <a href={contactUrl}>Contactez l’équipe</a>.</p>}
                <nav className="pagination" aria-label="Pagination">
                    {pageLink(products.prev_page_url, 'Précédent')}
                    <span>Page {products.current_page} sur {products.last_page}</span>
                    {pageLink(products.next_page_url, 'Suivant')}
                </nav>
            </>}
        </section>
    </>;
}

const root = document.getElementById('catalogue-app');
if (root) createRoot(root).render(<Catalogue {...JSON.parse(root.dataset.catalogue)} />);
