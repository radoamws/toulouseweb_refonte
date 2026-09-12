<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Abonné newsletter (demande client, 12/09/2026, voir
 * TECHNICAL_DOCUMENTATION.md §36). Pas de compte utilisateur associé — un
 * simple email suffit à s'inscrire, et le lien de désinscription
 * (`unsubscribe_token`) fait office d'unique "authentification".
 */
class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email', 'name', 'status', 'source', 'subscribed_at', 'unsubscribed_at',
        'unsubscribe_token', 'legacy_id',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscriber) {
            if (! $subscriber->unsubscribe_token) {
                $subscriber->unsubscribe_token = Str::random(48);
            }
            if (! $subscriber->status) {
                $subscriber->status = 'active';
            }
            if (! $subscriber->subscribed_at) {
                $subscriber->subscribed_at = now();
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function unsubscribe(): void
    {
        $this->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    }

    /**
     * Inscrit (ou réactive) un email — utilisé par le formulaire d'accueil,
     * le formulaire de contact (demande client : "toute personne s'inscrivant
     * dans la page contact s'inscrit automatiquement aussi à la newsletter")
     * et l'import legacy. `firstOrCreate` sur l'email seul : une personne
     * déjà inscrite qui soumet à nouveau le formulaire de contact ne doit
     * pas créer de doublon ni écraser sa date d'inscription d'origine — si
     * elle s'était désinscrite entre-temps, on ne la réinscrit PAS de force
     * ici (voir `resubscribe` séparé) pour respecter son choix explicite.
     */
    public static function subscribeEmail(string $email, ?string $name, string $source): self
    {
        $email = mb_strtolower(trim($email));

        $subscriber = static::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'source' => $source],
        );

        if (! $subscriber->wasRecentlyCreated && ! $subscriber->name && $name) {
            $subscriber->update(['name' => $name]);
        }

        return $subscriber;
    }
}
