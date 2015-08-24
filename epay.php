<?php
/*
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__ ) . ' is not allowed.');

if(!class_exists('vmPSPlugin'))
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVMPaymentEpay extends vmPSPlugin
{
	
	// instance of class
	public static $_this = false;
	
    function __construct(& $subject, $config) {
    	parent::__construct($subject, $config);
        
    	$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);    
    }
	
	private function _getEpayLanguage()
	{
		if(JText::_('VMPAYMENT_EPAY_LANGUAGE'))
			return JText::_('VMPAYMENT_EPAY_LANGUAGE');
		else
			return 1;
	}
	
	function _getPaymentResponseHtml($epayData, $payment_name)
	{
		$html = $this->renderByLayout('post_payment', array("EPAY_PAYMENT_NAME" => $payment_name,
													"EPAY_TRANSACTION_ID" =>$epayData["txnid"],
													"EPAY_ORDER_NUMBER" =>$epayData["orderid"],
											));		
		return $html;
	}
	
	protected function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('ePay Payment Solutions');
	}
	
	function getTableSQLFields()
	{
		$SQLfields = array
		(
			'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
			'order_number' => 'char(32) DEFAULT NULL',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
			'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
			'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency' => 'char(3)',
			'cost_per_transaction' => 'decimal(10,2) DEFAULT NULL',
			'cost_percent_total' => 'decimal(10,2) DEFAULT NULL',
			'tax_id' => 'smallint(1) DEFAULT NULL',
			'epay_response' => 'varchar(9000) DEFAULT NULL'
		);
		return $SQLfields;
	}
	
	function plgVmConfirmedOrder($cart, $order)
	{
        if(!($method = $this->getVmPluginMethod($order["details"]["BT"]->virtuemart_paymentmethod_id)))
			return null;
			
		if(!$this->selectedThisElement($method->payment_element))
			return false;

		$this->logInfo('plgVmOnConfirmedOrderGetPaymentForm order number: ' . $order['details']['BT']->order_number, 'message');
		$lang = JFactory::getLanguage();
		$lang->load('plg_vmpayment_epay', JPATH_ADMINISTRATOR);
		
		if(!class_exists('VirtueMartModelOrders'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		if(!class_exists('VirtueMartModelCurrency'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		
		$new_status = '';
		
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
		
		$vendorModel = new VirtueMartModelVendor();
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$this->getPaymentCurrency($method);
		$q = ' SELECT `currency_numeric_code` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="'  . $method->payment_currency . '" ';
		$db =  & JFactory::getDBO();
		$db->setQuery($q);
		$currency_numeric_code = $db->loadResult();
		
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		
		$session = JFactory::getSession();
		
		$post_variables = array
		(
			'merchantnumber' => $method->epay_merchant,
			'instantcapture' => $method->epay_instantcapture,
			'ownreceipt' => $method->epay_ownreceipt,
			'group' => $method->epay_group,
			'mailreceipt' => $method->epay_authmail,
			'language' => $this->_getEpayLanguage(),
			'orderid' => $order['details']['BT']->order_number,
			"amount" => $totalInPaymentCurrency * 100,
			"currency" => $currency_numeric_code,
			"accepturl" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
			"callbackurl" => JROUTE::_(JURI::root() . 'index.php?callback=1&option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
			"cancelurl" => JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id),
			"windowstate" => $method->epay_windowstate
		);
		
		$hash = md5(implode($post_variables, "") . $method->epay_md5key);
		
		// Prepare data that should be stored in the database
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$this->storePSPluginInternalData($dbValues);
		
		// add spin image
		$html = '<script type="text/javascript" src="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js" charset="UTF-8"></script>';
		$html .= '<script type="text/javascript">';
		$html .= 'paymentwindow = new PaymentWindow({';
		foreach($post_variables as $name => $value)
		{
			$html .= '\'' . $name . '\': "' . $value . '",';
		}
		
		$html .= '\'hash\': "' . $hash . '"';
		$html .= '});';
		$html .= '</script><input type="button" onclick="javascript: paymentwindow.open()" value="Go to payment" />';
		
		$html .= ' <script type="text/javascript">';
		$html .= ' paymentwindow.open();';
		$html .= ' </script>';
		// 	2 = don't delete the cart, don't send email and don't redirect
		$cart->_confirmDone = false;
		$cart->_dataValidated = false;
		$cart->setCartIntoSession();
		JRequest::setVar('html', $html);
	}
	
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id,  & $paymentCurrencyId)
	{
		if(!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
			return null;
			// Another method was selected, do nothing
		}
		if(!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
	}
	
	function plgVmOnPaymentResponseReceived( & $html = "")
	{
		if(!class_exists('VirtueMartModelOrders'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		
		$payment_data = $_GET;
		
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = $payment_data["pm"];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($payment_data["orderid"]);
		
		$vendorId = 0;
		if(!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
		{
			return null;
		}
		
		if(!$this->selectedThisElement($method->payment_element))
		{
			return false;
		}
		
		$db =  & JFactory::getDBO();
		$query = "SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id =" . $virtuemart_order_id;
		$db->setQuery($query);
		$payment = $db->loadObject();
		
		//if(!$payment = $this->getDataByOrderId($virtuemart_order_id))
		//{
		//	return;
		//}
		
		if(@$payment_data["callback"] == 1)
		{
			$this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id, 'message');
			
			$vendorId = 0;
			
			$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
			
			$this->logInfo('epay_data ' . implode('   ', $_GET), 'message');
			
			// get all know columns of the table
			$response_fields = $payment_data;
			unset($response_fields["option"]);
			unset($response_fields["view"]);
			unset($response_fields["task"]);
			unset($response_fields["tmpl"]);
			
			$response_fields["payment_name"] = $this->renderPluginName($method);
			$response_fields["order_number"] = $payment_data["orderid"];
			$response_fields["virtuemart_order_id"] = $virtuemart_order_id;
			$response_fields["epay_response"] = addslashes(serialize($response_fields));
			$response_fields["virtuemart_paymentmethod_id"] = $payment->virtuemart_paymentmethod_id;
			
			//$this->storePSPluginInternalData($response_fields);
			$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', TRUE);
			
			if(strlen($method->epay_md5key) > 0)
			{
				$params = $payment_data;
				$var = "";
				
				foreach($params as $key => $value)
				{
					if($key != "hash")
					{
						$var .= $value;
					}
				}
				
				if($payment_data["hash"] != md5($var . $method->epay_md5key))
				{
					echo "MD5 ERROR";
					$this->logInfo('MD5 Error: exit ', 'ERROR');
					return null;
				}
			}
			
			if((int)$payment_data["txnfee"] > 0)
			{
				$fee = (int)$payment_data["txnfee"] / 100;
				$db = JFactory::getDBO();
				$q = "UPDATE #__virtuemart_orders SET order_payment = " . (float)$fee . ", order_total = order_total+$fee WHERE virtuemart_order_id=" . $virtuemart_order_id;
				$db->setQuery($q);
				$db->query();
			}
			
			$new_status = $method->status_success;
			
			if($virtuemart_order_id)
			{
				// send the email only if payment has been accepted
				if(!class_exists('VirtueMartModelOrders'))
					require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
				$modelOrder = new VirtueMartModelOrders();
				$order["order_status"] = $new_status;
				$order["virtuemart_order_id"] = $virtuemart_order_id;
				$order["customer_notified"] = 1;
				$order['comments'] = JText::sprintf('VMPAYMENT_EPAY_PAYMENT_STATUS_CONFIRMED', $payment_data["orderid"]);
				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			}
			
			echo "OK";
		}
		else
		{
			$session = JFactory::getSession();
			
			vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
			
			if(!class_exists('VirtueMartModelOrders'))
				require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
			
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($payment_data["orderid"]);
			$payment_name = $this->renderPluginName($method);
			
			$payment_name = $this->renderPluginName($method);
			$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);
			
			$this->emptyCart($session->getId());
		}
		
		return true;
	}
	
	function plgVmOnUserPaymentCancel( & $virtuemart_order_id)
	{
		if(!class_exists('VirtueMartModelOrders'))
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		
		$order_number = JRequest::getVar('orderid');
		$payment_method_id = JRequest::getVar('pm');
		if(!$order_number)
			return false;
		$db = JFactory::getDBO();
		$query = '  SELECT '  . $this->_tablename . '.`virtuemart_order_id` FROM ' . $this->_tablename . " WHERE  `order_number`= '" . $order_number . "'" . ' AND  `virtuemart_paymentmethod_id` = ' . $payment_method_id;
		$db->setQuery($query);
		$virtuemart_order_id = $db->loadResult();
		
		//fwrite($fp, "order" . $virtuemart_order_id);
		if(!$virtuemart_order_id)
		{
			return null;
		}
		
		return true;
	}
	
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
	{
		if(!$this->selectedThisByMethodId($payment_method_id))
		{
			return null; // Another method was selected, do nothing
		}
		
		$db = JFactory::getDBO();
		$q = ' SELECT * FROM `'  . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if(!($paymentTable = $db->loadObject()))
		{
			// JError::raiseWarning(500, $db->getErrorMsg());
			return '';
		}
		
		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		
		$epay_response = @unserialize(str_replace('\"', '"', $paymentTable->epay_response));
		
		if(is_array($epay_response))
		{
			foreach($epay_response as $key => $value)
			{
				if($key != "HTTP_COOKIE" && $key != "Itemid")
					$html .= "<tr><td class=\"key\">" . $key . "</td><td align=\"left\">" . $value . "</td></tr>";
			}
		}
		
		$html .= '</table>' . "\n";
		return $html;
	}
	
	function getCosts(VirtueMartCart $cart, $method, $cart_prices)
	{
		return 0;
	}
	
	protected function checkConditions($cart, $method, $cart_prices)
	{
		return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
	{
		
		return $this->onStoreInstallPluginTable($jplugin_id);
	}

	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
	{
		return $this->OnSelectCheck($cart);
	}
	
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0,  & $htmlIn)
	{
		return $this->displayListFE($cart, $selected, $htmlIn);
	}
	
	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}
	
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}
	
	function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id,  & $payment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}
	
	function plgVmonShowOrderPrintPayment($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}
	
	function plgVmDeclarePluginParamsPayment($name, $id,  & $data)
	{
		return $this->declarePluginParams('payment', $name, $id, $data);
	}
	
	function plgVmSetOnTablePluginParamsPayment($name, $id,  & $table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}
    
    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	
}

// No closing tag