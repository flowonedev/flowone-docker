/**
 * Centralized feature guide data for every module.
 * 
 * All user-visible strings are i18n keys under the "featureGuide" namespace.
 * The FeatureGuide.vue component calls t() on every key before rendering.
 * 
 * Structure:
 *   titleKey / footerKey  -> single i18n key
 *   layerKey / layerIcon  -> intelligence layer label key + Google Material icon
 *   tier.nameKey / tier.descriptionKey -> single i18n key
 *   section.labelKey -> i18n key (null = no label)
 *   section.featureKeys -> array of i18n keys
 *   integration.moduleKey -> key into featureGuide.moduleNames
 *   integration.descriptionKey -> i18n key
 */

const g = 'featureGuide'
const m = `${g}.moduleNames`

export const featureGuides = {

  drive: {
    titleKey: `${g}.drive.title`,
    footerKey: `${g}.drive.footer`,
    layerKey: 'appHeader.infrastructureIntelligence',
    layerIcon: 'dns',
    tiers: [
      {
        nameKey: `${g}.drive.tiers.drive.name`,
        addonKey: null,
        color: 'primary',
        descriptionKey: `${g}.drive.tiers.drive.description`,
        sections: [
          {
            labelKey: `${g}.drive.tiers.drive.storage`,
            featureKeys: [
              `${g}.drive.tiers.drive.fileUpload`,
              `${g}.drive.tiers.drive.folderOrganization`,
              `${g}.drive.tiers.drive.dragDrop`,
              `${g}.drive.tiers.drive.imagePreview`,
            ],
          },
          {
            labelKey: `${g}.drive.tiers.drive.documents`,
            featureKeys: [
              `${g}.drive.tiers.drive.docEditor`,
              `${g}.drive.tiers.drive.presentationEditor`,
              `${g}.drive.tiers.drive.markdown`,
              `${g}.drive.tiers.drive.fullTextSearch`,
            ],
          },
          {
            labelKey: `${g}.drive.tiers.drive.sharing`,
            featureKeys: [
              `${g}.drive.tiers.drive.publicShareLinks`,
              `${g}.drive.tiers.drive.folderSharing`,
              `${g}.drive.tiers.drive.portalDelivery`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'view_kanban', moduleKey: `${m}.boardPro`, descriptionKey: `${g}.drive.integrations.boardPro` },
      { icon: 'receipt_long', moduleKey: `${m}.crmInvoices`, descriptionKey: `${g}.drive.integrations.crmInvoices` },
      { icon: 'photo_library', moduleKey: `${m}.moodBoards`, descriptionKey: `${g}.drive.integrations.moodBoards` },
      { icon: 'mail', moduleKey: `${m}.email`, descriptionKey: `${g}.drive.integrations.email` },
    ],
  },

  clients: {
    titleKey: `${g}.clients.title`,
    footerKey: `${g}.clients.footer`,
    layerKey: 'appHeader.clientIntelligence',
    layerIcon: 'groups',
    tiers: [
      {
        nameKey: `${g}.clients.tiers.clients.name`,
        addonKey: null,
        color: 'primary',
        descriptionKey: `${g}.clients.tiers.clients.description`,
        sections: [
          {
            labelKey: `${g}.clients.tiers.clients.core`,
            featureKeys: [
              `${g}.clients.tiers.clients.autoDetect`,
              `${g}.clients.tiers.clients.statusTracking`,
              `${g}.clients.tiers.clients.contactManagement`,
              `${g}.clients.tiers.clients.activityTimeline`,
              `${g}.clients.tiers.clients.linkBoards`,
              `${g}.clients.tiers.clients.hourlyRate`,
            ],
          },
        ],
      },
      {
        nameKey: `${g}.clients.tiers.timeTracker.name`,
        addonKey: 'timeTrackerEnabled',
        color: 'blue',
        descriptionKey: `${g}.clients.tiers.timeTracker.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.clients.tiers.timeTracker.perClientTime`,
              `${g}.clients.tiers.timeTracker.effectiveRate`,
              `${g}.clients.tiers.timeTracker.profitability`,
            ],
          },
        ],
      },
      {
        nameKey: `${g}.clients.tiers.crmPro.name`,
        addonKey: 'crmProEnabled',
        color: 'emerald',
        descriptionKey: `${g}.clients.tiers.crmPro.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.clients.tiers.crmPro.pipeline`,
              `${g}.clients.tiers.crmPro.invoices`,
              `${g}.clients.tiers.crmPro.expenses`,
              `${g}.clients.tiers.crmPro.billingFields`,
              `${g}.clients.tiers.crmPro.reports`,
              `${g}.clients.tiers.crmPro.healthScoring`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.clients.integrations.boards` },
      { icon: 'payments', moduleKey: `${m}.financials`, descriptionKey: `${g}.clients.integrations.financials` },
      { icon: 'timer', moduleKey: `${m}.timeTracker`, descriptionKey: `${g}.clients.integrations.timeTracker` },
      { icon: 'mail', moduleKey: `${m}.email`, descriptionKey: `${g}.clients.integrations.email` },
    ],
  },

  calendar: {
    titleKey: `${g}.calendar.title`,
    footerKey: `${g}.calendar.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.calendar.tiers.calendar.name`,
        addonKey: 'calendarEnabled',
        color: 'primary',
        descriptionKey: `${g}.calendar.tiers.calendar.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.calendar.tiers.calendar.monthWeekDay`,
              `${g}.calendar.tiers.calendar.createEdit`,
              `${g}.calendar.tiers.calendar.caldav`,
              `${g}.calendar.tiers.calendar.multiple`,
              `${g}.calendar.tiers.calendar.colorCoded`,
              `${g}.calendar.tiers.calendar.reminders`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'check_circle', moduleKey: `${m}.tasks`, descriptionKey: `${g}.calendar.integrations.tasks` },
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.calendar.integrations.boards` },
    ],
  },

  moodboards: {
    titleKey: `${g}.moodboards.title`,
    footerKey: `${g}.moodboards.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.moodboards.tiers.moodboards.name`,
        addonKey: 'moodboardsEnabled',
        color: 'primary',
        descriptionKey: `${g}.moodboards.tiers.moodboards.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.moodboards.tiers.moodboards.dragDrop`,
              `${g}.moodboards.tiers.moodboards.multipleBoards`,
              `${g}.moodboards.tiers.moodboards.shareLinks`,
              `${g}.moodboards.tiers.moodboards.imageUpload`,
              `${g}.moodboards.tiers.moodboards.freeform`,
              `${g}.moodboards.tiers.moodboards.backgrounds`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'dashboard_customize', moduleKey: `${m}.boardPro`, descriptionKey: `${g}.moodboards.integrations.boardPro` },
      { icon: 'folder', moduleKey: `${m}.drive`, descriptionKey: `${g}.moodboards.integrations.drive` },
      { icon: 'share', moduleKey: `${m}.clientPortal`, descriptionKey: `${g}.moodboards.integrations.portal` },
    ],
  },

  timeTracker: {
    titleKey: `${g}.timeTracker.title`,
    footerKey: `${g}.timeTracker.footer`,
    layerKey: 'appHeader.deliveryIntelligence',
    layerIcon: 'engineering',
    tiers: [
      {
        nameKey: `${g}.timeTracker.tiers.timeTracker.name`,
        addonKey: 'timeTrackerEnabled',
        color: 'primary',
        descriptionKey: `${g}.timeTracker.tiers.timeTracker.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.timeTracker.tiers.timeTracker.oneClick`,
              `${g}.timeTracker.tiers.timeTracker.manualEntry`,
              `${g}.timeTracker.tiers.timeTracker.perClient`,
              `${g}.timeTracker.tiers.timeTracker.summaries`,
              `${g}.timeTracker.tiers.timeTracker.categorization`,
              `${g}.timeTracker.tiers.timeTracker.reports`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.timeTracker.integrations.clients` },
      { icon: 'view_kanban', moduleKey: `${m}.boardPro`, descriptionKey: `${g}.timeTracker.integrations.boardPro` },
      { icon: 'receipt_long', moduleKey: `${m}.crmInvoices`, descriptionKey: `${g}.timeTracker.integrations.crmPro` },
      { icon: 'payments', moduleKey: `${m}.financials`, descriptionKey: `${g}.timeTracker.integrations.financials` },
    ],
  },

  team: {
    titleKey: `${g}.team.title`,
    footerKey: `${g}.team.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.team.tiers.team.name`,
        addonKey: 'teamEnabled',
        color: 'primary',
        descriptionKey: `${g}.team.tiers.team.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.team.tiers.team.addManage`,
              `${g}.team.tiers.team.rolesStatus`,
              `${g}.team.tiers.team.presence`,
              `${g}.team.tiers.team.directory`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'chat', moduleKey: `${m}.chat`, descriptionKey: `${g}.team.integrations.chatDm` },
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.team.integrations.boards` },
      { icon: 'videocam', moduleKey: `${m}.chat`, descriptionKey: `${g}.team.integrations.chatVideo` },
    ],
  },

  chat: {
    titleKey: `${g}.chat.title`,
    footerKey: `${g}.chat.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.chat.tiers.chat.name`,
        addonKey: 'chatEnabled',
        color: 'primary',
        descriptionKey: `${g}.chat.tiers.chat.description`,
        sections: [
          {
            labelKey: `${g}.chat.tiers.chat.messaging`,
            featureKeys: [
              `${g}.chat.tiers.chat.directMessages`,
              `${g}.chat.tiers.chat.groupChannels`,
              `${g}.chat.tiers.chat.fileSharing`,
              `${g}.chat.tiers.chat.reactions`,
              `${g}.chat.tiers.chat.readReceipts`,
            ],
          },
          {
            labelKey: `${g}.chat.tiers.chat.meetings`,
            featureKeys: [
              `${g}.chat.tiers.chat.videoCalls`,
              `${g}.chat.tiers.chat.screenSharing`,
              `${g}.chat.tiers.chat.meetingInvites`,
              `${g}.chat.tiers.chat.inCallChat`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.team`, descriptionKey: `${g}.chat.integrations.team` },
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.chat.integrations.boards` },
    ],
  },

  emailMarketing: {
    titleKey: `${g}.emailMarketing.title`,
    footerKey: `${g}.emailMarketing.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.emailMarketing.tiers.emailMarketing.name`,
        addonKey: 'emailMarketingEnabled',
        color: 'primary',
        descriptionKey: `${g}.emailMarketing.tiers.emailMarketing.description`,
        sections: [
          {
            labelKey: `${g}.emailMarketing.tiers.emailMarketing.lists`,
            featureKeys: [
              `${g}.emailMarketing.tiers.emailMarketing.createLists`,
              `${g}.emailMarketing.tiers.emailMarketing.importCsv`,
              `${g}.emailMarketing.tiers.emailMarketing.manageSubscriptions`,
              `${g}.emailMarketing.tiers.emailMarketing.unsubscribe`,
            ],
          },
          {
            labelKey: `${g}.emailMarketing.tiers.emailMarketing.campaigns`,
            featureKeys: [
              `${g}.emailMarketing.tiers.emailMarketing.htmlEditor`,
              `${g}.emailMarketing.tiers.emailMarketing.scheduling`,
              `${g}.emailMarketing.tiers.emailMarketing.tracking`,
              `${g}.emailMarketing.tiers.emailMarketing.templates`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.emailMarketing.integrations.clients` },
      { icon: 'mail', moduleKey: `${m}.email`, descriptionKey: `${g}.emailMarketing.integrations.email` },
    ],
  },

  automationHub: {
    titleKey: `${g}.automationHub.title`,
    footerKey: `${g}.automationHub.footer`,
    layerKey: 'appHeader.operationsIntelligence',
    layerIcon: 'hub',
    tiers: [
      {
        nameKey: `${g}.automationHub.tiers.automationHub.name`,
        addonKey: 'automationHubEnabled',
        color: 'primary',
        descriptionKey: `${g}.automationHub.tiers.automationHub.description`,
        sections: [
          {
            labelKey: `${g}.automationHub.tiers.automationHub.workflowBuilder`,
            featureKeys: [
              `${g}.automationHub.tiers.automationHub.visualEditor`,
              `${g}.automationHub.tiers.automationHub.dragDrop`,
              `${g}.automationHub.tiers.automationHub.conditionalBranching`,
              `${g}.automationHub.tiers.automationHub.delaySchedule`,
            ],
          },
          {
            labelKey: `${g}.automationHub.tiers.automationHub.triggersActions`,
            featureKeys: [
              `${g}.automationHub.tiers.automationHub.emailTriggers`,
              `${g}.automationHub.tiers.automationHub.boardTriggers`,
              `${g}.automationHub.tiers.automationHub.crmTriggers`,
              `${g}.automationHub.tiers.automationHub.webhookTriggers`,
              `${g}.automationHub.tiers.automationHub.sendEmailActions`,
              `${g}.automationHub.tiers.automationHub.createCardActions`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'mail', moduleKey: `${m}.email`, descriptionKey: `${g}.automationHub.integrations.email` },
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.automationHub.integrations.boards` },
      { icon: 'receipt_long', moduleKey: `${m}.crmInvoices`, descriptionKey: `${g}.automationHub.integrations.crmPro` },
      { icon: 'webhook', moduleKey: `${m}.external`, descriptionKey: `${g}.automationHub.integrations.external` },
    ],
  },

  crmPipeline: {
    titleKey: `${g}.crmPipeline.title`,
    footerKey: `${g}.crmPipeline.footer`,
    layerKey: 'appHeader.revenueIntelligence',
    layerIcon: 'payments',
    tiers: [
      {
        nameKey: `${g}.crmPipeline.tiers.crmPro.name`,
        addonKey: 'crmProEnabled',
        color: 'primary',
        descriptionKey: `${g}.crmPipeline.tiers.crmPro.description`,
        sections: [
          {
            labelKey: `${g}.crmPipeline.tiers.crmPro.pipeline`,
            featureKeys: [
              `${g}.crmPipeline.tiers.crmPro.kanbanDealBoard`,
              `${g}.crmPipeline.tiers.crmPro.customStages`,
              `${g}.crmPipeline.tiers.crmPro.dealValue`,
              `${g}.crmPipeline.tiers.crmPro.winProbability`,
              `${g}.crmPipeline.tiers.crmPro.dragDropStages`,
            ],
          },
          {
            labelKey: `${g}.crmPipeline.tiers.crmPro.forecasting`,
            featureKeys: [
              `${g}.crmPipeline.tiers.crmPro.weightedForecast`,
              `${g}.crmPipeline.tiers.crmPro.monthlyProjections`,
              `${g}.crmPipeline.tiers.crmPro.winRateAnalytics`,
              `${g}.crmPipeline.tiers.crmPro.conversionFunnel`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.crmPipeline.integrations.clients` },
      { icon: 'receipt_long', moduleKey: `${m}.invoices`, descriptionKey: `${g}.crmPipeline.integrations.invoices` },
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.crmPipeline.integrations.boards` },
      { icon: 'bolt', moduleKey: `${m}.automation`, descriptionKey: `${g}.crmPipeline.integrations.automation` },
    ],
  },

  crmInvoices: {
    titleKey: `${g}.crmInvoices.title`,
    footerKey: `${g}.crmInvoices.footer`,
    layerKey: 'appHeader.revenueIntelligence',
    layerIcon: 'payments',
    tiers: [
      {
        nameKey: `${g}.crmInvoices.tiers.crmPro.name`,
        addonKey: 'crmProEnabled',
        color: 'primary',
        descriptionKey: `${g}.crmInvoices.tiers.crmPro.description`,
        sections: [
          {
            labelKey: `${g}.crmInvoices.tiers.crmPro.invoicing`,
            featureKeys: [
              `${g}.crmInvoices.tiers.crmPro.createInvoices`,
              `${g}.crmInvoices.tiers.crmPro.taxDiscount`,
              `${g}.crmInvoices.tiers.crmPro.partialPayments`,
              `${g}.crmInvoices.tiers.crmPro.recurring`,
              `${g}.crmInvoices.tiers.crmPro.pdfGeneration`,
              `${g}.crmInvoices.tiers.crmPro.sendViaEmail`,
            ],
          },
          {
            labelKey: `${g}.crmInvoices.tiers.crmPro.billingProviders`,
            featureKeys: [
              `${g}.crmInvoices.tiers.crmPro.billingo`,
              `${g}.crmInvoices.tiers.crmPro.szamlazz`,
              `${g}.crmInvoices.tiers.crmPro.autoPush`,
              `${g}.crmInvoices.tiers.crmPro.statusSync`,
              `${g}.crmInvoices.tiers.crmPro.autoSavePdf`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.crmInvoices.integrations.clients` },
      { icon: 'handshake', moduleKey: `${m}.pipeline`, descriptionKey: `${g}.crmInvoices.integrations.pipeline` },
      { icon: 'view_kanban', moduleKey: `${m}.boardPro`, descriptionKey: `${g}.crmInvoices.integrations.boardPro` },
      { icon: 'payments', moduleKey: `${m}.financials`, descriptionKey: `${g}.crmInvoices.integrations.financials` },
      { icon: 'folder', moduleKey: `${m}.drive`, descriptionKey: `${g}.crmInvoices.integrations.drive` },
    ],
  },

  crmDashboard: {
    titleKey: `${g}.crmDashboard.title`,
    footerKey: `${g}.crmDashboard.footer`,
    layerKey: 'appHeader.revenueIntelligence',
    layerIcon: 'payments',
    tiers: [
      {
        nameKey: `${g}.crmDashboard.tiers.crmPro.name`,
        addonKey: 'crmProEnabled',
        color: 'primary',
        descriptionKey: `${g}.crmDashboard.tiers.crmPro.description`,
        sections: [
          {
            labelKey: `${g}.crmDashboard.tiers.crmPro.dashboard`,
            featureKeys: [
              `${g}.crmDashboard.tiers.crmPro.revenueOverview`,
              `${g}.crmDashboard.tiers.crmPro.pipelineValue`,
              `${g}.crmDashboard.tiers.crmPro.monthlyChart`,
              `${g}.crmDashboard.tiers.crmPro.clientHealth`,
            ],
          },
          {
            labelKey: `${g}.crmDashboard.tiers.crmPro.reports`,
            featureKeys: [
              `${g}.crmDashboard.tiers.crmPro.invoiceAging`,
              `${g}.crmDashboard.tiers.crmPro.clientValueRanking`,
              `${g}.crmDashboard.tiers.crmPro.profitabilityAnalysis`,
              `${g}.crmDashboard.tiers.crmPro.dealForecast`,
              `${g}.crmDashboard.tiers.crmPro.conversionFunnel`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'handshake', moduleKey: `${m}.pipeline`, descriptionKey: `${g}.crmDashboard.integrations.pipeline` },
      { icon: 'receipt_long', moduleKey: `${m}.invoices`, descriptionKey: `${g}.crmDashboard.integrations.invoices` },
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.crmDashboard.integrations.clients` },
      { icon: 'timer', moduleKey: `${m}.timeTracker`, descriptionKey: `${g}.crmDashboard.integrations.timeTracker` },
    ],
  },

  financials: {
    titleKey: `${g}.financials.title`,
    footerKey: `${g}.financials.footer`,
    layerKey: 'appHeader.revenueIntelligence',
    layerIcon: 'payments',
    tiers: [
      {
        nameKey: `${g}.financials.tiers.kanbanBoards.name`,
        addonKey: null,
        color: 'primary',
        descriptionKey: `${g}.financials.tiers.kanbanBoards.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.financials.tiers.kanbanBoards.milestoneAmounts`,
              `${g}.financials.tiers.kanbanBoards.invoiceDateTracking`,
              `${g}.financials.tiers.kanbanBoards.cashFlowProjection`,
              `${g}.financials.tiers.kanbanBoards.groupBy`,
              `${g}.financials.tiers.kanbanBoards.chartsYoy`,
            ],
          },
        ],
      },
      {
        nameKey: `${g}.financials.tiers.boardPro.name`,
        addonKey: 'boardProEnabled',
        color: 'blue',
        descriptionKey: `${g}.financials.tiers.boardPro.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.financials.tiers.boardPro.revenuePerCard`,
              `${g}.financials.tiers.boardPro.marginCalc`,
              `${g}.financials.tiers.boardPro.timeBudget`,
              `${g}.financials.tiers.boardPro.boardRevenueView`,
              `${g}.financials.tiers.boardPro.financialAutomations`,
            ],
          },
        ],
      },
      {
        nameKey: `${g}.financials.tiers.crmPro.name`,
        addonKey: 'crmProEnabled',
        color: 'emerald',
        descriptionKey: `${g}.financials.tiers.crmPro.description`,
        sections: [
          {
            labelKey: null,
            featureKeys: [
              `${g}.financials.tiers.crmPro.createInvoices`,
              `${g}.financials.tiers.crmPro.billingoSzamlazz`,
              `${g}.financials.tiers.crmPro.pipelineDeals`,
              `${g}.financials.tiers.crmPro.expenseTracking`,
              `${g}.financials.tiers.crmPro.invoiceAutomations`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'view_kanban', moduleKey: `${m}.boards`, descriptionKey: `${g}.financials.integrations.boards` },
      { icon: 'credit_score', moduleKey: `${m}.boardPro`, descriptionKey: `${g}.financials.integrations.boardPro` },
      { icon: 'receipt_long', moduleKey: `${m}.crmInvoices`, descriptionKey: `${g}.financials.integrations.crmInvoices` },
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.financials.integrations.clients` },
    ],
  },

  boards: {
    titleKey: `${g}.boards.title`,
    footerKey: `${g}.boards.footer`,
    layerKey: 'appHeader.deliveryIntelligence',
    layerIcon: 'engineering',
    tiers: [
      {
        nameKey: `${g}.boards.tiers.kanbanBoards.name`,
        addonKey: null,
        color: 'primary',
        descriptionKey: `${g}.boards.tiers.kanbanBoards.description`,
        sections: [
          {
            labelKey: `${g}.boards.tiers.kanbanBoards.views`,
            featureKeys: [
              `${g}.boards.tiers.kanbanBoards.kanbanBoard`,
              `${g}.boards.tiers.kanbanBoards.tableView`,
              `${g}.boards.tiers.kanbanBoards.calendarView`,
              `${g}.boards.tiers.kanbanBoards.timelineView`,
            ],
          },
          {
            labelKey: `${g}.boards.tiers.kanbanBoards.features`,
            featureKeys: [
              `${g}.boards.tiers.kanbanBoards.checklists`,
              `${g}.boards.tiers.kanbanBoards.labels`,
              `${g}.boards.tiers.kanbanBoards.milestoneBilling`,
              `${g}.boards.tiers.kanbanBoards.teamSharing`,
              `${g}.boards.tiers.kanbanBoards.boardMap`,
              `${g}.boards.tiers.kanbanBoards.trelloImport`,
            ],
          },
        ],
      },
      {
        nameKey: `${g}.boards.tiers.boardPro.name`,
        addonKey: 'boardProEnabled',
        color: 'blue',
        descriptionKey: `${g}.boards.tiers.boardPro.description`,
        sections: [
          {
            labelKey: `${g}.boards.tiers.boardPro.extraViews`,
            featureKeys: [
              `${g}.boards.tiers.boardPro.revenueBillingView`,
              `${g}.boards.tiers.boardPro.timeTrackingView`,
              `${g}.boards.tiers.boardPro.workBreakdownView`,
              `${g}.boards.tiers.boardPro.moodBoardSplitView`,
            ],
          },
          {
            labelKey: `${g}.boards.tiers.boardPro.cardFeatures`,
            featureKeys: [
              `${g}.boards.tiers.boardPro.perCardRevenue`,
              `${g}.boards.tiers.boardPro.linkedEmails`,
              `${g}.boards.tiers.boardPro.driveFiles`,
              `${g}.boards.tiers.boardPro.commandCenter`,
            ],
          },
          {
            labelKey: `${g}.boards.tiers.boardPro.automationReports`,
            featureKeys: [
              `${g}.boards.tiers.boardPro.boardAutomations`,
              `${g}.boards.tiers.boardPro.emailRules`,
              `${g}.boards.tiers.boardPro.executiveReport`,
              `${g}.boards.tiers.boardPro.proSettings`,
            ],
          },
        ],
      },
    ],
    integrations: [
      { icon: 'people', moduleKey: `${m}.clients`, descriptionKey: `${g}.boards.integrations.clients` },
      { icon: 'payments', moduleKey: `${m}.financials`, descriptionKey: `${g}.boards.integrations.financials` },
      { icon: 'mail', moduleKey: `${m}.boardProEmail`, descriptionKey: `${g}.boards.integrations.boardProEmail` },
      { icon: 'folder', moduleKey: `${m}.boardProDrive`, descriptionKey: `${g}.boards.integrations.boardProDrive` },
    ],
  },
}
