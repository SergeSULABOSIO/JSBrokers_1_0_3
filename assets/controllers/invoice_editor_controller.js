import { Controller } from '@hotwired/stimulus';

// --- Utilitaires : Montant en Lettres ---
const numberToFrenchWords = (num) => {
    const units = ["", "un", "deux", "trois", "quatre", "cinq", "six", "sept", "huit", "neuf", "dix", "onze", "douze", "treize", "quatorze", "quinze", "seize", "dix-sept", "dix-huit", "dix-neuf"];
    const tens = ["", "dix", "vingt", "trente", "quarante", "cinquante", "soixante", "soixante-dix", "quatre-vingt", "quatre-vingt-dix"];

    if (num === 0) return "zéro";
    if (num < 20) return units[num];
    if (num < 100) {
        const t = Math.floor(num / 10);
        const u = num % 10;
        if (t === 7) return u === 1 ? "soixante et onze" : "soixante-" + units[10 + u];
        if (t === 9) return "quatre-vingt-" + units[10 + u];
        if (t === 8) return u === 0 ? "quatre-vingts" : "quatre-vingt-" + units[u];
        if (u === 0) return tens[t];
        if (u === 1) return tens[t] + " et un";
        return tens[t] + "-" + units[u];
    }
    if (num < 1000) {
        const h = Math.floor(num / 100);
        const rest = num % 100;
        let res = h === 1 ? "cent" : units[h] + " cent" + (rest === 0 ? "s" : "");
        return rest === 0 ? res : res + " " + numberToFrenchWords(rest);
    }
    if (num < 1000000) {
        const t = Math.floor(num / 1000);
        const rest = num % 1000;
        let res = t === 1 ? "mille" : numberToFrenchWords(t) + " mille";
        return rest === 0 ? res : res + " " + numberToFrenchWords(rest);
    }
    if (num < 1000000000) {
        const m = Math.floor(num / 1000000);
        const rest = num % 1000000;
        let res = m === 1 ? "un million" : numberToFrenchWords(m) + " millions";
        return rest === 0 ? res : res + " " + numberToFrenchWords(rest);
    }
    return num.toString();
};

