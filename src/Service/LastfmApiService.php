<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;

class LastfmApiService
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $client;
    private CacheInterface $cache;
    private string $apiKey;
    private int $requestCount = 0;
    private float $startTime;

    public function __construct(HttpClientInterface $client, CacheInterface $cache,EntityManagerInterface $entityManager, string $lastfmApiKey)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->entityManager = $entityManager;
        $this->apiKey = $lastfmApiKey;
        $this->startTime = microtime(true);
    }

    // --- Méthodes principales ---

    /**
     * Collecte tous les tags en combinant plusieurs sources.
     *
     * @return array
     */
    public function collectAllTags(): array
    {
        // Pas besoin de set_time_limit ici si vous exécutez via CLI
        return $this->cache->get('all_lastfm_tags_extended', function (ItemInterface $item) {
            $item->expiresAfter(86400); // Le cache expire après 24 heures

            $allTags = [];
            $letters = range('a', 'z');
            $countries = ['United States', 'United Kingdom', 'France', 'Germany', 'Japan', 'Australia', 'Brazil', 'Canada', 'Russia', 'India'];

            // Contrôle du rate limit
            $this->requestCount = 0;
            $this->startTime = microtime(true);

            // Limiter les lettres pour réduire le temps d'exécution
            $letters = array_slice($letters, 0, 5); // Par exemple, seulement les 5 premières lettres

            // Étape 1 : Parcourir les lettres pour rechercher des artistes
            foreach ($letters as $letter) {
                $artists = $this->searchArtistsByLetter($letter, 50);

                foreach ($artists as $artist) {
                    $artistName = $artist['name'];

                    // Collecter les tags de l'artiste
                    $artistTags = $this->getArtistTopTags($artistName);
                    foreach ($artistTags as $tag) {
                        $allTags[$tag['name']] = $tag;
                    }

                    // Vous pouvez commenter la collecte des albums pour gagner du temps
                    /*
                    // Collecter les albums de l'artiste
                    $albums = $this->getArtistTopAlbums($artistName, 2);
                    foreach ($albums as $album) {
                        $albumName = $album['name'];
                        $albumTags = $this->getAlbumTopTags($artistName, $albumName);
                        foreach ($albumTags as $tag) {
                            $allTags[$tag['name']] = $tag;
                        }
                    }
                    */
                }
            }

            // Étape 2 : Parcourir les pays pour obtenir les artistes populaires
            foreach ($countries as $country) {
                $artists = $this->getTopArtistsByCountry($country, 20);

                foreach ($artists as $artist) {
                    $artistName = $artist['name'];

                    // Collecter les tags de l'artiste
                    $artistTags = $this->getArtistTopTags($artistName);
                    foreach ($artistTags as $tag) {
                        $allTags[$tag['name']] = $tag;
                    }
                }
            }

            // Étape 3 : Utiliser les tags similaires pour enrichir la liste
            $collectedTags = array_keys($allTags);
            foreach ($collectedTags as $tagName) {
                $similarTags = $this->getSimilarTags($tagName);
                foreach ($similarTags as $tag) {
                    $allTags[$tag['name']] = $tag;
                }
            }

            // Retourner la liste des tags uniques
            return array_values($allTags);
        });
    }

    // --- Méthodes de recherche ---

    /**
     * Recherche des artistes commençant par une lettre donnée.
     *
     * @param string $letter
     * @param int $limit
     * @return array
     */
    public function searchArtistsByLetter(string $letter, int $limit = 100): array
    {
        $artists = [];
        $page = 1;
        $totalPages = 1;

        do {
            // Contrôle du rate limit
            $this->controlRateLimit();

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'artist.search',
                        'artist' => $letter . '*',
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                        'limit' => $limit,
                        'page' => $page,
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['results']['artistmatches']['artist'])) {
                    $artists = array_merge($artists, $data['results']['artistmatches']['artist']);
                }

                $totalResults = (int)$data['results']['opensearch:totalResults'];
                $totalPages = ceil($totalResults / $limit);
                $page++;
            } catch (\Exception $e) {
                // Gérer l'exception
                break;
            }

        } while ($page <= $totalPages && $page <= 5); // Limiter à 5 pages pour éviter trop de requêtes

        return $artists;
    }

    /**
     * Recherche des tracks commençant par une lettre donnée.
     *
     * @param string $letter
     * @param int $limit
     * @return array
     */
    public function searchTracksByLetter(string $letter, int $limit = 100): array
    {
        $tracks = [];
        $page = 1;
        $totalPages = 1;

        do {
            // Contrôle du rate limit
            $this->controlRateLimit();

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'track.search',
                        'track' => $letter . '*',
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                        'limit' => $limit,
                        'page' => $page,
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['results']['trackmatches']['track'])) {
                    $tracks = array_merge($tracks, $data['results']['trackmatches']['track']);
                }

                $totalResults = (int)$data['results']['opensearch:totalResults'];
                $totalPages = ceil($totalResults / $limit);
                $page++;
            } catch (\Exception $e) {
                // Gérer l'exception
                break;
            }

        } while ($page <= $totalPages && $page <= 5); // Limiter à 5 pages

        return $tracks;
    }

    // --- Méthodes pour les artistes ---

    /**
     * Récupère les top tags d'un artiste.
     *
     * @param string $artist
     * @return array
     */
    public function getArtistTopTags(string $artist): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('artist_top_tags_' . md5($artist), function (ItemInterface $item) use ($artist) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'artist.getTopTags',
                        'artist' => $artist,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['toptags']['tag'])) {
                    return $data['toptags']['tag'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    /**
     * Récupère les albums les plus populaires d'un artiste.
     *
     * @param string $artist
     * @param int $limit
     * @return array
     */
    public function getArtistTopAlbums(string $artist, int $limit = 5): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('artist_top_albums_' . md5($artist), function (ItemInterface $item) use ($artist, $limit) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'artist.getTopAlbums',
                        'artist' => $artist,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                        'limit' => $limit,
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['topalbums']['album'])) {
                    return $data['topalbums']['album'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    // --- Méthodes pour les albums ---

    /**
     * Récupère les top tags d'un album.
     *
     * @param string $artist
     * @param string $album
     * @return array
     */
    public function getAlbumTopTags(string $artist, string $album): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('album_top_tags_' . md5($artist . '_' . $album), function (ItemInterface $item) use ($artist, $album) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'album.getTopTags',
                        'artist' => $artist,
                        'album' => $album,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['toptags']['tag'])) {
                    return $data['toptags']['tag'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    // --- Méthodes pour les tracks ---

    /**
     * Récupère les top tags d'un track.
     *
     * @param string $artist
     * @param string $track
     * @return array
     */
    public function getTrackTopTags(string $artist, string $track): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('track_top_tags_' . md5($artist . '_' . $track), function (ItemInterface $item) use ($artist, $track) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'track.getTopTags',
                        'artist' => $artist,
                        'track' => $track,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['toptags']['tag'])) {
                    return $data['toptags']['tag'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    // --- Méthodes pour les tags ---

    /**
     * Récupère les tags similaires à un tag donné.
     *
     * @param string $tag
     * @return array
     */
    public function getSimilarTags(string $tag): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('similar_tags_' . md5($tag), function (ItemInterface $item) use ($tag) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'tag.getSimilar',
                        'tag' => $tag,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['similartags']['tag'])) {
                    return $data['similartags']['tag'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    // --- Méthodes pour les artistes par pays ---

    /**
     * Récupère les artistes les plus populaires d'un pays donné.
     *
     * @param string $country
     * @param int $limit
     * @return array
     */
    public function getTopArtistsByCountry(string $country, int $limit = 50): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        return $this->cache->get('top_artists_by_country_' . md5($country), function (ItemInterface $item) use ($country, $limit) {
            $item->expiresAfter(3600);

            try {
                $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                    'query' => [
                        'method' => 'geo.getTopArtists',
                        'country' => $country,
                        'api_key' => $this->apiKey,
                        'format' => 'json',
                        'limit' => $limit,
                    ],
                ]);

                $data = $response->toArray();

                if (isset($data['topartists']['artist'])) {
                    return $data['topartists']['artist'];
                }
            } catch (\Exception $e) {
                // Gérer l'exception
                return [];
            }

            return [];
        });
    }

    /**
     * Récupère les artistes associés à un genre donné.
     *
     * @param string $genreName
     * @return array
     */
    public function getArtistsByGenre(string $genreName): array
    {
        // Vérifier si les artistes sont en cache
        $cacheKey = 'artists_by_genre_' . md5($genreName);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($genreName) {
            $item->expiresAfter(3600);

            // Vérifier si les artistes sont en base de données
            $genre = $this->entityManager->getRepository(Genre::class)->find($genreName);

            if ($genre && !$genre->getArtists()->isEmpty()) {
                return $genre->getArtists()->toArray();
            }

            // Sinon, récupérer depuis l'API et stocker en base
            $artistsData = $this->getArtistsByGenreFromApi($genreName);

            if (!$genre) {
                $genre = new Genre();
                $genre->setName($genreName);
                $this->entityManager->persist($genre);
            }

            foreach ($artistsData as $data) {
                $artistName = $data['name'];

                // Vérifier si l'artiste existe déjà en base
                $artist = $this->entityManager->getRepository(Artist::class)->find($artistName);

                if (!$artist) {
                    $artist = new Artist();
                    $artist->setName($artistName);
                    $artist->setUrl($data['url']);
                    $this->entityManager->persist($artist);
                }

                $artist->addGenre($genre);
                $genre->addArtist($artist);
            }

            $this->entityManager->flush();

            return $genre->getArtists()->toArray();
        });
    }




    // --- Contrôle du rate limit ---

    /**
     * Contrôle le rate limit pour respecter la limite de 4 requêtes par seconde.
     */
    private function controlRateLimit()
    {
        $this->requestCount++;

        if ($this->requestCount >= 4) {
            $elapsedTime = microtime(true) - $this->startTime;

            if ($elapsedTime < 1) {
                usleep((1 - $elapsedTime) * 1000000); // Pause pour respecter la limite
            }

            $this->requestCount = 0;
            $this->startTime = microtime(true);
        }
    }


        /**
     * Récupère les artistes associés à un genre donné depuis l'API Last.fm.
     *
     * @param string $genreName
     * @param int $limit
     * @return array
     */
    private function getArtistsByGenreFromApi(string $genreName, int $limit = 50): array
    {
        // Contrôle du rate limit
        $this->controlRateLimit();

        try {
            $response = $this->client->request('GET', 'http://ws.audioscrobbler.com/2.0/', [
                'query' => [
                    'method' => 'tag.getTopArtists',
                    'tag' => $genreName,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                    'limit' => $limit,
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['topartists']['artist'])) {
                return $data['topartists']['artist'];
            }
        } catch (\Exception $e) {
            // Gérer l'exception (par exemple, journaliser l'erreur)
            $this->logger->error(sprintf('Erreur lors de la récupération des artistes pour le genre %s : %s', $genreName, $e->getMessage()));
            return [];
        }

        return [];
    }
}
