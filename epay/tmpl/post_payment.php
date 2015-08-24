<?php
defined ('_JEXEC') or die();

		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('EPAY_PAYMENT_NAME', $viewData['EPAY_PAYMENT_NAME']);
		$html .= $this->getHtmlRow('EPAY_TRANSACTION_ID', $viewData['EPAY_TRANSACTION_ID']);
		$html .= $this->getHtmlRow('EPAY_ORDER_NUMBER', $viewData['EPAY_ORDER_NUMBER']);
		
		$html .= '</table>' . "\n";
print $html;