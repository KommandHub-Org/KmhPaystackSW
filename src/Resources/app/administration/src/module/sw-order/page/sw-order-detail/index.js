import template from './sw-order-detail.html.twig';
import { PAYSTACK_HANDLER_IDENTIFIER, isAbortError } from '../../../../util/paystack';


Shopware.Component.override('sw-order-detail', {
    template,

    inject: [
        'repositoryFactory',
        'systemConfigApiService',
    ],

    props: {
        orderId: {
            type: String,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            isLoading: true,
            paystackOrder: null,
            config: {},
        };
    },

    computed: {
        /**
         * Extend the default order criteria and load
         * payment method associations for transaction checks.
         *
         * @returns {Criteria}
         */
        orderCriteria() {
            const criteria = this.$super('orderCriteria');

            criteria.addAssociation('transactions.paymentMethod');
            criteria.addAssociation('transactions.stateMachineState');
            criteria.addAssociation('transactions.captures.refunds.stateMachineState');
            criteria.addAssociation('transactions.captures.stateMachineState');

            return criteria;
        },

        /**
         * Order repository.
         *
         * @returns {Repository}
         */
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        /**
         * Determines whether the order contains
         * a Paystack transaction.
         *
         * @returns {boolean}
         */
        isPaystackPayment() {
            if (!this.paystackOrder?.transactions?.length) {
                return false;
            }

            return this.paystackOrder.transactions.some(
                (transaction) =>
                    transaction.paymentMethod?.handlerIdentifier === PAYSTACK_HANDLER_IDENTIFIER
            );
        },
    },

    watch: {
        orderId() {
            void this.fetchOrder();
        },
    },

    created() {
        void this.fetchOrder();
    },

    methods: {
        /**
         * Load the order including transaction and payment method associations.
         *
         * @returns {Promise<void>}
         */
        async fetchOrder() {
            if (!this.orderId) {
                this.isLoading = false;
                return;
            }

            this.isLoading = true;

            try {
                this.paystackOrder = await this.orderRepository.get(
                    this.orderId,
                    Shopware.Context.api,
                    this.orderCriteria
                );

                if (this.paystackOrder?.salesChannelId) {
                    this.config = await this.systemConfigApiService.getValues(
                        'KmhPaystackSW.config',
                        this.paystackOrder.salesChannelId
                    );
                }
            } catch (error) {
                if (isAbortError(error)) {
                    return;
                }

                console.error('[Paystack] Failed to load order details:', error?.message ?? error);
            } finally {
                this.isLoading = false;
            }
        },
    },
});
