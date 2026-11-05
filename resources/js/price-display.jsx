import React from 'react';

export function PriceDisplay({ price }) {
    if (price.status !== 'ready') return <p role="status">Tarif momentanément indisponible. Contactez l’équipe avant de commander.</p>;
    const basis = price.tax_basis ?? '(base fiscale à préciser)';
    return <>
        <p><strong>Prix de vente {price.unit_price} {price.currency} {basis}</strong></p>
        {price.base_unit_price !== price.unit_price && <p>Tarif habituel : {price.base_unit_price} {price.currency} {basis}</p>}
        {price.promotion && <p>Offre : {price.promotion.title}. Jusqu’au{' '}
            <time dateTime={price.promotion.ends_at}>{new Intl.DateTimeFormat('fr-MA', { timeZone: 'Africa/Casablanca', dateStyle: 'short', timeStyle: 'short' }).format(new Date(price.promotion.ends_at))}</time> (heure de Casablanca).</p>}
        {price.applied_source === 'customer_discount' && <p>Votre remise client est appliquée : {price.customer_discount_percentage} %.</p>}
        {price.promotion && Number(price.customer_discount_percentage) > 0 && <p>Le meilleur tarif est retenu, sans cumul des remises.</p>}
    </>;
}
