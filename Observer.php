<?php
/**
 *
 * Do not edit or add to this file if you wish to upgrade the module to newer
 * versions in the future. If you wish to customize the module for your
 * needs please contact us to https://www.milople.com/contact-us.html
 *
 * @category     Ecommerce
 * @package      Milople_Rsp
 * @copyright    Copyright (c) 2017 Milople Technologies Pvt. Ltd. All Rights Reserved.
 * @url          https://www.milople.com/magento-extensions/recurring-and-subscription-payments.html
 *
 * Milople was known as Indies Services earlier.
 *
 **/

class Milople_Rsp_Model_Observer extends Mage_Core_Model_Config_Data
{
  
	/**
	 * Asssign selected term and its price to product 
	*/
   public function AddToCartAfter(Varien_Event_Observer $observer)
   {
      $item = $observer->getQuoteItem();
      if ($item->getParentItem())
      {
         $item = $item->getParentItem();
      }
      $postdata = Mage::app()->getRequest()->getPost();
      $cartItem = $observer->getEvent()->getQuoteItem();
      
	  if (empty($postdata))
      {
         $infoBuyRequest = $item->getOptionByCode('info_buyRequest');
         $buyInfo_custom = new Varien_Object(unserialize($infoBuyRequest->getValue()));
         $postdata = $buyInfo_custom->getData();
      }
      $buyInfo = $cartItem->getBuyRequest();
      $product = $cartItem->getProduct();
      $idForgroup = $buyInfo->getProduct();
      
	  if (empty($idForgroup)) // This is for grouped product
      {
         $product_id = $product->getId();
      }
      else
      {
         $product_id = $buyInfo->getProduct();
      }
      $plans_product = Mage::getModel('rsp/plans_product')->load($product_id, 'product_id');
      $additionaprice = Mage::getModel('rsp/plans')->load($plans_product->getPlanId(), 'plan_id');
    
	  if ($additionaprice->getData())
      {
         if ($postdata['milople_rsp_type'])
         {
            if ($postdata['milople_rsp_type'] > 0)
            {
               if (isset($postdata['bundle_option']))
               {
                  $this->calculateBundleItemPrice($postdata, $item);
                  $price = Mage::helper('rsp')->calculateParentBundlePrice($postdata, $item);
               }
               else
               {
                  if ($item->getProduct()->getFirstPeriodPrice() > 0)
                  {
                     $price = $item->getProduct()->getFirstPeriodPrice();
                  }
                  else
                  {
                     $termid = $postdata['milople_rsp_type'];
                     $qty = $buyInfo->getQty();
                     $productId = $buyInfo->getProduct();
                     $types = Mage::getModel('rsp/terms')->load($termid);
                     $price = $types->getPrice();
           			 $product_price = ($item->getProduct()->getSpecialPrice())?$item->getProduct()->getSpecialPrice():$item->getProduct()->getPrice();
					 $custom_option_price = $item->getProduct()->getFinalPrice() - $item->getProduct()->getPrice();
                     /* Put condition for a case when special price is applied to product. */
                     if ($custom_option_price < 0)
                     {
                        $custom_option_price = 0;
                     }
                     if ($types->getPriceCalculationType() == 1)
                     {
                        $price = $product_price * $types->getPrice() / 100 + $custom_option_price;
                     }
                     else
                     {
                        $price = $types->getPrice() + $custom_option_price;
                     }
                  }
               }
               $price = Mage::helper('directory')->currencyConvert($price, Mage::app()->getStore()->getBaseCurrencyCode(), Mage::app()->getStore()->getCurrentCurrencyCode());
               
				/** 
			    * Set the custom prices
	 			    * Add this condition because if customer add 2nd time configured product 
                * with same option which he added previously that time,quatinty have to 
                * increase but that is not increasing.Thats why this condiging 
                * we have put 
			   */
               if ($item->getProductType() == 'configurable')
               {
                  $item->setCustomPrice($price);
                  $item->setOriginalCustomPrice($price);
                  $item = $observer->getQuoteItem();
                  $item->setQty($postdata['qty']);
               }
               else
               {
                  $item->setCustomPrice($price);
                  $item->setOriginalCustomPrice($price);
               }
               /**
			    * Enable super mode on the product.
				*/
               $item->getProduct()->setIsSuperMode(true);
               if ($additionaprice->getStartDate() == 1)
               {
                  if ($postdata['milople_rsp_start'])
                  {
                     if (strtotime($postdata['milople_rsp_start']) < time())
                     {
                        $buyInfo->setMilopleRspStart(date('m-d-Y'));
                     }
                  }
               }
            }
            else
            {
				return;
            }
         }
         else
         {
            if ($additionaprice->getPlanStatus() != 2)
            {
               Mage::getSingleton('checkout/session')->addNotice('Please specify the products option(s)');
               $response = Mage::app()->getFrontController()->getResponse();
               $response->setRedirect($product->getProductUrl());
               $response->sendResponse();
               return;
            }
            elseif (Mage::getStoreConfig(Milople_Rsp_Helper_Config::XML_PATH_GENERAL_ANONYMOUS_SUBSCRIPTIONS) == 2)
            {
               return;
            }
            else // If plan is Disable
            {
               return;
            }
         }
      }
   }
   
