<?php
/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace Paybox3x;

use Propel\Runtime\Connection\ConnectionInterface;
use Paybox\Paybox;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;

class Paybox3x extends Paybox
{
    /** @var string */
    const DOMAIN_NAME = 'paybox3x';

    /** The module domain for internationalisation */
    const MODULE_DOMAIN = "paybox3x";

    /** The module domain for internationalisation */
    const MODULE_CODE = "paybox3x";

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'paybox3x_payment_confirmation';

    /** The notification of payment confirmation */
    const NOTIFICATION_MESSAGE_NAME = 'paybox3x_payment_status_notification';

    const CONFIG_KEY_INTERVAL = 'day_interval';
    const CONFIG_DEFAULT_VALUE_INTERVAL = '30';

    const CONFIG_KEY_MINIMAL_AMOUNT = 'minimal_amount';
    const CONFIG_DEFAULT_VALUE_MINIMAL_AMOUNT = '200';

    /**
     * @inheritdoc
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        if (null === static::getConfigValue(self::CONFIG_KEY_INTERVAL, null)) {
            static::setConfigValue(self::CONFIG_KEY_INTERVAL, self::CONFIG_DEFAULT_VALUE_INTERVAL);
        }

        if (null === static::getConfigValue(self::CONFIG_KEY_MINIMAL_AMOUNT, null)) {
            static::setConfigValue(self::CONFIG_KEY_MINIMAL_AMOUNT, self::CONFIG_DEFAULT_VALUE_MINIMAL_AMOUNT);
        }

        // Create payment confirmation message from templates, if not already defined
        $emailTemplatesDir = __DIR__ . DS . 'I18n' . DS . 'email-templates' . DS;

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            (new Message())
                ->setName(self::CONFIRMATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox 3x payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setHtmlMessage(file_get_contents($emailTemplatesDir . 'en.html'))
                ->setTextMessage(file_get_contents($emailTemplatesDir . 'en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par PayBox 3x')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->setHtmlMessage(file_get_contents($emailTemplatesDir . 'fr.html'))
                ->setTextMessage(file_get_contents($emailTemplatesDir . 'fr.txt'))
                ->save();
        }

        if (null === MessageQuery::create()->findOneByName(self::NOTIFICATION_MESSAGE_NAME)) {
            (new Message())
                ->setName(self::NOTIFICATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox payment status notification')
                ->setSubject('Paybox payment status for order {$order_ref}: {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($emailTemplatesDir . 'notification-en.html'))
                ->setTextMessage(file_get_contents($emailTemplatesDir . 'notification-en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Notification du résultat d\'un paiement par Paybox')
                ->setSubject('Résultats du paiement Paybox de la commande {$order_ref} : {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($emailTemplatesDir . 'notification-fr.html'))
                ->setTextMessage(file_get_contents($emailTemplatesDir . 'notification-fr.txt'))
                ->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s'.DS.'images', __DIR__), $con);
        }
    }

    /**
     * Check if total order amount is in the module's limits
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount()
    {
        $minAmount = (float) static::getConfigValue(self::CONFIG_KEY_MINIMAL_AMOUNT, self::CONFIG_DEFAULT_VALUE_MINIMAL_AMOUNT);

        // Check if total order amount is in the module's limits
        $orderTotal = $this->getCurrentOrderTotalAmount();

        return $orderTotal > $minAmount;
    }

    protected function doPayPayboxParameters(Order $order)
    {
        $params = array_merge(
            parent::doPayPayboxParameters($order),
            static::generateMultiParameters($order->getTotalAmount())
        );

        return $params;
    }

    public static function generateMultiParameters($amount)
    {
        $days = (int) static::getConfigValue(self::CONFIG_KEY_INTERVAL, self::CONFIG_DEFAULT_VALUE_INTERVAL);

        $amount = (int) round($amount * 100);

        $first = (int) round($amount / 3);
        $second = (int) round(($amount - $first) / 2);
        $last = (int) $amount - $first - $second;

        return [
            'PBX_TOTAL' => $first,
            'PBX_DATE1' => (new \DateTime())->add(new \DateInterval('P' . $days . 'D'))->format('d/m/Y'),
            'PBX_2MONT1' => $second,
            'PBX_DATE2' => (new \DateTime())->add(new \DateInterval('P' . ($days * 2) . 'D'))->format('d/m/Y'),
            'PBX_2MONT2' => $last,
        ];
    }
}
