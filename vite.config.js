import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/sales-review.jsx', 'resources/js/audit.jsx', 'resources/js/warehouse-stock.jsx', 'resources/js/delivery-preparation.jsx', 'resources/js/supplier-returns.jsx', 'resources/js/public-catalogue.jsx', 'resources/js/public-account.jsx', 'resources/js/customer-cart.jsx', 'resources/js/customer-orders.jsx', 'resources/js/product-references.jsx', 'resources/js/product-price.jsx', 'resources/js/promotions-manage.jsx', 'resources/js/customer-discounts.jsx'],
            input: ['resources/js/invoice-preview.jsx', 'resources/css/app.css', 'resources/js/app.js', 'resources/js/audit.jsx', 'resources/js/warehouse-stock.jsx', 'resources/js/delivery-preparation.jsx', 'resources/js/supplier-returns.jsx', 'resources/js/public-catalogue.jsx', 'resources/js/public-account.jsx', 'resources/js/customer-cart.jsx', 'resources/js/customer-orders.jsx', 'resources/js/product-references.jsx', 'resources/js/product-price.jsx', 'resources/js/promotions-manage.jsx', 'resources/js/customer-discounts.jsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
