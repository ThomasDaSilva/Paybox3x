<?php

namespace Paybox3x\Listener;

use Paybox\Paybox;
use Paybox3x\Paybox3x;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserInterface;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;

class SendConfirmationEmailListener extends BaseAction implements EventSubscriberInterface
{
    public function __construct(
        protected ParserInterface $parser,
        protected MailerFactory $mailer,
        protected EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @return MailerFactory
     */
    public function getMailer(): MailerFactory
    {
        return $this->mailer;
    }

    /**
     * Checks if we are the payment module for the order, and if the order is paid,
     * then send a confirmation email to the customer.
     *
     * @param OrderEvent $event
     * @throws \Exception
     */
    public function updateOrderStatus(OrderEvent $event)
    {
        $paybox = new Paybox3x();

        if ($event->getOrder()->isPaid() && $paybox->isPaymentModuleFor($event->getOrder())) {
            $contact_email = ConfigQuery::read('store_email', false);

            Tlog::getInstance()->debug(
                "Order ".$event->getOrder()->getRef().": sending confirmation email from store contact e-mail $contact_email"
            );

            if ($contact_email) {
                $order = $event->getOrder();

                $this->getMailer()->sendEmailToCustomer(
                    Paybox3x::CONFIRMATION_MESSAGE_NAME,
                    $order->getCustomer(),
                    [
                        'order_id' => $order->getId(),
                        'order_ref' => $order->getRef()
                    ]
                );

                Tlog::getInstance()->debug("Order ".$order->getRef().": confirmation email sent to customer.");

                if (Paybox::getConfigValue('send_confirmation_email_on_successful_payment', false)) {
                    // Send now the order confirmation email to the customer
                    $this->eventDispatcher->dispatch($event, TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL);
                }
            }
        } else {
            Tlog::getInstance()->debug(
                "Order ".$event->getOrder()->getRef().": no confirmation email sent (order not paid, or not the proper payment module)."
            );
        }
    }

    /**
     * Send the confirmation message only if the order is paid.
     *
     * @param OrderEvent $event
     * @throws PropelException
     */
    public function checkSendOrderConfirmationMessageToCustomer(OrderEvent $event)
    {
        if (Paybox3x::getConfigValue('send_confirmation_email_on_successful_payment', false)) {
            $paybox = new Paybox3x();

            if ($paybox->isPaymentModuleFor($event->getOrder())) {
                if (!$event->getOrder()->isPaid()) {
                    $event->stopPropagation();
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return array(
            TheliaEvents::ORDER_UPDATE_STATUS => array("updateOrderStatus", 128),
            TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL => array("checkSendOrderConfirmationMessageToCustomer", 150)
        );
    }
}
