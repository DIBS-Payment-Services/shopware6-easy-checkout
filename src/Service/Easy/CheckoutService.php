<?php

namespace Nets\Checkout\Service\Easy;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Nets\Checkout\Service\Easy\Api\EasyApiService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Nets\Checkout\Service\Easy\Api\Exception\EasyApiException;
use Nets\Checkout\Service\ConfigService;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\RequestStack;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

class CheckoutService
{

    const CHECKOUT_TYPE_EMBEDDED = 'embedded';
    const CHECKOUT_TYPE_HOSTED = 'hosted';
    const EASY_CHECKOUT_JS_ASSET_TEST = 'https://test.checkout.dibspayment.eu/v1/checkout.js?v=1';
    const EASY_CHECKOUT_JS_ASSET_LIVE = 'https://checkout.dibspayment.eu/v1/checkout.js?v=1';

    /**
     * @var EasyApiService
     */
    private $easyApiService;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var EntityRepositoryInterface
     */
    private $transactionRepository;

    /**
     * @var OrderTransactionStateHandler
     */
    private $transactionStateHandler;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    /**
     * regexp for filtering strings
     */
    const ALLOWED_CHARACTERS_PATTERN = '/[^\x{00A1}-\x{00AC}\x{00AE}-\x{00FF}\x{0100}-\x{017F}\x{0180}-\x{024F}'
    . '\x{0250}-\x{02AF}\x{02B0}-\x{02FF}\x{0300}-\x{036F}'
    . 'A-Za-z0-9\!\#\$\%\(\)*\+\,\-\.\/\:\;\\=\?\@\[\]\\^\_\`\{\}\~ ]+/u';

    /**
     * CheckoutService constructor.
     * @param EasyApiService $easyApiService
     * @param ConfigService $configService
     * @param EntityRepositoryInterface $transactionRepository
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param CartService $cartService
     * @param RequestStack $requestStack
     * @param StateMachineRegistry $machineRegistry
     */
    public function __construct(EasyApiService $easyApiService,
                                ConfigService $configService,
                                EntityRepositoryInterface $transactionRepository,
                                OrderTransactionStateHandler $orderTransactionStateHandler,
                                CartService $cartService,
                                RequestStack $requestStack,
                                StateMachineRegistry $machineRegistry
)
    {
        $this->easyApiService = $easyApiService;
        $this->configService = $configService;
        $this->transactionRepository = $transactionRepository;
        $this->transactionStateHandler = $orderTransactionStateHandler;
        $this->cartService = $cartService;
        $this->requestStack = $requestStack;
        $this->stateMachineRegistry = $machineRegistry;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string $checkoutType
     * @param AsyncPaymentTransactionStruct|null $transaction
     * @return string
     * @throws EasyApiException
     */
    public function createPayment(SalesChannelContext $salesChannelContext, $checkoutType = self::CHECKOUT_TYPE_EMBEDDED, AsyncPaymentTransactionStruct $transaction = null) {
        $environment = $this->configService->getEnvironment($salesChannelContext->getSalesChannel()->getId());
        $secretKey = $this->configService->getSecretKey($salesChannelContext->getSalesChannel()->getId());
        $this->easyApiService->setEnv($environment);
        $this->easyApiService->setAuthorizationKey($secretKey);
        $payload = json_encode($this->collectRequestParams($salesChannelContext, $checkoutType, $transaction));
        return $this->easyApiService->createPayment($payload);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param AsyncPaymentTransactionStruct|null $transaction
     * @param string $checkoutType
     * @return array
     */
    private function collectRequestParams(SalesChannelContext $salesChannelContext, $checkoutType = self::CHECKOUT_TYPE_EMBEDDED, AsyncPaymentTransactionStruct $transaction = null)
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);

        if(is_object( $transaction )) {
            $cartOrderEntityObject = $transaction->getOrder();
            $reference = $cartOrderEntityObject->getOrderNumber();
            $amount = $cartOrderEntityObject->getAmountTotal();
        } else {
            $cartOrderEntityObject = $cart;
            $amount = $cart->getPrice()->getTotalPrice();
            $reference = $salesChannelContext->getToken();
        }

        $data =  [
            'order' => [
                'items' => $this->getOrderItems($cartOrderEntityObject),
                'amount' => $this->prepareAmount($amount),
                'currency' => $salesChannelContext->getCurrency()->getIsoCode(),
                'reference' => $reference,
            ]];

        if( is_object($transaction) ) {
            $data['checkout']['returnUrl'] = $transaction->getReturnUrl();
        }
        $data['checkout']['termsUrl'] = $this->configService->getTermsAndConditionsUrl($salesChannelContext->getSalesChannel()->getId());
        $chargeNow = $this->configService->getChargeNow($salesChannelContext->getSalesChannel()->getId());

        if('yes' == $chargeNow) {
            $data['checkout']['charge'] = 'true';
        }

        $data['checkout']['merchantHandlesConsumerData'] = true;

        if (self::CHECKOUT_TYPE_HOSTED == $checkoutType) {
            $data['checkout']['integrationType'] = 'HostedPaymentPage';
        }
        if(self::CHECKOUT_TYPE_EMBEDDED == $checkoutType) {
            $data['checkout']['url'] = $this->requestStack->getCurrentRequest()->getUriForPath('/nets/order/finish');
        }

        $data['checkout']['consumer'] =
            ['email' =>  $salesChannelContext->getCustomer()->getEmail(),
             'shippingAddress' => [
                'addressLine1' => $salesChannelContext->getCustomer()->getActiveShippingAddress()->getStreet(),
                'addressLine2' => $salesChannelContext->getCustomer()->getActiveShippingAddress()->getStreet(),
                'postalCode' => $salesChannelContext->getCustomer()->getActiveShippingAddress()->getZipcode(),
                'city' => $salesChannelContext->getCustomer()->getActiveShippingAddress()->getCity(),
                'country' => $salesChannelContext->getCustomer()->getActiveShippingAddress()->getCountry()->getIso3()]];

//        $data['notifications'] =
//            ['webhooks' =>
//                [
//                    ['eventName' => 'payment.checkout.completed',
//                        'url' => 'https://some-url.com',
//                        'authorization' => substr(str_shuffle(MD5(microtime())), 0, 10)]
//                ]];

        if(!empty($salesChannelContext->getCustomer()->getActiveBillingAddress()->getCompany())) {
            $data['checkout']['consumer']['company'] = ['name' =>$salesChannelContext->getCustomer()->getActiveBillingAddress()->getCompany(),
                    'contact' =>
                        ['firstName' => $this->stringFilter($salesChannelContext->getCustomer()->getFirstname()),
                         'lastName' => $this->stringFilter($salesChannelContext->getCustomer()->getLastname())]];
         }else {

            $data['checkout']['consumer']['privatePerson'] = ['firstName' => $this->stringFilter($salesChannelContext->getCustomer()->getFirstname()),
                'lastName' => $this->stringFilter($salesChannelContext->getCustomer()->getLastname())];
        }

        return $data;
    }

