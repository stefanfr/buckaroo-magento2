<?php

/**
 *                  ___________       __            __
 *                  \__    ___/____ _/  |_ _____   |  |
 *                    |    |  /  _ \\   __\\__  \  |  |
 *                    |    | |  |_| ||  |   / __ \_|  |__
 *                    |____|  \____/ |__|  (____  /|____/
 *                                              \/
 *          ___          __                                   __
 *         |   |  ____ _/  |_   ____ _______   ____    ____ _/  |_
 *         |   | /    \\   __\_/ __ \\_  __ \ /    \ _/ __ \\   __\
 *         |   ||   |  \|  |  \  ___/ |  | \/|   |  \\  ___/ |  |
 *         |___||___|  /|__|   \_____>|__|   |___|  / \_____>|__|
 *                  \/                           \/
 *                  ________
 *                 /  _____/_______   ____   __ __ ______
 *                /   \  ___\_  __ \ /  _ \ |  |  \\____ \
 *                \    \_\  \|  | \/|  |_| ||  |  /|  |_| |
 *                 \______  /|__|    \____/ |____/ |   __/
 *                        \/                       |__|
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright Copyright (c) 2015 Total Internet Group B.V. (http://www.tig.nl)
 * @license   http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */

namespace TIG\Buckaroo\Gateway\Http\TransactionBuilder;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use TIG\Buckaroo\Model\ConfigProvider\Account;
use TIG\Buckaroo\Model\ConfigProvider\Method\Factory;

class Refund extends AbstractTransactionBuilder
{
    /** @var UrlInterface */
    protected $urlBuilder;

    /** @var RemoteAddress */
    protected $remoteAddress;

    /** @var Factory */
    protected $configProviderMethodFactory;

    /**
     * @param ProductMetadataInterface $productMetadata
     * @param ModuleListInterface      $moduleList
     * @param Account                  $configProviderAccount
     * @param ObjectManagerInterface   $objectManager
     * @param UrlInterface             $urlBuilder
     * @param RemoteAddress            $remoteAddress
     * @param Factory                  $configProviderMethodFactory
     * @param null                     $amount
     * @param null                     $currency
     */
    public function __construct(
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        Account $configProviderAccount,
        ObjectManagerInterface $objectManager,
        UrlInterface $urlBuilder,
        RemoteAddress $remoteAddress,
        Factory $configProviderMethodFactory,
        $amount = null,
        $currency = null
    ) {
        parent::__construct($productMetadata, $moduleList, $configProviderAccount, $objectManager, $amount, $currency);

        $this->urlBuilder = $urlBuilder;
        $this->remoteAddress = $remoteAddress;
        $this->configProviderMethodFactory = $configProviderMethodFactory;
    }

    /**
     * @throws \TIG\Buckaroo\Exception
     */
    protected function setRefundCurrencyAndAmount()
    {
        /**
         * @var \TIG\Buckaroo\Model\Method\AbstractMethod $methodInstance
         */
        $methodInstance = $this->order->getPayment()->getMethodInstance();
        $method = $methodInstance->buckarooPaymentMethodCode;

        $configProvider = $this->configProviderMethodFactory->get($method);
        $allowedCurrencies = $configProvider->getAllowedCurrencies();

        if (in_array($this->order->getOrderCurrencyCode(), $allowedCurrencies)) {
            /**
             * @todo find a way to fix the cumulative rounding issue that occurs in creditmemos.
             *       This problem occurs when the creditmemo is being refunded in the order's currency, rather than the
             *       store's base currency.
             */
            $this->setCurrency($this->order->getOrderCurrencyCode());
            $this->setAmount(round($this->getAmount() * $this->order->getBaseToOrderRate(), 2));
        } elseif (in_array($this->order->getBaseCurrencyCode(), $allowedCurrencies)) {
            $this->setCurrency($this->order->getBaseCurrencyCode());
        } else {
            throw new \TIG\Buckaroo\Exception(
                __("The selected payment method does not support the selected currency or the store's base currency.")
            );
        }
    }

    /**
     * @return array
     */
    public function getBody()
    {
        if (!$this->getCurrency()) {
            $this->setRefundCurrencyAndAmount();
        }

        $order = $this->getOrder();

        $ip = $order->getRemoteIp();
        if (!$ip) {
            $ip = $this->remoteAddress->getRemoteAddress();
        }

        $processUrl = $this->urlBuilder->getRouteUrl('buckaroo/redirect/process');

        $body = [
            'Currency' => $this->getCurrency(),
            'AmountDebit' => 0,
            'AmountCredit' => $this->getAmount(),
            'Invoice' => $this->getInvoiceId(),
            'Order' => $order->getIncrementId(),
            'Description' => $this->configProviderAccount->getTransactionLabel(),
            'ClientIP' => (object)[
                '_' => $ip,
                'Type' => strpos($ip, ':') === false ? 'IPv4' : 'IPv6',
            ],
            'ReturnURL' => $processUrl,
            'ReturnURLCancel' => $processUrl,
            'ReturnURLError' => $processUrl,
            'ReturnURLReject' => $processUrl,
            'OriginalTransactionKey' => $this->originalTransactionKey,
            'StartRecurrent' => $this->startRecurrent,
            'PushURL' => $this->urlBuilder->getDirectUrl('rest/V1/buckaroo/push'),
            'Services' => (object)[
                'Service' => $this->getServices()
            ],
        ];

        return $body;
    }
}
