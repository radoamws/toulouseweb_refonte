<?php

namespace App\Console\Commands\Migration;

use App\Models\ContactMessage;
use App\Services\Migration\LegacyCleaner;
use App\Services\Migration\MigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 7 du plan de migration (TECHNICAL_DOCUMENTATION.md §10) : messages
 * de contact actifs (t_contact_us). L'ancien formulaire (t_contacts,
 * 2001-2014) n'est volontairement PAS migré — obsolète, voir audit DB §4.
 */
class MigrateContacts extends Command
{
    protected $signature = 'migrate:contacts';

    protected $description = 'Migre les messages de contact actifs (t_contact_us) depuis toulouseweb_old';

    public function handle(): int
    {
        $log = new MigrationLog('contacts');

        foreach (DB::connection('legacy')->table('t_contact_us')->orderBy('id')->get() as $row) {
            $existing = ContactMessage::where('legacy_id', $row->id)->first();
            if ($existing) {
                $log->skipped("Message legacy #{$row->id} déjà migré.");

                continue;
            }

            $message = ContactMessage::create([
                'legacy_id' => $row->id,
                'name' => LegacyCleaner::text($row->nomprenoms) ?? 'Inconnu',
                'email' => LegacyCleaner::text($row->courriel) ?? '',
                'phone' => LegacyCleaner::text($row->telephone),
                'subject' => LegacyCleaner::text($row->sujet),
                'message' => LegacyCleaner::text($row->message) ?? '',
                'is_read' => true, // historique legacy : considéré comme déjà traité
            ]);
            // created_at fixé après coup pour préserver la date réelle du message
            // (Eloquent écrase created_at à la création malgré la valeur fournie).
            $message->timestamps = false;
            $message->created_at = LegacyCleaner::date($row->dateadd) ?? now();
            $message->save();

            $log->created("#{$row->id} -> #{$message->id}");
        }

        $this->info($log->summary());

        return self::SUCCESS;
    }
}
