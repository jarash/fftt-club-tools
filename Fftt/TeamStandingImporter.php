<?php

if (!defined('ABSPATH')) {
    exit;
}

class FfttClubToolsTeamStandingImporter
{
    /**
     * @return array<string,int>
     */
    public static function run(): array
    {
        $apiLogin = self::getConfigValue('FFTT_CLUB_TOOLS_API_LOGIN', 'fftt_club_tools_api_login');
        $apiPassword = self::getConfigValue('FFTT_CLUB_TOOLS_API_PASSWORD', 'fftt_club_tools_api_password');
        $apiTeamId = self::getConfigValue('FFTT_CLUB_TOOLS_API_TEAM_ID', 'fftt_club_tools_api_team_id');

        if ($apiLogin === '' || $apiPassword === '' || $apiTeamId === '') {
            throw new RuntimeException('Configuration API incomplète. Vérifier login, mot de passe et ID club.');
        }

        $ffttService = new FfttService($apiLogin, $apiPassword, $apiTeamId);
        $stats = [
            'total' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $terms = get_terms([
            'taxonomy' => 'equipe',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            throw new RuntimeException('Impossible de récupérer les équipes WordPress: ' . $terms->get_error_message());
        }

        try {
            $teams = $ffttService->ffttApi->listEquipesByClub($ffttService->clubId);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossible de récupérer les équipes FFTT: ' . $exception->getMessage(), 0, $exception);
        }

        foreach ($teams as $team) {
            $stats['total']++;
            $clubTeamName = (string) $team->getLibelle();
            $division = (string) $team->getDivision();
            $divisionLink = (string) $team->getLienDivision();

            try {
                $poolTeams = $ffttService->ffttApi->listEquipePouleByLienDivision($divisionLink);
            } catch (Throwable $exception) {
                $stats['skipped']++;
                $stats['errors']++;
                error_log('[FFTT Club Tools team standing import] Erreur API pour ' . $clubTeamName . ': ' . $exception->getMessage());
                continue;
            }

            $poolTeam = self::findPoolTeam($clubTeamName, $poolTeams);
            if ($poolTeam === null) {
                $stats['skipped']++;
                error_log('[FFTT Club Tools team standing import] Equipe non trouvee dans la poule: ' . $clubTeamName);
                continue;
            }

            $term = self::findTerm($terms, array_merge([
                $clubTeamName,
                (string) $poolTeam->getNomEquipe(),
                (string) $poolTeam->getNumero(),
                $division,
            ], self::buildDivisionAliases($division)));

            if (!$term instanceof WP_Term) {
                $stats['skipped']++;
                error_log('[FFTT Club Tools team standing import] Terme WordPress non trouve pour: ' . $clubTeamName . ' / ' . (string) $poolTeam->getNomEquipe());
                continue;
            }

            update_term_meta($term->term_id, 'fftt_team_standing_rank', $poolTeam->getClassement());
            update_term_meta($term->term_id, 'fftt_team_standing_points', $poolTeam->getPoints());
            update_term_meta($term->term_id, 'fftt_team_standing_played', $poolTeam->getMatchJouees());
            update_term_meta($term->term_id, 'fftt_team_standing_wins', $poolTeam->getVictoires());
            update_term_meta($term->term_id, 'fftt_team_standing_losses', $poolTeam->getDefaites());
            update_term_meta($term->term_id, 'fftt_team_standing_pool_team_name', (string) $poolTeam->getNomEquipe());
            update_term_meta($term->term_id, 'fftt_team_standing_division', $division);
            update_term_meta($term->term_id, 'fftt_team_standing_updated_at', current_time('mysql'));

            $stats['updated']++;
        }

        return $stats;
    }

    private static function findPoolTeam(string $clubTeamName, array $poolTeams): ?object
    {
        $normalizedClubTeamName = self::normalize($clubTeamName);

        foreach ($poolTeams as $poolTeam) {
            $poolTeamName = self::normalize((string) $poolTeam->getNomEquipe());
            if (self::namesMatch($normalizedClubTeamName, $poolTeamName)) {
                return $poolTeam;
            }
        }

        return null;
    }

    /**
     * @param array<WP_Term> $terms
     * @param array<string>  $candidates
     */
    private static function findTerm(array $terms, array $candidates): ?WP_Term
    {
        $normalizedCandidates = array_values(array_filter(array_map([self::class, 'normalize'], $candidates)));

        foreach ($terms as $term) {
            $termValues = [
                self::normalize($term->name),
                self::normalize($term->slug),
                self::normalize($term->description),
            ];

            foreach ($termValues as $termValue) {
                if ($termValue === '') {
                    continue;
                }

                foreach ($normalizedCandidates as $candidate) {
                    if (self::namesMatch($termValue, $candidate)) {
                        return $term;
                    }
                }
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        $value = remove_accents($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';

        return preg_replace('/\s+/', ' ', trim($value)) ?: '';
    }

    /**
     * @return array<string>
     */
    private static function buildDivisionAliases(string $division): array
    {
        $normalizedDivision = self::normalize($division);
        if ($normalizedDivision === '') {
            return [];
        }

        if (preg_match('/\b(departementale|regionale|nationale|prenationale)\s+([0-9]+)\b/', $normalizedDivision, $matches) !== 1) {
            return [];
        }

        $prefix = match ($matches[1]) {
            'departementale' => 'd',
            'regionale' => 'r',
            'nationale' => 'n',
            'prenationale' => 'pn',
            default => '',
        };

        if ($prefix === '') {
            return [];
        }

        return [
            $prefix . $matches[2],
            strtoupper($prefix) . $matches[2],
        ];
    }

    private static function namesMatch(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        return $left === $right || str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    private static function getConfigValue(string $constantName, string $optionName): string
    {
        if (defined($constantName)) {
            return trim((string) constant($constantName));
        }

        $optionValue = get_option($optionName, null);
        if ($optionValue !== null && $optionValue !== '') {
            return trim((string) $optionValue);
        }

        return '';
    }
}
