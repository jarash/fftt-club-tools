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

            $term = self::findTerm(
                $terms,
                $clubTeamName,
                (string) $poolTeam->getNomEquipe(),
                (string) $poolTeam->getNumero(),
                $division
            );

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
        $normalizedClubTeamName = self::normalizeTeamName($clubTeamName);
        $clubTeamNumber = self::extractTeamNumber($clubTeamName);
        $bestCandidate = null;
        $bestScore = -1;
        $isAmbiguous = false;

        foreach ($poolTeams as $poolTeam) {
            $poolTeamName = self::normalizeTeamName((string) $poolTeam->getNomEquipe());
            if (!self::namesMatch($normalizedClubTeamName, $poolTeamName)) {
                continue;
            }

            $score = 0;
            if ($normalizedClubTeamName === $poolTeamName) {
                $score += 6;
            } else {
                $score += 2;
            }

            $poolTeamNumber = self::extractTeamNumber((string) $poolTeam->getNomEquipe());
            if ($clubTeamNumber > 0 && $poolTeamNumber > 0) {
                if ($clubTeamNumber !== $poolTeamNumber) {
                    continue;
                }

                $score += 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $poolTeam;
                $isAmbiguous = false;
            } elseif ($score === $bestScore) {
                $isAmbiguous = true;
            }
        }

        if ($bestCandidate === null || $isAmbiguous) {
            return null;
        }

        return $bestCandidate;
    }

    /**
     * @param array<WP_Term> $terms
     */
    private static function findTerm(array $terms, string $clubTeamName, string $poolTeamName, string $poolTeamNumber, string $division): ?WP_Term
    {
        $normalizedClubTeamName = self::normalizeTeamName($clubTeamName);
        $normalizedPoolTeamName = self::normalizeTeamName($poolTeamName);
        $divisionAliases = array_map([self::class, 'normalize'], self::buildDivisionAliases($division));
        $divisionAliases = array_values(array_filter($divisionAliases));

        $clubTeamNumber = self::extractTeamNumber($clubTeamName);
        $poolTeamNumberAsInt = (int) trim($poolTeamNumber);
        $expectedNumber = $clubTeamNumber > 0 ? $clubTeamNumber : ($poolTeamNumberAsInt > 0 ? $poolTeamNumberAsInt : 0);

        $bestTerm = null;
        $bestScore = -1;
        $isAmbiguous = false;

        foreach ($terms as $term) {
            $termValues = [
                self::normalize($term->name),
                self::normalize($term->slug),
                self::normalize($term->description),
            ];

            $score = 0;
            foreach ($termValues as $termValue) {
                if ($termValue === '') {
                    continue;
                }

                if ($termValue === $normalizedClubTeamName || $termValue === $normalizedPoolTeamName) {
                    $score += 12;
                } elseif (self::namesMatch($termValue, $normalizedClubTeamName) || self::namesMatch($termValue, $normalizedPoolTeamName)) {
                    $score += 4;
                }

                if (!empty($divisionAliases) && in_array($termValue, $divisionAliases, true)) {
                    $score += 3;
                }
            }

            if ($expectedNumber > 0) {
                $termHasExpectedNumber = false;
                foreach ($termValues as $termValue) {
                    if ($termValue !== '' && self::containsTeamNumber($termValue, $expectedNumber)) {
                        $termHasExpectedNumber = true;
                        break;
                    }
                }

                if ($termHasExpectedNumber) {
                    $score += 10;
                }
            }

            if ($score <= 0) {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTerm = $term;
                $isAmbiguous = false;
            } elseif ($score === $bestScore) {
                $isAmbiguous = true;
            }
        }

        if ($bestTerm === null || $isAmbiguous) {
            return null;
        }

        return $bestTerm;
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

    private static function extractTeamNumber(string $value): int
    {
        $normalized = self::normalizeTeamName($value);
        if ($normalized === '') {
            return 0;
        }

        if (preg_match('/\b(\d{1,2})\b(?!.*\b\d{1,2}\b)/', $normalized, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private static function normalizeTeamName(string $value): string
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            return '';
        }

        // Les libellés API peuvent contenir un suffixe "phase X" qui ne fait pas partie de l'identité de l'équipe.
        $withoutPhase = preg_replace('/\bphase\s+\d+\b/', ' ', $normalized) ?: $normalized;

        return preg_replace('/\s+/', ' ', trim($withoutPhase)) ?: '';
    }

    private static function containsTeamNumber(string $value, int $number): bool
    {
        if ($number <= 0 || $value === '') {
            return false;
        }

        return preg_match('/\b' . preg_quote((string) $number, '/') . '\b/', $value) === 1;
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
