<?php

declare(strict_types=1);

namespace Kommandhub\PaystackSW\BankVerification\Controller;

use Kommandhub\PaystackSW\Setting\Service\Config;
use Kommandhub\PaystackSW\BankVerification\Service\BankValidationFactory;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BankVerificationController extends StorefrontController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Config $config,
        private readonly EntityRepository $customerRepository,
        private readonly BankValidationFactory $bankValidationFactory,
        private readonly DataValidator $validator
    ) {
    }

    /**
     * Fetches the list of supported banks from Paystack.
     */
    #[Route(path: '/paystack/bank/list', name: 'frontend.paystack.bank.list', defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true], methods: ['GET'])]
    public function getBanks(SalesChannelContext $context): JsonResponse
    {
        if (!$this->isBankDataCollectionEnabled($context)) {
            return new JsonResponse(['status' => false, 'message' => 'Feature disabled'], 404);
        }

        $secretKey = $this->getSecretKey($context);

        try {
            $response = $this->httpClient->request('GET', 'https://api.paystack.co/bank', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                ],
            ]);

            return new JsonResponse($response->toArray());
        } catch (\Exception $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Verifies a bank account number using Paystack's resolve endpoint.
     */
    #[Route(path: '/paystack/bank/verify', name: 'frontend.paystack.bank.verify', defaults: ['XmlHttpRequest' => true, PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true], methods: ['POST'])]
    public function verifyAccount(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->isBankDataCollectionEnabled($context)) {
            return new JsonResponse(['status' => false, 'message' => 'Feature disabled'], 404);
        }

        $secretKey = $this->getSecretKey($context);

        $accountNumber = (string)$request->request->get('account_number');
        $bankCode = (string)$request->request->get('bank_code');

        if (!$accountNumber || !$bankCode) {
            return new JsonResponse(['status' => false, 'message' => 'Missing parameters'], 400);
        }

        if ($this->isSandbox($context)) {
            // Paystack test bank code for sandbox testing
            $bankCode = '001';
        }

        try {
            // Using Resolve Account endpoint as it is standard for verification
            $response = $this->httpClient->request('GET', 'https://api.paystack.co/bank/resolve', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                ],
                'query' => [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                ],
            ]);

            $data = $response->toArray(false);

            if ($response->getStatusCode() !== 200 || !($data['status'] ?? false)) {
                return new JsonResponse([
                    'status' => false,
                    'message' => $data['message'] ?? 'Account verification failed.',
                ], $response->getStatusCode() === 200 ? 400 : $response->getStatusCode());
            }

            return new JsonResponse($data);
        } catch (\Exception $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Saves bank account details to the customer's wallet.
     */
    #[Route(
        path: '/paystack/bank/save',
        name: 'frontend.paystack.bank.save',
        defaults: [PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true],
        methods: ['POST']
    )]
    public function saveBank(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): Response
    {
        if (!$this->isBankDataCollectionEnabled($context)) {
            throw $this->createNotFoundException();
        }

        $validation = $this->bankValidationFactory->create($context);
        $violations = $this->validator->getViolations($data->all(), $validation);

        if ($violations->count() > 0) {
            $this->addFlash(StorefrontController::DANGER, 'Please correct the following errors:');

            foreach ($violations as $violation) {
                $this->addFlash(StorefrontController::DANGER, $violation->getMessage());
            }

            return $this->redirectToRoute('frontend.account.profile.page');
        }

        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'customFields' => [
                    'kmh_paystack_bank_name' => $data->get('bankName'),
                    'kmh_paystack_bank_code' => $data->get('bankCode'),
                    'kmh_paystack_account_number' => $data->get('accountNumber'),
                    'kmh_paystack_account_name' => $data->get('accountName'),
                    'kmh_paystack_bvn' => $data->get('bvn'),
                ],
            ],
        ], $context->getContext());

        $this->addFlash(StorefrontController::SUCCESS, 'Bank details saved successfully.');

        return $this->redirectToRoute('frontend.account.profile.page');
    }

    private function getSecretKey(SalesChannelContext $context): string
    {
        $salesChannelId = $context->getSalesChannel()->getId();

        return $this->isSandbox($context)
            ? $this->config->getString('apiSecretKeySandbox', salesChannelId: $salesChannelId)
            : $this->config->getString('apiSecretKey', salesChannelId: $salesChannelId);
    }

    private function isSandbox(SalesChannelContext $context): bool
    {
        return $this->config->getBool('enableSandbox', salesChannelId: $context->getSalesChannel()->getId());
    }

    private function isBankDataCollectionEnabled(SalesChannelContext $context): bool
    {
        return $this->config->getBool('collectBankData', salesChannelId: $context->getSalesChannel()->getId());
    }
}
