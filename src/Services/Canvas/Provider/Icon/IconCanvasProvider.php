<?php

namespace App\Services\Canvas\Provider\Icon;

class IconCanvasProvider
{
    /**
     * @var array<string, string>
     */
    private const ICON_ALIAS_MAP = [
        // --- Icônes d'Entités ---
        'assistant-ia'    => 'jsb:assistant-ia',          // icône locale (bulle de chat + étincelle IA)
        'assistant-ia-parametres' => 'jsb:assistant-ia-settings', // icône locale (tête de robot)
        'assureur'        => 'wpf:security-checked',
        'autorite-fiscale'=> 'hugeicons:bank',
        'avenant'         => 'fluent:document-edit-16-regular',
        'bordereau'       => 'streamline-ultimate:notes-checklist-flip',
        'charge'          => 'tabler:receipt-tax',     // type de charge (comptabilité OHADA)
        'chargement'      => 'system-uicons:box-download',
        'depense'         => 'hugeicons:dollar-send-02', // sortie de fonds (pendant de « paiement »)
        'classeur'        => 'hugeicons:folder-01',
        'client'          => 'hugeicons:user-group',
        'compte-bancaire' => 'hugeicons:wallet-02',
        'condition'       => 'streamline-plump:file-check-alternate',
        'contact'         => 'hugeicons:contact-01',
        'cotation'        => 'hugeicons:invoice-02',
        'document'        => 'hugeicons:document-validation',
        'document-comptable' => 'tabler:report-money', // états comptables OHADA (workspace & rôles)
        'entreprise'      => 'hugeicons:building-02',
        'feedback'        => 'tabler:message-chatbot',
        'fournisseur'     => 'tabler:building-store', // opérateur économique (achats / services généraux)
        'groupe'          => 'mingcute:group-3-fill',
        'invite'          => 'hugeicons:mail-send-01',
        'modele-piece'    => 'uil:document-layout-left',
        'monnaie'         => 'hugeicons:dollar-circle',
        'note'            => 'tdesign:bill-filled',
        'offre'           => 'hugeicons:invoice-01',
        'operation'       => 'tabler:transaction-dollar', // Ajout pour la rubrique Opération
        'paiement'        => 'hugeicons:dollar-receive-02',
        'partenaire'      => 'carbon:partnership',
        'piece-sinistre'  => 'hugeicons:document-attachment',
        'piste'           => 'material-symbols:footprint',
        'portefeuille'    => 'mdi:briefcase-account', // portefeuille client rattaché à un gestionnaire de compte
        'revenu'          => 'hugeicons:money-bag-02',
        'risque'          => 'ant-design:product-filled',
        'role'            => 'famicons:hand-right-outline',
        'sinistre'        => 'hugeicons:accident',
        'tache'           => 'hugeicons:task-02',
        'taxe'            => 'tabler:tax',
        'tranche'         => 'hugeicons:pie-chart-03',
        'type-revenu'     => 'hugeicons:money-bag-01',
        'utilisateur'     => 'mdi:user-circle',
        'dashboard'       => 'ant-design:dashboard-twotone',
        'default'         => 'mdi:file-outline', // Icône par défaut
        
        // --- Icônes d'Actions ---
        'action:add'          => 'gridicons:add',
        'action:calendar'     => 'jsb:calendar', // icône locale (aucune dispo dans les sets)
        'action:count'        => 'jsb:hashtag',  // icône locale (compteur / quantité)
        'action:analyser'     => 'boxicons:analyze-filled',
        'action:alert'        => 'fluent:alert-24-regular',
        'action:annulation'   => 'fluent:calendar-cancel-24-regular',
        'action:cancel'       => 'hugeicons:cancel-01',
        'action:cart'         => 'pepicons-print:cart',
        'action:check'        => 'material-symbols:check-box',
        'action:close'        => 'hugeicons:cancel-01', // Alias pour 'cancel'
        'action:completed'    => 'fluent-mdl2:completed',
        'action:copy'         => 'hugeicons:copy-01',
        'action:delete'       => 'fluent:delete-20-filled',
        'action:description'  => 'fluent:text-description-16-filled',
        'action:detach'       => 'mdi:link-variant-off', // retrait non destructif d'un rattachement
        'action:disable'      => 'material-symbols:person-add-disabled',
        'action:download'     => 'hugeicons:download-01',
        'action:edit'         => 'tabler:edit',
        'action:exit'         => 'hugeicons:logout-04',
        'action:filter'       => 'mdi:filter',
        'action:hide'         => 'hugeicons:view-off-slash',
        'action:incorporation'=> 'material-symbols:add-ad-outline-rounded',
        'action:information'  => 'hugeicons:ai-idea',
        'action:invite'       => 'mingcute:invite-fill',
        'action:ongoing'      => 'mdi:progress-download',
        'action:open'         => 'fad:open',
        'action:options'      => 'simple-line-icons:options-vertical',
        'action:password'     => 'hugeicons:square-lock-password',
        'action:premium'      => 'ic:twotone-workspace-premium',
        'action:print'        => 'carbon:printer',
        'action:prorogation'  => 'iconoir:truck-length',
        'action:refresh'      => 'hugeicons:refresh-01',
        'action:reload'       => 'ci:arrows-reload-01',
        'action:renew'        => 'carbon:renew',
        'action:resiliation'  => 'gravity-ui:hand-stop',
        'action:reset'        => 'bx:reset',
        'action:resend-invitation' => 'mdi:email-sync-outline',
        'action:role'         => 'carbon:user-role',
        'action:save'         => 'fa-solid:save',
        'action:search'       => 'hugeicons:search-01',
        'action:send-email'   => 'hugeicons:mail-send-01',
        'action:settings'     => 'material-symbols:settings',
        'action:transfer'     => 'mdi:swap-horizontal', // transfert vers un autre rattachement
        'action:upload'       => 'hugeicons:upload-01',
        'action:view'         => 'hugeicons:view',
    ];

    /**
     * Résout un nom d'alias d'icône en son vrai nom (ex: 'assureur' -> 'wpf:security-checked').
     * Si le nom n'est pas un alias, il retourne null pour que l'appelant puisse utiliser le nom original.
     */
    public function resolveIconName(string $alias): ?string
    {
        return self::ICON_ALIAS_MAP[$alias] ?? null;
    }
}
