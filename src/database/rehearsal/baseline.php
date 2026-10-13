<?php

/*
|--------------------------------------------------------------------------
| Release 0.9 migration rehearsal: the pinned baseline
|--------------------------------------------------------------------------
| The rehearsal harness (App\Support\Rehearsal) builds its baseline from EXACTLY these migrations, in this
| order, and nothing else: it migrates them, seeds the deterministic fixtures, snapshots, and only then applies
| the approved candidates named in candidates.php. Each entry is the SHA-256 of the migration file as it
| shipped, so a baseline file that is edited or missing is reported as drift.
|
| Every file in database/migrations must be named either here or in candidates.php. A migration that is in
| neither is refused before anything is changed: it must not silently join the baseline (it would then be
| installed on an empty schema and never exercised against populated data).
|
| This list is history. It changes in only two situations, both reviewed:
|   - a candidate has shipped and a LATER package needs its columns in the fixtures: move the entry from
|     candidates.php to the end of this list (name => sha256) in the same change that updates the fixtures;
|   - never otherwise. Shipped migrations are not edited (R0.9-0 section 6, item 7).
|
| Names must be unique, in ascending order (the order the migrator applies them), and every candidate must sort
| after the last baseline migration. To recompute a hash: sha256sum database/migrations/<name>.php
*/

