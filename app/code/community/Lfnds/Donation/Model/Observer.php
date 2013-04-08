<?php

/**
 * elefunds Magento Module
 *
 * Copyright (c) 2012, elefunds GmbH <hello@elefunds.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of the elefunds GmbH nor the names of its
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * Watches for events that are interacting with the elefunds module.
 *
 * @package    elefunds Magento Module
 * @subpackage Model
 * @author     Raul Armando Salamanca Gonzalez <raul.salamanca@gmx.de>, Christian Peters <christian@elefunds.de>
 * @copyright  2012 elefunds GmbH <hello@elefunds.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://www.elefunds.de
 * @since      File available since Release 1.0.0
 */
class Lfnds_Donation_Model_Observer
{

    /**
     * @var Lfnds_Donation_Helper_Data
     */
    protected $helper;

    public function __construct() {
        $this->helper = Mage::helper('lfnds_donation');
    }

    /**
     * Adds the virtual product to the quote, so it's in included in the
     * processed order on save order after.
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function onPreDispatchSaveOrder(Varien_Event_Observer $observer) {

        $params = Mage::app()->getRequest()->getParams();
        $elefundsVariables = $this->getElefundsVariablesFromRequestParams($params);

        if (count($elefundsVariables) > 0) {

            /** @var Mage_Checkout_Model_Session $checkoutSession  */
            $checkoutSession = Mage::getSingleton('checkout/session');
            $quote = $checkoutSession->getQuote();

            /** @var Mage_Sales_Model_Quote_Item $elefundsProduct  */
            $elefundsProduct = $this->helper->getVirtualProduct();

            if ($elefundsProduct === NULL) {
                Mage::log('Elefunds object not found on store!');
                return;
            }

            if ($quote->hasProductId($elefundsProduct->getId())) {
                return;
            }

