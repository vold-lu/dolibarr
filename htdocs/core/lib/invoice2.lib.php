<?php
/* Copyright (C) 2009-2013  Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France             <frederic.france@free.fr>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       htdocs/core/lib/invoice2.lib.php
 *      \ingroup    invoice
 *      \brief      Function to rebuild PDF and merge PDF files into one
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';


/**
 * Function to build a compiled PDF
 *
 * @param	DoliDB		$db						Database handler
 * @param	Translate	$langs					Object langs
 * @param	Conf		$conf					Object conf
 * @param	string		$diroutputpdf			Dir to output file
 * @param	string		$newlangid				Lang id
 * @param 	string[]	$filter					Array with filters
 * @param 	integer		$dateafterdate			Invoice after date
 * @param 	integer 	$datebeforedate			Invoice before date
 * @param 	integer		$paymentdateafter		Payment after date (must includes hour)
 * @param 	integer		$paymentdatebefore		Payment before date (must includes hour)
 * @param	int			$usestdout				Add information onto standard output
 * @param	string		$regenerate				''=Use existing PDF files, 'nameofpdf'=Regenerate all PDF files using the template
 * @param	string		$filesuffix				Suffix to add into file name of generated PDF
 * @param	string		$paymentbankid			Only if payment on this bank account id
 * @param	int[]		$thirdpartiesid			List of thirdparties id when using filter=excludethirdpartiesid	or filter=onlythirdpartiesid
 * @param	string		$fileprefix				Prefix to add into filename of generated PDF
 * @param	int			$donotmerge				0=Default, 1=Disable the merge so do only the regeneration of PDFs.
 * @param	string		$mode					'invoice' for invoices, 'proposal' for proposal, ...
 * @return	int									Error code
 */
