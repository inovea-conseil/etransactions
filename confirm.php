<?php
/* Copyright (C) 2012      Mikael Carlavan        <mcarlavan@qis-network.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *     	\file       htdocs/public/etransactions/confirm.php
 *		\ingroup    etransactions
 */

define("NOLOGIN",1);		// This means this output page does not require to be logged.
define("NOCSRFCHECK",1);	// We accept to go on this page from external web site.

$res=@include("../main.inc.php");					// For root directory
if (! $res) $res=@include("../../main.inc.php");	// For "custom" directory

require_once(DOL_DOCUMENT_ROOT."/core/lib/company.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/security.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/date.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/functions.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/functions2.lib.php");
require_once(DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php');
require_once(DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php");
require_once(DOL_DOCUMENT_ROOT."/compta/paiement/class/paiement.class.php");
require_once(DOL_DOCUMENT_ROOT."/commande/class/commande.class.php");

dol_include_once('/etransactions/class/etransactions.class.php');
dol_syslog('ETransactions: confirmation page has been called'); 
// Security check
if (empty($conf->etransactions->enabled)) 
    exit;

$langs->setDefaultLang('fr_FR');

$langs->load("main");
$langs->load("other");
$langs->load("dict");
$langs->load("bills");
$langs->load("companies");
$langs->load("errors");
$langs->load("etransactions@etransactions");




// Check module configuration
$error = false;
dol_syslog('ETransactions: Check configuration'); 

// Check module configuration
if (empty($conf->global->API_ID))
{
	$error = true;
	dol_syslog('ETransactions: Configuration error : ID is not defined');    
}

// Check module configuration
if (empty($conf->global->API_RANK))
{
	$error = true;
	dol_syslog('ETransactions: Configuration error : rank is not defined');    
}

if (empty($conf->global->API_SHOP_ID))
{
	$error = true;
	dol_syslog('ETransactions: Configuration error : society ID is not defined');    
}
 

   
if ($error)
{
    exit;
}

if ($conf->global->API_KEY && function_exists('openssl_pkey_get_public'))
{
	// Controle signature
	$vals = array();
	$fields = array('montant', 'ref', 'auto', 'trans', 'num', 'erreur', 'tarif');
	foreach ($fields as $f)
	{
		$vals[$i] = $f.'='.urldecode(GETPOST($f));
		$i++;
	}
	$signature = implode('&', $vals);

	$pub_key = openssl_pkey_get_public($conf->global->API_KEY);
	$received = base64_decode(urldecode(GETPOST('sign'))); 

	if (openssl_verify($signature, $received, $pub_key) != 0)
	{
		$error = true;
		dol_syslog('ETransactions: Received signature differs. Received : '.$received.', computed : '.$signature);
	}

	if ($error)
	{
		exit;
	}
}

$erreur = GETPOST('erreur');
$key = urldecode(GETPOST('ref'));

$etransactions = new ETransactions($db);
$result = $etransactions->fetch('', $key);

if ($result <= 0)
{
	$error = true;
	dol_syslog('ETransactions: Invoice/order with specified reference does not exist, confirmation payment email has not been sent');
	exit;
}

$isInvoice = ($etransactions->type == 'invoice' ? true : false);

$item = ($isInvoice) ? new Facture($db) : new Commande($db);
$result = $item->fetch($etransactions->fk_object);	
$item->fetch_thirdparty();

$referenceDolibarr = $item->ref;

$dateTransaction = '';
$referenceTransaction = urldecode(GETPOST('trans'));
$referenceAutorisation = urldecode(GETPOST('auto'));

$amountTransaction = GETPOST('tarif', 'int');
$clientBankName = ''; 
$clientName = $item->thirdparty->name;

$substit = array(
	'__OBJREF__' => $referenceDolibarr,
	'__SOCNAM__' => $conf->global->MAIN_INFO_SOCIETE_NOM,
	'__SOCMAI__' => $conf->global->MAIN_INFO_SOCIETE_MAIL,
	'__CLINAM__' => $clientName,                
	'__AMOOBJ__' => $amountTransaction/100,
);
	

$success = (($erreur == '00000' && !empty($referenceAutorisation)) ? true : false);
            
// Update DB
if ($success)
{
	dol_syslog('ETransactions: Payment accepted');
	
    // If order, first convert it into invoice, then mark is as paid
    if (!$isInvoice)
    { 
        $item->fetch_lines();
        
        // Create invoice
        $invoice = new Facture($db);
        $result = $invoice->createFromOrder($item);
        
        $item = new Facture($db);
        $item->fetch($invoice->id);
        $item->fetch_thirdparty();                  
    }
    
	
	  
    // Set transaction reference 
    $item->setValueFrom('ref_int', $referenceTransaction);
    $id = $item->id;        
    
    $db->begin();
    
    $amount = $amountTransaction/100; // Convert to EUR
    
    // Creation of payment line
    $payment = new Paiement($db);
    $payment->datepaye     = dol_now();
    $payment->amounts      = array($id => price2num($amount));   
    $payment->amount      = $amount;   
    $payment->paiementid   = dol_getIdFromCode($db, 'CB', 'c_paiement');
    $payment->num_paiement = $referenceAutorisation;
    $payment->note         = '';

    $paymentId = $payment->create($user, $conf->global->UPDATE_INVOICE_STATUT);

    if ($paymentId < 0)
    {
        dol_syslog('ETransactions: Payment has not been created in the database');
    }

	if (!empty($conf->global->BANK_ACCOUNT_ID))
	{
		$payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $conf->global->BANK_ACCOUNT_ID, $clientName, $clientBankName);      
	}        
    
                
    $db->commit(); 
    
    $subject = ($isInvoice ? $langs->transnoentities('InvoiceSuccessPaymentEmailSubject') : $langs->transnoentities('OrderSuccessPaymentEmailSubject'));         
    $message = ($isInvoice ? $langs->transnoentities('InvoiceSuccessPaymentEmailBody') : $langs->transnoentities('OrderSuccessPaymentEmailBody'));
    
    $subject = make_substitutions($subject, $substit);           
    $message = make_substitutions($message, $substit);        
          
}else{

    dol_syslog('ETransactions: Payment refused');
    $message = '';
    
    switch($erreur)
    {
    	case '00003' : 
    		$message = $langs->transnoentities('ErrorPaymentTechnicalErrorEmail');
    	break;  
    	default : 
    		$message = $langs->transnoentities('ErrorPaymentUnauthorizedEmail');
    	break;  	
    }
    
    $subject = ($isInvoice ? $langs->transnoentities('InvoiceErrorPaymentEmailSubject') : $langs->transnoentities('OrderErrorPaymentEmailSubject'));         
    $message .= ($isInvoice ? $langs->transnoentities('InvoiceErrorPaymentEmailBody') : $langs->transnoentities('OrderErrorPaymentEmailBody'));    

    $subject = make_substitutions($subject, $substit);           
    $message = make_substitutions($message, $substit);    
}

if (!$error)
{
    //Get data for email  
	$sendto = $item->thirdparty->email;
  

    $from = $conf->global->MAIN_INFO_SOCIETE_MAIL;
             
	$message = str_replace('\n',"<br />", $message);
	
	$deliveryreceipt = 0;//$conf->global->DELIVERY_RECEIPT_EMAIL;
	$addr_cc = ($conf->global->CC_EMAIL ? $conf->global->MAIN_INFO_SOCIETE_MAIL: "");

	if (!empty($conf->global->CC_EMAILS)){
		$addr_cc.= (empty($addr_cc) ? $conf->global->CC_EMAILS : ','.$conf->global->CC_EMAILS);
	}

	$mail = new CMailFile($subject, $sendto, $from, $message, array(), array(), array(), $addr_cc, "", $deliveryreceipt, 1);
	$result = $mail->error;
            
    if (!$result)
    {
        $result = $mail->sendfile();
        if ($result){
            dol_syslog('ETransactions: Confirmation payment email has been correctly sent');
        }else{
            dol_syslog('ETransactions: Error sending confirmation payment email');
        }
    }
    else
    {
        dol_syslog('ETransactions: Error in creating confirmation payment email');
    }    
}


$db->close();
?>