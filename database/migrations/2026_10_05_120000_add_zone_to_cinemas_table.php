<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demande client (05/10/2026) : revenir à la page /cinema d'avant, qui
 * listait les salles en 2 colonnes "Toulouse et complexes" / "Toiles de
 * banlieues" — une vraie donnée métier du legacy (`t_cine.id_cine_categ`,
 * 1|2), jamais migrée car la v1 de ce projet n'affichait plus les salles que
 * sous forme de puces non catégorisées. Backfillée ici depuis `old/toulouse.sql`
 * (mapping `legacy_id` => catégorie vérifié ligne à ligne contre les 28
 * salles réellement en base de prod) plutôt que redevinée depuis l'adresse
 * (le code postal ne suit PAS cette distinction : CGR Blagnac/Gaumont Labège/
 * Kinepolis Fenouillet sont "complexe" bien qu'hors Toulouse-ville, alors que
 * Tempo Ciné/Le Castelia sont "banlieue" — la vraie distinction legacy est
 * grande chaîne/complexe vs petite salle associative, pas la géographie).
 *
 * Défaut 'complexe' (jamais NULL) : une salle future créée sans zone
 * explicite (admin, factory de test) doit rester visible quelque part sur
 * /cinema plutôt que disparaître des 2 listes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cinemas', function (Blueprint $table) {
            $table->string('zone')->default('complexe')->after('address');
        });

        // legacy_id => zone, lu depuis old/toulouse.sql (`t_cine.id_cine_categ` : 1 = complexe, 2 = banlieue).
        $banlieueLegacyIds = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 31];

        DB::table('cinemas')->whereIn('legacy_id', $banlieueLegacyIds)->update(['zone' => 'banlieue']);
        // Les autres legacy_id (1-8, 32-34) sont déjà 'complexe' par défaut,
        // tout comme les salles ajoutées après la migration legacy (ex.
        // "UGC Montaudran", legacy_id=36, absent du dump — chaîne UGC, donc
        // complexe) qui gardent simplement le défaut de la colonne.
    }

    public function down(): void
    {
        Schema::table('cinemas', function (Blueprint $table) {
            $table->dropColumn('zone');
        });
    }
};
