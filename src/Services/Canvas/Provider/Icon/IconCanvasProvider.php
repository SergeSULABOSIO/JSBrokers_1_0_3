<?php

namespace App\Services\Canvas\Provider\Icon;

class IconCanvasProvider
{
    /**
     * @var array<string, string>
     */
    private const ICON_ALIAS_MAP = [
        // --- Icônes d'Entités ---
        'assureur'        => 'wpf:security-checked',
        'autorite-fiscale'=> 'hugeicons:bank',
        'avenant'         => 'hugeicons:document-edit',
        'bordereau'       => 'hugeicons:report-columns',
        'chargement'      => 'hugeicons:box-download',
        'classeur'        => 'hugeicons:folder-01',
        'client'          => 'hugeicons:user-group',
        'compte-bancaire' => 'hugeicons:wallet-02',
        'condition'       => 'hugeicons:file-check-02',
        'contact'         => 'hugeicons:contact-01',
        'cotation'        => 'hugeicons:invoice-02',
        'document'        => 'hugeicons:document-01',
        'entreprise'      => 'hugeicons:building-02',
        'feedback'        => 'hugeicons:message-chat-02',
        'groupe'          => 'hugeicons:users-02',
        'invite'          => 'hugeicons:mail-send-01',
        'modele-piece'    => 'hugeicons:document-layout-left',
        'monnaie'         => 'hugeicons:dollar-circle',
        'note'            => 'hugeicons:bill',
        'offre'           => 'hugeicons:invoice-01',
        'paiement'        => 'hugeicons:dollar-receive',
        'partenaire'      => 'hugeicons:collaboration-01',
        'piece-sinistre'  => 'hugeicons:document-attachment',
        'piste'           => 'hugeicons:footprint',
        'revenu'          => 'hugeicons:money-bag-02',
        'risque'          => 'hugeicons:shield-warning',
        'sinistre'        => 'hugeicons:accident',
        'tache'           => 'hugeicons:task-02',
        'taxe'            => 'hugeicons:percentage-01',
        'tranche'         => 'hugeicons:pie-chart-03',
        'type-revenu'     => 'hugeicons:money-bag-01',
        'utilisateur'     => 'raphael:user',
        'default'         => 'hugeicons:file-05', // Icône par défaut

        // --- Icônes d'Actions ---
        'action:add'          => 'gridicons:add',
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
        'action:disable'      => 'material-symbols:person-add-disabled',
        'action:download'     => 'hugeicons:download-01',
        'action:edit'         => 'tabler:edit',
        'action:exit'         => 'hugeicons:logout-04',
        'action:filter'       => 'hugeicons:filter-02',
        'action:incorporation'=> 'material-symbols:add-ad-outline-rounded',
        'action:information'  => 'hugeicons:ai-idea',
        'action:invite'       => 'mingcute:invite-fill',
        'action:ongoing'      => 'mdi:progress-download',
        'action:open'         => 'fad:open',
        'action:options'      => 'simple-line-icons:options-vertical',
        'action:premium'      => 'ic:twotone-workspace-premium',
        'action:print'        => 'hugeicons:print',
        'action:prorogation'  => 'iconoir:truck-length',
        'action:refresh'      => 'hugeicons:refresh-01',
        'action:reload'       => 'ci:arrows-reload-01',
        'action:renew'        => 'carbon:renew',
        'action:resiliation'  => 'gravity-ui:hand-stop',
        'action:reset'        => 'hugeicons:refresh-02',
        'action:role'         => 'carbon:user-role',
        'action:save'         => 'fa-solid:save',
        'action:search'       => 'hugeicons:search-01',
        'action:settings'     => 'material-symbols:settings',
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
