<?php

if (!defined('ABSPATH')) {
    exit;
}

class FfttClubToolsImporter
{
    /**
     * @return array<string,int>
     */
    public static function run(): array
    {
        $apiLogin = self::getConfigValue(
            ['FFTT_CLUB_TOOLS_API_LOGIN', 'USFAVTT_API_LOGIN'],
            ['fftt_club_tools_api_login', 'usfavtt_api_login']
        );
        $apiPassword = self::getConfigValue(
            ['FFTT_CLUB_TOOLS_API_PASSWORD', 'USFAVTT_API_PASSWORD'],
            ['fftt_club_tools_api_password', 'usfavtt_api_password']
        );
        $apiTeamId = self::getConfigValue(
            ['FFTT_CLUB_TOOLS_API_TEAM_ID', 'USFAVTT_API_TEAM_ID'],
            ['fftt_club_tools_api_team_id', 'usfavtt_api_team_id']
        );

        if ($apiLogin === '' || $apiPassword === '' || $apiTeamId === '') {
            throw new RuntimeException('Configuration API incomplète. Vérifier login, mot de passe et ID club.');
        }

        $ffttService = new FfttService($apiLogin, $apiPassword, $apiTeamId);
        $stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        try {
            $players = $ffttService->ffttApi->listJoueursByClub($ffttService->clubId);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossible de récupérer la liste des joueurs: ' . $exception->getMessage(), 0, $exception);
        }

        foreach ($players as $player) {
            $stats['total']++;
            $licenceId = (string) $player->getLicence();

            try {
                $playerDetail = $ffttService->ffttApi->retrieveJoueurDetails($licenceId);
                $virtualPoints = $ffttService->ffttApi->retrieveVirtualPoints($licenceId);
            } catch (Throwable $exception) {
                $stats['skipped']++;
                $stats['errors']++;
                error_log('[FFTT Club Tools import] Erreur API pour la licence ' . $licenceId . ': ' . $exception->getMessage());
                continue;
            }

            $existingPlayerIds = get_posts([
                'post_type' => 'joueur',
                'fields' => 'ids',
                'post_status' => ['publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit'],
                'meta_query' => [
                    [
                        'key' => 'numero_licence',
                        'value' => $licenceId,
                        'compare' => '=',
                    ],
                ],
                'orderby' => 'ID',
                'order' => 'ASC',
                'posts_per_page' => -1,
            ]);

            $postId = null;

            if (count($existingPlayerIds) > 1) {
                error_log('[FFTT Club Tools import] Doublons detectes pour la licence ' . $licenceId . ' (IDs: ' . implode(',', $existingPlayerIds) . ').');
            }

            if (!empty($existingPlayerIds)) {
                $postId = (int) $existingPlayerIds[0];
            } else {
                $postId = wp_insert_post([
                    'post_title' => $playerDetail->getPrenom() . ' ' . $playerDetail->getNom(),
                    'post_content' => '',
                    'post_status' => 'draft',
                    'post_type' => 'joueur',
                ], true);

                if (is_wp_error($postId)) {
                    $stats['skipped']++;
                    $stats['errors']++;
                    error_log('[FFTT Club Tools import] Impossible de créer le joueur licence ' . $licenceId . ': ' . $postId->get_error_message());
                    continue;
                }

                $stats['created']++;
                update_post_meta($postId, 'numero_licence', $licenceId);
            }

            if (!$postId) {
                $stats['skipped']++;
                continue;
            }

            update_post_meta($postId, 'nom', $playerDetail->getNom());
            update_post_meta($postId, 'prenom', $playerDetail->getPrenom());
            update_post_meta($postId, 'sexe', $playerDetail->isHomme() ? 'homme' : 'femme');
            update_post_meta($postId, 'categorie', $playerDetail->getCategorie());
            update_post_meta($postId, 'points_fftt', $playerDetail->getPointsLicence());
            update_post_meta($postId, 'points_fftt_debut_saison', $playerDetail->getPointDebutSaison());
            update_post_meta($postId, 'points_fftt_mensuel', $playerDetail->getPointsMensuel());
            update_post_meta($postId, 'points_fftt_virtuel', $virtualPoints->getVirtualPoints());
            update_post_meta($postId, 'progression_points_fftt', $virtualPoints->getSeasonlyPointsWon());

            $stats['updated']++;
        }

        return $stats;
    }

    private static function getConfigValue(array $constantNames, array $optionNames): string
    {
        foreach ($constantNames as $constantName) {
            if (defined($constantName)) {
                return trim((string) constant($constantName));
            }
        }

        foreach ($optionNames as $optionName) {
            $optionValue = get_option($optionName, null);
            if ($optionValue !== null && $optionValue !== '') {
                return trim((string) $optionValue);
            }
        }

        return '';
    }
}
