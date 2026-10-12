<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;

/**
 * Demande client (12/10/2026) : sur la home, remplacer "Antiquités-Brocantes"
 * par "Restaurants" (2e position, juste après "A emporter") dans le bandeau
 * "Catégories populaires" (`HomeController::index()` : les 8 premières
 * catégories actives par `order`, voir TECHNICAL_DOCUMENTATION.md §13/§55).
 *
 * `categories.order` n'avait JAMAIS été renseigné en production avant cette
 * migration (vérifié : 100% des lignes à 0) — l'ordre actuel du bandeau
 * (alphabétique) n'est qu'un hasard de l'id d'insertion, pas une valeur
 * intentionnelle.
 *
 * ⚠️ `categories.order` est `unsignedInteger` (voir
 * create_annuaire_tables.php) — impossible d'utiliser des valeurs négatives
 * pour forcer les 8 catégories voulues avant "tout le reste à 0" (contre-
 * exemple découvert en testant localement : MySQL strict rejette
 * l'`UPDATE` avec "Out of range value for column 'order'"). Stratégie
 * retenue à la place : relever TOUTES les catégories actuellement à 0 (donc
 * 100% du catalogue) à 999, PUIS assigner 1-8 à la séquence voulue — ce qui
 * libère mécaniquement les 8 premières places pour elles. Sans effet de
 * bord ailleurs : `ListingController::index()` trie par `order` PUIS par
 * `name` (`orderBy('order')->orderBy('name')`) — un bump uniforme de 0 à
 * 999 ne change donc rien à l'ordre alphabétique relatif des catégories non
 * concernées sur /annuaire, seul `HomeController::index()` (AUCUN tri
 * secondaire, limit(8) brut) est réellement affecté.
 *
 * "Restaurants" (slug `restaurants`) existait déjà comme catégorie de
 * niveau 0 active, avec une icône déjà appropriée
 * (`/images/category-icons/food.svg`) — aucune icône à créer.
 * "Antiquités-Brocantes" n'est pas supprimée ni désactivée, simplement
 * laissée à 999 avec le reste du catalogue — retirée du top 8, rien d'autre.
 *
 * Changement de DONNÉE uniquement (même nature que le précédent équivalent
 * sur `event_categories.order`, §55), via une migration (plutôt qu'en
 * direct en production) pour que ce changement passe par la revue de
 * code/CI-CD normale du dépôt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Category::where('level', 0)->where('is_active', true)->where('order', 0)->update(['order' => 999]);

        // slug => nouvel ordre, dans la séquence voulue par le client.
        $sequence = [
            'a-emporter' => 1,
            'restaurants' => 2,
            'a-faire-le-dimanche' => 3,
            'ameublement' => 4,
            'animaux' => 5,
            'anniversaires' => 6,
            'art-et-culture' => 7,
            'associations' => 8,
        ];

        foreach ($sequence as $slug => $order) {
            Category::where('slug', $slug)->where('level', 0)->update(['order' => $order]);
        }
    }

    public function down(): void
    {
        Category::where('level', 0)->where('is_active', true)->where('order', '>', 0)->update(['order' => 0]);
    }
};
