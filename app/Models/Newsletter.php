<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Campagne newsletter (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36) — créée à la main dans l'admin, ou en
 * brouillon automatique à la publication d'une fiche annuaire/actualité/
 * annonce, ou après un scraping agenda/cinéma (voir `trigger_type`).
 *
 * ⚠️ Volontairement PAS de génération/envoi automatique vers TOUS les
 * abonnés ici, même pour les brouillons auto-générés : un brouillon reste
 * `status = draft` tant qu'un administrateur n'a pas déclenché l'envoi
 * depuis Filament (voir NewsletterResource et
 * App\Services\Newsletter\NewsletterSender). Double sécurité demandée par
 * le client le 12/09/2026 : "n'envoie à personne d'autre que moi avant mon
 * GO" — voir aussi `services.newsletter.sending_enabled`.
 */
class Newsletter extends Model
{
    protected $fillable = [
        'subject', 'preview_text', 'body_html', 'status', 'trigger_type',
        'triggerable_type', 'triggerable_id', 'test_sent_at', 'sent_at',
        'recipient_count', 'created_by',
    ];

    protected $casts = [
        'test_sent_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function triggerable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