            $elefundsProduct->setPrice($elefundsVariables['roundup'] / 100);
            $elefundsProduct->setBasePrice($elefundsVariables['roundup'] / 100);
            $quote->addProduct($elefundsProduct);
        }
    }

    /**
     * Dispatched after the order is saved.
     *
     * Manipulates the quote with an elefunds virtual product.
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function onSaveOrderAfter(Varien_Event_Observer $observer) {

        $params = Mage::app()->getRequest()->getParams();
        $elefundsVariables = $this->getElefundsVariablesFromRequestParams($params);

        if(count($elefundsVariables) > 0) {

            /* @var $order Mage_Sales_Model_Order */
            $order = $observer->getEvent()->getOrder();

            if ($elefundsVariables['isReceiptRequested']) {
                $billingAddress = $order->getBillingAddress();
                $streets = $billingAddress->getStreet(); // 5.3 compliant lazy array access
                $user = array(
                    'firstName'      =>  $billingAddress->getFirstname() ? $billingAddress->getFirstname() : '',
                    'lastName'       =>  $billingAddress->getLastname() ? $billingAddress->getLastname() : $order->getCustomerName(),
                    'email'          =>  $billingAddress->getEmail(),
                    'streetAddress'  =>  $streets[0],
                    'zip'            =>  (int)$billingAddress->getPostcode(),
                    'city'           =>   $billingAddress->getCity()
                );
            } else {
                $user = array();
            }

            /** @var Lfnds_Donation_Model_Mysql4_Donation_Collection $donationCollection  */
            $donationCollection = Mage::getModel('lfnds_donation/donation')->getCollection();
            $donationCollection->addDonation(
                $order->getIncrementId(),
                $elefundsVariables['roundup'],
                $order->getTotalDue() * 100,
                $elefundsVariables['receiverIds'],
                $this->helper->getAvailableReceiverIds(),
                $user,
                $order->getBillingAddress()->getCountryId(),
                $elefundsVariables['suggestedRoundUp'],
                Lfnds_Donation_Model_Donation::NEW_ORDER
            );
        }
    }

    /**
     * Dispatched when the order is finished.
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function onOrderFinished(Varien_Event_Observer $observer)
    {
        $orderIds = $observer->getEvent()->getOrderIds();

        $orderId = 0;
        if (is_array($orderIds) && !empty($orderIds)) {
            $orderId = (int)$orderIds[0];
        }

        /* @var $order Mage_Sales_Model_Order */
        $order = Mage::getModel('sales/order')->load($orderId);

        /** @var Mage_Sales_Model_Quote_Item $elefundsProduct  */
        $elefundsProduct = $this->helper->getVirtualProduct();

        if ($elefundsProduct !== NULL || $order->getItemByQuoteItemId($elefundsProduct->getQuoteId()) !== NULL) {

            $donation = Mage::getModel('lfnds_donation/donation');
            $donation->loadByAttribute('foreign_id', $order->getIncrementId());

            if ($donation !== NULL && $donation->getId()) {
                $donation->setState(Lfnds_Donation_Model_Donation::SCHEDULED_FOR_ADDING);
                $donation->save();
            }

            $this->helper->getSyncManager()->syncDonations();
        }
    }

    /**
     * Whenever an order is saved, we check if it contains a donation and if the status change is of
     * interest for the API. If so, we invoke the sync process.
     *
     * @param Varien_Event_Observer $observer
     * @return void
     */
    public function onOrderSaved(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getOrder();

        $newState = $order->getData('state');
        $oldState = $order->getOrigData('state');

        if ($newState === NULL || $oldState === NULL) {
            return;
        }

        /** @var Lfnds_Donation_Model_Donation $donation  */
        $donation = Mage::getModel('lfnds_donation/donation');
        $donation->loadByAttribute('foreign_id', $order->getIncrementId());

        if ($donation !== NULL) {
            $stateHasChanged = $newState !== $oldState;

            if ($stateHasChanged) {

                // We have to map the magento states to API states ...
                $statesToBeMappedToAddingState = array(
                    Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                );

                $statesToBeMappedToCancelledState = array(
                    Mage_Sales_Model_Order::STATE_CANCELED
                );

                $statesToBeMappedToCompletionState = array(
                    Mage_Sales_Model_Order::STATE_CLOSED,
                    Mage_Sales_Model_Order::STATE_COMPLETE
                );

                $stateToBySyncedToTheApi = -1;

                if (in_array($newState, $statesToBeMappedToAddingState)) {
                    $stateToBySyncedToTheApi = Lfnds_Donation_Model_Donation::SCHEDULED_FOR_ADDING;
                }
                if (in_array($newState, $statesToBeMappedToCancelledState)) {
                    $stateToBySyncedToTheApi = Lfnds_Donation_Model_Donation::SCHEDULED_FOR_CANCELLATION;
                }
                if (in_array($newState, $statesToBeMappedToCompletionState)) {
                    $stateToBySyncedToTheApi = Lfnds_Donation_Model_Donation::SCHEDULED_FOR_COMPLETION;
                }

                if ($stateToBySyncedToTheApi > Lfnds_Donation_Model_Donation::NEW_ORDER) {
                    if ($donation->getState() !== $stateToBySyncedToTheApi) {
                        $donation->setState($stateToBySyncedToTheApi);
                        $donation->save();
                        $this->helper->getSyncManager()->syncDonations();
                    }
                }
            }
        }
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function limitPayments(Varien_Event_Observer $observer) {
        /**  @var Lfnds_Donation_Block_Checkout_Banner $block */
        $block = $observer->getEvent()->getObject();

        try {
            $paymentCode = Mage::getSingleton('checkout/session')->getQuote()
                ->getPayment()
                ->getMethodInstance()
                ->getCode();
        } catch (Exception $exception) {
            // This exception is thrown, when the merchant uses some kind of one step checkout
            // (where no payment has yet been set).
            // If so, check if you have enabled the one step checkout in the settings.
            // If it's a one step checkout that we do not support,
            // we silently bypass the module, as the shop would crash otherwise.
            Mage::log('We could not get a default payment and are deactivating the banner.');
        }

        $excludedMethods = $this->helper->getExcludedPaymentMethods();
        if (in_array($paymentCode, $excludedMethods)) {
            $block->deactivateBanner();
        }
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function excludeDonationFromDiscount(Varien_Event_Observer $observer){
        $quoteItem = $observer->getQuoteItem();
        $product = $observer->getProduct();
        if ($product->getSku() == Lfnds_Donation_Model_Donation::ELEFUNDS_VIRTUAL_PRODUCT_SKU) {
            $quoteItem->setNoDiscount(TRUE);
        }
    }

    /**
     * @param array $params
     * @return array
     */
    protected function getElefundsVariablesFromRequestParams(array $params) {

        $elefundsVariables = array();


        if ($this->helper->isActive() && isset($params['elefunds_checkbox']) && isset($params['elefunds_donation_cent']) && ctype_digit($params['elefunds_donation_cent'])) {

            /** +++ Input Validation +++ */

            $elefundsVariables['roundup']  = (int)$params['elefunds_donation_cent'];
            $elefundsVariables['receiverIds'] = array_map(function($x) { return (int)$x; }, $params['elefunds_receiver']);

            if (isset($params['elefunds_suggested_round_up_cent']) && ctype_digit($params['elefunds_suggested_round_up_cent'])) {
                $elefundsVariables['suggestedRoundUp'] = (int)$params['elefunds_suggested_round_up_cent'];
            } else {
                $elefundsVariables['suggestedRoundUp'] = 0;
            }

            $elefundsVariables['isReceiptRequested'] = isset($params['elefunds_receipt_input']);

            /** +++ Input Validation +++ */

        }

        return $elefundsVariables;
    }

}

