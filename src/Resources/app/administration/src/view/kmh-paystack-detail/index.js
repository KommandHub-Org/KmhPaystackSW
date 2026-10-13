import template from './kmh-paystack-detail.html.twig';
import './kmh-paystack-detail.scss';
import icon from './icon.png';
import { PAYSTACK_HANDLER_IDENTIFIER, isAbortError } from '../../util/paystack';
import {
    maxRefundableAmount as calcMaxRefundable,
    minRefundableAmount as calcMinRefundable,
    currencyDecimals,
} from '../../service/refund-calculator';

const { Store, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Shopware.Component.register('kmh-paystack-detail', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    /** @private */
    metaInfo() {
        return {
            title: this.$t('kmh-paystack-detail.title'),
        };
    },

    inject: [
        'repositoryFactory',
        'paystackRefundService',
        'systemConfigApiService',
        'acl',
    ],

    /** @private */
    data() {
        return {
            showRefundModal: false,
            refundAmount: null,
            refundCurrency: null,
            customerNote: null,
            merchantNote: null,
            isRefundLoading: false,
            isRefundSuccess: false,
            activeTransaction: null,
            activeCapture: null,
            showRefundsListModal: false,

            captures: [],
            refunds: [],
            isLoading: false,
            config: {},
        };
    },

    computed: {
        /**
         * Order repository.
         *
         * @returns {Repository}
         */
        orderRepository() {
            return this.repositoryFactory.create('order');
        },

        /**
         * Returns the order object from the swOrderDetail store.
         *
         * @returns {Object}
         */
        order() {
            return Store.get('swOrderDetail')?.order;
        },

        /**
         * Checks if the order has any unsaved changes.
         *
         * @returns {Boolean}
         */
        orderChanges() {
            if (!this.order || !this.order?.id || !this.orderRepository) {
                return false;
            }

            return this.orderRepository.hasChanges(this.order);
        },

        /**
         * Returns the repository for order transaction captures.
         *
         * @returns {Object}
         */
        transactionCaptureRepository() {
            return this.repositoryFactory.create('order_transaction_capture');
        },

        /**
         * Finds the Paystack transaction within the order's transactions.
         *
         * @returns {Object|null}
         */
        paystackTransaction() {
            if (!this.order || !this.order?.transactions) {
                return null;
            }

            return this.order.transactions.find((transaction) => {
                return transaction.paymentMethod?.handlerIdentifier === PAYSTACK_HANDLER_IDENTIFIER
                    || (transaction.customFields && transaction.customFields.paystack_reference);
            });
        },

        /**
         * Returns whether the current transaction is fully refunded.
         *
         * @returns {Boolean}
         */
        isTransactionRefunded() {
            return this.paystackTransaction?.stateMachineState?.technicalName === 'refunded';
        },

        /**
         * Transforms the Paystack transaction into a data grid compatible format.
         *
         * @returns {Array<Object>}
         */
        paystackTransactionData() {
            if (!this.paystackTransaction?.id) {
                return [];
            }

            return [{
                id: this.paystackTransaction.id,
                amount: this.paystackTransaction.customFields?.paystack_amount,
                currency: this.paystackTransaction.customFields?.paystack_currency,
                channel: this.paystackTransaction.customFields?.paystack_payment_type,
                reference: this.paystackTransaction.customFields?.paystack_reference,
                transactionId: this.paystackTransaction.customFields?.paystack_transaction_id,
                fee: this.paystackTransaction.customFields?.paystack_transaction_fee,
                verifiedAt: this.paystackTransaction.customFields?.paystack_verified_at,
                state: this.paystackTransaction.stateMachineState,
            }];
        },

        /**
         * Column configuration for the Paystack transaction grid.
         *
         * @returns {Array<Object>}
         */
        paystackTransactionColumns() {
            return [
                {
                    property: 'amount',
                    label: 'kmh-paystack-detail.summary.chargedAmount',
                    primary: true,
                },
                {
                    property: 'reference',
                    label: 'kmh-paystack-detail.grid.reference',
                },
                {
                    property: 'transactionId',
                    label: 'kmh-paystack-detail.grid.transactionId',
                },
                {
                    property: 'fee',
                    label: 'kmh-paystack-detail.grid.processingFee',
                },
                {
                    property: 'state',
                    label: 'kmh-paystack-detail.grid.state',
                },
                {
                    property: 'verifiedAt',
                    label: 'kmh-paystack-detail.grid.verifiedAt',
                },
            ];
        },

        /**
         * Column configuration for the capture grid.
         *
         * @returns {Array<Object>}
         */
        paystackCaptureColumns() {
            return [
                {
                    property: 'amount',
                    label: 'kmh-paystack-detail.captures.grid.amount',
                    primary: true,
                },
                {
                    property: 'state',
                    label: 'kmh-paystack-detail.captures.grid.state',
                },
                {
                    property: 'externalReference',
                    label: 'kmh-paystack-detail.captures.grid.externalReference',
                },
                {
                    property: 'createdAt',
                    label: 'kmh-paystack-detail.captures.grid.createdAt',
                },
            ];
        },

        /**
         * Column configuration for the refunds grid.
         *
         * @returns {Array<Object>}
         */
        paystackRefundColumns() {
            return [
                {
                    property: 'amount',
                    label: 'kmh-paystack-detail.refunds.grid.amount',
                    primary: true,
                },
                {
                    property: 'state',
                    label: 'kmh-paystack-detail.refunds.grid.state',
                },
                {
                    property: 'externalReference',
                    label: 'kmh-paystack-detail.refunds.grid.externalReference',
                },
                {
                    property: 'reason',
                    label: 'kmh-paystack-detail.refunds.grid.reason',
                },
                {
                    property: 'createdAt',
                    label: 'kmh-paystack-detail.refunds.grid.createdAt',
                },
            ];
        },

        /**
         * The currency used for refund calculations, falling back to the
         * transaction's stored currency before the modal sets it.
         *
         * @returns {String|undefined}
         */
        activeCurrency() {
            return this.refundCurrency || this.paystackTransaction?.customFields?.paystack_currency;
        },

        /**
         * Calculates the maximum refundable amount (major units).
         *
         * @returns {Number}
         */
        maxRefundableAmount() {
            if (!this.paystackTransaction) {
                return 0;
            }

            return calcMaxRefundable({
                transactionAmount: Number(this.paystackTransaction.customFields?.paystack_amount ?? 0),
                captures: this.captures,
                refunds: this.refunds,
                currency: this.activeCurrency,
            });
        },

        /**
         * Whether a refund can currently be initiated: feature enabled, user has
         * the privilege, transaction not fully refunded, and a positive balance
         * that clears the Paystack minimum.
         *
         * @returns {Boolean}
         */
        canRefund() {
            return this.refundEnabled
                && this.acl.can('paystack.refund')
                && !this.isTransactionRefunded
                && this.maxRefundableAmount >= this.minRefundableAmount;
        },

        /**
         * Returns the filtered list of refunds based on the active capture.
         *
         * @returns {Array<Object>}
         */
        paystackRefunds() {
            if (this.activeCapture?.id) {
                return this.refunds.filter(refund => refund.captureId === this.activeCapture.id);
            }

            return this.refunds;
        },

        /**
         * Returns the currency filter.
         *
         * @returns {Function}
         */
        currencyFilter() {
            return Shopware.Filter.getByName('currency');
        },

        /**
         * Returns the Paystack icon.
         *
         * @returns {String}
         */
        paystackIcon() {
            return icon;
        },
        /**
         * Returns whether refunds are enabled.
         *
         * @returns {Boolean}
         */
        refundEnabled() {
            return this.config['KmhPaystackSW.config.refundEnabled'] !== false;
        },
        /**
         * Returns the minimum refund amount for Paystack (in minor units).
         *
         * @returns {Number}
         */
        minPaystackRefundAmount() {
            return this.config['KmhPaystackSW.config.minimumRefundAmount'] ?? 50;
        },
        /**
         * Returns the minimum refund amount in major units.
         *
         * @returns {Number}
         */
        minRefundableAmount() {
            return calcMinRefundable(this.minPaystackRefundAmount, this.activeCurrency);
        },

        /**
         * Decimal precision for the refund amount field, per active currency.
         *
         * @returns {Number}
         */
        refundAmountDigits() {
            return currencyDecimals(this.activeCurrency);
        },
    },

    /** @private */
    watch: {
        paystackTransaction: {
            immediate: true,
            handler(transaction) {
                if (transaction?.id) {
                    void this.loadCapturesAndRefunds();
                }
            },
        },
        // Config depends on the order's sales channel, which the swOrderDetail
        // store may populate after this component is created; load reactively.
        'order.salesChannelId': {
            immediate: true,
            handler(salesChannelId) {
                if (salesChannelId) {
                    void this.loadConfig(salesChannelId);
                }
            },
        },
    },

    methods: {
        /**
         * Loads plugin configuration for the given sales channel.
         *
         * @param {String} salesChannelId
         * @returns {Promise<void>}
         */
        async loadConfig(salesChannelId) {
            this.config = await this.systemConfigApiService.getValues(
                'KmhPaystackSW.config',
                salesChannelId
            );
        },
        /**
         * Creates search criteria for fetching captures associated with a transaction.
         *
         * @param {String} transactionId
         * @returns {Criteria}
         */
        transactionCaptureCriteria(transactionId) {
            const criteria = new Criteria();

            criteria.addFilter(
                Criteria.equals('orderTransactionId', transactionId)
            );

            criteria.addAssociation('stateMachineState');
            criteria.addAssociation('refunds');
            criteria.addAssociation('refunds.stateMachineState');

            return criteria;
        },

        /**
         * Fetches captures and their associated refunds for the current Paystack transaction.
         *
         * @async
         * @returns {Promise<void>}
         */
        async loadCapturesAndRefunds() {
            // Re-entrancy guard: the paystackTransaction watcher can fire while a
            // load is already in flight — avoid the concurrent double request.
            if (!this.paystackTransaction?.id || this.isLoading) {
                return;
            }

            this.isLoading = true;

            try {
                const captureResult = await this.transactionCaptureRepository.search(
                    this.transactionCaptureCriteria(this.paystackTransaction.id),
                    Shopware.Context.api
                );

                const captures = captureResult.map((capture) => ({
                    ...capture,
                    state: capture.stateMachineState?.translated?.name
                        || capture.stateMachineState?.name
                        || '',
                }));

                const refunds = [];

                captures.forEach((capture) => {
                    if (!capture.refunds?.length) {
                        return;
                    }

                    capture.refunds.forEach((refund) => {
                        refunds.push({
                            ...refund,
                            state: refund.stateMachineState?.translated?.name
                                || refund.stateMachineState?.name
                                || '',
                        });
                    });
                });

                this.captures = captures;
                this.refunds = refunds;
            } catch (error) {
                if (isAbortError(error)) {
                    return;
                }

                this.createNotificationError({
                    message: error.message,
                });
            } finally {
                this.isLoading = false;
            }
        },

        /**
         * Opens the refund modal for the current transaction.
         *
         * The refund is always transaction-level here (the only entry point is
         * the transaction grid), so no capture is selected. `canRefund`
         * guarantees maxRefundableAmount >= minRefundableAmount, so prefilling
         * the maximum is always a valid starting amount.
         */
        onOpenRefundModal() {
            this.activeTransaction = this.paystackTransaction;
            this.activeCapture = null;
            this.refundCurrency = this.paystackTransaction.customFields?.paystack_currency;
            this.refundAmount = this.maxRefundableAmount;
            this.showRefundModal = true;
        },

        /**
         * Opens the refund list modal for the selected capture.
         *
         * @param {Object} item
         */
        onOpenRefundsListModal(item) {
            this.activeCapture = item;
            this.showRefundsListModal = true;
        },

        /**
         * Closes the refund list modal and resets active capture.
         */
        onCloseRefundsListModal() {
            this.activeCapture = null;
            this.showRefundsListModal = false;
        },

        /**
         * Closes the refund modal and resets refund-related data.
         */
        onCloseRefundModal() {
            this.showRefundModal = false;
            this.activeTransaction = null;
            this.refundAmount = null;
            this.refundCurrency = null;
            this.customerNote = null;
            this.merchantNote = null;
        },

        /**
         * Initiates the refund process through the Paystack refund service.
         */
        onConfirmRefund() {
            if (this.refundAmount < this.minRefundableAmount) {
                this.createNotificationError({
                    message: this.$t('kmh-paystack-detail.refund.errorAmountTooLow', {
                        minAmount: this.currencyFilter(this.minRefundableAmount, this.refundCurrency),
                    }),
                });
                return;
            }

            if (this.refundAmount > this.maxRefundableAmount) {
                this.createNotificationError({
                    message: this.$t('kmh-paystack-detail.refund.errorAmountTooHigh', {
                        maxAmount: this.currencyFilter(this.maxRefundableAmount, this.refundCurrency),
                    }),
                });
                return;
            }

            this.isRefundLoading = true;

            const payload = {
                transaction: this.activeTransaction.customFields?.paystack_reference,
                orderTransactionId: this.activeTransaction.id,
                orderTransactionCaptureId: this.activeCapture?.id,
                // Major units; server converts to minor units per currency decimals.
                amount: this.refundAmount,
                currency: this.refundCurrency,
                customer_note: this.customerNote,
                merchant_note: this.merchantNote,
            };

            this.paystackRefundService.refund(payload)
                .then(async () => {
                    this.isRefundSuccess = true;
                    await this.loadCapturesAndRefunds();
                })
                .catch((error) => {
                    if (isAbortError(error)) {
                        return;
                    }

                    const errorData = error.response?.data;
                    let message = errorData?.error || error.message;

                    if (errorData?.meta?.nextStep) {
                        message += ` ${errorData.meta.nextStep}`;
                    }

                    this.createNotificationError({
                        message,
                    });
                })
                .finally(() => {
                    this.isRefundLoading = false;
                });
        },

        /**
         * Handles the completion of the refund process.
         */
        onRefundFinished() {
            this.isRefundSuccess = false;
            this.onCloseRefundModal();
        },
    },
});
