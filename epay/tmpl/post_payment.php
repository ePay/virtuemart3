<?php
defined ('_JEXEC') or die();

/**
 * @author Valérie Isaksen
 * @version $Id$
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2004-Copyright (C) 2004-2015 Virtuemart Team. All rights reserved.   - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('EPAY_PAYMENT_NAME', $viewData['EPAY_PAYMENT_NAME']);
		$html .= $this->getHtmlRow('EPAY_TRANSACTION_ID', $viewData['EPAY_TRANSACTION_ID']);
		$html .= $this->getHtmlRow('EPAY_ORDER_NUMBER', $viewData['EPAY_ORDER_NUMBER']);
		
		$html .= '</table>' . "\n";
print $html;