    /**
     * @param Struct $cartOrderEntityObject
     * @return array
     */
    private function getOrderItems(Struct $cartOrderEntityObject) {

        $items = [];

          foreach ($cartOrderEntityObject->getLineItems() as $item) {

                $taxes = $this->getRowTaxes($item->getPrice()->getCalculatedTaxes());

                if($cartOrderEntityObject instanceof Cart) {

                        $items[] = [
                            'reference' => $item->getId(),
                            'name' => $this->stringFilter($item->getLabel()),
                            'quantity' => $item->getQuantity(),
                            'unit' => 'pcs',
                            'unitPrice' => $this->prepareAmount($item->getPrice()->getUnitPrice() - $taxes['taxAmount']),
                            'taxRate' => $this->prepareAmount($taxes['taxRate']),
                            'taxAmount' => $this->prepareAmount($taxes['taxAmount']),
                            'grossTotalAmount' => $this->prepareAmount($item->getPrice()->getTotalPrice()),
                            'netTotalAmount' => $this->prepareAmount($item->getPrice()->getTotalPrice() - $taxes['taxAmount'])];

               }


                 if($cartOrderEntityObject instanceof OrderEntity) {

                      $items[] = [
                          'reference' => $item->getProductId(),
                          'name' => $this->stringFilter($item->getLabel()),
                          'quantity' => $item->getQuantity(),
                          'unit' => 'pcs',
                          'unitPrice' => $this->prepareAmount($item->getUnitPrice() - $taxes['taxAmount']),
                          'taxRate' => $this->prepareAmount($taxes['taxRate']),
                          'taxAmount' => $this->prepareAmount($taxes['taxAmount']),
                          'grossTotalAmount' => $this->prepareAmount($item->getTotalPrice()),
                          'netTotalAmount' => $this->prepareAmount($item->getTotalPrice() - $taxes['taxAmount'])];
                  }

        }

        $shippingCost =  $cartOrderEntityObject->getShippingCosts();

        if($shippingCost->getTotalPrice() > 0) {
            $items[] = $this->shippingCostLine($shippingCost);
        }
        return $items;
    }

    /**
     * @param OrderEntity $orderEntity
     * @param $amount
     * @return array
     */
    public function getTransactionOrderItems(OrderEntity $orderEntity, $amount) {
        if($amount == $orderEntity->getAmountTotal()) {
            $orderItems = $this->getOrderItems($orderEntity);
         } else {
            $orderItems = $this->getDummyOrderItem($this->prepareAmount($amount));
         }

         return ['amount' => $this->prepareAmount($amount),
                  'orderItems' => $orderItems
        ];
    }

    /**
     * @param CalculatedTaxCollection $calculatedTaxCollection
     * @return array
     */
    private function getRowTaxes(CalculatedTaxCollection $calculatedTaxCollection) {
        $taxAmount = 0;
        $taxRate = 0;
        foreach($calculatedTaxCollection as $calculatedTax) {
            $taxRate += $calculatedTax->getTaxRate();
            $taxAmount += $calculatedTax->getTax();
        }
        return ['taxRate' => $taxRate,
                'taxAmount' => $taxAmount];
    }