return [
    '0001_01_01_000000_create_users_table' => '10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f',
    '0001_01_01_000001_create_cache_table' => 'fbb4665e5a977e71df4e74ec75b3c06ca4f17ad0de7138fe9786639189978e77',
    '0001_01_01_000002_create_jobs_table' => '7c9c712adf4091c87bf2612f4d0dccdfa1df4501553e4ac068b83d7bd5462fa5',
    '2026_03_25_043019_create_permission_tables' => '9be723cec2a8625506c8e0073a4bbabca1fbb08dbbbfb67ab55fe3e93d7298f0',
    '2026_03_25_054944_create_invitations_table' => 'e69a4f9b0c79c65998db389b23f41440c6272fde01cf3a96c84a97c9073dd9ed',
    '2026_03_25_054950_add_auth_fields_to_users_table' => 'e3481930c9c524da6be93b977137529f31340e82265997a90925c37551428fd3',
    '2026_03_25_055000_create_social_accounts_table' => '41e8a71f33f970d4d2810ab76c9d8a3693a5253fa6515ceb3fd828b5cda97d79',
    '2026_03_25_055013_create_password_histories_table' => '3f0abc40fcac238dba94745b4d0faee76e84402477b6948b470cb22b7dec926f',
    '2026_03_25_125248_create_activity_log_table' => '587d973b90e91618bfe82ea05488ca98c05982ebfda6e09b748fbaec8e49eba9',
    '2026_03_25_125249_add_event_column_to_activity_log_table' => '7b939413d1f49e769caf46448f96ca9be0ff3c146dedfb83703184d352578c26',
    '2026_03_25_125250_add_batch_uuid_column_to_activity_log_table' => 'ebc57f53396fa2e3dda9ba7678c95183ca12412e5c7815c0e0b324ef33207865',
    '2026_03_25_130335_create_tickets_table' => '12673138223b93c2c2ef733db97c41761b373be2c77a5c1ce166920c5e767d43',
    '2026_03_25_130405_create_ticket_replies_table' => 'dc7488ffecfe125fd30ef82c5f77c86ec4ff007af0833f9a0e91abdc514f04a3',
    '2026_03_25_130433_create_ticket_attachments_table' => 'f0ba06b74d6138f7465330be2a0e255236c4dd71fb4a382d3401a96796eed228',
    '2026_03_25_130505_create_ticket_status_histories_table' => '701a9637f7489e8b3c2cc8e22e28cab47858545ec81c1aae3ec620e385d28a06',
    '2026_03_25_173153_create_projects_table' => '1f08fcba3d3e531f2f5c8097bdb86f60aad9a12ae0eef666ba245ffad050cc50',
    '2026_03_25_173207_create_project_members_table' => '0738e053c8f45334d5dbb2a8905bad9c08e4a12807bd8a1f49f0ef92580dfb93',
    '2026_03_25_173222_create_project_columns_table' => '46e18eb77d1808d2d34a1e74a13f5ed986504d76dc549e25058d96a99b605873',
    '2026_03_25_173238_create_project_tasks_table' => 'dd57970e60e193627855c2a9a208d705db805bada45602e00a43e6658529d627',
    '2026_03_25_173254_create_project_task_checklist_items_table' => '19c003ff2e4eec5c3b8402e6eec3a470a2d3e098ac1b408472ed39bf7b7db7b2',
    '2026_03_25_173309_create_project_milestones_table' => '6ba07f6ba248e940318768ceb609dac6564a1a8fe87d2a3962dab63477d2b456',
    '2026_03_25_173323_create_project_task_comments_table' => 'd0b5a47b0cb639d899a9d3727bb3ea20a6d167dda0dc729f180b48a5829aa4c0',
    '2026_03_25_181132_create_invoices_table' => 'c6f556a861548dedc535f6bb11023d3897d346ce1e8473aa6a20de6e1111c1cf',
    '2026_03_25_181153_create_invoice_items_table' => '1af2dd5b78079a730b7d672d10100987d59e6b5815a9e2529a8888eb27a5787d',
    '2026_03_25_181213_create_invoice_payments_table' => 'dd6758d02e9b543f67b4612fc05066a017ad473fa575ebf9ff40b01dc182b1c2',
    '2026_03_26_000416_create_time_entries_table' => '671edacf2dfdf60c741566713175681ed09ea14c81f04699c2453279f385d15a',
    '2026_03_26_095958_create_organizations_table' => '12cae21ca68cb8ab86e20ff146648ff61570f420227b68ebe5e9ac8b66b6ba8c',
    '2026_03_26_095959_create_organization_members_table' => '36c7c41bfbfca6dec8906b7a3838265ea1d4fa0ca07208f27e98597a773d1736',
    '2026_03_26_100000_create_crm_companies_table' => 'e07089074d95da9dc497ad7cbef7804fd9cdf29b58ea60639417d570478fdd26',
    '2026_03_26_100001_create_crm_contacts_table' => '4cf8107e57aed22312555c187dbdefc7ba417bfb45d2ab88de15aa18b97dc97f',
    '2026_03_26_110000_create_cms_pages_table' => '15bc8a671d3723223f015c8ab8c6d2d8b4d3121d5af1a369817ae66fc009b6dc',
    '2026_03_26_120001_rename_project_tasks_to_tasks' => '49a8ef3ffcbd1d1b53890666e68c9125ebcd376f03836cd9badfb7777d64476a',
    '2026_03_26_120002_rename_project_task_checklist_items' => '8eb762cbc851ce29da35d86765e19e37f7f801f3c78a158e49bd3e6ba0b061a4',
    '2026_03_26_120003_rename_project_task_comments' => 'd946491d2c7c4f3bbc5a7292753ba8cc726a4a8275f16935d24fd81a0037836a',
    '2026_03_26_120004_create_project_company_table' => '395383eddb495af66001fe846bc514e310d04b32979afcc8bed1cfd1f6719ed4',
    '2026_03_26_120005_create_task_dependencies_table' => '8182b6cf5ccb536b2b4ef0b8eab9803c193670c867016a74e74db6273ed91fd6',
    '2026_03_26_120006_add_company_id_to_tickets' => '6b4dc8a7ebb81ff9e881ebeb3f656dc3677fdd713d9355d4eb5a12d5b054bcda',
    '2026_03_26_120007_update_time_entries_task_id_fk' => '7280d418917f12b32d2d3cbf4755596aab2eb5fee929aa5c0660b05cf70c88ae',
    '2026_03_26_130001_add_ticket_id_and_stopped_at_to_time_entries' => '52d32446b7dfb30e43c02d863c3c20ee78cae1e1453e848752ab34f6273e58fa',
    '2026_03_26_130002_create_time_entry_blocks_table' => '59abfdb168e3e9bf98450d226f40770a8b72b1aff92e65ff990a1e1771e1ced3',
    '2026_06_30_210000_widen_time_entry_duration_minutes' => '1ac6b89e7079cbcfa2c84f4238fcde5fb398a635f508d4c8311c3763a75180d4',
    '2026_09_19_000000_allow_passwordless_users' => '78809c5e0759e00df158b068c7b1a3eddf8d3273c9e222ddeaea7edceffcc5c7',
    '2026_09_19_000000_enforce_one_social_account_per_provider' => '5b9adeb1718ab5e481f7ba90716b653a1d0ddf1afb17cdc2233c315562de821f',
    '2026_09_21_120000_restrict_time_entry_project_and_task_deletes' => '0cbc6f2126530d4847a1cf09ac23de5edb9014a1b924afdfca7ec026b2cd0855',
    '2026_10_03_120000_add_completion_to_project_milestones' => '7f5043d7e0ddbdf5745a57d20b224b7a097ed5dac62c47a6fc0dcbfd4d398c92',
];
