<?php
/**
 * Class InitializedCommand
 *
 * @author      Open Source Team
 * @copyright   2021 Pagar.me (https://pagar.me)
 * @license     https://pagar.me Copyright
 *
 * @link        https://pagar.me
 */

namespace Pagarme\Pagarme\Gateway\Transaction\Base\Command;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order\Payment;
use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Abstractions\AbstractPlatformOrderDecorator;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Services\OrderLogService;
use Mundipagg\Core\Kernel\Services\OrderService;
use Mundipagg\Core\Recurrence\Services\RecurrenceService;
use Mundipagg\Core\Recurrence\Services\SubscriptionService;
use Pagarme\Pagarme\Concrete\Magento2CoreSetup;
use Pagarme\Pagarme\Concrete\Magento2PlatformPaymentMethodDecorator;
use Pagarme\Pagarme\Model\Ui\CreditCard\ConfigProvider;
use Pagarme\Pagarme\Model\Ui\TwoCreditCard\ConfigProvider as TwoCreditCardConfigProvider;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as M2WebApiException;
use Pagarme\Pagarme\Helper\RecurrenceProductHelper;

class InitializeCommand implements CommandInterface
{
    /**
     * @param array $commandSubject
     * @return $this
     */
    public function execute(array $commandSubject)
    {
        /** @var \Magento\Framework\DataObject $stateObject */
        $stateObject = $commandSubject['stateObject'];
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof Payment) {
            throw new \LogicException('Order Payment should be provided');
        }
        $orderResult = $this->doCoreDetour($payment);
        if ($orderResult !== false) {
            $orderResult->loadByIncrementId(
                $orderResult->getIncrementId()
            );

            $stateObject->setData(
                OrderInterface::STATE,
                $orderResult->getState()->getState()
            );
            $stateObject->setData(
                OrderInterface::STATUS,
                $orderResult->getStatus()
            );
            return $this;
        }

        $payment->getOrder()->setCanSendNewEmailFlag(true);
        $baseTotalDue = $payment->getOrder()->getBaseTotalDue();
        $totalDue = $payment->getOrder()->getTotalDue();
        $payment->authorize(true, $baseTotalDue);
        $payment->setAmountAuthorized($totalDue);
        $payment->setBaseAmountAuthorized($payment->getOrder()->getBaseTotalDue());
        $customStatus = $payment->getData('custom_status');

        $stateObject->setData(OrderInterface::STATE, Order::STATE_PENDING_PAYMENT);

        if ($payment->getMethod() === ConfigProvider::CODE || $payment->getMethod() === TwoCreditCardConfigProvider::CODE) {
            $stateObject->setData(OrderInterface::STATE, $customStatus->getData('state'));
            $stateObject->setData(OrderInterface::STATUS, $customStatus->getData('status'));
        }

        if ($payment->getMethod() != ConfigProvider::CODE) {
            $stateObject->setData(OrderInterface::STATUS, $payment->getMethodInstance()->getConfigData('order_status'));
        }

        $stateObject->setData('is_notified', false);

        return $this;
    }

     /** @return AbstractPlatformOrderDecorator */
    private function doCoreDetour($payment)
    {
        $order =  $payment->getOrder();

        $log = new OrderLogService();

        Magento2CoreSetup::bootstrap();

        $platformOrderDecoratorClass = MPSetup::get(
            MPSetup::CONCRETE_PLATFORM_ORDER_DECORATOR_CLASS
        );

        $platformPaymentMethodDecoratorClass = MPSetup::get(
            MPSetup::CONCRETE_PLATFORM_PAYMENT_METHOD_DECORATOR_CLASS
        );

        /** @var PlatformOrderInterface $orderDecorator */
        $orderDecorator = new $platformOrderDecoratorClass();
        $orderDecorator->setPlatformOrder($order);

        $paymentMethodDecorator = new $platformPaymentMethodDecoratorClass();
        $paymentMethodDecorator->setPaymentMethod($orderDecorator);

        $orderDecorator->setPaymentMethod($paymentMethodDecorator->getPaymentMethod());

        $quote = $orderDecorator->getQuote();

        try {
            $quoteSuccess = $quote->getCustomerNote();
            if ($quoteSuccess === 'pagarme-processing') {
                $log->orderInfo(
                    $orderDecorator->getCode(),
                    "Quote already used, order id duplicated. Customer Note: {$quoteSuccess}"
                );
                throw new \Exception("Quote already used, order id duplicated.");
            }

            $quote->setCustomerNote('pagarme-processing');
            $quote->save();

            $log->orderInfo(
                $orderDecorator->getCode(),
                "Changing status quote to processing."
            );

            $subscriptionService = new SubscriptionService();
            $isSubscription = $subscriptionService->isSubscription($orderDecorator);

            if ($isSubscription) {
                $subscriptionService->createSubscriptionAtMundipagg($orderDecorator);
            }

            if (!$isSubscription) {
                $orderService = new OrderService();
                $orderService->createOrderAtMundipagg($orderDecorator);
            }

            $orderDecorator->save();

            return $orderDecorator;
        } catch (\Exception $e) {

            $quote->setCustomerNote('');
            $quote->save();

            $message = "Order failed, changing status quote to failed. \n";
            $message .= "Error message: " . $e->getMessage();
            $log->orderInfo(
                $orderDecorator->getCode(),
                $message
            );

            throw new M2WebApiException(
                new Phrase($e->getMessage()),
                0,
                $e->getCode()
            );
        }
    }
}