    /**
     * @param CalculatedPrice $cost
     * @return array
     */
    private function shippingCostLine(CalculatedPrice $cost) {
        return [
            'reference' => 'shipping',
            'name' => 'Shipping',
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => $this->prepareAmount($cost->getTotalPrice()),
            'taxRate' => 0,
            'taxAmount' => 0,
            'grossTotalAmount' => $this->prepareAmount($cost->getTotalPrice()),
            'netTotalAmount' => $this->prepareAmount( $cost->getTotalPrice() )
        ];
    }

    /**
     * @param $amount
     * @return int
     */
    private function prepareAmount($amount = 0) {
        return (int)round($amount * 100);
    }

    /**
     * @param $string
     * @return string
     */
    public function stringFilter($string = '') {
        $string = substr($string, 0, 128);
        return preg_replace(self::ALLOWED_CHARACTERS_PATTERN, '', $string);
    }

    /**
     * @param OrderEntity $orderEntity
     * @param $salesChannelContextId
     * @param Context $context
     * @param $paymentId
     * @param $amount
     * @return array
     */
    public function chargePayment(OrderEntity $orderEntity, $salesChannelContextId, Context $context, $paymentId, $amount) {
        $transaction = $orderEntity->getTransactions()->first();
        $environment = $this->configService->getEnvironment($salesChannelContextId);
        $secretKey = $this->configService->getSecretKey($salesChannelContextId);
        $this->easyApiService->setEnv($environment);
        $this->easyApiService->setAuthorizationKey($secretKey);

        $payload = $this->getTransactionOrderItems($orderEntity, $amount);

        $this->easyApiService->chargePayment($paymentId, json_encode($payload));

        $payment = $this->easyApiService->getPayment($paymentId);

        if($transaction->getStateMachineState()->getTechnicalName() != 'open') {
            $this->transactionStateHandler->reopen($transaction->getId(), $context);
        }

        if($this->prepareAmount($amount) == $payment->getOrderAmount()) {
            $this->transactionStateHandler->paid($transaction->getId(), $context);
        }else {
            $this->payPartially($transaction->getId(), $context);

        }
        return $payload;
    }

    /**
     * @param OrderEntity $orderEntity
     * @param $salesChannelContextId
     * @param Context $context
     * @param $paymentId
     * @param $amount
     * @return array
     * @throws EasyApiException
     */
    public function refundPayment(OrderEntity $orderEntity, $salesChannelContextId, Context $context, $paymentId, $amount) {
        $transaction = $orderEntity->getTransactions()->first();
        $environment = $this->configService->getEnvironment($salesChannelContextId);
        $secretKey = $this->configService->getSecretKey($salesChannelContextId);
        $this->easyApiService->setEnv($environment);
        $this->easyApiService->setAuthorizationKey($secretKey);
        $payment = $this->easyApiService->getPayment($paymentId);
        $chargeId = $payment->getFirstChargeId();
        $payload = $this->getTransactionOrderItems($orderEntity, $amount);
        $this->easyApiService->refundPayment($chargeId, json_encode($payload));
        $payment = $this->easyApiService->getPayment($paymentId);

        if($this->prepareAmount($amount) == $payment->getOrderAmount()) {
            $this->transactionStateHandler->refund($transaction->getId(), $context);
        }else {
           if($transaction->getStateMachineState()->getTechnicalName() == 'refunded_partially') {
                $this->transactionStateHandler->reopen($transaction->getId(), $context);
                $this->payPartially( $transaction->getId(), $context );
            }
            $this->transactionStateHandler->refundPartially($transaction->getId(), $context);
        }
        return $payload;
    }

    /**
     * @param OrderTransactionEntity $transaction
     * @param $context
     * @param array $fields
     */
    private function updateTransactionCustomFields(OrderTransactionEntity $transaction, $context ,$fields = []) {
        $customFields = $transaction->getCustomFields();
        $fields_arr = $customFields['nets_easy_payment_details'];
        $merged = array_merge($fields_arr, $fields);
        $customFields['nets_easy_payment_details'] = $merged;
        $update = [
            'id'           => $transaction->getId(),
            'customFields' => $customFields,
        ];
        $transaction->setCustomFields($customFields);
        $this->transactionRepository->update([$update], $context);
    }

    /**
     * @param $amount
     * @return array
     */
    private function getDummyOrderItem($amount) {
        $items = [];
        // Products
        $ref = 'item'. rand(1, 100);
        $items[] = [
            'reference' => $ref,
            'name' => $ref,
            'quantity' => 1,
            'unit' => 'pcs',
            'unitPrice' => $amount,
            'taxRate' => 0,
            'taxAmount' => 0,
            'grossTotalAmount' => $amount,
            'netTotalAmount' => $amount];
        return $items;
    }

    private function payPartially(string $transactionId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $transactionId,
                StateMachineTransitionActions::ACTION_PAY_PARTIALLY,
                'stateId'
            ),
            $context
        );
    }
}
