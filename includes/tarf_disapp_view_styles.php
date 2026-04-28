<?php
/**
 * Shared DISAPP (2.2D) card CSS for full page and in-page modals.
 */
if (!function_exists('tarf_emit_disapp_view_styles')) {
    function tarf_emit_disapp_view_styles(): void
    {
        static $emitted = false;
        if ($emitted) {
            return;
        }
        $emitted = true;
        ?>
    <style>
        /* 2.2D DISAPP [TRAVEL] — typography/spacing matched to Word (half-points ÷ 2 = pt) */
        .tarf-disapp {
            font-family: Georgia, "Times New Roman", serif;
            color: #000;
            max-width: 8.75in;
            margin: 0 auto;
            padding: 0.15rem 0 1.5rem;
            background: transparent;
            box-shadow: none;
        }
        .tarf-disapp .tarf-disapp-hdr {
            margin: 0 0 0.5rem;
            line-height: 0;
        }
        .tarf-disapp .tarf-disapp-hdr-img {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
        }
        .tarf-disapp .tarf-disapp-ftr {
            margin-top: 1.35rem;
            padding-top: 0.35rem;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 9pt;
            line-height: 1.25;
        }
        .tarf-disapp .tarf-disapp-ftr-line {
            margin: 0 0 0.12rem;
            color: #1f3864;
        }
        .tarf-disapp .tarf-disapp-ftr-addr .tarf-ftr-c1 { color: #1f3864; }
        .tarf-disapp .tarf-disapp-ftr-addr .tarf-ftr-c2 { color: #222a35; }
        .tarf-disapp .tarf-disapp-ftr-web {
            font-family: Gungsuh, "Times New Roman", Georgia, serif;
            color: #1f3864;
        }
        .tarf-disapp .tarf-disapp-ftr-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.35rem 1rem;
            color: #1f3864;
        }
        .tarf-disapp .tarf-disapp-ftr-docref {
            font-weight: 700;
            color: #1f3864;
        }
        .tarf-disapp .tarf-head-num {
            text-align: right;
            font-size: 12pt;
            line-height: 1.2;
            margin: 0 0 0.15rem;
        }
        .tarf-disapp .tarf-head-num .tarf-head-i { font-style: italic; }
        .tarf-disapp .tarf-head-num .tarf-head-u { font-style: italic; text-decoration: underline; }
        .tarf-disapp .tarf-title {
            text-align: center;
            font-weight: 700;
            font-size: 12pt;
            margin: 0.35rem 0 0.85rem;
            line-height: 1.2;
        }
        .tarf-disapp table.disapp-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-left: -0.075in;
            font-size: 9pt;
            line-height: 1.5;
        }
        .tarf-disapp table.disapp-grid td {
            border: 0.5pt solid #000;
            padding: 0.28rem 0.4rem;
            vertical-align: top;
            text-align: justify;
        }
        .tarf-disapp table.disapp-grid .lbl {
            font-weight: 700;
        }
        .tarf-disapp table.disapp-grid col.disapp-col-label { width: 34.22%; }
        .tarf-disapp table.disapp-grid col.disapp-col-gap { width: 1%; }
        .tarf-disapp table.disapp-grid col.disapp-col-value { width: 64.78%; }
        .tarf-disapp table.disapp-grid tr.disapp-thick-sep td {
            border-bottom: 3pt solid #000;
        }
        .tarf-disapp table.disapp-grid tr.disapp-thick-sep + tr td {
            border-top: 3pt solid #000;
        }
        .tarf-disapp .tarf-disapp-office-order {
            margin-top: 1rem;
            padding-top: 0.65rem;
            border-top: 2pt solid #000;
            font-size: 11pt;
            line-height: 1.45;
        }
        .tarf-disapp .tarf-disapp-office-order .tarf-oo-title {
            text-align: center;
            font-weight: 700;
            font-size: 11pt;
            margin: 0 0 0.5rem;
            letter-spacing: 0.03em;
        }
        .tarf-disapp .tarf-disapp-office-order .tarf-oo-no {
            text-align: right;
            font-style: italic;
            text-decoration: underline;
            margin: 0 0 0.4rem;
            font-size: 12pt;
        }
        .tarf-disapp .tarf-disapp-office-order .tarf-oo-to {
            margin: 0 0 0.5rem;
            text-align: justify;
        }
        .tarf-disapp .tarf-disapp-office-order .tarf-oo-to strong {
            font-weight: 700;
        }
        .tarf-disapp .tarf-disapp-office-order .tarf-oo-body {
            margin: 0;
            text-align: justify;
        }
        .tarf-disapp .disapp-after-table { margin-top: 0.35rem; }
        .tarf-disapp .recommending-label {
            font-size: 11pt;
            line-height: 1.2;
            margin: 0.85rem 0 0.35rem;
            font-weight: 400;
        }
        .tarf-disapp .endorse-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-left: -0.09rem;
            font-size: 11pt;
            line-height: 1.2;
        }
        .tarf-disapp .endorse-grid td {
            border: 1pt solid #000;
            padding: 0.35rem 0.5rem 0.45rem;
            vertical-align: top;
            width: 50%;
            text-align: center;
        }
        .tarf-disapp .endorse-grid .sub { font-size: 11pt; margin: 0 0 0.35rem; }
        .tarf-disapp .endorse-grid .name-line { font-weight: 700; font-size: 12pt; margin: 0; }
        .tarf-disapp .endorse-grid .role-line { font-size: 11pt; font-weight: 400; margin: 0; }
        .tarf-disapp .endorse-grid tr.endorse-names td { min-height: 4.5rem; }
        .tarf-disapp .endorse-grid tr.endorse-roles td { min-height: 1.15rem; }
        .tarf-disapp .endorse-grid.endorse-grid-tarf td { width: 33.333%; }
        .tarf-disapp .endorse-grid.endorse-grid-ntarf td { width: 25%; }
        .tarf-disapp .status-notes .note-block {
            font-size: 10pt;
            line-height: 1.35;
            margin: 0.5rem 0 0;
            text-align: justify;
        }
        .tarf-disapp .status-notes .note-block:first-of-type { margin-top: 0.35rem; }
        .tarf-disapp .status-notes .note-block strong {
            font-size: 10pt;
            font-weight: 700;
            display: block;
            margin-bottom: 0.15rem;
        }
        .tarf-disapp .ntarf-endorse-hint {
            font-size: 9pt;
            line-height: 1.2;
            margin: 0.15rem 0 0.25rem;
            font-weight: 400;
        }
        .tarf-disapp .approved-block { margin-top: 0.85rem; font-size: 11pt; line-height: 1.25; }
        .tarf-disapp .approved-block .approved-label { margin: 0; font-weight: 400; }
        .tarf-disapp .approved-block .approved-spacer { margin: 0; min-height: 0.65rem; }
        .tarf-disapp .approved-block .approved-esig-wrap {
            margin: 0.15rem 0 0;
            line-height: 0;
            min-height: 2.5rem;
        }
        .tarf-disapp .approved-block .approved-esig {
            display: block;
            max-width: 220px;
            width: auto;
            height: auto;
            max-height: 72px;
            object-fit: contain;
        }
        .tarf-disapp .approved-block .approved-name { margin: 0.15rem 0 0; font-weight: 700; }
        .tarf-disapp .approved-block .approved-title {
            margin: 0.15rem 0 0;
            font-weight: 400;
            text-decoration: underline;
        }
        .tarf-disapp .status-notes .final {
            font-weight: 700;
            font-size: 15pt;
            text-decoration: underline;
            line-height: 1.25;
            margin: 0 0 0.35rem;
        }
        .tarf-disapp .status-notes .note-line {
            font-size: 10pt;
            line-height: 1.35;
            margin: 0.15rem 0 0;
            text-align: justify;
        }
        .tarf-disapp .disapp-official-form-ref { margin-top: 0.85rem; font-size: 10pt; line-height: 1.35; }
        .tarf-disapp .disapp-official-form-ref strong { display: block; margin-bottom: 0.2rem; }
        .tarf-disapp .disapp-filled-official-form-ref { margin-top: 0.55rem; font-size: 10pt; line-height: 1.35; }
        .tarf-disapp .disapp-filled-official-form-ref strong { display: block; margin-bottom: 0.2rem; }
        .tarf-disapp .attachments { margin-top: 1rem; font-size: 10pt; }
        .tarf-disapp .tarf-portal-meta { margin-top: 0.65rem; font-size: 9pt; color: #444; }
        .layout-faculty.tarf-request-view .tarf-disapp,
        .tarf-request-preview-modal .modal-body .tarf-disapp { overflow: visible; }
        @media print {
            /*
             * Page setup matches Word DISAPP layout: Top 2.54 cm, Left/Right 1.27 cm, Bottom 0.76 cm (A4 portrait).
             * Do not change @page margins. To fit NTARF/TARF on one sheet: zoom + tighter vertical spacing (print only).
             */
            @page {
                size: A4 portrait;
                margin: 2.54cm 1.27cm 0.76cm 1.27cm;
            }
            .no-print { display: none !important; }
            html {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .tarf-disapp {
                max-width: 8.75in;
                margin-left: auto;
                margin-right: auto;
                padding: 0 0 0.35rem;
                box-sizing: border-box;
                zoom: 0.74;
            }
            .tarf-disapp .tarf-disapp-hdr {
                margin: 0 0 0.2rem;
            }
            .tarf-disapp .tarf-disapp-hdr-img {
                display: block;
                width: auto;
                max-width: 100%;
                max-height: 46px;
                height: auto;
                margin: 0 auto;
            }
            .tarf-disapp .tarf-head-num {
                margin: 0 0 0.05rem;
            }
            .tarf-disapp .tarf-title {
                margin: 0.15rem 0 0.35rem;
            }
            .tarf-disapp table.disapp-grid {
                margin-left: 0;
                line-height: 1.22;
            }
            .tarf-disapp table.disapp-grid td {
                padding: 0.14rem 0.28rem;
            }
            .tarf-disapp .disapp-after-table {
                margin-top: 0.2rem;
            }
            .tarf-disapp .status-notes .note-block {
                margin-top: 0.22rem;
                line-height: 1.22;
            }
            .tarf-disapp .status-notes .note-block:first-of-type {
                margin-top: 0.18rem;
            }
            .tarf-disapp .status-notes .final {
                margin: 0 0 0.12rem;
                line-height: 1.15;
            }
            .tarf-disapp .status-notes .note-line {
                margin: 0.06rem 0 0;
                line-height: 1.22;
            }
            .tarf-disapp .recommending-label {
                margin: 0.35rem 0 0.12rem;
            }
            .tarf-disapp .endorse-grid {
                margin-left: 0;
                line-height: 1.12;
            }
            .tarf-disapp .endorse-grid td {
                padding: 0.15rem 0.3rem 0.22rem;
            }
            .tarf-disapp .endorse-grid .sub {
                margin: 0 0 0.1rem;
            }
            .tarf-disapp .endorse-grid tr.endorse-names td {
                min-height: 0 !important;
            }
            .tarf-disapp .endorse-grid tr.endorse-roles td {
                min-height: 0 !important;
            }
            .tarf-disapp .ntarf-endorse-hint {
                margin: 0.04rem 0 0.08rem;
            }
            .tarf-disapp .approved-block {
                margin-top: 0.28rem;
            }
            .tarf-disapp .approved-block .approved-esig-wrap {
                min-height: 0 !important;
                margin: 0.08rem 0 0;
            }
            .tarf-disapp .approved-block .approved-esig {
                max-height: 40px;
            }
            .tarf-disapp .approved-block .approved-spacer {
                min-height: 0.2rem;
                margin: 0;
                line-height: 1;
            }
            .tarf-disapp .disapp-official-form-ref {
                margin-top: 0.25rem;
            }
            .tarf-disapp .disapp-filled-official-form-ref {
                margin-top: 0.15rem;
            }
            .tarf-disapp .attachments {
                margin-top: 0.35rem;
            }
            .tarf-disapp .tarf-disapp-ftr {
                margin-top: 0.35rem;
                padding-top: 0.12rem;
                line-height: 1.12;
            }
            .tarf-disapp .tarf-disapp-ftr-line {
                margin: 0 0 0.04rem;
            }
            body.tarf-request-view .main-content,
            body.tarf-request-view .container-fluid {
                padding: 0 !important;
                margin: 0 !important;
                max-width: none !important;
            }
            body.tarf-request-view .row {
                margin: 0 !important;
            }
            /*
             * Preview modal on faculty/tarf_request: Do NOT use visibility:hidden — it keeps
             * layout height (sidebar + long forms) so print preview shows many blank/extra pages.
             * Collapse layout with display:none on chrome and siblings of the preview modal only.
             */
            html:has(.tarf-request-preview-modal.show),
            body:has(.tarf-request-preview-modal.show) {
                height: auto !important;
                min-height: 0 !important;
            }
            body:has(.tarf-request-preview-modal.show) header.header,
            body:has(.tarf-request-preview-modal.show) #sidebar,
            body:has(.tarf-request-preview-modal.show) .sidebar.faculty-sidebar {
                display: none !important;
            }
            body:has(.tarf-request-preview-modal.show) main.main-content > *:not(#tarfRequestViewModal) {
                display: none !important;
            }
            body:has(.tarf-request-preview-modal.show) .modal-backdrop {
                display: none !important;
            }
            body:has(.tarf-request-preview-modal.show) main.main-content {
                display: block !important;
                min-height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal.modal {
                display: block !important;
                position: static !important;
                inset: auto !important;
                width: auto !important;
                height: auto !important;
                overflow: visible !important;
                padding: 0 !important;
                margin: 0 !important;
                opacity: 1 !important;
                transform: none !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-dialog {
                max-width: none !important;
                margin: 0 !important;
                transform: none !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-dialog-scrollable .modal-body {
                max-height: none !important;
                overflow: visible !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-content {
                border: none !important;
                box-shadow: none !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-header,
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-footer {
                display: none !important;
            }
            body:has(.tarf-request-preview-modal.show) #tarfRequestViewModal .modal-body {
                padding: 0 !important;
            }
            body:has(.tarf-request-preview-modal.show) .container-fluid,
            body:has(.tarf-request-preview-modal.show) .container-fluid > .row {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
                min-height: 0 !important;
            }
            /* Mobile bottom bar + chat sit outside <main> (faculty_sidebar) — must hide for “form only” */
            body:has(.tarf-request-preview-modal.show) nav.faculty-bottom-nav,
            body:has(.tarf-request-preview-modal.show) .faculty-bottom-nav,
            body:has(.tarf-request-preview-modal.show) .chat-bubble-container,
            body:has(.tarf-request-preview-modal.show) #chatBubble {
                display: none !important;
            }
            body:has(.tarf-request-preview-modal.show) .main-content {
                padding-bottom: 0 !important;
            }
            /* Full-page view: print only DISAPP (faculty + admin tarf_request_view) */
            body.tarf-request-view:has(.tarf-disapp) header.header,
            body.tarf-request-view:has(.tarf-disapp) #sidebar,
            body.tarf-request-view:has(.tarf-disapp) .sidebar,
            body.tarf-request-view:has(.tarf-disapp) nav.faculty-bottom-nav,
            body.tarf-request-view:has(.tarf-disapp) .faculty-bottom-nav,
            body.tarf-request-view:has(.tarf-disapp) .chat-bubble-container,
            body.tarf-request-view:has(.tarf-disapp) #chatBubble {
                display: none !important;
            }
            body.tarf-request-view:has(.tarf-disapp) .container-fluid,
            body.tarf-request-view:has(.tarf-disapp) .container-fluid > .row {
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }
            body.tarf-request-view:has(.tarf-disapp) main.main-content {
                padding: 0 !important;
                margin: 0 !important;
                min-height: 0 !important;
            }
            html:has(body.tarf-request-view .tarf-disapp),
            body.tarf-request-view:has(.tarf-disapp) {
                height: auto !important;
                min-height: 0 !important;
            }
        }
    </style>
        <?php
    }
}
