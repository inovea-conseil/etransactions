<?php
/* Copyright (C) 2012      Mikael Carlavan        <contact@mika-carl.fr>
 *                                                http://www.mikael-carlavan.fr
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



$res=@include("../../main.inc.php");				// For root directory
if (! $res) $res=@include("../../../main.inc.php");	// For "custom" directory

require_once(DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.form.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/functions2.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/doleditor.class.php");


$langs->load('etransactions@etransactions');
$langs->load('main');
$langs->load('admin');

if (!$user->admin)
{
   accessforbidden();
}

//Init error
$error = false;
$message = false;


$api_test = $conf->global->API_TEST ? $conf->global->API_TEST : 0;
$api_rank = $conf->global->API_RANK ? $conf->global->API_RANK : '';//PBX_RANG
$api_id = $conf->global->API_ID ? $conf->global->API_ID : '';//PBX_IDENTIFIANT
$api_cgi = $conf->global->API_CGI ? $conf->global->API_CGI : DOL_DOCUMENT_ROOT;
$api_shop_id = $conf->global->API_SHOP_ID ? $conf->global->API_SHOP_ID : '';//PBX_SITE
$api_key = $conf->global->API_KEY ? $conf->global->API_KEY : '';//PBX_IDENTIFIANT

$security_token = $conf->global->SECURITY_TOKEN ? $conf->global->SECURITY_TOKEN : '';

$delivery_receipt_email = $conf->global->DELIVERY_RECEIPT_EMAIL ? $conf->global->DELIVERY_RECEIPT_EMAIL : 0;
$cc_email = $conf->global->CC_EMAIL ? $conf->global->CC_EMAIL : '';
$cc_emails = $conf->global->CC_EMAILS ? $conf->global->CC_EMAILS : '';
$update_invoice_statut = $conf->global->UPDATE_INVOICE_STATUT ? $conf->global->UPDATE_INVOICE_STATUT : 0;
$bank_account_id = $conf->global->BANK_ACCOUNT_ID ? $conf->global->BANK_ACCOUNT_ID : 0;
$payment_auto_send = $conf->global->PAYMENT_AUTO_SEND ? $conf->global->PAYMENT_AUTO_SEND : 0;

$action = GETPOST("action");

// Sauvegarde parametres
if ($action == 'update')
{
    $db->begin();
	
	$api_test = trim(GETPOST("api_test"));
	$api_shop_id = trim(GETPOST("api_shop_id"));
	$api_id = trim(GETPOST("api_id"));
	$api_key = trim(GETPOST("api_key"));
	$api_rank = trim(GETPOST("api_rank"));
	$api_cgi = trim(GETPOST("api_cgi"));
	$security_token = trim(GETPOST("security_token"));
	$payment_auto_send = trim(GETPOST("payment_auto_send"));
	
	$delivery_receipt_email = trim(GETPOST("delivery_receipt_email"));
	$cc_email = trim(GETPOST("cc_email"));
	$cc_emails = trim(GETPOST("cc_emails"));
	$update_invoice_statut = trim(GETPOST("update_invoice_statut"));
	$bank_account_id = trim(GETPOST("bank_account_id"));
		
    dolibarr_set_const($db, 'API_TEST', $api_test, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'API_SHOP_ID', $api_shop_id, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'API_ID', $api_id, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'API_RANK', $api_rank, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'API_CGI', $api_cgi, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'API_KEY', $api_key, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'SECURITY_TOKEN', $security_token, 'chaine', 0, '', $conf->entity);
		
    dolibarr_set_const($db, 'DELIVERY_RECEIPT_EMAIL', $delivery_receipt_email, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CC_EMAIL', $cc_email, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CC_EMAILS', $cc_emails, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'UPDATE_INVOICE_STATUT', $update_invoice_statut, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'PAYMENT_AUTO_SEND', $payment_auto_send, 'chaine', 0, '', $conf->entity);	
	dolibarr_set_const($db, 'BANK_ACCOUNT_ID', $bank_account_id, 'chaine', 0, '', $conf->entity);	
		
	$db->commit();

	$cgiBinPath = rtrim($api_cgi,"\\/");
	$cgiBinPath = str_replace('/', DIRECTORY_SEPARATOR, $cgiBinPath);
			
	$cgiScripts = array('modulev500-RhE5-32bits.cgi_', 'modulev500-RhE5-64bits.cgi_', 'modulev500-Windows-32bits.exe_');
	
	if (file_exists($cgiBinPath))
	{
		$osName = php_uname('s');
		$osVersion = php_uname('m');
		
		// Copy file
		$isWin = false;
		if (strtoupper($osName) === 'WIN')
		{
			$isWin = true;
			$cgiScript = $cgiScripts[2];		
		}
		else
		{
			if (strtoupper($osVersion) == 'X86_64')
			{
				$cgiScript = $cgiScripts[1];
			}
			else
			{
				$cgiScript = $cgiScripts[0];
			}
		}
		
		$destFile = $cgiBinPath .DIRECTORY_SEPARATOR.($isWin ? 'modulev2.exe' : 'modulev2.cgi');
		$srcFile = dol_buildpath('/etransactions/bin/'.$cgiScript);
		
		if (copy($srcFile, $destFile))
		{
			@chmod($destFile, 0755);
			
			$message = $langs->trans("SetupSaved");
			$error = false;			
		}
		else
		{
			$message = $langs->trans("ErrorDuringScriptCopy");
			$error = true;
		}
		

	
	}
	else
	{
		$message = $langs->trans("CgiPathDoesNotExist");
		$error = true;	
	}


}

$api_key_editor = new DolEditor('api_key', $api_key, '',200,'dolibarr_notes','',false,true, $conf->fckeditor->enabled,ROWS_6,50);


$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';

$htmltooltips = array(
    'ApiTest'    => $langs->trans("ApiTestTooltip"),
    'ApiShopId'  => $langs->trans("ApiShopIdTooltip"),
    'ApiId'  => $langs->trans("ApiIdTooltip"),
    'ApiKey'  => $langs->trans("ApiKeyTooltip"),
    'ApiCgi'  => $langs->trans("ApiCgiTooltip"),
    'ApiRank'  => $langs->trans("ApiRankTooltip"),
    'SecurityToken' => $langs->trans("SecurityTokenTooltip"),
    'DeliveryReceiptEmail' => $langs->trans("DeliveryReceiptEmailTooltip"), 
    'CcEmail' => $langs->trans("CcEmailTooltip"), 
    'CcEmails' => $langs->trans("CcEmailsTooltip"), 
    'UpdateInvoiceStatut' => $langs->trans("UpdateInvoiceStatutTooltip"), 
    'BankAccountId' => $langs->trans("BankAccountIdTooltip"),
    'PaymentAutoSend' => $langs->trans("PaymentAutoSendTooltip"),
                        
);

$form = new Form($db);

require_once("../tpl/admin.config.tpl.php");

$db->close();

?>