function rebuild_merge_pdf($db, $langs, $conf, $diroutputpdf, $newlangid, $filter, $dateafterdate, $datebeforedate, $paymentdateafter, $paymentdatebefore, $usestdout, $regenerate = '', $filesuffix = '', $paymentbankid = '', $thirdpartiesid = [], $fileprefix = 'mergedpdf', $donotmerge = 0, $mode = 'invoice')
{
	if ($mode == 'invoice') {
		require_once DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php";
		require_once DOL_DOCUMENT_ROOT."/core/modules/facture/modules_facture.php";

		$table = "facture";
		$dir_output = $conf->facture->dir_output;
		$date = "datef";

		if ($diroutputpdf == 'auto') {
			$diroutputpdf = $conf->invoice->dir_output.'/temp';
		}
	} elseif ($mode == 'order') {
		require_once DOL_DOCUMENT_ROOT."/commande/class/commande.class.php";
		require_once DOL_DOCUMENT_ROOT."/core/modules/commande/modules_commande.php";

		$table = "commande";
		$dir_output = $conf->order->dir_output;
		$date = "date";

		if ($diroutputpdf == 'auto') {
			$diroutputpdf = $conf->order->dir_output.'/temp';
		}
	} elseif ($mode == 'proposal') {
		require_once DOL_DOCUMENT_ROOT."/comm/propal/class/propal.class.php";
		require_once DOL_DOCUMENT_ROOT."/core/modules/propale/modules_propale.php";

		$table = "propal";
		$dir_output = $conf->propal->dir_output;
		$date = "datep";

		if ($diroutputpdf == 'auto') {
			$diroutputpdf = $conf->propal->dir_output.'/temp';
		}
	} elseif ($mode == 'shipment') {
		require_once DOL_DOCUMENT_ROOT."/expedition/class/expedition.class.php";
		require_once DOL_DOCUMENT_ROOT."/core/modules/expedition/modules_expedition.php";

		$table = "propal";
		$dir_output = $conf->shipment->dir_output;
		$date = "date";

		if ($diroutputpdf == 'auto') {
			$diroutputpdf = $conf->shipment->dir_output.'/temp';
		}
	} else {
		print "Bad value for mode";
		return -1;
	}

	$sql = "SELECT DISTINCT f.rowid, f.ref";
	$sql .= " FROM ".MAIN_DB_PREFIX.$table." as f";
	$sqlwhere = '';
	$sqlorder = '';
	if (in_array('all', $filter)) {
		$sqlorder = " ORDER BY f.ref ASC";
	}
	if (in_array('date', $filter)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= " f.fk_statut > 0";
		$sqlwhere .= " AND f.".$db->sanitize($date)." >= '".$db->idate($dateafterdate)."'";
		$sqlwhere .= " AND f.".$db->sanitize($date)." <= '".$db->idate($datebeforedate)."'";
		$sqlorder = " ORDER BY ".$db->sanitize($date)." ASC";
	}
	// Filter for invoices only
	if (in_array('nopayment', $filter)) {
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON f.rowid = pf.fk_facture";
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= " f.fk_statut > 0";
		$sqlwhere .= " AND pf.fk_paiement IS NULL";
	}
	// Filter for invoices only
	if (in_array('payments', $filter) || in_array('bank', $filter)) {
		$sql .= ", ".MAIN_DB_PREFIX."paiement_facture as pf, ".MAIN_DB_PREFIX."paiement as p";
		if (in_array('bank', $filter)) {
			$sql .= ", ".MAIN_DB_PREFIX."bank as b";
		}
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= " f.fk_statut > 0";
		$sqlwhere .= " AND f.rowid = pf.fk_facture";
		$sqlwhere .= " AND pf.fk_paiement = p.rowid";
		if (in_array('payments', $filter)) {
			$sqlwhere .= " AND p.datep >= '".$db->idate($paymentdateafter)."'";
			$sqlwhere .= " AND p.datep <= '".$db->idate($paymentdatebefore)."'";
		}
		if (in_array('bank', $filter)) {
			$sqlwhere .= " AND p.fk_bank = b.rowid";
			$sqlwhere .= " AND b.fk_account = ".((int) $paymentbankid);
		}
		$sqlorder = " ORDER BY p.datep ASC";
	}
	// Filter for invoices only
	if (in_array('nodeposit', $filter)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= ' type <> 3';
	}
	// Filter for invoices only
	if (in_array('noreplacement', $filter)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= ' type <> 1';
	}
	// Filter for invoices only
	if (in_array('nocreditnote', $filter)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= ' type <> 2';
	}
	if (in_array('excludethirdparties', $filter) && is_array($thirdpartiesid)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= ' f.fk_soc NOT IN ('.$db->sanitize(implode(',', $thirdpartiesid)).')';
	}
	if (in_array('onlythirdparties', $filter) && is_array($thirdpartiesid)) {
		if (empty($sqlwhere)) {
			$sqlwhere = ' WHERE ';
		} else {
			$sqlwhere .= " AND";
		}
		$sqlwhere .= ' f.fk_soc IN ('.$db->sanitize(implode(',', $thirdpartiesid)).')';
	}
	if ($sqlwhere) {
		$sql .= $sqlwhere;
	}
	if ($sqlorder) {
		$sql .= $sqlorder;
	}

	//print $sql; exit;
	dol_syslog("scripts/invoices/rebuild_merge.php:", LOG_DEBUG);

	if ($usestdout) {
		print '--- start'."\n";
	}

	// Start of transaction
	//$db->begin();

	$error = 0;
	$result = 0;
	$files = array(); // liste les fichiers

	dol_syslog("scripts/invoices/rebuild_merge.php", LOG_DEBUG);
	if ($resql = $db->query($sql)) {
		$num = $db->num_rows($resql);
		$cpt = 0;

		if ($num) {
			// First loop on each resultset to build PDF
			// -----------------------------------------

			while ($cpt < $num) {
				$obj = $db->fetch_object($resql);

				$fac = null;
				if ($mode == 'invoice') {
					$fac = new Facture($db);
				} elseif ($mode == 'order') {
					$fac = new Commande($db);
				} elseif ($mode == 'proposal') {
					$fac = new Propal($db);
				} elseif ($mode == 'shipment') {
					$fac = new Expedition($db);
				}

				$result = $fac->fetch($obj->rowid);
				if ($result > 0) {
					$outputlangs = $langs;
					if (!empty($newlangid)) {
						if ($outputlangs->defaultlang != $newlangid) {
							$outputlangs = new Translate("", $conf);
							$outputlangs->setDefaultLang($newlangid);
						}
					}
					$filename = $dir_output.'/'.$fac->ref.'/'.$fac->ref.'.pdf';
					if ($regenerate || !dol_is_file($filename)) {
						if ($usestdout) {
							print "Build PDF for document ".$obj->ref." - Lang = ".$outputlangs->defaultlang."\n";
						}
						$result = $fac->generateDocument($regenerate ? $regenerate : $fac->model_pdf, $outputlangs);
					} else {
						if ($usestdout) {
							print "PDF for document ".$obj->ref." already exists\n";
						}
					}

					// Add file into files array
					$files[] = $filename;
				}

				if ($result <= 0) {
					$error++;
					if ($usestdout) {
						print "Error: Failed to build PDF for document ".($fac->ref ? $fac->ref : ' id '.$obj->rowid)."\n";
					} else {
						dol_syslog("Failed to build PDF for document ".($fac->ref ? $fac->ref : ' id '.$obj->rowid), LOG_ERR);
					}
				}

				$cpt++;
			}

			if ($donotmerge) {
				return 1;
			}

			// Define format of output PDF
			$formatarray = pdf_getFormat($langs);
			$page_largeur = $formatarray['width'];
			$page_hauteur = $formatarray['height'];
			$format = array($page_largeur, $page_hauteur);

			if ($usestdout) {
				print "Using output PDF format ".implode('x', $format)."\n";
			} else {
				dol_syslog("Using output PDF format ".implode('x', $format), LOG_ERR);
			}


			// Now, build a merged files with all files in $files array
			//---------------------------------------------------------

			// Create empty PDF
			$pdf = pdf_getInstance($format);
			if (class_exists('TCPDF')) {
				$pdf->setPrintHeader(false);
				$pdf->setPrintFooter(false);
			}
			$pdf->SetFont(pdf_getPDFFont($langs));

			if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
				$pdf->SetCompression(false);
			}
			//$pdf->SetCompression(false);

			$pagecount = 0;
			// Add all others
			foreach ($files as $file) {
				if ($usestdout) {
					print "Merge PDF file for invoice ".$file."\n";
				} else {
					dol_syslog("Merge PDF file for invoice ".$file);
				}

				// Charge un document PDF depuis un fichier.
				$pagecount = $pdf->setSourceFile($file);
				for ($i = 1; $i <= $pagecount; $i++) {
					$tplidx = $pdf->importPage($i);
					$s = $pdf->getTemplatesize($tplidx);
					$pdf->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
					$pdf->useTemplate($tplidx);
				}
			}

			// Create output dir if not exists
			dol_mkdir($diroutputpdf);

			// Save merged file
			$filename = $fileprefix;
			if (empty($filename)) {
				$filename = 'mergedpdf';
			}
			if (!empty($filesuffix)) {
				$filename .= '_'.$filesuffix;
			}
			$file = $diroutputpdf.'/'.$filename.'.pdf';

			if (!$error && $pagecount) {
				$pdf->Output($file, 'F');
				dolChmod($file);
			}

			if ($usestdout) {
				if (!$error) {
					print "Merged PDF has been built in ".$file."\n";
				} else {
					print "Can't build PDF ".$file."\n";
				}
			}

			$result = 1;
		} else {
			if ($usestdout) {
				print "No invoices found for criteria.\n";
			} else {
				dol_syslog("No invoices found for criteria");
			}
			$result = 0;
		}
	} else {
		dol_print_error($db);
		dol_syslog("scripts/invoices/rebuild_merge.php: Error");
		$error++;
	}

	if ($error) {
		return -1;
	} else {
		return $result;
	}
}
