<?php
/**
 * NTARF DISAPP card markup; expects variables from ntarf_render_disapp_card_html().
 *
 * @var array $row
 * @var array $form
 * @var array $attachments
 * @var array $opts
 * @var string $requestedSupportHtml
 * @var string $fundLine
 * @var string $fundCertLabel
 * @var string $totalAmt
 * @var string $filedTs
 * @var string $finalApprovalLine
 * @var string $statusNotesEndorser
 * @var string $statusNotesFund
 * @var string $notesApproverEndorser
 * @var string $notesFundAvailability
 * @var string $notesVenuePmes
 * @var string $supName
 * @var string $endName
 * @var string $uploadBase
 * @var string $dateTimeCell
 * @var bool $disappShowOfficialFormLink when true (endorsed), show link to official blank Word form
 * @var string|null $filledOfficialOrderHref download URL for filled official form PDF (endorsed)
 * @var string|null $filledOfficialOrderLabel anchor text for filled PDF
 * @var string $officeOrderSectionHtml OFFICE ORDER block (non-travel narrative)
 */
$disappHeaderSrc = function_exists('asset_url') ? asset_url('tarf_disapp_header.png', true) : 'assets/tarf_disapp_header.png';
$presidentApprovedEsig = !empty($row['president_endorsed_by']);
$bp = function_exists('getBasePath') ? rtrim((string) getBasePath(), '/') : '';
$presEsigSrc = ($bp !== '' ? $bp : '') . '/traf_docx/' . rawurlencode('madam esig.jpg');
$pdfExport = !empty($GLOBALS['tarf_disapp_pdf_export']);
?>
        <div class="tarf-disapp mb-4">
            <header class="tarf-disapp-hdr" aria-label="Institution header">
                <img src="<?php echo tarf_disapp_escape($disappHeaderSrc); ?>" alt="" width="573" height="90" class="tarf-disapp-hdr-img">
            </header>
            <div class="tarf-head-num"><span class="tarf-head-i">NTARF # </span><span class="tarf-head-u"><?php echo (int) $row['id']; ?> s. <?php echo (int) $row['serial_year']; ?></span></div>
            <div class="tarf-title">[NON-TRAVEL] ACTIVITY REQUEST FORM</div>

            <table class="disapp-grid">
                <colgroup>
                    <col class="disapp-col-label">
                    <col class="disapp-col-gap">
                    <col class="disapp-col-value">
                </colgroup>
                <tr>
                    <td class="lbl" colspan="2">Name of Requester</td>
                    <td><?php echo tarf_disapp_escape($form['requester_name'] ?? ''); ?> (<?php echo tarf_disapp_escape($form['college_office_project'] ?? ''); ?>)</td>
                </tr>
                <tr class="disapp-thick-sep">
                    <td class="lbl" colspan="2">Date Filed</td>
                    <td><?php echo tarf_disapp_escape($filedTs); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Activity Requested</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['activity_requested'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Main Organizer</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['main_organizer'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Justification/Explanation</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['justification'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Venue</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['venue'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Date &amp; Time of Activity</td>
                    <td><?php echo nl2br(tarf_disapp_escape($dateTimeCell)); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Involved WPU Personnel</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['involved_wpu_personnel'] ?? '')); ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Type of Involvement</td>
                    <td><?php echo nl2br(tarf_disapp_escape($form['type_of_involvement'] ?? '')); ?></td>
                </tr>
                <tr class="disapp-thick-sep">
                    <td class="lbl" colspan="2">Requested Support</td>
                    <td><?php echo $requestedSupportHtml; ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Funding Charged to</td>
                    <td><?php echo $fundLine; ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Total Estimated Amount</td>
                    <td><?php echo $totalAmt !== '—' ? tarf_disapp_escape($totalAmt) : '—'; ?></td>
                </tr>
                <tr>
                    <td class="lbl" colspan="2">Status and Notes</td>
                    <td class="status-notes">
                        <div class="note-block"><strong>Final Approval:</strong></div>
                        <div class="final"><?php echo tarf_disapp_escape($finalApprovalLine); ?></div>
                        <div class="note-block"><strong>Notes from Approver or Endorser:</strong><?php echo nl2br(tarf_disapp_escape($notesApproverEndorser)); ?></div>
                        <div class="note-block"><strong>Notes re Fund Availability:</strong><?php echo nl2br(tarf_disapp_escape($notesFundAvailability)); ?></div>
                        <div class="note-block"><strong>Venue or PMES Notes:</strong><?php echo nl2br(tarf_disapp_escape($notesVenuePmes)); ?></div>
                    </td>
                </tr>
            </table>

            <?php if (!empty($officeOrderSectionHtml)): ?>
            <section class="tarf-disapp-office-order" aria-label="Office order">
                <?php echo $officeOrderSectionHtml; ?>
            </section>
            <?php endif; ?>

            <p class="recommending-label disapp-after-table">Recommending Approval:</p>
            <table class="endorse-grid endorse-grid-ntarf">
                <tr class="endorse-names">
                    <td>
                        <div class="sub">(Endorsed through NTARF System)</div>
                        <div class="sub ntarf-endorse-hint">Applicable Endorser&rsquo;s Name</div>
                        <div class="name-line"><?php
                            $ae = trim((string) ($form['applicable_endorser'] ?? ''));
                            echo $ae !== '' ? tarf_disapp_escape($ae) : '—';
                            if (!empty($endName)) {
                                echo '<br>', tarf_disapp_escape($endName);
                            }
                        ?></div>
                    </td>
                    <td>
                        <div class="sub">(Endorsed through NTARF System)</div>
                        <div class="sub ntarf-endorse-hint">Fund Availability Certified By</div>
                        <div class="name-line"><?php echo $fundCertLabel !== '—' ? $fundCertLabel : '—'; ?></div>
                    </td>
                    <td>
                        <div class="sub">(Endorsed through NTARF System)</div>
                        <div class="sub ntarf-endorse-hint">Endorser for Venue Availability</div>
                        <div class="name-line">—</div>
                    </td>
                    <td>
                        <div class="sub">(Endorsed through NTARF System)</div>
                        <div class="sub ntarf-endorse-hint">Endorser for Electricity and Generator Use</div>
                        <div class="name-line">—</div>
                    </td>
                </tr>
                <tr class="endorse-roles">
                    <td><div class="role-line">Immediate Supervisor</div></td>
                    <td><div class="role-line">Budget/Accounting</div></td>
                    <td><div class="role-line">Facility In-Charge</div></td>
                    <td><div class="role-line">PMES Supervisor</div></td>
                </tr>
            </table>

            <div class="approved-block">
                <p class="approved-label">Approved:</p>
                <?php if ($presidentApprovedEsig): ?>
                <p class="approved-esig-wrap">
                    <img src="<?php echo tarf_disapp_escape($presEsigSrc); ?>" alt="" class="approved-esig">
                </p>
                <?php else: ?>
                <p class="approved-spacer" aria-hidden="true">&nbsp;</p>
                <p class="approved-spacer" aria-hidden="true">&nbsp;</p>
                <?php endif; ?>
                <p class="approved-name">Amabel S. Liao, PhD</p>
                <p class="approved-title">University President</p>
            </div>

            <?php
            if (!empty($disappShowOfficialFormLink) && empty($pdfExport)) {
                $officialFormHref = ($bp !== '' ? $bp : '') . '/traf_docx/' . rawurlencode('2.1 [NON-TRAVEL] Activity Request Form.docx');
                $officialFormLabel = '2.1 [NON-TRAVEL] Activity Request Form.docx';
                ?>
            <div class="disapp-official-form-ref">
                <strong>Official blank form</strong>
                <p class="mb-0"><a href="<?php echo tarf_disapp_escape($officialFormHref); ?>"><?php echo tarf_disapp_escape($officialFormLabel); ?></a></p>
            </div>
            <?php } ?>

            <?php if (!empty($filledOfficialOrderHref) && empty($pdfExport)): ?>
            <div class="disapp-filled-official-form-ref">
                <strong>Filled official form (from your request)</strong>
                <p class="mb-0"><a href="<?php echo tarf_disapp_escape($filledOfficialOrderHref); ?>" download><?php echo tarf_disapp_escape($filledOfficialOrderLabel ?? 'Download'); ?></a></p>
            </div>
            <?php endif; ?>

            <?php if (count($attachments) > 0): ?>
            <div class="attachments">
                <strong>Attachments</strong>
                <ul class="mb-0">
                    <?php foreach ($attachments as $a):
                        $p = $a['path'] ?? '';
                        if ($p === '') {
                            continue;
                        }
                        $href = $uploadBase . str_replace('\\', '/', $p);
                        ?>
                        <li><?php if (!empty($pdfExport)): ?><?php echo tarf_disapp_escape($a['original_name'] ?? basename($p)); ?> <span class="text-muted">(<?php echo tarf_disapp_escape($a['role'] ?? 'file'); ?>)</span><?php else: ?><a href="<?php echo tarf_disapp_escape($href); ?>" target="_blank" rel="noopener"><?php echo tarf_disapp_escape($a['original_name'] ?? basename($p)); ?></a> <span class="text-muted">(<?php echo tarf_disapp_escape($a['role'] ?? 'file'); ?>)</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <footer class="tarf-disapp-ftr" aria-label="Institution footer">
                <p class="tarf-disapp-ftr-line tarf-disapp-ftr-addr">
                    <span class="tarf-ftr-c1">San </span><span class="tarf-ftr-c2">Juan</span><span class="tarf-ftr-c1">, Aborlan, Palawan 5302</span>
                </p>
                <p class="tarf-disapp-ftr-line tarf-disapp-ftr-web">www.wpu.edu.ph ● pres.office@wpu.edu.ph</p>
                <p class="tarf-disapp-ftr-line tarf-disapp-ftr-meta">
                    <span class="tarf-disapp-ftr-mobile"> Mobile: +639193836791</span>
                    <span class="tarf-disapp-ftr-docref">WPU- QSF-OUOP-OUP-08 Rev.00 (10.21.24)</span>
                </p>
            </footer>

            <?php if (empty($pdfExport)): ?>
            <div class="tarf-portal-meta no-print">
                Requester email: <?php echo tarf_disapp_escape($form['requester_email'] ?? ''); ?> ·
                Supervisor / unit email: <?php echo tarf_disapp_escape($form['supervisor_email'] ?? ''); ?>
            </div>
            <?php endif; ?>
        </div>