   public function salesOrderItemSaveAfter($observer)
    {
	    $orderItem = $observer->getEvent()->getItem();
        $product = Mage::getModel('catalog/product')
                ->setStoreId($orderItem->getOrder()->getStoreId())
                ->load($orderItem->getProductId());
		if (method_exists($product->getTypeInstance(), 'prepareOrderItemSave'))
		{
			$product->getTypeInstance()->prepareOrderItemSave($product, $orderItem);
		}
    }
	
   /**
    * Update selected term and its price for product 
	*/
   public function CheckoutCartUpdateItemComplete(Varien_Event_Observer $observer)
   {
      $postdata = Mage::app()->getRequest()->getPost();
      $cartItem = $observer->getEvent()->getItem();
      $buyInfo = $cartItem->getBuyRequest();
      $product = $cartItem->getProduct();
      $plans_product = Mage::getModel('rsp/plans_product')->load($buyInfo->getProduct(), 'product_id');
      $additionaprice = Mage::getModel('rsp/plans')->load($plans_product->getPlanId(), 'plan_id');
      
	   /**
	   * fatch value of selected configured product for add final product price
	   */
      $allAttributes = '';
      try
      {
         if (isset($postdata['super_attribute']))
         {
            $allAttributes = $postdata['super_attribute'];
         }
      }
      catch (Exception $e)
      {
         throw $e;
      }
      $productID = $buyInfo->getProduct(); //Replace with your method to get your product Id.
      $product = Mage::getModel('catalog/product')->load($productID);
      $original_qty = 0;
      $original_qty += $buyInfo->getQty(); //$postdata['qty'];
      
	   /**
	   * Calculate selected Configured product's Price
	   */
      $configure_price = 0;
      if ($product->getTypeID() == 'configurable')
      {
         $productAttributeOptions = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);
         $attributeOptions = array();
         foreach ($productAttributeOptions as $productAttribute)
         {
            foreach ($productAttribute['values'] as $attribute)
            {
               if (in_array($attribute['value_index'], $allAttributes))
               {
                  $configure_price += $attribute['pricing_value'];
               }
            }
         }
      }
      $quote = Mage::helper('checkout/cart')->getCart()->getQuote();
      $attributePrice = 0;
      /**
	   * Calculate Custom Options Price 
	   */
      foreach ($quote->getItemsCollection() as $item)
      {
         if ($item->getId() == $cartItem->getId())
         {
            if ($optionIds = $item->getProduct()->getCustomOption('option_ids'))
            {
               $attributePrice = 0;
               foreach (explode(',', $optionIds->getValue()) as $optionId)
               {
                  if ($option = $item->getProduct()->getOptionById($optionId))
                  {
                     $confItemOption = $item->getProduct()->getCustomOption('option_' . $option->getId());
                     $group = $option->groupFactory($option->getType())->setOption($option)->setConfigurationItemOption($confItemOption);
                     $attributePrice += $group->getOptionPrice($confItemOption->getValue(), 0);
                  }
               }
            }
         }
      }
      $final_extra_price = $attributePrice + $configure_price;
      /**
	   * pricing of attribue finish 
	   */
      if ($additionaprice->getData())
      {
         if ($buyInfo->getMilopleRspType())
         {
            if ($buyInfo->getMilopleRspType() >= 0)
            {
               if ($postdata['bundle_option'])
               {
                  $this->calculateUpdateBundleItemPrice($postdata, $cartItem);
                  $price = Mage::helper('rsp')->calculateParentBundlePrice($postdata, $cartItem);
               }
               elseif ($product->getFirstPeriodPrice() > 0)
               {
                  $price = $product->getFirstPeriodPrice();
               }
               else
               {
                  $termid = $buyInfo->getMilopleRspType();
                  $qty = $buyInfo->getQty();
                  $productId = $buyInfo->getProduct();
                  $types = Mage::getModel('rsp/terms')->load($buyInfo->getMilopleRspType());
                  $termprice = $types->getPrice();
				  $product_price = ($product->getSpecialPrice())?$product->getSpecialPrice():$product->getPrice(); 
                  if ($types->getPriceCalculationType() == 1)
                  {
                     $termprice = $product_price * $types->getPrice() / 100;
                  }
                  $price = $termprice + $final_extra_price;
               }
               $price = Mage::helper('directory')->currencyConvert($price, Mage::app()->getStore()->getBaseCurrencyCode(), Mage::app()->getStore()->getCurrentCurrencyCode());
               // Get the quote item
               $item = $observer->getItem();
               // Ensure we have the parent item, if it has one
               $item = ($item->getParentItem() ? $item->getParentItem() : $item);
               // Set the custom price
               $item->setCustomPrice($price);
               $item->setOriginalCustomPrice($price);
               $item->save();
               // Enable super mode on the product.
               $item->getProduct()->setIsSuperMode(true);
               if ($additionaprice->getStartDate() == 1)
               {
                  if ($buyInfo->getMilopleRspStart())
                  {
                     if (strtotime($buyInfo->getMilopleRspStart()) < time())
                     {
                        $buyInfo->setMilopleRspStart(date('m-d-Y'));
                     }
                  }
               }
            }
            else
            {
               // Get the quote item
               $item = $observer->getItem();
               // Ensure we have the parent item, if it has one
               $item = ($item->getParentItem() ? $item->getParentItem() : $item);
               $product = Mage::getModel('catalog/product')->load($item->getProduct()->getId());
               // Set the custom price
               $item->setQty($postdata['qty']);
               $item->setCustomPrice(($product->getPrice() + $attributePrice)); //added value of custom option
               $item->setOriginalCustomPrice(($product->getPrice() + $attributePrice)); //added value of custom option
               $item->save();
               // Enable super mode on the product.
               $item->getProduct()->setIsSuperMode(true);
            }
         }
         else
         {
            if ($additionaprice->getPlanStatus() != 2)
            {
               Mage::getSingleton('checkout/session')->addNotice('Please specify the products option(s)');
               $response = Mage::app()->getFrontController()->getResponse();
               $response->setRedirect($product->getProductUrl());
               $response->sendResponse();
               return;
            }
            else // If plan is Disable 
            {
               return;
            }
         }
      }
   }
   
   /**
    * Save Payment info in session
   */
   public function savePaymentInfoInSession($observer)
   {
      try
      {
         if (!Milople_Rsp_Model_Subscription::isIterating())
         {
            $quote = $observer->getEvent()->getQuote();
            if (!$quote->getPaymentsCollection()->count())
               return;
            $Payment = $quote->getPayment();
            if ($Payment && $Payment->getMethod())
            {
               if ($Payment->getMethodInstance() instanceof Mage_Payment_Model_Method_Cc)
               {
                  // Credit Card number
                  if ($Payment->getMethodInstance()->getInfoInstance() && ($ccNumber = $Payment->getMethodInstance()->getInfoInstance()->getCcNumber()))
                  {
					 
                     $ccCid = $Payment->getMethodInstance()->getInfoInstance()->getCcCid();
                     $ccType = $Payment->getMethodInstance()->getInfoInstance()->getCcType();
                     $ccExpMonth = $Payment->getMethodInstance()->getInfoInstance()->getCcExpMonth();
                     $ccExpYear = $Payment->getMethodInstance()->getInfoInstance()->getCcExpYear();
                     Mage::getSingleton('customer/session')->setrspCcNumber($ccNumber);
                     Mage::getSingleton('customer/session')->setrspCcCid($ccCid);
                  }
               }
            }
         }
      }
      catch (Exception $e)
      {
         //throw($e);
      }
   }

   /**
    * Assigns subscription of product to current user
    * @param object $observer
    * @return
    */
   public function assignSubscriptionToCustomer($observer)
   {
      $order = $observer->getEvent()->getOrder();
      $quote = $observer->getEvent()->getQuote();
      //$this->setRecurringOrderInfo($order, $quote);
	  $helper = Mage::helper('rsp');
	  $helper->createSubscription($order, $quote);  
   }
	
   /**
    * Assign subscription of product to current user when he choose 
    * Paypal express for checkout
    */
   public function paypalExpressCheckout($observer)
   {
      $order = $observer->getEvent()->getOrder();
      if ($order->getPayment()->getMethod() == "paypal_express")
      {
         $store_id = Mage::getSingleton('core/store')->load($order->getStoreId());
         $quote = Mage::getModel('sales/quote')->setStore($store_id)->load($order->getQuoteId());
		 $helper = Mage::helper('rsp');
		 $helper->createSubscription($order, $quote);
      }
   }
	
   /**
    * Returns product SKU for specified options set
    * @param Mage_Catalog_Model_Product $Product
    * @param mixed                      $options
    * @return string
    */
   public function getProductSku(Mage_Catalog_Model_Product $Product, $options = null)
   {
      if ($options)
      {
         $productOptions = $Product->getOptions();
         foreach ($options as $option)
         {
            if ($ProductOption = @$productOptions[$option['option_id']])
            {
               $values = $ProductOption->getValues();
               if (($value = $values[$option['option_value']]) && $value->getSku())
               {
                  return $value->getSku();
               }
            }
         }
      }
      return $Product->getSku();
   }
	
   /**
    * Returns current customer
    * @return Mage_Customer_Model_Customer
    */
   public function getCustomer()
   {
      if (!$this->getData('customer'))
      {
         $customer = Mage::getSingleton('customer/session')->getCustomer();
         $this->setCustomer($customer);
      }
      return $this->getData('customer');
   }
	
   /**
    * Return sales item as object
    * @param Mage_Sales_Model_Order_Item $item
    * @return Varien_Object
    */
   protected function _getOrderItemOptionValues(Mage_Sales_Model_Order_Item $item)
   {
      $buyRequest = $item->getProductOptionByCode('info_buyRequest');
      $obj = new Varien_Object;
      $obj->setData($buyRequest);
      return $obj;
   }
	
   /**
    * Activates or suspends subscription on order status change
    * @param object $observer
    * @return
    */
   public function updateSubscriptionStatus($observer)
   {
      $Order = $observer->getOrder();
      $status = $Order->getStatus();
      $items = Mage::getModel('rsp/subscription_item')->getCollection()->addOrderFilter($Order);
      $items->getSelect()->group('subscription_id');
      /**
       * this is for primary order
       */
      if ($items->count())
      {
         foreach ($items as $item)
         {
            $Subscription = Mage::getModel('rsp/subscription')->load($item->getSubscriptionId());
            // If order is complete now and subscription is suspeneded - activate subscription
            if (Mage::helper('rsp')->isOrderStatusValidForActivation($Order) && ($Subscription->getStatus() == Milople_Rsp_Model_Subscription::STATUS_SUSPENDED))
            {
               if (($status == Mage_Sales_Model_Order::STATE_PROCESSING) && !Mage::helper('rsp')->isSubscriptionItemInvoiced($item))
               {
                  Mage::getModel('rsp/subscription_flat')->load($Subscription->getId(), 'subscription_id')->setFlatLastOrderStatus($Subscription->getLastOrder()->getStatus())->save();
                  continue;
               }
               $Subscription->setStatus(Milople_Rsp_Model_Subscription::STATUS_ENABLED)->save();
            }
            elseif ($Subscription->isActive() && !Mage::helper('rsp')->isOrderStatusValidForActivation($Order))
            {
               $Subscription->setStatus(Milople_Rsp_Model_Subscription::STATUS_SUSPENDED)->save();
            }
            else
            {
               Mage::getModel('rsp/subscription_flat')->load($Subscription->getId(), 'subscription_id')->setFlatLastOrderStatus($Subscription->getLastOrder()->getStatus())->save();
            }
         }
      }
	   
      /**
       * If payment failed(e.g. order status is not completed), suspend affected subscription
       */
      if ($Sequence = Mage::getModel('rsp/sequence')->load($Order->getId(), 'order_id'))
      {
         if ($Sequence->getId())
         {
            $Subscription = $Sequence->getSubscription();
            // If order is complete now and subscription is suspeneded - activate subscription
            if (Mage::helper('rsp')->isOrderStatusValidForActivation($Order))
            {
               $Sequence->setStatus(Milople_Rsp_Model_Sequence::STATUS_PAYED)->save();
               $Subscription->setStatus(Milople_Rsp_Model_Subscription::STATUS_ENABLED)->setFlagNoSequenceUpdate(1)->save()->setFlagNoSequenceUpdate(0);
            }
            elseif ($Subscription->isActive() && !Mage::helper('rsp')->isOrderStatusValidForActivation($Order))
            {
               $Subscription->setStatus(Milople_Rsp_Model_Subscription::STATUS_SUSPENDED)->setFlagNoSequenceUpdate(1)->save()->setFlagNoSequenceUpdate(0);
            }
         }
      }
   }
	
   /**
    * Checks if subscription is suspended. Remove download links if product attribute 'Download access
    * based on the subscription status' is true
    * @param object $observer
    * @return
    */
   public function rnrSubscriptionSuspend($observer)
   {
      $subscription = $observer->getEvent()->getSubscription();
      $purchasedLinks = Mage::getResourceModel('downloadable/link_purchased_item_collection');
      $orders = Mage::getModel('rsp/sequence')->getOrdersBySubscription($subscription);
      $orders[] = $subscription->getOrder();
      foreach ($orders as $order)
      {
         foreach ($order->getAllItems() as $item)
         {
            $product = Mage::getModel('catalog/product')->load($item->getProductId());
            if ($product->getTypeId() != 'downloadable')
               continue;
            if ($product->getIndiesrspDownloadByStatus())
            {
               foreach ($purchasedLinks as $link)
                  if ($link->getOrderItemId() == $item->getId())
                     $link->setStatus(Mage_Downloadable_Model_Link_Purchased_Item::LINK_STATUS_PENDING_PAYMENT)->save();
            }
         }
      }
   }
	
   /**
    * Checks if subscription is reactivated. Add download links
    * @param object $observer
    * @return
    */
   public function rnrSubscriptionReactivate($observer)
   {
		$subscription = $observer->getEvent()->getSubscription();
		$orders = Mage::getModel('rsp/sequence')->getOrdersBySubscription($subscription);
		$orders[] = $subscription->getOrder();
		$order_item_ids = array();
		foreach ($orders as $order) {
			foreach ($order->getAllItems() as $item) {
				if ($item->getProductType()==Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE || $item->getRealProductType() == Mage_Downloadable_Model_Product_Type::TYPE_DOWNLOADABLE) {
					$order_item_ids[] = $item->getId();
				}
			}
		}

		$purchasedLinks = Mage::getResourceModel('downloadable/link_purchased_item_collection')->addFieldToFilter('order_item_id', array('in' => $order_item_ids));
		foreach ($purchasedLinks as $link) {
			$link->setStatus(Mage_Downloadable_Model_Link_Purchased_Item::LINK_STATUS_AVAILABLE)->save();
		}
   }
	
   /**
    * returns all sequences with given status for particular subscription
    * @return Indies_rsp_Model_Mysql4_Sequence_Collection
    */
   public function getSequenceItems($subscription, $status)
   {
      return Mage::getModel('rsp/sequence')->getCollection()->addSubscriptionFilter($subscription)->addStatusFilter($status);
   }
	
   /** 
   	*	Show/Hide payment methods which are supported by 
   	*	Recurring and Subscription Extension 
   */
   public function paymentIsAvailable($observer)
   {
      $method = $observer->getMethodInstance();
      $quote = $observer->getQuote();
      if (is_null($quote))
      {
         return;
      }
      if (!$quote instanceof Mage_Sales_Model_Quote)
      {
         $observer->getResult()->isAvailable = false;
         return;
      }
      $haveItems = false;
      foreach ($quote->getAllItems() as $item)
      {
         $buyInfo = $item->getBuyRequest();
         $SubscriptionType = $buyInfo->getMilopleRspType();
         if (Mage::helper('rsp')->isSubscriptionProduct($item) && (!is_null($SubscriptionType) && ($SubscriptionType > 0)))
         {
            $haveItems = true;
            break;
         }
      }
      if ($haveItems && !Mage::getModel('rsp/subscription')->hasMethodInstance($method->getCode()))
      {
         $observer->getResult()->isAvailable = false;
      }
   }
	
   /**
    *  Trigger to create cim account while placing an order with Authoize.net payment method
	*/
   public function onepageCheckoutSaveOrderBefore($observer)
   {
      $quote = $observer->getQuote();
      $order = $observer->getOrder();
      $haveItems = false;
      foreach ($quote->getAllVisibleItems() as $item)
      {
         $buyInfo = $item->getBuyRequest();
         $SubscriptionType = $buyInfo->getMilopleRspType();
         if (!is_null($SubscriptionType))
         {
            $haveItems = true;
            break;
         }
      }
      if (!Mage::getSingleton('rsp/subscription')->getId() && $haveItems)
      {
         switch ($order->getPayment()->getMethod())
         {
            case Milople_Rsp_Model_Payment_Method_Authorizenet::PAYMENT_METHOD_CODE:
               $paymentModel = Mage::getModel('rsp/payment_method_authorizenet');
               $service = $paymentModel->getWebService();
               $service->setPayment($quote->getPayment());
			   
               $subscription = new Varien_Object();
               $subscription->setStoreId($order->getStoreId());
               $service->setSubscription($subscription);
               try
               {
                  $service->createCIMAccount();
               }
               catch (Exception $e)
               {
                  throw new Mage_Core_Exception($e->getMessage());
               }
            default:
         }
      }
   }
	
   /**
     *  Send Payment Confirmation mail after invoice is paid 
	*/
   public function SalesOrderInvoicePay($observer)
   {
      $invoice = $observer->getEvent()->getInvoice();
      $this->sentPaymentConfirmationmail($invoice);
   }

   /**
     *  Send Payment Confirmation mail after invoice is paid 
	*/
   public function sentPaymentConfirmationmail($invoice)
   {
      $subscription = Mage::getModel('rsp/subscription_item')->load($invoice->getOrderId(), 'primary_order_id');
      $data = $subscription->getData();
      if (!empty($data))
      {
         switch ($invoice->getState())
         {
            case Mage_Sales_Model_Order_Invoice::STATE_PAID:
               if (Mage::getStoreConfig(Milople_Rsp_Helper_Config::XML_PATH_NEXT_PAYMNET_CONFORMATION, Mage::app()->getStore()) == '1')
               {
                  $alert = Mage::getModel('rsp/alert_event');
                  $customer = Mage::getModel('customer/customer')->load($invoice->getCustomerId());
                  $model = Mage::getModel('rsp/subscription')->load($subscription->getSubscriptionId());
                  $alert->send($model, Mage::getStoreConfig(Milople_Rsp_Helper_Config::XML_PATH_NEXT_PAYMNET_CONFORMATION_TEMPLATE), 0, Mage::getStoreConfig(Milople_Rsp_Helper_Config::XML_PATH_NEXT_PAYMNET_CONFORMATION_SENDER));
               }
               break;
         }
      }
      return $this;
   }
	
   /** Change child item/product price of Bundle product 
    * while adding to the cart
   */
   public function calculateBundleItemPrice($params, $item)
   {
      $termid = $params['milople_rsp_type'];
      $cart = Mage::helper('checkout/cart')->getCart()->getQuote()->getAllItems();
      /* Change bundle items price */
      $price = '';
      $helper = Mage::helper('rsp');
      foreach ($cart as $i)
      {
         if ($i->getId() == '') // Dont getting id for currently adding bundle item.
         {
            $options = $i->getOptions();
            foreach ($options as $option)
            {
               if ($option->getCode() == 'bundle_selection_attributes')
               {
                  $unserialized = unserialize($option->getValue());
                  if ($item->getProduct()->getFirstPeriodPrice() > 0)
                     $price = $item->getProduct()->getFirstPeriodPrice();
                  else
                     $price = $helper->getBundleItemsPrice($termid, $unserialized['price']);
                  $unserialized['price'] = number_format($price, 2, '.', ',');
                  $option->setValue(serialize($unserialized));
               }
            }
            try
            {
               $i->setOptions($options)->save();
            }
            catch (Exception $e)
            {
            }
            $i->setCustomPrice($price);
            $i->setOriginalCustomPrice($price);
         }
      }
      return;
   }
	
   /**
     * Change price of Bundle product while adding to the cart
    */
   public function calculateUpdateBundleItemPrice($params, $item)
   {
      $quote = Mage::helper('checkout/cart')->getCart()->getQuote();
      $termid = $params['milople_rsp_type'];
      $helper = Mage::helper('rsp');
      $cart = Mage::helper('checkout/cart')->getCart()->getQuote()->getAllItems();
      $all_array = array();
      foreach ($cart as $i)
      {
         if ($i->getParentItemId() == $item->getId())
         {
            $options = $i->getOptions();
            foreach ($options as $option)
            {
               if ($option->getCode() == 'bundle_selection_attributes')
               {
                  $unserialized = unserialize($option->getValue());
                  if ($item->getProduct()->getFirstPeriodPrice() > 0)
                     $price = $i->getProduct()->getFirstPeriodPrice();
                  else
                     $price = $helper->getBundleItemsPrice($termid, $unserialized['price']);
                  $unserialized['price'] = number_format($price, 2, '.', ',');
                  $data = Mage::getModel('sales/quote_item_option')->load($option->getId())->setValue(serialize($unserialized))->save();
               }
            }
            try
            {
            }
            catch (Exception $e)
            {
               Mage::log($e->getMesage());
            }
            $product = Mage::getModel('catalog/product')->load($params['product']);
            $i->setCustomPrice($price);
            $i->setOriginalCustomPrice($price);
            if ($product->getPrice() == 0)
            {
               $i->setPrice($price);
               $i->save();
            }
         }
      }
   }
	
   /**
    * Save serial key
	*/
   public function save()
   {
      $data = $this->_getData('fieldset_data');
      $obj = Mage::helper('rsp');
      if ($data['serial_key'] != '')
      {
         $serialkey = $data['serial_key'];
         if ($obj->canRun($serialkey))
         {
            return parent::save();
         }
         else
         {
            Mage::throwException($obj->getAdminMessage());
         }
      }
      else
      {
         Mage::throwException($obj->getAdminMessage());
      }
   }
}
