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
 *     	\file       htdocs/public/etransactions/payment.php
 *		\ingroup    etransactions
 *		\brief      File to offer a payment form for an invoice
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

require_once(DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php");
require_once(DOL_DOCUMENT_ROOT."/commande/class/commande.class.php");

dol_include_once('/etransactions/class/etransactions.class.php');

// Security check
if (empty($conf->etransactions->enabled)) 
    accessforbidden('',1,1,1);

$langs->load("main");
$langs->load("other");
$langs->load("dict");
$langs->load("bills");
$langs->load("companies");
$langs->load("errors");
$langs->load("etransactions@etransactions");

$key    = GETPOST("key", 'alpha');

$error = false;
$message = false;


$etransactions = new ETransactions($db);
$result = $etransactions->fetch('', $key);

if ($result <= 0)
{
	$error = true;
	$message = $langs->trans('NoPaymentObject');
}

// Check module configuration
if (empty($conf->global->API_ID))
{
	$error = true;
	$message = $langs->trans('ConfigurationError');
	dol_syslog('ETransactions: Configuration error : ID is not defined');    
}

// Check module configuration
if (empty($conf->global->API_RANK))
{
	$error = true;
	$message = $langs->trans('ConfigurationError');
	dol_syslog('ETransactions: Configuration error : rank is not defined');    
}

if (empty($conf->global->API_SHOP_ID))
{
	$error = true;
	$message = $langs->trans('ConfigurationError');
	dol_syslog('ETransactions: Configuration error : society ID is not defined');    
}

if (!$error)
{
	$isInvoice = ($etransactions->type == 'invoice' ? true : false);

	
	/* Build URL form */
	$cgiBinPath = rtrim($conf->global->API_CGI,"\\/");
	$cgiBinPath = str_replace('/', DIRECTORY_SEPARATOR, $cgiBinPath);			
	
	$osName = php_uname('s');
	$isWin = strtoupper($osName) === 'WIN' ? true : false;		
	$urlServer = $cgiBinPath .DIRECTORY_SEPARATOR.($isWin ? 'modulev2.exe' : 'modulev2.cgi');	
	/**/

	$item = ($isInvoice) ? new Facture($db) : new Commande($db);

	$result = $item->fetch($etransactions->fk_object);

	$alreadyPaid = 0;
	$creditnotes = 0;
	$deposits = 0;
	$totalObject = 0;
	$amountTransaction = 0;

	$needPayment = false;

	$result = $item->fetch_thirdparty($item->socid);
    
    if ($isInvoice)
    {
        $alreadyPaid = $item->getSommePaiement();
        $creditnotes = $item->getSumCreditNotesUsed();
        $deposits = $item->getSumDepositsUsed();         
    }

    $totalObject = $item->total_ttc;
       
    $alreadyPaid = empty($alreadyPaid) ? 0 : $alreadyPaid;
    $creditnotes = empty($creditnotes) ? 0 : $creditnotes;
    $deposits = empty($deposits) ? 0 : $deposits;
    
    $totalObject = empty($totalObject) ? 0 : $totalObject;
    
    $amountTransaction =  $totalObject - ($alreadyPaid + $creditnotes + $deposits);
    
    $needPayment = ($item->statut == 1) ? true : false;
    
    // Do nothing if payment is already completed
    if (price2num($amountTransaction, 'MT') == 0 || !$needPayment)
    {
        $error = true;
        $message = ($isInvoice ? $langs->trans('InvoicePaymentAlreadyDone') : $langs->trans('OrderPaymentAlreadyDone'));    
        dol_syslog('ETransactions: Payment already completed, form will not be displayed');
    }
    else
    {
    
		$amountTransactionNum = intval(100 * price2num($amountTransaction, 'MT')); // Cents
		$customerEmail = $item->thirdparty->email;
		
		$fields = array(
			'PBX_MODE' => 4,
			'PBX_SITE' => $conf->global->API_TEST ? 1999888 : $conf->global->API_SHOP_ID,
			'PBX_DEVISE' => 978,
			'PBX_RANG' => $conf->global->API_TEST ? 98 : $conf->global->API_RANK,
			'PBX_IDENTIFIANT' => $conf->global->API_TEST ? 3 : $conf->global->API_ID,
			'PBX_TOTAL' => $amountTransactionNum,
			'PBX_CMD' => $key,
			'PBX_PORTEUR' => $customerEmail,
			'PBX_RETOUR' => 'montant:M\;ref:R\;auto:A\;trans:T\;num:S\;erreur:E\;tarif:M\;sign:K',
			'PBX_REFUSE' => urlencode(dol_buildpath('/etransactions/error.php', 2)),
			'PBX_ERREUR' => urlencode(dol_buildpath('/etransactions/error.php', 2)),
			'PBX_ANNULE' => urlencode(dol_buildpath('/etransactions/return.php', 2)),
			'PBX_EFFECTUE' => urlencode(dol_buildpath('/etransactions/success.php', 2)),
			//'PBX_REPONDRE_A' => urlencode(dol_buildpath('/etransactions/confirm.php', 2)),
		); 
		
		$scriptCmd = $urlServer;
		foreach ($fields as $f => $v)
		{
			$scriptCmd.= ' '.$f.'='.$v;
		}
		
		//var_dump($scriptCmd);
		system($scriptCmd);
    }   

}

if ($error)
{
    
    /*
     * View
     */
     
    $substit = array(
        '__SOCNAM__' => $conf->global->MAIN_INFO_SOCIETE_NOM,
        '__SOCMAI__' => $conf->global->MAIN_INFO_SOCIETE_MAIL,
    );
    
    $welcomeTitle = make_substitutions($langs->transnoentities('InvoicePaymentFormWelcomeTitle'), $substit);     
    $message = make_substitutions($message, $substit);
    
    require_once('tpl/message.tpl.php');    
}

$db->close();

?>