const convertAmountToWords = (amount, currencyCode) => {
    const intPart = Math.floor(amount);
    const decPart = Math.round((amount - intPart) * 100);
    let words = numberToFrenchWords(intPart);
    words = words.charAt(0).toUpperCase() + words.slice(1);
    let str = `${words} ${currencyCode}`;
    if (decPart > 0) str += ` et ${numberToFrenchWords(decPart)} cent${decPart > 1 ? 's' : ''}`;
    return str;
};

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { submitUrl: String, invoiceId: String, previewModeClass: String }
    static targets = [
        "loadingBar", "errorToast", "errorMessage", "successToast", "successMessage",
        "duplicateToast", "duplicateMessage",
        "targetContent", "labelInput", "refInput", "dateInput", "autoLabelCheck",
        "itemsBody", "taxCodeDisplay", "taxRateDisplay", "currencyDisplay", "taxContainer", "currencyContainer",
        "subtotalDisplay", "taxRowDisplay", "taxNameDisplay", "taxAmountDisplay",
        "grandTotalDisplay", "wordsDisplay", "totalPaidDisplay", "balanceDueDisplay",
        "banksBody", "selectionModal", "modalTitle", "modalList", "modalSearchContainer",
        "statusUnsaved", "statusSaved", "statusTime", "btnSave", "btnSaveLabel", "btnAction", "exportWrapper", "exportMenu", "btnExport",
        "tooltip", "ttCotation", "ttRevenu", "ttType", "ttPolice", "ttAvenant", "ttPeriode", "ttMontantHT", "ttPrimeTTC", "ttCreditContent", "ttInvoiceContent", "ttRetroCom",
        "badgeCreation", "badgeId", "printArea", "mainTitle", "subtotalLabel", "grandTotalLabel", "paidLabel", "banksWrapper", "legalMentions", "taxExemptMention",
        "paymentsBadge", "paymentModal", "paymentListModal", "paymentAmountInput", "paymentDateInput", "paymentRefInput", "paymentCurrencyLabel", "paymentListBody", "paymentModalTitle", "paymentIdInput",
        "btnPaymentLabel", "paymentProofName", "paymentProofInput", "btnPaymentProofClear",
        "companyName", "userName", "userRole", "companyFooterName", "companyFooterInfo"
    ]

    connect() {
        this.MOCKS = {
            COMPANY: { nom: 'AIB RDC Sarl', adresse: 'Kinshasa, Gombe / RDC', telephone: '+243 81 000 0000', rccm: 'CD/KIN/RCCM/14-B-XXXX', idnat: '01-XXXXX-NXXXXX', numimpot: 'AXXXXXXX' },
            USER: { id: 1, nom: 'Jean Dupont', email: 'jean.dupont@aib-rdc.com', rolePrincipal: 'Courtier Senior' },
            ASSUREURS: [{ id: 10, nom: 'RAWSUR SA', adressePhysique: 'Boulevard du 30 Juin, Kinshasa', rccm: 'CD/KIN/RCCM/17-B-00000', numimpot: 'A0000000A' }, { id: 20, nom: 'ACTIVA ASSURANCES RDC', adressePhysique: 'Avenue de l\'Équateur, Kinshasa', rccm: 'CD/KIN/RCCM/13-B-11111', numimpot: 'A1111111B' }],
            CLIENTS: [{ id: 100, nom: 'Brasserie du Congo (BRACONGO)', adresse: 'Kingabwa, Kinshasa', rccm: 'CD/KIN/RCCM/14-B-4444', numimpot: 'A4444444D', idnat: '01-111-N11111' }, { id: 200, nom: 'Vodacom RDC', adresse: 'Gombe, Kinshasa', rccm: 'CD/KIN/RCCM/14-B-5555', numimpot: 'A5555555E', idnat: '01-222-N22222' }],
            PARTENAIRES: [{ id: 1000, nom: 'Cabinet de Conseil K&A', adressePhysique: 'Limete, Kinshasa', rccm: 'CD/KIN/RCCM/19-B-7777', numimpot: 'A7777777F', part: 0.15 }],
            TYPE_REVENUS: [{ id: 1, nom: 'Commission Ordinaire', redevable: 1 }, { id: 2, nom: 'Commission Fronting', redevable: 1 }, { id: 3, nom: 'Frais de Gestion', redevable: 0 }, { id: 4, nom: 'Honoraires de Conseil', redevable: 0 }],
            BANKS: [{ id: 1, intitule: 'Compte Principal', banque: 'Banque Centrale', numero: 'FR76 0000 0000 0000 0000 0000 000', codeSwift: 'XXXXXXXX' }, { id: 2, intitule: 'Compte Secondaire', banque: 'Société Générale', numero: 'FR76 3000 3000 0000 0000 0000 000', codeSwift: 'SOGEFRXP' }],
            MONNAIES: [{ id: 1, nom: 'Franc Congolais', code: 'CDF', tauxusd: '2800.00' }, { id: 2, nom: 'Dollars Américains', code: 'USD', tauxusd: '1.00' }],
            TAXES: [{ id: 1, description: 'Taxe sur la Valeur Ajoutée', code: 'TVA', tauxIARD: '0.16', redevable: 0 }, { id: 2, description: 'Frais Arca', code: 'ARCA', tauxIARD: '0.02', redevable: 1 }]
        };

        this.MOCKS.COTATIONS = [
            { id: 1, nom: 'Cotation Flotte Automobile - BGFI', montantTTC: 5450, assureur: this.MOCKS.ASSUREURS[0], client: this.MOCKS.CLIENTS[0], partenaires: [this.MOCKS.PARTENAIRES[0]], avenant: { referencePolice: 'POL-AUTO-001', numero: 'AV-001', startingAt: '01/01/2023', endingAt: '31/12/2023' }, tranches: [{ id: 101, nom: '1ère Tranche (Acompte)', pourcentage: 40, echeanceAt: '15/01/2023' }, { id: 102, nom: '2e Tranche', pourcentage: 30, echeanceAt: '15/04/2023' }, { id: 103, nom: '3e Tranche (Solde)', pourcentage: 30, echeanceAt: '15/07/2023' }], revenus: [{ id: 1, nom: 'Commission ordinaire - RC Auto', typeRevenu: this.MOCKS.TYPE_REVENUS[0], montantCalculeHT: 250, retroCommission: 37.50 }, { id: 2, nom: 'Frais d\'ouverture de dossier', typeRevenu: this.MOCKS.TYPE_REVENUS[2], montantCalculeHT: 150.00, retroCommission: 0 }] },
            { id: 2, nom: 'Cotation Assurance Santé', montantTTC: 12800.50, assureur: this.MOCKS.ASSUREURS[1], client: this.MOCKS.CLIENTS[1], partenaires: [], avenant: { referencePolice: 'POL-SANTE-089', numero: 'AV-INITIAL', startingAt: '01/06/2023', endingAt: '31/05/2024' }, tranches: [{ id: 201, nom: 'Paiement Unique', pourcentage: 100, echeanceAt: '01/06/2023' }], revenus: [{ id: 3, nom: 'Commission ordinaire - Santé', typeRevenu: this.MOCKS.TYPE_REVENUS[0], montantCalculeHT: 1500.00, retroCommission: 150.00 }, { id: 4, nom: 'Frais de gestion annuelle', typeRevenu: this.MOCKS.TYPE_REVENUS[2], montantCalculeHT: 300.00, retroCommission: 30.00 }] }
        ];
        this.MOCKS.FLATTENED_REVENUS = this.MOCKS.COTATIONS.flatMap(c => c.revenus.map(r => ({ ...r, cotation: { ...c, revenus: undefined } })));

        this.state = {
            billingTarget: 'assureur', 
            selectedTarget: this.MOCKS.ASSUREURS[0],
            items: [], 
            payments: [],
            bankAccounts: [{ id: Date.now(), bank: this.MOCKS.BANKS[0] }],
            taxe: this.MOCKS.TAXES[0],
            currency: this.MOCKS.MONNAIES[1], 
            isAutoLabelEnabled: true,
            hasUnsavedChanges: false,
            invoiceId: this.hasInvoiceIdValue && this.invoiceIdValue !== '' ? this.invoiceIdValue : null,
        };
        
        this.activeModalType = null;
        this.activeRowId = null;
        this.activeBankRowId = null;

        if (this.hasBtnExportTarget) {
            this.btnExportTarget.disabled = true;
        }

        this.initUI();
        this.renderAll();
    }

    initUI() {
        if (this.hasCompanyNameTarget) this.companyNameTarget.innerText = this.MOCKS.COMPANY.nom;
        if (this.hasUserNameTarget) this.userNameTarget.innerText = this.MOCKS.USER.nom;
        if (this.hasUserRoleTarget) this.userRoleTarget.innerText = this.MOCKS.USER.rolePrincipal;
        if (this.hasCompanyFooterNameTarget) this.companyFooterNameTarget.innerText = `${this.MOCKS.COMPANY.nom} • ${this.MOCKS.COMPANY.adresse} • Tél: ${this.MOCKS.COMPANY.telephone}`;
        if (this.hasCompanyFooterInfoTarget) this.companyFooterInfoTarget.innerText = `RCCM: ${this.MOCKS.COMPANY.rccm} • ID Nat: ${this.MOCKS.COMPANY.idnat} • N° Impôt: ${this.MOCKS.COMPANY.numimpot}`;

        if (this.state.invoiceId) {
            if (this.hasBadgeCreationTarget) this.badgeCreationTarget.classList.add('d-none');
            if (this.hasBadgeIdTarget) {
                this.badgeIdTarget.classList.remove('d-none');
                this.badgeIdTarget.innerText = `ID: ${this.state.invoiceId}`;
            }
        }
    }

    checkAllDuplicates() {
        let itemDupe = false;
        let bankDupe = false;

        const seenItems = new Set();
        for (let item of this.state.items) {
            if (!item.revenu || !item.tranche) continue;
            const key = `${item.revenu.id}-${item.quantity}-${item.unitPrice}-${item.tranche.id}`;
            if (seenItems.has(key)) { itemDupe = true; break; }
            seenItems.add(key);
        }

        const seenBanks = new Set();
        for (let acc of this.state.bankAccounts) {
            if (!acc.bank) continue;
            if (seenBanks.has(acc.bank.id)) { bankDupe = true; break; }
            seenBanks.add(acc.bank.id);
        }

        if (itemDupe) {
            this.showDuplicateToast("Cet article exact est déjà présent dans la facture.");
            return true;
        } else if (bankDupe) {
            this.showDuplicateToast("Ce compte bancaire est déjà présent dans la liste.");
            return true;
        } else {
            this.hideDuplicateToast();
            return false;
        }
    }

    showDuplicateToast(msg) {
        if (this.hasDuplicateMessageTarget) this.duplicateMessageTarget.innerText = msg;
        if (this.hasDuplicateToastTarget) {
            this.duplicateToastTarget.classList.remove('d-none');
            this.duplicateToastTarget.classList.add('d-flex');
        }
    }

    hideDuplicateToast() {
        if (this.hasDuplicateToastTarget) {
            this.duplicateToastTarget.classList.add('d-none');
            this.duplicateToastTarget.classList.remove('d-flex');
        }
    }

    renderAll() {
        this.updateTargetUI();
        this.updateLabel();
        this.renderItems();
        this.renderBanks();
        this.renderTotals();
        this.checkAllDuplicates();
    }

    updateTargetUI() {
        this.element.querySelectorAll('.btn-target').forEach(btn => {
            if (btn.dataset.targetType === this.state.billingTarget) {
                btn.className = "btn btn-light btn-sm text-xxs fw-bold text-uppercase tracking-widest shadow-sm text-dark btn-target";
            } else {
                btn.className = "btn btn-link btn-sm text-xxs fw-bold text-uppercase tracking-widest text-secondary text-decoration-none btn-target";
            }
        });

        const isCredit = this.state.billingTarget === 'partenaire';
        if (this.hasMainTitleTarget) this.mainTitleTarget.innerText = isCredit ? 'NOTE DE CRÉDIT' : 'FACTURE';
        if (this.hasSubtotalLabelTarget) this.subtotalLabelTarget.innerText = isCredit ? 'Total Lignes' : 'Sous-total HT';
        if (this.hasGrandTotalLabelTarget) this.grandTotalLabelTarget.innerText = isCredit ? 'Rétro-commission' : 'Total TTC';
        if (this.hasPaidLabelTarget) this.paidLabelTarget.innerText = isCredit ? 'Total Reversé' : 'Total Encaissé';
        if (this.hasBtnPaymentLabelTarget) this.btnPaymentLabelTarget.innerText = isCredit ? 'Reverser' : 'Encaisser';

        if (isCredit) {
            if (this.hasTaxRowDisplayTarget) { this.taxRowDisplayTarget.classList.add('d-none'); this.taxRowDisplayTarget.classList.remove('d-flex'); }
            if (this.hasTaxContainerTarget) this.taxContainerTarget.classList.add('opacity-50', 'pointer-events-none');
            if (this.hasBanksWrapperTarget) this.banksWrapperTarget.classList.add('d-none');
        } else {
            if (this.hasTaxRowDisplayTarget) { this.taxRowDisplayTarget.classList.remove('d-none'); this.taxRowDisplayTarget.classList.add('d-flex'); }
            if (this.hasTaxContainerTarget) this.taxContainerTarget.classList.remove('opacity-50', 'pointer-events-none');
            if (this.hasBanksWrapperTarget) this.banksWrapperTarget.classList.remove('d-none');
        }

        const t = this.state.selectedTarget;
        if (!t) {
            if (this.hasTargetContentTarget) this.targetContentTarget.innerHTML = `<span class="text-xxs fw-bold text-secondary text-uppercase tracking-widest">Sélectionner un ${this.state.billingTarget}</span>`;
            return;
        }

        const idNat = t.idnat ? ` • ID NAT: ${t.idnat}` : (t.rccm ? ` • RCCM: ${t.rccm}` : '');
        if (this.hasTargetContentTarget) {
            this.targetContentTarget.innerHTML = `
                <div class="flex-grow-1 text-start">
                    <div class="fw-bold fs-6 text-dark lh-sm mb-1">${t.nom}</div>
                    <div class="text-xxs text-secondary fw-medium mb-1">${t.adressePhysique || t.adresse || ''}</div>
                    <div class="text-xxs font-monospace text-secondary">NIFF: ${t.numimpot} ${idNat}</div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-secondary d-print-none"><polyline points="6 9 12 15 18 9"/></svg>
            `;
        }
    }

    updateLabel() {
        if (!this.state.isAutoLabelEnabled) return;
        const dateObj = new Date(this.dateInputTarget.value);
        const mois = !isNaN(dateObj) ? dateObj.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }) : '';
        const name = this.state.selectedTarget?.nom || '';
        
        let label = '';
        if (this.state.billingTarget === 'assureur') label = `Facturation des commissions - ${name} - ${mois}`;
        else if (this.state.billingTarget === 'client') label = `Facturation des honoraires - ${name} - ${mois}`;
        else label = `Note de rétro-commission - ${name} - ${mois}`;
        
        label = label.charAt(0).toUpperCase() + label.slice(1);
        if (this.hasLabelInputTarget) this.labelInputTarget.value = label;
    }

    renderItems() {
        if (!this.hasItemsBodyTarget) return;
        const isCredit = this.state.billingTarget === 'partenaire';

        if (this.state.items.length === 0) {
            this.itemsBodyTarget.innerHTML = `<tr><td colspan="7" class="py-5 text-center text-xs text-secondary fst-italic d-print-none border-bottom">Aucune prestation ajoutée. Cliquez sur le bouton ci-dessous pour commencer.</td></tr>`;
            return;
        }

        this.itemsBodyTarget.innerHTML = this.state.items.map((item, index) => {
            const rowTotal = item.revenu ? (item.quantity * item.unitPrice * (item.tranche?.pourcentage || 0) / 100) : 0;
            
            return `
            <tr class="align-top border-bottom hover-bg-light transition-colors">
                <td class="py-3 text-center font-monospace text-xxs fw-bold text-secondary d-print-none pt-4">${index + 1}</td>
                <td class="py-3 ps-2 cursor-default position-relative" data-action="mouseenter->invoice-editor#showTooltip mousemove->invoice-editor#moveTooltip mouseleave->invoice-editor#hideTooltip" data-item-id="${item.id}">
                    <button type="button" data-action="click->invoice-editor#openRevenuModal" data-row-id="${item.id}" class="w-100 text-start border-0 bg-transparent p-0 d-block">
                        ${item.revenu ? `
                            <span class="fw-bold text-xs text-dark d-block mb-1">${item.revenu.nom}</span>
                            <div class="text-xxs text-secondary fw-normal fst-italic lh-sm text-wrap">
                                <span class="d-block text-primary opacity-75">${item.revenu.typeRevenu.nom}</span>
                                <span class="d-block">Police: ${item.revenu.cotation.avenant.referencePolice}</span>
                                ${!isCredit ? `<span class="d-block">Prime TTC: ${item.revenu.cotation.montantTTC.toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}</span>` : ''}
                            </div>
                        ` : `
                            <div class="d-flex align-items-center gap-2 text-secondary py-2 hover-text-primary d-print-none">
                              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                              <span class="fw-bold text-xxs text-uppercase tracking-widest">Sélectionner</span>
                            </div>
                        `}
                    </button>
                </td>
                <td class="py-3 text-center pt-4">
                    <input type="number" min="1" data-action="input->invoice-editor#updateQty" data-row-id="${item.id}" value="${item.quantity}" ${!item.revenu?'disabled':''} class="input-bare text-center fw-bold text-xs text-dark w-100" />
                </td>
                <td class="py-3 text-end pt-4">
                    <div class="d-flex align-items-center justify-content-end fw-bold text-xs text-dark">
                        <input type="number" step="0.01" data-action="input->invoice-editor#updatePrice" data-row-id="${item.id}" value="${item.unitPrice}" ${!item.revenu?'disabled':''} class="input-bare text-end fw-bold text-xs text-dark me-1" style="max-width: 70px;" />
                        <span class="text-secondary text-xxs">${item.revenu ? this.state.currency.code : ''}</span>
                    </div>
                </td>
                <td class="py-3 text-center pt-4">
                    <button type="button" data-action="click->invoice-editor#openTrancheModal" data-row-id="${item.id}" ${!item.revenu?'disabled':''} class="w-100 text-center border-0 bg-transparent p-0 d-flex flex-column align-items-center">
                        <span class="fw-bold text-xxs text-dark lh-sm">${item.tranche ? item.tranche.nom : '-'}</span>
                        ${item.tranche ? `<span class="text-xxs text-secondary font-monospace tracking-tight" style="font-size:0.6rem;">(${item.tranche.pourcentage}%)<br/>Échéance: ${item.tranche.echeanceAt}</span>` : ''}
                    </button>
                </td>
                <td class="py-3 pe-2 text-end fw-bold text-dark text-xs pt-4 text-wrap">
                    ${item.revenu ? rowTotal.toLocaleString('fr-FR', {minimumFractionDigits:2}) + ' ' + this.state.currency.code : '-'}
                </td>
                <td class="py-3 text-center d-print-none pt-4">
                    <button type="button" data-action="click->invoice-editor#removeItem" data-row-id="${item.id}" class="border-0 bg-transparent text-secondary hover-text-danger p-0 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    renderBanks() {
        if (!this.hasBanksBodyTarget) return;
        const bankCounts = {};
        this.state.bankAccounts.forEach(acc => {
            if (acc.bank) bankCounts[acc.bank.id] = (bankCounts[acc.bank.id] || 0) + 1;
        });

        this.banksBodyTarget.innerHTML = this.state.bankAccounts.map(acc => {
            const isDupe = acc.bank && bankCounts[acc.bank.id] > 1;
            
            const containerClasses = isDupe 
                ? "d-flex gap-3 align-items-center w-100 p-2 bg-danger bg-opacity-10 border border-danger rounded-3" 
                : "d-flex gap-3 align-items-center w-100 p-2 border border-transparent";
            const textColor = isDupe ? "text-danger" : "text-dark";
            const subTextColor = isDupe ? "text-danger opacity-75" : "text-secondary";
            const borderStyle = isDupe ? "border-0" : "border-bottom border-secondary border-dashed";

            return `
            <div class="${containerClasses}">
                <button type="button" data-action="click->invoice-editor#openExistingBankModal" data-bank-id="${acc.id}" class="flex-grow-1 d-flex gap-3 text-start border-0 bg-transparent align-items-center ${borderStyle} d-print-none pb-1 cursor-pointer">
                    ${acc.bank ? `
                        <span class="text-xxs fw-bold text-uppercase tracking-widest ${textColor} text-end w-25">${acc.bank.nom || acc.bank.banque}</span>
                        <span class="text-xxs fw-medium text-uppercase tracking-widest ${subTextColor} text-center w-50">${acc.bank.numero}</span>
                        <span class="text-xxs fw-medium text-uppercase tracking-widest ${subTextColor} text-left w-1/4">${acc.bank.codeSwift}</span>
                    ` : `<span class="text-xxs fw-bold text-secondary w-100 text-center d-flex align-items-center justify-content-center gap-2">Choisir un compte bancaire</span>`}
                </button>
                <div class="d-print-none d-flex align-items-center justify-content-center" style="width: 32px;">
                    ${this.state.bankAccounts.length > 1 ? `
                        <button type="button" data-action="click->invoice-editor#removeBank" data-bank-id="${acc.id}" class="btn btn-link text-danger p-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>` : ''}
                </div>
            </div>
        `;
        }).join('');
    }

    renderTotals() {
        const isCredit = this.state.billingTarget === 'partenaire';
        const subtotal = this.state.items.reduce((acc, item) => acc + (item.quantity * item.unitPrice * (item.tranche?.pourcentage || 0) / 100), 0);
        const taxAmount = isCredit ? 0 : subtotal * parseFloat(this.state.taxe?.tauxIARD || 0);
        const grandTotal = subtotal + taxAmount;
        const totalPaid = this.state.payments.reduce((acc, p) => acc + p.montant, 0);
        const balanceDue = grandTotal - totalPaid;

        if (this.hasTaxCodeDisplayTarget) this.taxCodeDisplayTarget.innerText = this.state.taxe.code;
        if (this.hasTaxRateDisplayTarget) this.taxRateDisplayTarget.innerText = (parseFloat(this.state.taxe.tauxIARD)*100).toFixed(0)+'%';
        if (this.hasTaxNameDisplayTarget) this.taxNameDisplayTarget.innerText = `${this.state.taxe.code} (${this.hasTaxRateDisplayTarget ? this.taxRateDisplayTarget.innerText : ''})`;
        if (this.hasCurrencyDisplayTarget) this.currencyDisplayTarget.innerText = this.state.currency.code;

        const fmt = (n) => n.toLocaleString('fr-FR', {minimumFractionDigits: 2}) + ' ' + this.state.currency.code;
        
        if (this.hasSubtotalDisplayTarget) this.subtotalDisplayTarget.innerText = fmt(subtotal);
        if (this.hasTaxAmountDisplayTarget) this.taxAmountDisplayTarget.innerText = fmt(taxAmount);
        if (this.hasGrandTotalDisplayTarget) this.grandTotalDisplayTarget.innerText = fmt(grandTotal);
        if (this.hasTotalPaidDisplayTarget) this.totalPaidDisplayTarget.innerText = fmt(totalPaid);
        if (this.hasBalanceDueDisplayTarget) this.balanceDueDisplayTarget.innerText = fmt(balanceDue);
        
        if (this.hasWordsDisplayTarget) this.wordsDisplayTarget.innerText = convertAmountToWords(grandTotal, this.state.currency.code); 
        
        if (this.hasPaymentsBadgeTarget) {
            const count = this.state.payments.length;
            if (count > 0) {
                this.paymentsBadgeTarget.classList.remove('d-none');
                this.paymentsBadgeTarget.innerText = count;
            } else {
                this.paymentsBadgeTarget.classList.add('d-none');
            }
        }
    }

    setTarget(e) {
        this.state.billingTarget = e.currentTarget.dataset.targetType;
        const source = this.state.billingTarget === 'assureur' ? this.MOCKS.ASSUREURS : (this.state.billingTarget === 'client' ? this.MOCKS.CLIENTS : this.MOCKS.PARTENAIRES);
        this.state.selectedTarget = source[0] || null;
        this.state.items = []; 
        this.state.payments = [];
        this.markUnsaved();
        this.renderAll();
    }

    openNewRevenuModal() {
        this.activeRowId = 'NEW';
        this.activeModalType = 'revenu';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir un revenu`;
        
        const filtered = this.MOCKS.FLATTENED_REVENUS.filter(r => {
            const tgtId = this.state.selectedTarget?.id;
            if(!tgtId) return false;
            if (this.state.billingTarget === 'assureur') return r.cotation.assureur.id === tgtId && r.typeRevenu.redevable === 1;
            if (this.state.billingTarget === 'client') return r.cotation.client.id === tgtId && r.typeRevenu.redevable === 0;
            if (this.state.billingTarget === 'partenaire') return r.cotation.partenaires?.some(p => p.id === tgtId);
            return false; 
        });
        this.renderModalList(filtered, 'revenu');
        this.openModal();
    }
    
    removeItem(e) {
        const id = parseInt(e.currentTarget.dataset.rowId);
        if (this.state.items.length > 1) {
            this.state.items = this.state.items.filter(i => i.id !== id);
            this.markUnsaved();
            this.renderAll();
        }
    }

    openNewBankModal() {
        this.activeBankRowId = 'NEW';
        this.activeModalType = 'bank';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir un compte à ajouter`;
        this.renderModalList(this.MOCKS.BANKS, 'bank');
        this.openModal();
    }

    openExistingBankModal(e) {
        this.activeBankRowId = parseInt(e.currentTarget.dataset.bankId);
        this.activeModalType = 'bank';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Remplacer la banque`;
        this.renderModalList(this.MOCKS.BANKS, 'bank');
        this.openModal();
    }

    removeBank(e) {
        const id = parseInt(e.currentTarget.dataset.bankId);
        this.state.bankAccounts = this.state.bankAccounts.filter(b => b.id !== id);
        this.markUnsaved();
        this.renderBanks();
        this.checkAllDuplicates();
    }

    updateQty(e) {
        const item = this.state.items.find(i => i.id === parseInt(e.currentTarget.dataset.rowId));
        if (item) item.quantity = parseInt(e.currentTarget.value) || 0;
        this.markUnsaved();
        this.renderAll();
    }

    updatePrice(e) {
        const item = this.state.items.find(i => i.id === parseInt(e.currentTarget.dataset.rowId));
        if (item) item.unitPrice = parseFloat(e.currentTarget.value) || 0;
        this.markUnsaved();
        this.renderAll();
    }

    toggleAutoLabel(e) {
        this.state.isAutoLabelEnabled = e.currentTarget.checked;
        this.updateLabel();
    }

    onLabelInput() {
        this.state.isAutoLabelEnabled = false;
        if (this.hasAutoLabelCheckTarget) this.autoLabelCheckTarget.checked = false;
        this.markUnsaved();
    }

    onDateChange() {
        this.updateLabel();
        this.markUnsaved();
    }

    markUnsaved() {
        this.state.hasUnsavedChanges = true;
        if (this.hasStatusUnsavedTarget) this.statusUnsavedTarget.classList.remove('d-none');
        if (this.hasStatusSavedTarget) this.statusSavedTarget.classList.add('d-none');
        if (this.hasStatusTimeTarget) this.statusTimeTarget.classList.add('d-none');
    }

    showTooltip(e) {
        const itemId = parseInt(e.currentTarget.dataset.itemId);
        const item = this.state.items.find(i => i.id === itemId);
        if (!item || !item.revenu || !this.hasTooltipTarget) return;

        if (this.hasTtCotationTarget) this.ttCotationTarget.innerText = item.revenu.cotation.nom;
        if (this.hasTtRevenuTarget) this.ttRevenuTarget.innerText = item.revenu.nom;
        if (this.hasTtTypeTarget) this.ttTypeTarget.innerText = item.revenu.typeRevenu.nom;
        if (this.hasTtPoliceTarget) this.ttPoliceTarget.innerText = item.revenu.cotation.avenant.referencePolice;
        if (this.hasTtAvenantTarget) this.ttAvenantTarget.innerText = item.revenu.cotation.avenant.numero;
        if (this.hasTtPeriodeTarget) this.ttPeriodeTarget.innerText = `${item.revenu.cotation.avenant.startingAt} - ${item.revenu.cotation.avenant.endingAt}`;
        
        const isCredit = this.state.billingTarget === 'partenaire';
        if (isCredit) {
            if (this.hasTtInvoiceContentTarget) this.ttInvoiceContentTarget.classList.add('d-none');
            if (this.hasTtCreditContentTarget) this.ttCreditContentTarget.classList.remove('d-none');
            if (this.hasTtRetroComTarget) this.ttRetroComTarget.innerText = `${(item.revenu.retroCommission || 0).toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}`;
        } else {
            if (this.hasTtCreditContentTarget) this.ttCreditContentTarget.classList.add('d-none');
            if (this.hasTtInvoiceContentTarget) this.ttInvoiceContentTarget.classList.remove('d-none');
            if (this.hasTtMontantHTTarget) this.ttMontantHTTarget.innerText = `${item.revenu.montantCalculeHT.toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}`;
            if (this.hasTtPrimeTTCTarget) this.ttPrimeTTCTarget.innerText = `${item.revenu.cotation.montantTTC.toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}`;
        }

        this.tooltipTarget.classList.remove('d-none');
        this.tooltipTarget.classList.add('d-flex');
    }

    moveTooltip(e) {
        if (!this.hasTooltipTarget) return;
        this.tooltipTarget.style.left = `${e.clientX + 15}px`;
        this.tooltipTarget.style.top = `${e.clientY + 15}px`;
    }

    hideTooltip() {
        if (!this.hasTooltipTarget) return;
        this.tooltipTarget.classList.add('d-none');
        this.tooltipTarget.classList.remove('d-flex');
    }

    toggleExportMenu(e) {
        if (e) e.stopPropagation();
        if (this.hasExportMenuTarget) this.exportMenuTarget.classList.toggle('d-none');
    }

    closeDropdowns(e) {
        if (this.hasExportMenuTarget && !this.exportMenuTarget.classList.contains('d-none')) {
            if (this.hasExportWrapperTarget && !this.exportWrapperTarget.contains(e.target)) {
                this.exportMenuTarget.classList.add('d-none');
            }
        }
    }

    openTargetModal() {
        this.activeModalType = 'target';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir un ${this.state.billingTarget}`;
        const source = this.state.billingTarget === 'assureur' ? this.MOCKS.ASSUREURS : (this.state.billingTarget === 'client' ? this.MOCKS.CLIENTS : this.MOCKS.PARTENAIRES);
        this.renderModalList(source, 'target');
        this.openModal();
    }

    openRevenuModal(e) {
        this.activeRowId = parseInt(e.currentTarget.dataset.rowId);
        this.activeModalType = 'revenu';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir un revenu`;
        
        const filtered = this.MOCKS.FLATTENED_REVENUS.filter(r => {
            const tgtId = this.state.selectedTarget?.id;
            if(!tgtId) return false;
            if (this.state.billingTarget === 'assureur') return r.cotation.assureur.id === tgtId && r.typeRevenu.redevable === 1;
            if (this.state.billingTarget === 'client') return r.cotation.client.id === tgtId && r.typeRevenu.redevable === 0;
            if (this.state.billingTarget === 'partenaire') return r.cotation.partenaires?.some(p => p.id === tgtId);
            return false; 
        });
        this.renderModalList(filtered, 'revenu');
        this.openModal();
    }

    openTrancheModal(e) {
        this.activeRowId = parseInt(e.currentTarget.dataset.rowId);
        this.activeModalType = 'tranche';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir la tranche`;
        const item = this.state.items.find(i => i.id === this.activeRowId);
        this.renderModalList(item.revenu?.cotation.tranches || [], 'tranche');
        this.openModal();
    }

    openCurrencyModal() {
        this.activeModalType = 'currency';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir une devise`;
        this.renderModalList(this.MOCKS.MONNAIES, 'currency');
        this.openModal();
    }

    openTaxModal() {
        this.activeModalType = 'taxe';
        if (this.hasModalTitleTarget) this.modalTitleTarget.innerText = `Choisir une taxe`;
        this.renderModalList(this.MOCKS.TAXES, 'taxe');
        this.openModal();
    }

    renderModalList(items, type, searchTerm = "") {
        if (!this.hasModalListTarget) return;
        const searchLower = searchTerm.toLowerCase();
        
        const filtered = items.filter(item => {
            if (!searchTerm) return true;
            if (type === 'target') return item.nom.toLowerCase().includes(searchLower) || (item.numimpot && item.numimpot.toLowerCase().includes(searchLower)) || (item.rccm && item.rccm.toLowerCase().includes(searchLower));
            if (type === 'revenu') return item.nom.toLowerCase().includes(searchLower) || item.typeRevenu.nom.toLowerCase().includes(searchLower) || item.cotation.nom.toLowerCase().includes(searchLower) || item.cotation.avenant.referencePolice.toLowerCase().includes(searchLower);
            if (type === 'bank') return item.intitule.toLowerCase().includes(searchLower) || item.banque.toLowerCase().includes(searchLower);
            if (type === 'currency') return item.code.toLowerCase().includes(searchLower) || item.nom.toLowerCase().includes(searchLower);
            if (type === 'taxe') return item.code.toLowerCase().includes(searchLower) || item.description.toLowerCase().includes(searchLower);
            return true;
        });

        if (filtered.length === 0) {
            this.modalListTarget.innerHTML = `<div class="text-center py-4 text-xs text-secondary fst-italic">Aucun résultat trouvé.</div>`;
            return;
        }

        this.modalListTarget.innerHTML = filtered.map(item => {
            let svg = '';
            if (type === 'target') {
                if(this.state.billingTarget === 'assureur') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-primary mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>`;
                if(this.state.billingTarget === 'client') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-info mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="12" cy="10" r="3"/><path d="M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/></svg>`;
                if(this.state.billingTarget === 'partenaire') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-success mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>`;
            }
            if (type === 'revenu') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-secondary mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>`;
            if (type === 'tranche') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-secondary mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`;
            if (type === 'bank') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-secondary mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/></svg>`;
            if (type === 'currency') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/></svg>`;
            if (type === 'taxe') svg = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" class="text-secondary mt-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/></svg>`;

            if (type === 'target') {
                const idNat = item.idnat ? ` • ID NAT: ${item.idnat}` : (item.rccm ? ` • RCCM: ${item.rccm}` : '');
                return `<button type="button" data-action="click->invoice-editor#selectOption" data-id="${item.id}" class="list-group-item list-group-item-action d-flex gap-3 text-start border-0 rounded p-3 mb-1"><div>${svg}</div><div class="flex-grow-1"><div class="fw-bold text-sm text-dark lh-sm mb-1">${item.nom}</div><div class="text-xxs text-secondary mb-1">${item.adressePhysique || item.adresse}</div><div class="d-flex justify-content-between text-xxs font-monospace text-secondary text-uppercase"><span>NIFF: ${item.numimpot} ${idNat}</span></div></div></button>`;
            }
            
            if (type === 'revenu') {
                const price = this.state.billingTarget === 'partenaire' ? item.retroCommission : item.montantCalculeHT;
                return `<button type="button" data-action="click->invoice-editor#selectOption" data-id="${item.id}" class="list-group-item list-group-item-action d-flex gap-3 text-start border-0 rounded p-3 mb-1"><div>${svg}</div><div class="flex-grow-1"><div class="d-flex justify-content-between align-items-start mb-1"><span class="text-xxs fw-bold text-secondary text-uppercase lh-sm">${item.cotation.nom} <br/> <span class="text-primary">${item.typeRevenu.nom}</span></span><span class="fw-bold text-xs text-dark">${(price||0).toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}</span></div><div class="fw-bold text-sm text-dark lh-sm mt-1">${item.nom}</div><div class="mt-2 pt-2 border-top d-flex flex-column gap-1 text-xxs font-monospace text-secondary"><div class="d-flex justify-content-between flex-wrap gap-2"><div><span class="text-uppercase" style="font-size:0.5rem">Police:</span> <span class="text-dark fw-bold">${item.cotation.avenant.referencePolice}</span> <span class="mx-2 text-light">|</span> <span class="text-uppercase" style="font-size:0.5rem">Prime Totale TTC:</span> <span class="text-dark fw-bold">${item.cotation.montantTTC.toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}</span></div><div><span class="text-uppercase" style="font-size:0.5rem">Avenant:</span> <span class="text-dark fw-bold">${item.cotation.avenant.numero}</span></div></div></div></div></button>`;
            }

            if (type === 'tranche') return `<button type="button" data-action="click->invoice-editor#selectOption" data-id="${item.id}" class="list-group-item list-group-item-action d-flex gap-3 text-start border-0 rounded p-3 mb-1"><div>${svg}</div><div class="flex-grow-1"><div class="d-flex justify-content-between align-items-start mb-1"><span class="text-xxs fw-bold text-secondary text-uppercase">Modalité de paiement</span><span class="fw-bold text-xs text-dark">${item.pourcentage}%</span></div><div class="fw-bold text-sm text-dark lh-sm">${item.nom}</div><div class="text-xxs text-secondary font-monospace mt-1 text-uppercase tracking-tight">Échéance: <span class="fw-bold text-dark">${item.echeanceAt}</span></div></div></button>`;
            
            if (type === 'bank') return `<button type="button" data-action="click->invoice-editor#selectOption" data-id="${item.id}" class="list-group-item list-group-item-action d-flex gap-3 text-start border-0 rounded p-3 mb-1"><div>${svg}</div><div class="flex-grow-1"><div class="d-flex justify-content-between align-items-start mb-1"><span class="text-xxs fw-bold text-secondary text-uppercase">${item.intitule}</span></div><div class="font-semibold text-sm text-dark lh-sm">${item.nom || item.banque}</div><div class="text-xxs text-secondary font-monospace mt-1">N°: ${item.numero}</div><div class="text-xxs text-secondary font-monospace">SWIFT/BIC: ${item.codeSwift}</div></div></button>`;
            
            if (type === 'currency') return `<button type="button" data-action="click->invoice-editor#selectOption" data-id="${item.id}" class="list-group-item list-group-item-action d-flex gap-3 text-start border-0 rounded p-3 mb-1 align-items-center"><div>${svg}</div><div class="flex-grow-1 w-100 text-start"><div class="d-flex justify-content-between align-items-start mb-1"><span class="text-xxs fw-bold text-dark text-uppercase">${item.code}</span><span class="text-xxs fw-bold text-secondary text-uppercase">Taux USD : ${item.tauxusd}</span></div><div class="fw-bold text-sm text-dark lh-sm">${item.nom}</div></div></button>`;
            
            if (type === 'taxe') {
                const isSelectable = item.redevable === 0;
                return `<button type="button" ${isSelectable?'data-action="click->invoice-editor#selectOption"':''} data-id="${item.id}" ${!isSelectable?'disabled':''} class="list-group-item ${isSelectable?'list-group-item-action':'disabled opacity-50'} d-flex gap-3 text-start border-0 rounded p-3 mb-1"><div>${svg}</div><div class="flex-grow-1 w-100 text-start"><div class="d-flex justify-content-between align-items-start mb-1"><span class="text-xxs fw-bold text-dark text-uppercase">${item.code}</span><span class="text-xxs fw-bold text-secondary text-uppercase">${(parseFloat(item.tauxIARD) * 100).toFixed(0)}%</span></div><div class="fw-bold text-sm text-dark lh-sm">${item.description}</div>${!isSelectable ? '<div class="text-xxs text-danger fw-bold text-uppercase tracking-widest mt-1">Non applicable (Payable par le courtier)</div>' : ''}</div></button>`;
            }
        }).join('');
    }

    filterModal(e) {
        const term = e.target.value;
        let source = [];
        if (this.activeModalType === 'target') source = this.state.billingTarget === 'assureur' ? this.MOCKS.ASSUREURS : (this.state.billingTarget === 'client' ? this.MOCKS.CLIENTS : this.MOCKS.PARTENAIRES);
        else if (this.activeModalType === 'revenu') source = this.MOCKS.FLATTENED_REVENUS.filter(r => {
            const tgtId = this.state.selectedTarget?.id;
            if(!tgtId) return false;
            if (this.state.billingTarget === 'assureur') return r.cotation.assureur.id === tgtId && r.typeRevenu.redevable === 1;
            if (this.state.billingTarget === 'client') return r.cotation.client.id === tgtId && r.typeRevenu.redevable === 0;
            if (this.state.billingTarget === 'partenaire') return r.cotation.partenaires?.some(p => p.id === tgtId);
            return false; 
        });
        else if (this.activeModalType === 'bank') source = this.MOCKS.BANKS;
        else if (this.activeModalType === 'currency') source = this.MOCKS.MONNAIES;
        else if (this.activeModalType === 'taxe') source = this.MOCKS.TAXES;

        this.renderModalList(source, this.activeModalType, term);
    }

    selectOption(e) {
        const id = parseInt(e.currentTarget.dataset.id) || e.currentTarget.dataset.id;
        
        if (this.activeModalType === 'target') {
            const source = this.state.billingTarget === 'assureur' ? this.MOCKS.ASSUREURS : (this.state.billingTarget === 'client' ? this.MOCKS.CLIENTS : this.MOCKS.PARTENAIRES);
            this.state.selectedTarget = source.find(s => s.id === id);
            this.state.items = []; 
        } else if (this.activeModalType === 'revenu') {
            const revenu = this.MOCKS.FLATTENED_REVENUS.find(r => r.id === id);
            const unitPrice = this.state.billingTarget === 'partenaire' ? (revenu.retroCommission||0) : revenu.montantCalculeHT;
            const tranche = revenu.cotation.tranches[0];

            if (this.activeRowId === 'NEW') {
                this.state.items.push({ id: Date.now(), revenu, quantity: 1, unitPrice, tranche });
            } else {
                const item = this.state.items.find(i => i.id === this.activeRowId);
                if (item) {
                    item.revenu = revenu;
                    item.unitPrice = unitPrice;
                    item.tranche = tranche;
                }
            }
        } else if (this.activeModalType === 'tranche') {
            const item = this.state.items.find(i => i.id === this.activeRowId);
            item.tranche = item.revenu.cotation.tranches.find(t => t.id === id);
        } else if (this.activeModalType === 'bank') {
            const selectedBank = this.MOCKS.BANKS.find(b => b.id === id);
            if (this.activeBankRowId === 'NEW') {
                this.state.bankAccounts.push({ id: Date.now(), bank: selectedBank });
            } else {
                const bankAcc = this.state.bankAccounts.find(b => b.id === this.activeBankRowId);
                if (bankAcc) bankAcc.bank = selectedBank;
            }
        } else if (this.activeModalType === 'currency') {
            this.state.currency = this.MOCKS.MONNAIES.find(m => m.id === id);
        } else if (this.activeModalType === 'taxe') {
            this.state.taxe = this.MOCKS.TAXES.find(t => t.id === id);
        }

        this.markUnsaved();
        this.renderAll();
        this.closeModal();
    }

    openModal() {
        if (this.hasSelectionModalTarget) {
            this.selectionModalTarget.classList.remove('d-none');
            this.selectionModalTarget.classList.add('d-flex');
        }
    }
    
    closeModal() {
        if (this.hasSelectionModalTarget) {
            this.selectionModalTarget.classList.add('d-none');
            this.selectionModalTarget.classList.remove('d-flex');
        }
    }

    openPaymentForm() {
        const isCredit = this.state.billingTarget === 'partenaire';
        const subtotal = this.state.items.reduce((acc, item) => acc + (item.quantity * item.unitPrice * (item.tranche?.pourcentage || 0) / 100), 0);
        const taxAmount = isCredit ? 0 : subtotal * parseFloat(this.state.taxe?.tauxIARD || 0);
        const grandTotal = subtotal + taxAmount;
        const totalPaid = this.state.payments.reduce((acc, p) => acc + p.montant, 0);
        const balanceDue = grandTotal - totalPaid;

        this.currentEditPayment = null;
        if (this.hasPaymentIdInputTarget) this.paymentIdInputTarget.value = '';
        if (this.hasPaymentAmountInputTarget) this.paymentAmountInputTarget.value = balanceDue > 0 ? balanceDue.toFixed(2) : 0;
        if (this.hasPaymentDateInputTarget) this.paymentDateInputTarget.value = new Date().toISOString().split('T')[0];
        if (this.hasPaymentRefInputTarget) this.paymentRefInputTarget.value = '';
        if (this.hasPaymentCurrencyLabelTarget) this.paymentCurrencyLabelTarget.innerText = this.state.currency.code;
        if (this.hasPaymentModalTitleTarget) this.paymentModalTitleTarget.innerText = isCredit ? 'Reverser' : 'Encaisser';
        
        this.clearPaymentFile();

        if (this.hasPaymentModalTarget) {
            this.paymentModalTarget.classList.remove('d-none');
            this.paymentModalTarget.classList.add('d-flex');
        }
    }

    closePaymentForm() {
        if (this.hasPaymentModalTarget) {
            this.paymentModalTarget.classList.add('d-none');
            this.paymentModalTarget.classList.remove('d-flex');
        }
    }

    handlePaymentFileChange(e) {
        const file = e.target.files[0];
        if (file) {
            if (this.hasPaymentProofNameTarget) this.paymentProofNameTarget.innerText = file.name;
            if (this.hasBtnPaymentProofClearTarget) this.btnPaymentProofClearTarget.classList.remove('d-none');
        }
    }

    clearPaymentFile() {
        if (this.hasPaymentProofInputTarget) this.paymentProofInputTarget.value = '';
        if (this.hasPaymentProofNameTarget) this.paymentProofNameTarget.innerText = 'Cliquer pour attacher un fichier...';
        if (this.hasBtnPaymentProofClearTarget) this.btnPaymentProofClearTarget.classList.add('d-none');
    }

    submitPayment(e) {
        e.preventDefault();
        const id = this.paymentIdInputTarget.value ? parseInt(this.paymentIdInputTarget.value) : Date.now();
        const montant = parseFloat(this.paymentAmountInputTarget.value) || 0;
        const paidAt = this.paymentDateInputTarget.value;
        const reference = this.paymentRefInputTarget.value;
        
        let preuveName = '';
        if (this.paymentProofInputTarget.files.length > 0) {
            preuveName = this.paymentProofInputTarget.files[0].name;
        } else if (this.currentEditPayment && this.currentEditPayment.preuveName) {
            preuveName = this.currentEditPayment.preuveName;
        }

        const existingIndex = this.state.payments.findIndex(p => p.id === id);
        if (existingIndex >= 0) {
            this.state.payments[existingIndex] = { id, montant, paidAt, reference, preuveName };
        } else {
            this.state.payments.push({ id, montant, paidAt, reference, preuveName });
        }

        this.closePaymentForm();
        this.markUnsaved();
        this.renderAll();
    }

    openPaymentsList() {
        this.renderPaymentsList();
        if (this.hasPaymentListModalTarget) {
            this.paymentListModalTarget.classList.remove('d-none');
            this.paymentListModalTarget.classList.add('d-flex');
        }
    }

    closePaymentsList() {
        if (this.hasPaymentListModalTarget) {
            this.paymentListModalTarget.classList.add('d-none');
            this.paymentListModalTarget.classList.remove('d-flex');
        }
    }

    renderPaymentsList() {
        if (!this.hasPaymentListBodyTarget) return;

        if (this.state.payments.length === 0) {
            this.paymentListBodyTarget.innerHTML = `<div class="text-center py-5 text-sm text-secondary fst-italic bg-light rounded-3">Aucune transaction enregistrée.</div>`;
            return;
        }

        this.paymentListBodyTarget.innerHTML = this.state.payments.map(p => `
            <div class="d-flex justify-content-between align-items-center p-3 rounded-3 border bg-light hover-bg-light transition-colors mb-2">
                <div class="d-flex gap-3 align-items-center">
                    <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                    </div>
                    <div>
                        <div class="fw-bold fs-6 text-dark lh-sm">${p.montant.toLocaleString('fr-FR', {minimumFractionDigits: 2})} ${this.state.currency.code}</div>
                        <div class="text-xxs text-secondary font-monospace mt-1">${p.paidAt} • ${p.reference || 'Aucune référence'}</div>
                        ${p.preuveName ? `<div class="text-xxs text-success text-uppercase fw-bold tracking-widest mt-1 d-flex align-items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> ${p.preuveName}</div>` : ''}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" data-action="click->invoice-editor#editPayment" data-id="${p.id}" class="btn btn-link text-primary p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    </button>
                    <button type="button" data-action="click->invoice-editor#deletePayment" data-id="${p.id}" class="btn btn-link text-danger p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2-2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>
                </div>
            </div>
        `).join('');
    }

    editPayment(e) {
        const id = parseInt(e.currentTarget.dataset.id);
        const payment = this.state.payments.find(p => p.id === id);
        if (!payment) return;

        this.currentEditPayment = payment; 
        this.paymentIdInputTarget.value = payment.id;
        this.paymentAmountInputTarget.value = payment.montant;
        this.paymentDateInputTarget.value = payment.paidAt;
        this.paymentRefInputTarget.value = payment.reference;
        this.paymentCurrencyLabelTarget.innerText = this.state.currency.code;
        
        if (payment.preuveName) {
            this.paymentProofNameTarget.innerText = payment.preuveName;
            this.btnPaymentProofClearTarget.classList.remove('d-none');
        } else {
            this.clearPaymentFile();
        }

        const isCredit = this.state.billingTarget === 'partenaire';
        this.paymentModalTitleTarget.innerText = isCredit ? 'Modifier reversement' : 'Modifier encaissement';

        this.closePaymentsList();
        this.paymentModalTarget.classList.remove('d-none');
        this.paymentModalTarget.classList.add('d-flex');
    }

    deletePayment(e) {
        const id = parseInt(e.currentTarget.dataset.id);
        this.state.payments = this.state.payments.filter(p => p.id !== id);
        this.markUnsaved();
        this.renderAll();
        this.renderPaymentsList();
    }

    async save() {
        if (this.checkAllDuplicates()) return; 
        
        this.setProcessing(true);

        const articlesPayload = this.state.items.filter(i => i.revenu).map((item, index) => ({
            nom: item.revenu.nom,
            tranche: item.tranche?.id,
            montant: item.quantity * item.unitPrice * (item.tranche?.pourcentage || 0) / 100,
            idPoste: index + 1,
            revenuFacture: item.revenu.id,
            taxeFacturee: this.state.billingTarget !== 'partenaire' ? this.state.taxe?.id : null
        }));

        let targetData = {};
        if (this.state.billingTarget === 'assureur') targetData = { assureur: this.state.selectedTarget?.id };
        else if (this.state.billingTarget === 'client') targetData = { client: this.state.selectedTarget?.id };
        else targetData = { partenaire: this.state.selectedTarget?.id };

        const payload = {
            id: this.state.invoiceId || undefined,
            nom: this.labelInputTarget.value,
            type: this.state.billingTarget === 'partenaire' ? 1 : 0,
            addressedTo: this.state.billingTarget === 'client' ? 0 : (this.state.billingTarget === 'assureur' ? 1 : 2),
            description: this.labelInputTarget.value,
            reference: this.refInputTarget.value,
            sentAt: this.dateInputTarget.value,
            ...targetData,
            articles: articlesPayload
        };

        console.log("=== PAYLOAD ENVOYÉ ===");
        console.log(JSON.stringify(payload, null, 2));

        try {
            if (!this.submitUrlValue || this.submitUrlValue.includes('fake')) {
                await new Promise(r => setTimeout(r, 1200));
                if (!this.state.invoiceId) this.state.invoiceId = `ID-SIM-${Math.floor(Math.random()*1000)}`;
            } else {
                const response = await fetch(this.submitUrlValue, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                if (!response.ok) throw new Error("Erreur Serveur");
                const data = await response.json();
                if (data.entity?.id) this.state.invoiceId = data.entity.id;
            }

            this.state.hasUnsavedChanges = false;
            this.showSuccessToast();
            this.initUI(); 
            
            if (this.hasStatusUnsavedTarget) this.statusUnsavedTarget.classList.add('d-none');
            if (this.hasStatusSavedTarget) this.statusSavedTarget.classList.remove('d-none');
            if (this.hasStatusTimeTarget) {
                this.statusTimeTarget.classList.remove('d-none');
                this.statusTimeTarget.innerText = `Dernier enreg. à ${new Date().toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'})}`;
            }

        } catch (error) {
            this.showErrorToast("Erreur lors de la sauvegarde");
        } finally {
            this.setProcessing(false);
        }
    }

    setProcessing(isProcessing) {
        this.btnActionTargets.forEach(b => {
            b.disabled = isProcessing;
            b.classList.toggle('opacity-50', isProcessing);
            b.classList.toggle('cursor-not-allowed', isProcessing);
        });

        this.loadingBarTargets.forEach(bar => {
            if (isProcessing) bar.classList.remove('d-none');
            else bar.classList.add('d-none');
        });

        if (this.hasBtnSaveLabelTarget) this.btnSaveLabelTarget.innerText = isProcessing ? 'En cours...' : 'Enregistrer';

        if (this.hasBtnExportTarget) {
            if (isProcessing) {
                this.btnExportTarget.disabled = true;
                this.btnExportTarget.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                const isPreview = this.printAreaTarget.classList.contains(this.previewModeClassValue || 'preview-wrapper');
                this.btnExportTarget.disabled = !isPreview;
                this.btnExportTarget.classList.toggle('opacity-50', !isPreview);
                this.btnExportTarget.classList.toggle('cursor-not-allowed', !isPreview);
            }
        }
        
        if (isProcessing && this.hasExportMenuTarget) {
            this.exportMenuTarget.classList.add('d-none');
        }
    }

    showSuccessToast() {
        if (this.hasSuccessToastTarget) {
            this.successToastTarget.classList.remove('d-none');
            this.successToastTarget.classList.add('d-flex');
            setTimeout(() => {
                if (this.hasSuccessToastTarget) {
                    this.successToastTarget.classList.add('d-none');
                    this.successToastTarget.classList.remove('d-flex');
                }
            }, 3000);
        }
    }

    showErrorToast(msg) {
        if (this.hasErrorMessageTarget) this.errorMessageTarget.innerText = msg;
        if (this.hasErrorToastTarget) {
            this.errorToastTarget.classList.remove('d-none');
            this.errorToastTarget.classList.add('d-flex');
            setTimeout(() => {
                if (this.hasErrorToastTarget) {
                    this.errorToastTarget.classList.add('d-none');
                    this.errorToastTarget.classList.remove('d-flex');
                }
            }, 4000);
        }
    }

    setPreviewMode(e) {
        const isPreview = e.currentTarget.dataset.mode === 'true';
        const container = this.printAreaTarget;
        const previewClass = this.previewModeClassValue || 'preview-wrapper';
        
        if (isPreview) {
            container.classList.add(previewClass);
            if (this.hasBtnExportTarget) {
                this.btnExportTarget.disabled = false;
                this.btnExportTarget.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        } else {
            container.classList.remove(previewClass);
            if (this.hasBtnExportTarget) {
                this.btnExportTarget.disabled = true;
                this.btnExportTarget.classList.add('opacity-50', 'cursor-not-allowed');
            }
            if (this.hasExportMenuTarget) {
                this.exportMenuTarget.classList.add('d-none');
            }
        }

        this.element.querySelectorAll('.mode-btn').forEach(btn => {
            if (btn.dataset.mode === String(isPreview)) {
                btn.className = "mode-btn btn btn-light btn-sm flex-grow-1 text-xxs fw-bold text-uppercase tracking-widest shadow-sm text-dark";
            } else {
                btn.className = "mode-btn btn btn-link btn-sm flex-grow-1 text-xxs fw-bold text-uppercase tracking-widest text-secondary text-decoration-none";
            }
        });
    }

    exportPdf(e) {
        if (e) e.preventDefault();
        if (this.hasExportMenuTarget) this.exportMenuTarget.classList.add('d-none');
        setTimeout(() => window.print(), 150);
    }
    
    exportImage(e) {
        if (e) e.preventDefault();
        if (this.hasExportMenuTarget) this.exportMenuTarget.classList.add('d-none');
        this.setProcessing(true);
        if (!window.html2canvas) {
            const script = document.createElement('script');
            script.src = "https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js";
            script.onload = () => this.generateImage();
            document.head.appendChild(script);
        } else {
            this.generateImage();
        }
    }

    generateImage() {
        window.html2canvas(this.printAreaTarget, { scale: 2, useCORS: true }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.download = `Facture_${this.refInputTarget.value}.png`;
            link.href = imgData;
            link.click();
            this.setProcessing(false);
            this.showSuccessToast();
        });
    }
}