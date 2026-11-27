<?php

return [
    'permissions' => [
        'identity.companies.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'identity.companies.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'identity.companies.read',
            ],
        ],
        'identity.members.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'identity.members.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'identity.companies.read',
                1 => 'identity.members.read',
            ],
        ],
        'identity.roles.assign' => [
            'mutating' => true,
            'requires' => [
                0 => 'identity.companies.read',
                1 => 'identity.members.read',
            ],
        ],
        'identity.owners.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'identity.companies.read',
                1 => 'identity.members.read',
            ],
        ],
        'identity.audit.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'clients.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'clients.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'clients.read',
            ],
        ],
        'clients.archive' => [
            'mutating' => true,
            'requires' => [
                0 => 'clients.read',
            ],
        ],
        'clients.restore' => [
            'mutating' => true,
            'requires' => [
                0 => 'clients.read',
            ],
        ],
        'vehicles.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'vehicles.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'vehicles.read',
            ],
        ],
        'vehicles.archive' => [
            'mutating' => true,
            'requires' => [
                0 => 'vehicles.read',
            ],
        ],
        'vehicles.restore' => [
            'mutating' => true,
            'requires' => [
                0 => 'vehicles.read',
            ],
        ],
        'appointments.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'appointments.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'appointments.read',
            ],
        ],
        'appointments.cancel' => [
            'mutating' => true,
            'requires' => [
                0 => 'appointments.read',
            ],
        ],
        'appointments.archive' => [
            'mutating' => true,
            'requires' => [
                0 => 'appointments.read',
            ],
        ],
        'appointments.settings.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'appointments.read',
            ],
        ],
        'interventions.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'interventions.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'interventions.read',
            ],
        ],
        'interventions.complete' => [
            'mutating' => true,
            'requires' => [
                0 => 'interventions.read',
            ],
        ],
        'interventions.cancel' => [
            'mutating' => true,
            'requires' => [
                0 => 'interventions.read',
            ],
        ],
        'interventions.catalog.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'interventions.catalog.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'interventions.catalog.read',
                1 => 'interventions.read',
            ],
        ],
        'interventions.pricing.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'interventions.pricing.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'interventions.pricing.read',
                1 => 'interventions.read',
            ],
        ],
        'inventory.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'inventory.catalog.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.suppliers.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.purchases.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.receipts.record' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.returns.record' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.stock.adjust' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.stock.reserve' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.stock.consume' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.stock.release' => [
            'mutating' => true,
            'requires' => [
                0 => 'inventory.read',
            ],
        ],
        'inventory.valuation.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'billing.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'billing.estimates.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.estimates.approve' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.work_orders.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.work_orders.authorize' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.invoices.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.invoices.issue' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.payments.record' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.credit_notes.issue' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.refunds.record' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.prices.override' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.settings.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'billing.drafts.archive' => [
            'mutating' => true,
            'requires' => [
                0 => 'billing.read',
            ],
        ],
        'accounting.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'accounting.bills.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.bills.post' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.supplier_payments.record' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.journal.post' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.journal.reverse' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.periods.close' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.tax_policies.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'accounting.tax_policies.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
                1 => 'accounting.tax_policies.read',
            ],
        ],
        'accounting.reports.read' => [
            'mutating' => false,
            'requires' => [
            ],
        ],
        'accounting.attachments.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.mileage.write' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
        'accounting.treasury.manage' => [
            'mutating' => true,
            'requires' => [
                0 => 'accounting.read',
            ],
        ],
    ],
    'roles' => [
        'owner' => [
            0 => 'accounting.attachments.manage',
            1 => 'accounting.bills.post',
            2 => 'accounting.bills.write',
            3 => 'accounting.journal.post',
            4 => 'accounting.journal.reverse',
            5 => 'accounting.mileage.write',
            6 => 'accounting.periods.close',
            7 => 'accounting.read',
            8 => 'accounting.reports.read',
            9 => 'accounting.supplier_payments.record',
            10 => 'accounting.tax_policies.manage',
            11 => 'accounting.tax_policies.read',
            12 => 'accounting.treasury.manage',
            13 => 'appointments.archive',
            14 => 'appointments.cancel',
            15 => 'appointments.read',
            16 => 'appointments.settings.manage',
            17 => 'appointments.write',
            18 => 'billing.credit_notes.issue',
            19 => 'billing.drafts.archive',
            20 => 'billing.estimates.approve',
            21 => 'billing.estimates.write',
            22 => 'billing.invoices.issue',
            23 => 'billing.invoices.write',
            24 => 'billing.payments.record',
            25 => 'billing.prices.override',
            26 => 'billing.read',
            27 => 'billing.refunds.record',
            28 => 'billing.settings.manage',
            29 => 'billing.work_orders.authorize',
            30 => 'billing.work_orders.write',
            31 => 'clients.archive',
            32 => 'clients.read',
            33 => 'clients.restore',
            34 => 'clients.write',
            35 => 'identity.audit.read',
            36 => 'identity.companies.manage',
            37 => 'identity.companies.read',
            38 => 'identity.members.manage',
            39 => 'identity.members.read',
            40 => 'identity.owners.manage',
            41 => 'identity.roles.assign',
            42 => 'interventions.cancel',
            43 => 'interventions.catalog.manage',
            44 => 'interventions.catalog.read',
            45 => 'interventions.complete',
            46 => 'interventions.pricing.manage',
            47 => 'interventions.pricing.read',
            48 => 'interventions.read',
            49 => 'interventions.write',
            50 => 'inventory.catalog.manage',
            51 => 'inventory.purchases.manage',
            52 => 'inventory.read',
            53 => 'inventory.receipts.record',
            54 => 'inventory.returns.record',
            55 => 'inventory.stock.adjust',
            56 => 'inventory.stock.consume',
            57 => 'inventory.stock.release',
            58 => 'inventory.stock.reserve',
            59 => 'inventory.suppliers.manage',
            60 => 'inventory.valuation.read',
            61 => 'vehicles.archive',
            62 => 'vehicles.read',
            63 => 'vehicles.restore',
            64 => 'vehicles.write',
        ],
        'administrator' => [
            0 => 'appointments.archive',
            1 => 'appointments.cancel',
            2 => 'appointments.read',
            3 => 'appointments.settings.manage',
            4 => 'appointments.write',
            5 => 'billing.drafts.archive',
            6 => 'billing.estimates.approve',
            7 => 'billing.estimates.write',
            8 => 'billing.invoices.issue',
            9 => 'billing.invoices.write',
            10 => 'billing.payments.record',
            11 => 'billing.prices.override',
            12 => 'billing.read',
            13 => 'billing.settings.manage',
            14 => 'billing.work_orders.authorize',
            15 => 'billing.work_orders.write',
            16 => 'clients.archive',
            17 => 'clients.read',
            18 => 'clients.restore',
            19 => 'clients.write',
            20 => 'identity.audit.read',
            21 => 'identity.companies.manage',
            22 => 'identity.companies.read',
            23 => 'identity.members.manage',
            24 => 'identity.members.read',
            25 => 'identity.roles.assign',
            26 => 'interventions.cancel',
            27 => 'interventions.catalog.manage',
            28 => 'interventions.catalog.read',
            29 => 'interventions.complete',
            30 => 'interventions.pricing.manage',
            31 => 'interventions.pricing.read',
            32 => 'interventions.read',
            33 => 'interventions.write',
            34 => 'inventory.catalog.manage',
            35 => 'inventory.purchases.manage',
            36 => 'inventory.read',
            37 => 'inventory.receipts.record',
            38 => 'inventory.returns.record',
            39 => 'inventory.stock.adjust',
            40 => 'inventory.stock.consume',
            41 => 'inventory.stock.release',
            42 => 'inventory.stock.reserve',
            43 => 'inventory.suppliers.manage',
            44 => 'inventory.valuation.read',
            45 => 'vehicles.archive',
            46 => 'vehicles.read',
            47 => 'vehicles.restore',
            48 => 'vehicles.write',
        ],
        'service_advisor' => [
            0 => 'appointments.archive',
            1 => 'appointments.cancel',
            2 => 'appointments.read',
            3 => 'appointments.write',
            4 => 'billing.estimates.approve',
            5 => 'billing.estimates.write',
            6 => 'billing.invoices.issue',
            7 => 'billing.invoices.write',
            8 => 'billing.payments.record',
            9 => 'billing.read',
            10 => 'billing.work_orders.authorize',
            11 => 'billing.work_orders.write',
            12 => 'clients.archive',
            13 => 'clients.read',
            14 => 'clients.write',
            15 => 'identity.companies.read',
            16 => 'interventions.catalog.read',
            17 => 'interventions.pricing.read',
            18 => 'interventions.read',
            19 => 'inventory.read',
            20 => 'vehicles.archive',
            21 => 'vehicles.read',
            22 => 'vehicles.write',
        ],
        'technician' => [
            0 => 'appointments.read',
            1 => 'clients.read',
            2 => 'identity.companies.read',
            3 => 'interventions.cancel',
            4 => 'interventions.catalog.read',
            5 => 'interventions.complete',
            6 => 'interventions.read',
            7 => 'interventions.write',
            8 => 'inventory.read',
            9 => 'inventory.stock.consume',
            10 => 'inventory.stock.release',
            11 => 'inventory.stock.reserve',
            12 => 'vehicles.read',
        ],
        'inventory_manager' => [
            0 => 'identity.companies.read',
            1 => 'interventions.read',
            2 => 'inventory.catalog.manage',
            3 => 'inventory.purchases.manage',
            4 => 'inventory.read',
            5 => 'inventory.receipts.record',
            6 => 'inventory.returns.record',
            7 => 'inventory.stock.adjust',
            8 => 'inventory.stock.consume',
            9 => 'inventory.stock.release',
            10 => 'inventory.stock.reserve',
            11 => 'inventory.suppliers.manage',
            12 => 'inventory.valuation.read',
        ],
        'accountant' => [
            0 => 'accounting.attachments.manage',
            1 => 'accounting.bills.post',
            2 => 'accounting.bills.write',
            3 => 'accounting.journal.post',
            4 => 'accounting.journal.reverse',
            5 => 'accounting.mileage.write',
            6 => 'accounting.periods.close',
            7 => 'accounting.read',
            8 => 'accounting.reports.read',
            9 => 'accounting.supplier_payments.record',
            10 => 'accounting.tax_policies.manage',
            11 => 'accounting.tax_policies.read',
            12 => 'accounting.treasury.manage',
            13 => 'billing.read',
            14 => 'clients.read',
            15 => 'identity.companies.read',
            16 => 'inventory.read',
            17 => 'inventory.valuation.read',
            18 => 'vehicles.read',
        ],
        'auditor' => [
            0 => 'accounting.read',
            1 => 'accounting.reports.read',
            2 => 'accounting.tax_policies.read',
            3 => 'appointments.read',
            4 => 'billing.read',
            5 => 'clients.read',
            6 => 'identity.audit.read',
            7 => 'identity.companies.read',
            8 => 'identity.members.read',
            9 => 'interventions.catalog.read',
            10 => 'interventions.pricing.read',
            11 => 'interventions.read',
            12 => 'inventory.read',
            13 => 'inventory.valuation.read',
            14 => 'vehicles.read',
        ],
    ],
    'recent_authentication_permissions' => [
        0 => 'billing.credit_notes.issue',
        1 => 'billing.refunds.record',
        2 => 'billing.prices.override',
        3 => 'accounting.periods.close',
        4 => 'accounting.tax_policies.manage',
        5 => 'accounting.treasury.manage',
        6 => 'accounting.journal.reverse',
        7 => 'identity.owners.manage',
        8 => 'identity.roles.assign',
    ],
];
