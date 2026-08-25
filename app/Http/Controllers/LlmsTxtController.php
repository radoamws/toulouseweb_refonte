<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Response;

/**
 * `/llms.txt` (brief §13, "optimisation GEO/AI Search" — priorité SEO/GEO
 * absolue). Suit la convention émergente llmstxt.org (comparable à
 * robots.txt/sitemap.xml mais destinée aux agents IA/LLM plutôt qu'aux
 * moteurs de recherche classiques) : un point d'entrée texte clair
 * résumant le site et ses sections principales, pour les agents qui
 * privilégient ce fichier à un crawl HTML complet.
 *
 * Contenu dynamique (pas un fichier statique comme sitemap.xml) — coût
 * négligeable (pas de requête lourde, juste `SiteSetting::current()`) et
 * reste synchronisé sans étape de régénération, contrairement au sitemap
 * qui, lui, énumère des milliers d'URLs (voir GenerateSitemap).
 *
 * Volontairement complémentaire de robots.txt (qui, lui, n'exclut AUCUN
 * user-agent IA — GPTBot/ClaudeBot/PerplexityBot etc. peuvent déjà tout
 * crawler à l'exception de /admin et /track-click) et des données
 * structurées Schema.org déjà posées sur chaque page (JSON-LD) — trois
 * couches complémentaires, pas redondantes.
 */
class LlmsTxtController
{
    public function __invoke(): Response
    {
        $settings = SiteSetting::current();
        $baseUrl = rtrim(config('app.url'), '/');

        $lines = [
            "# {$settings->site_name}",
            '',
            "> {$settings->description}",
            '',
            'Portail local dédié à Toulouse et sa région (France). Contenu public, mis à jour quotidiennement (agenda et cinéma alimentés par scraping automatisé, actualités/annuaire/annonces par une équipe éditoriale et des dépôts publics modérés).',
            '',
            '## Sections principales',
            '',
            "- [Annuaire]({$baseUrl}/annuaire) : commerces, restaurants et services locaux, classés par catégorie.",
            "- [Agenda]({$baseUrl}/agenda) : événements et spectacles à Toulouse (dont la catégorie Théâtre : {$baseUrl}/agenda/theatre).",
            "- [Cinéma]({$baseUrl}/cinema) : films et horaires de séances des salles toulousaines.",
            "- [Actualités]({$baseUrl}/actualites) : actualité locale.",
            "- [Annonces]({$baseUrl}/annonces) : petites annonces entre particuliers.",
            '',
            '## Notes pour les agents',
            '',
            "- Toutes les pages listées ci-dessus sont rendues côté serveur (HTML complet, sans exécution JavaScript requise) et portent des données structurées Schema.org (Organization, LocalBusiness/Restaurant, Event, Movie/MovieTheater, NewsArticle, BreadcrumbList selon le type de page).",
            "- Plan complet des URLs publiques : {$baseUrl}/sitemap.xml",
            "- Règles de crawl : {$baseUrl}/robots.txt (aucun user-agent IA n'est bloqué)",
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
