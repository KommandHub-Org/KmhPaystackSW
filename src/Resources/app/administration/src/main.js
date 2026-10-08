import frFR from './snippet/fr-FR.json';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('fr-FR', frFR);
Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

import PaystackRefundService from './service/paystack-refund.service';

Shopware.Service().register('paystackRefundService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');
    return new PaystackRefundService(initContainer.httpClient, container.loginService);
});

import './acl';
import './module/sw-order/page/sw-order-detail'
import './view/kmh-paystack-detail'

Shopware.Module.register('kmh-paystack-detail', {
    routeMiddleware(next, currentRoute) {
        if (currentRoute.name === 'sw.order.detail') {
            const paystackRoute = 'kmh.paystack.detail';

            if (!currentRoute.children.some(child => child.name === paystackRoute)) {
                currentRoute.children.push({
                    name: paystackRoute,
                    path: 'kmh/paystack',
                    component: 'kmh-paystack-detail',
                    meta: {
                        parentPath: 'sw.order.detail',
                        privilege: 'order.viewer',
                    },
                    props: {
                        default: ($route) => {
                            return { orderId: $route.params.id.toLowerCase() };
                        },
                    },
                });
            }
        }
        next(currentRoute);
    }
});